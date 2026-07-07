<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('facturacion.aplica_itbis', true);
        $this->migrator->add('facturacion.precio_incluye_itbis', false);
        $this->migrator->add('facturacion.tasa_itbis_defecto', '18');
        $this->migrator->add('facturacion.tipo_comprobante_defecto', '32');
        $this->migrator->add('facturacion.moneda', 'DOP');
    }
};
