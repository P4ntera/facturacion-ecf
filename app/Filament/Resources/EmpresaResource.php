<?php

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Filament\Resources\EmpresaResource\Pages;
use App\Models\Empresa;
use App\Models\User;
use App\Services\RolesEmpresaService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class EmpresaResource extends Resource
{
    protected static ?string $model = Empresa::class;

    // Empresa es el propio modelo de tenant (no pertenece a un tenant): esta pantalla la
    // administra el super-admin viendo TODAS las empresas, así que queda fuera del scoping
    // automático de Filament (que si no, filtraría la lista a una sola empresa).
    protected static bool $isScopedToTenant = false;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Empresas';

    protected static ?string $modelLabel = 'Empresa';

    protected static ?string $pluralModelLabel = 'Empresas';

    protected static ?string $slug = 'empresas';

    protected static string|\UnitEnum|null $navigationGroup = 'Super Admin';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('razon_social')
                    ->label('Razón social')
                    ->required()
                    ->maxLength(255),

                TextInput::make('nombre_comercial')
                    ->label('Nombre comercial')
                    ->maxLength(255),

                TextInput::make('rnc')
                    ->label('RNC')
                    ->required()
                    ->regex('/^\d{9}(\d{2})?$/')
                    ->validationMessages(['regex' => 'El RNC debe tener 9 u 11 dígitos.'])
                    ->maxLength(11),

                TextInput::make('slug')
                    ->label('Slug (URL)')
                    ->helperText('Se genera solo desde la razón social si lo dejas vacío.')
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                Toggle::make('usa_ecf')
                    ->label('Usa e-CF (facturación electrónica)')
                    ->default(true),

                Toggle::make('activa')
                    ->label('Activa')
                    ->helperText('Desactivarla bloquea el acceso al panel a todos sus usuarios.')
                    ->default(true),

                Section::make('Módulos')
                    ->description('Qué partes del sistema puede usar esta empresa. Desactivar un módulo solo lo oculta: sus datos existentes se conservan y reaparecen al reactivarlo.')
                    ->columnSpanFull()
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->components([
                        TextEntry::make('modulos_nota')
                            ->hiddenLabel()
                            ->state('Configuración de la Empresa, Usuarios y Roles siempre están disponibles y no aparecen aquí: sin ellos la empresa se queda sin forma de administrarse.')
                            ->columnSpanFull(),

                        ...collect(Modulo::cases())
                            ->groupBy(fn (Modulo $modulo) => $modulo->grupo())
                            ->map(fn ($modulos, string $grupo) => Section::make($grupo)
                                ->columns(2)
                                ->components(
                                    $modulos->map(fn (Modulo $modulo) => Toggle::make("modulos.{$modulo->value}")
                                        ->label($modulo->etiqueta())
                                        ->default(true))->all()
                                ))
                            ->values()
                            ->all(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('razon_social')
                    ->label('Razón social')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nombre_comercial')
                    ->label('Nombre comercial')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('rnc')
                    ->label('RNC')
                    ->searchable(),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->badge()
                    ->color('gray'),

                IconColumn::make('usa_ecf')
                    ->label('e-CF')
                    ->boolean(),

                IconColumn::make('activa')
                    ->label('Activa')
                    ->boolean(),

                TextColumn::make('users_count')
                    ->label('Usuarios')
                    ->counts('users')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('activa')->label('Activa')->default(true),
                TernaryFilter::make('usa_ecf')->label('Usa e-CF'),
            ])
            ->recordActions([
                Action::make('crearUsuarioAdmin')
                    ->label('Crear usuario admin')
                    ->icon('heroicon-o-user-plus')
                    ->color('gray')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('Correo electrónico')
                            ->email()
                            ->required()
                            ->unique('users', 'email')
                            ->maxLength(255),

                        TextInput::make('password')
                            ->label('Contraseña')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8),
                    ])
                    ->action(function (Empresa $record, array $data): void {
                        $usuario = User::create([
                            'empresa_id' => $record->id,
                            'name' => $data['name'],
                            'email' => $data['email'],
                            'password' => Hash::make($data['password']),
                            'email_verified_at' => now(),
                        ]);

                        // No basta con $usuario->assignRole('Administrador'): el contexto de
                        // permisos activo en este momento es el tenant que el super-admin tiene
                        // abierto en la URL (esta pantalla no está scopeada a un tenant), que
                        // puede no ser $record. El service fija el contexto de $record antes de
                        // asignar y lo restaura al terminar.
                        app(RolesEmpresaService::class)->asignarAdministrador($usuario, $record);

                        Notification::make()
                            ->title("Usuario administrador creado para {$record->razon_social}")
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('razon_social');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmpresas::route('/'),
            'create' => Pages\CreateEmpresa::route('/create'),
            'edit' => Pages\EditEmpresa::route('/{record}/edit'),
        ];
    }
}
