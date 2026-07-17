<?php

namespace App\Filament\Resources;

use App\Enums\TipoProveedor;
use App\Filament\Resources\ProveedorResource\Pages;
use App\Filament\Resources\ProveedorResource\RelationManagers;
use App\Models\Proveedor;
use App\Services\DgiiRncService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProveedorResource extends Resource
{
    protected static ?string $model = Proveedor::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Proveedores';

    protected static ?string $modelLabel = 'Proveedor';

    protected static ?string $pluralModelLabel = 'Proveedores';

    protected static ?string $slug = 'proveedores';

    protected static string|\UnitEnum|null $navigationGroup = 'Maestros';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([

            Section::make('Identificación Fiscal')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextInput::make('rnc')
                        ->label('RNC / Cédula')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(11)
                        ->minLength(9)
                        ->numeric()
                        ->helperText('9 dígitos para RNC, 11 para Cédula. Sin guiones.')
                        ->suffixAction(
                            Action::make('buscar_dgii')
                                ->label('Buscar en DGII')
                                ->icon('heroicon-o-magnifying-glass')
                                ->action(function ($get, $set) {
                                    $rnc = preg_replace('/[^0-9]/', '', $get('rnc') ?? '');

                                    if (! $rnc) {
                                        Notification::make()->title('Ingresa un RNC o Cédula primero.')->warning()->send();

                                        return;
                                    }

                                    $service = app(DgiiRncService::class);

                                    if (! $service->esRncValido($rnc)) {
                                        Notification::make()->title('RNC inválido')->body('Debe tener 9 dígitos (RNC) u 11 dígitos (Cédula).')->danger()->send();

                                        return;
                                    }

                                    $datos = $service->consultarRnc($rnc);

                                    if (! $datos) {
                                        Notification::make()->title('No encontrado')->body('El RNC/Cédula no está registrado en la DGII.')->warning()->send();

                                        return;
                                    }

                                    $set('rnc', $datos['rnc']);
                                    $set('nombre', $datos['nombre']);
                                    $set('nombre_comercial', $datos['nombre_comercial']);
                                    $set('actividad_economica', $datos['actividad_economica']);
                                    $set('estado', $datos['estado']);

                                    Notification::make()->title('¡Datos cargados!')->body('Información obtenida de la DGII correctamente.')->success()->send();
                                })
                        ),

                    Select::make('estado')
                        ->label('Estado DGII')
                        ->options([
                            'ACTIVO' => 'Activo',
                            'INACTIVO' => 'Inactivo',
                            'SUSPENDIDO' => 'Suspendido',
                            'DADO DE BAJA' => 'Dado de Baja',
                        ])
                        ->default('ACTIVO')
                        ->required(),

                    Select::make('tipo')
                        ->label('Tipo de proveedor')
                        ->options([
                            TipoProveedor::FORMAL->value   => TipoProveedor::FORMAL->etiqueta(),
                            TipoProveedor::INFORMAL->value => TipoProveedor::INFORMAL->etiqueta(),
                        ])
                        ->default(TipoProveedor::FORMAL->value)
                        ->required()
                        ->helperText('Informal: no emite comprobante fiscal propio; el sistema le genera uno (tipo 41) al comprarle.')
                        ->columnSpanFull(),
                ]),

            Section::make('Información General')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextInput::make('nombre')
                        ->label('Nombre / Razón Social')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    TextInput::make('nombre_comercial')
                        ->label('Nombre Comercial')
                        ->maxLength(255),

                    TextInput::make('actividad_economica')
                        ->label('Actividad Económica')
                        ->maxLength(255),
                ]),

            Section::make('Contacto')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextInput::make('telefono')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(20),

                    TextInput::make('email')
                        ->label('Correo Electrónico')
                        ->email()
                        ->maxLength(255),

                    Textarea::make('direccion')
                        ->label('Dirección')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Estado del Registro')
                ->columnSpanFull()
                ->schema([
                    Toggle::make('activo')
                        ->label('Activo en el sistema')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rnc')
                    ->label('RNC / Cédula')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('nombre')
                    ->label('Nombre / Razón Social')
                    ->searchable()
                    ->sortable()
                    ->limit(40),

                TextColumn::make('nombre_comercial')
                    ->label('Nombre Comercial')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('estado')
                    ->label('Estado DGII')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'ACTIVO' => 'success',
                        'INACTIVO' => 'gray',
                        'SUSPENDIDO' => 'danger',
                        'DADO DE BAJA' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (TipoProveedor $state) => match ($state) {
                        TipoProveedor::FORMAL   => 'Formal',
                        TipoProveedor::INFORMAL => 'Informal',
                    })
                    ->color(fn (TipoProveedor $state) => $state === TipoProveedor::INFORMAL ? 'warning' : 'gray'),

                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('email')
                    ->label('Correo')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                ToggleColumn::make('activo')
                    ->label('Activo')
                    ->disabled(fn (): bool => ! auth()->user()?->can('gestionar_maestros'))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado DGII')
                    ->options([
                        'ACTIVO' => 'Activo',
                        'INACTIVO' => 'Inactivo',
                        'SUSPENDIDO' => 'Suspendido',
                        'DADO DE BAJA' => 'Dado de Baja',
                    ]),

                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options([
                        TipoProveedor::FORMAL->value   => 'Formal',
                        TipoProveedor::INFORMAL->value => 'Informal',
                    ]),

                TernaryFilter::make('activo')->label('Activo en sistema')->default(true),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('nombre');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ProductosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProveedores::route('/'),
            'create' => Pages\CreateProveedor::route('/create'),
            'edit' => Pages\EditProveedor::route('/{record}/edit'),
        ];
    }
}
