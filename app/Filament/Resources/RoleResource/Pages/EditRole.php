<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Support\Permisos;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    // El borrado de roles tiene su propia acción con protecciones (ver RoleResource): sin botón
    // de borrado nativo aquí, para no ofrecer dos caminos distintos hacia lo mismo.
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $permisosActuales = $this->record->permissions->pluck('name');

        $data['permisos'] = collect(Permisos::catalogo())
            ->mapWithKeys(fn (array $permisos, string $modulo) => [
                $modulo => $permisosActuales->intersect(array_keys($permisos))->values()->all(),
            ])
            ->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $nuevosPermisos = RoleResource::aplanarPermisos($data['permisos'] ?? []);

        if (
            $this->record->name === RoleResource::ROL_PROTEGIDO
            && ! in_array(RoleResource::PERMISO_GESTIONAR_ROLES, $nuevosPermisos, true)
        ) {
            Notification::make()
                ->title('El rol "'.RoleResource::ROL_PROTEGIDO.'" siempre debe conservar el permiso "Gestionar roles y permisos".')
                ->danger()
                ->send();

            throw new Halt;
        }

        if ($this->quitariaAccesoPropio($nuevosPermisos)) {
            Notification::make()
                ->title('No puedes quitarte a ti mismo el acceso a "Roles y permisos"')
                ->body('Pide a otro administrador que haga este cambio.')
                ->danger()
                ->send();

            throw new Halt;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $permisos = RoleResource::aplanarPermisos($data['permisos'] ?? []);
        unset($data['permisos']);

        $record->update($data);
        $record->syncPermissions($permisos);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $record;
    }

    /**
     * True si guardar $nuevosPermisos en el rol que se está editando dejaría al usuario actual
     * sin el permiso roles.gestionar (ni por este rol ni por ningún otro camino: otro rol, o un
     * permiso directo). Solo aplica si el usuario actual tiene asignado este mismo rol.
     *
     * @param  array<int, string>  $nuevosPermisos
     */
    private function quitariaAccesoPropio(array $nuevosPermisos): bool
    {
        $usuario = auth()->user();

        if ($usuario === null || ! $this->record->users->contains($usuario->getKey())) {
            return false;
        }

        if (in_array(RoleResource::PERMISO_GESTIONAR_ROLES, $nuevosPermisos, true)) {
            return false;
        }

        $tieneOtraFuente = $usuario->roles()
            ->where('roles.id', '!=', $this->record->getKey())
            ->whereHas('permissions', fn ($query) => $query->where('name', RoleResource::PERMISO_GESTIONAR_ROLES))
            ->exists()
            || $usuario->permissions()->where('name', RoleResource::PERMISO_GESTIONAR_ROLES)->exists();

        return ! $tieneOtraFuente;
    }
}
