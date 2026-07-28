<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Models\Role;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['guard_name'] = 'web';

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $permisos = RoleResource::aplanarPermisos($data['permisos'] ?? []);
        unset($data['permisos']);

        /** @var Role $record */
        $record = parent::handleRecordCreation($data);

        $record->syncPermissions($permisos);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $record;
    }
}
