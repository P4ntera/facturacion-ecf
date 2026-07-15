<?php

namespace App\Filament\Resources;

use App\Enums\AnchoPapel;
use App\Enums\ModuloImpresion;
use App\Enums\TipoConexionImpresora;
use App\Filament\Resources\ImpresoraResource\Pages;
use App\Models\Impresora;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Mike42\Escpos\Exception\ImageSizeException;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\Printer;

class ImpresoraResource extends Resource
{
    protected static ?string $model = Impresora::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static ?string $navigationLabel = 'Impresoras';

    protected static ?string $modelLabel = 'Impresora';

    protected static ?string $pluralModelLabel = 'Impresoras';

    protected static ?string $slug = 'impresoras';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),

                TextInput::make('descripcion')
                    ->label('Descripción')
                    ->maxLength(255),

                Select::make('tipo_conexion')
                    ->label('Tipo de conexión')
                    ->options(collect(TipoConexionImpresora::cases())->mapWithKeys(
                        fn (TipoConexionImpresora $tipo) => [$tipo->value => $tipo->etiqueta()]
                    ))
                    ->required()
                    ->native(false)
                    ->live()
                    ->helperText('Red: el servidor imprime directo por IP, sin diálogo. Navegador: el usuario elige la impresora física en el diálogo del navegador.'),

                Select::make('modulo')
                    ->label('Módulo')
                    ->options(collect(ModuloImpresion::cases())->mapWithKeys(
                        fn (ModuloImpresion $modulo) => [$modulo->value => $modulo->etiqueta()]
                    ))
                    ->required()
                    ->native(false),

                TextInput::make('ip')
                    ->label('Dirección IP')
                    ->ip()
                    ->visible(fn (Get $get) => $get('tipo_conexion') === TipoConexionImpresora::RED->value)
                    ->required(fn (Get $get) => $get('tipo_conexion') === TipoConexionImpresora::RED->value),

                TextInput::make('puerto')
                    ->label('Puerto')
                    ->numeric()
                    ->integer()
                    ->default(9100)
                    ->visible(fn (Get $get) => $get('tipo_conexion') === TipoConexionImpresora::RED->value)
                    ->required(fn (Get $get) => $get('tipo_conexion') === TipoConexionImpresora::RED->value),

                Select::make('ancho_papel')
                    ->label('Ancho de papel')
                    ->options(collect(AnchoPapel::cases())->mapWithKeys(
                        fn (AnchoPapel $ancho) => [$ancho->value => $ancho->etiqueta()]
                    ))
                    ->default(AnchoPapel::MM80->value)
                    ->required()
                    ->native(false),

                Toggle::make('predeterminada')
                    ->label('Predeterminada de su módulo')
                    ->helperText('Solo puede haber una predeterminada por módulo; marcar esta desmarca automáticamente cualquier otra.'),

                Toggle::make('activa')
                    ->label('Activa')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('tipo_conexion')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (TipoConexionImpresora $state) => $state->etiqueta())
                    ->color(fn (TipoConexionImpresora $state) => $state === TipoConexionImpresora::RED ? 'info' : 'gray'),

                TextColumn::make('destino')
                    ->label('Destino')
                    ->getStateUsing(fn (Impresora $record) => $record->esDeRed() ? "{$record->ip}:{$record->puerto}" : 'Navegador'),

                TextColumn::make('ancho_papel')
                    ->label('Ancho')
                    ->formatStateUsing(fn (AnchoPapel $state) => $state->etiqueta()),

                TextColumn::make('modulo')
                    ->label('Módulo')
                    ->formatStateUsing(fn (ModuloImpresion $state) => $state->etiqueta())
                    ->badge(),

                IconColumn::make('predeterminada')
                    ->label('Predeterminada')
                    ->boolean(),

                ToggleColumn::make('activa')
                    ->label('Activa'),
            ])
            ->filters([
                SelectFilter::make('modulo')
                    ->label('Módulo')
                    ->options(collect(ModuloImpresion::cases())->mapWithKeys(
                        fn (ModuloImpresion $modulo) => [$modulo->value => $modulo->etiqueta()]
                    )),

                SelectFilter::make('tipo_conexion')
                    ->label('Tipo de conexión')
                    ->options(collect(TipoConexionImpresora::cases())->mapWithKeys(
                        fn (TipoConexionImpresora $tipo) => [$tipo->value => $tipo->etiqueta()]
                    )),

                TernaryFilter::make('activa')->label('Activa'),
            ])
            ->recordActions([
                Action::make('probarImpresion')
                    ->label('Probar impresión')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->action(fn (Impresora $record) => static::probarImpresion($record))
                    ->url(fn (Impresora $record) => $record->esDeRed()
                        ? null
                        : route('impresoras.prueba', $record), shouldOpenInNewTab: true),
            ]);
    }

    /**
     * Solo se ejecuta para impresoras de RED: para NAVEGADOR, la acción usa ->url() (arriba)
     * en vez de ->action(), porque abrir una pestaña es cosa del navegador, no del servidor.
     */
    protected static function probarImpresion(Impresora $record): void
    {
        if (! $record->esDeRed()) {
            return;
        }

        try {
            $conector = new NetworkPrintConnector($record->ip, $record->puerto ?? 9100, 3);
            $impresora = new Printer($conector);
            $impresora->setJustification(Printer::JUSTIFY_CENTER);
            $impresora->text("Prueba de impresion\n{$record->nombre}\n".now()->format('d/m/Y H:i')."\n");
            $impresora->feed();
            $impresora->cut();
            $impresora->close();
        } catch (ImageSizeException|\Exception $e) {
            Notification::make()
                ->title('No se pudo imprimir la prueba')
                ->body("Error de conexión con {$record->ip}:{$record->puerto} — {$e->getMessage()}")
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Prueba de impresión enviada')
            ->body("Se envió correctamente a {$record->ip}:{$record->puerto}.")
            ->success()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImpresoras::route('/'),
            'create' => Pages\CreateImpresora::route('/create'),
            'edit' => Pages\EditImpresora::route('/{record}/edit'),
        ];
    }
}
