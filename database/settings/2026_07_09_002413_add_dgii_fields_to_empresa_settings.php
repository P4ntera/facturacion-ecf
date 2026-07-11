<?php

use App\Enums\AmbienteEcf;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('empresa.dgii_api_key', null, encrypted: true);
        $this->migrator->add('empresa.dgii_ambiente', AmbienteEcf::TESTECF->value);
        $this->migrator->add('empresa.dgii_base_url', 'https://sandbox.pac-ecf.example.do/api/v1');
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('empresa.dgii_api_key');
        $this->migrator->deleteIfExists('empresa.dgii_ambiente');
        $this->migrator->deleteIfExists('empresa.dgii_base_url');
    }
};
