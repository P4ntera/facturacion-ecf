<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('empresa.rnc', '');
        $this->migrator->add('empresa.razon_social', '');
        $this->migrator->add('empresa.nombre_comercial', '');
        $this->migrator->add('empresa.direccion', null);
        $this->migrator->add('empresa.telefono', null);
        $this->migrator->add('empresa.email', null);
        $this->migrator->add('empresa.logo', null);
    }
};
