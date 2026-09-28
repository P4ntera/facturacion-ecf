<?php

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\CajaResource\Pages;
use App\Models\Caja;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CajaResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = Caja::class;

    public static function modulo(): Modulo
    {
        return Modulo::VENTAS_CAJAS;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?string $navigationLabel = 'Cajas registradoras';

    protected static ?string $modelLabel = 'Caja registradora';

    protected static ?string $pluralModelLabel = 'Cajas registradoras';

    protected static ?string $slug = 'cajas';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')
                ->label('Nombre')
                ->helperText('Ej. "Caja 1", "Caja Express". Es lo que elige el cajero al entrar al POS táctil.')
                ->required()
                ->maxLength(50)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('empresa_id', Filament::getTenant()->id))
                ->validationMessages(['unique' => 'Ya existe una caja con este nombre.']),

            TextInput::make('codigo')
                ->label('Código')
                ->placeholder('C01')
                ->maxLength(20)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('empresa_id', Filament::getTenant()->id))
                ->validationMessages(['unique' => 'Ya existe una caja con este código.']),

            TextInput::make('mensaje_display')
                ->label('Mensaje del display del cliente')
                ->placeholder('¡Gracias por su compra!')
                ->maxLength(120)
                ->columnSpanFull(),

            Toggle::make('activo')
                ->label('Activa')
                ->helperText('Una caja inactiva no aparece en el POS táctil y su display deja de funcionar.')
                ->default(true),

            TextEntry::make('display_url')
                ->label('URL del display del cliente')
                ->state(fn (?Caja $record): ?string => $record?->urlDisplay())
                ->helperText('Ábrela en la pantalla que mira el cliente (no requiere login). Quien tenga esta URL ve el carrito de esta caja: si se filtra, usa "Regenerar URL del display".')
                ->copyable()
                ->visible(fn (?Caja $record): bool => filled($record?->display_token))
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                ToggleColumn::make('activo')
                    ->label('Activa')
                    ->disabled(fn (): bool => ! auth()->user()?->can('cajas.editar'))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('activo')->label('Activa')->default(true),
            ])
            ->recordActions([
                Action::make('abrirDisplay')
                    ->label('Display')
                    ->icon('heroicon-o-tv')
                    ->color('gray')
                    ->url(fn (Caja $record): string => $record->urlDisplay(), shouldOpenInNewTab: true)
                    ->visible(fn (Caja $record): bool => $record->activo),

                Action::make('regenerarToken')
                    ->label('Regenerar URL del display')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('La URL actual del display dejará de funcionar de inmediato; tendrás que abrir la nueva en la pantalla del cliente.')
                    ->visible(fn (): bool => auth()->user()?->can('cajas.editar') ?? false)
                    ->action(function (Caja $record): void {
                        $record->regenerarDisplayToken();

                        Notification::make()->title('URL del display regenerada')->success()->send();
                    }),

                EditAction::make(),
            ])
            ->defaultSort('nombre');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCajas::route('/'),
            'create' => Pages\CreateCaja::route('/create'),
            'edit' => Pages\EditCaja::route('/{record}/edit'),
        ];
    }
}
