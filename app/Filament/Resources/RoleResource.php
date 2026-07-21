<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Models\Role;
use App\Support\Permisos;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\PermissionRegistrar;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    // Los roles (spatie/permission) son globales, no datos de una empresa: no tienen relación
    // "empresa" y no deben scopearse por tenant (Filament los escanearía y fallaría al no
    // encontrarla).
    protected static bool $isScopedToTenant = false;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Roles';

    protected static ?string $modelLabel = 'Rol';

    protected static ?string $pluralModelLabel = 'Roles';

    protected static ?string $slug = 'roles';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    /**
     * Nombre del rol que ningún usuario puede dejar sin el permiso 'roles.gestionar' ni
     * eliminar: es la única garantía de que siempre quede alguien con acceso a esta pantalla.
     */
    public const ROL_PROTEGIDO = 'Administrador';

    public const PERMISO_GESTIONAR_ROLES = 'roles.gestionar';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre del rol')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),

            ...collect(Permisos::catalogo())
                ->map(fn (array $permisos, string $modulo) => Section::make($modulo)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        CheckboxList::make("permisos.{$modulo}")
                            ->hiddenLabel()
                            ->options($permisos)
                            ->bulkToggleable()
                            ->columns(2)
                            ->disableOptionWhen(fn (string $value, ?Role $record): bool => $value === self::PERMISO_GESTIONAR_ROLES
                                && $record?->name === self::ROL_PROTEGIDO),
                    ]))
                ->values()
                ->all(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Rol')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('permisos_count')
                    ->label('Permisos')
                    ->getStateUsing(fn (Role $record) => $record->permissions()->count()),

                TextColumn::make('usuarios_count')
                    ->label('Usuarios')
                    ->getStateUsing(fn (Role $record) => $record->users()->count()),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('eliminar')
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Role $record) => static::eliminarConProtecciones($record)),
            ])
            ->defaultSort('name');
    }

    /**
     * Protecciones antes de borrar (además del gate roles.gestionar en RolePolicy): el rol
     * Administrador nunca se borra —se quedaría el sistema sin nadie con acceso total—, y un
     * rol con usuarios asignados tampoco —se quedarían sin rol de un momento a otro—.
     */
    protected static function eliminarConProtecciones(Role $record): void
    {
        if ($record->name === self::ROL_PROTEGIDO) {
            Notification::make()
                ->title('El rol "'.self::ROL_PROTEGIDO.'" no se puede eliminar.')
                ->danger()
                ->send();

            return;
        }

        $cantidadUsuarios = $record->users()->count();

        if ($cantidadUsuarios > 0) {
            Notification::make()
                ->title('Este rol tiene usuarios asignados y no se puede eliminar')
                ->body("Reasigna a los {$cantidadUsuarios} usuario(s) a otro rol antes de eliminarlo.")
                ->danger()
                ->send();

            return;
        }

        $record->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Notification::make()->title('Rol eliminado')->success()->send();
    }

    /**
     * Aplana la matriz "permisos.{módulo}" del formulario (agrupada por sección) a la lista
     * plana de nombres de permiso que espera Role::syncPermissions().
     *
     * @param  array<string, array<int, string>>  $permisosPorModulo
     * @return array<int, string>
     */
    public static function aplanarPermisos(array $permisosPorModulo): array
    {
        return collect($permisosPorModulo)->flatten()->unique()->values()->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
