<?php

namespace App\Enums;

enum TipoComprobante: string
{
    case FACTURA_CREDITO_FISCAL = '31';
    case FACTURA_CONSUMO        = '32';
    case NOTA_DEBITO            = '33';
    case NOTA_CREDITO           = '34';
    case COMPRAS                = '41';
    case GASTOS_MENORES         = '43';
    case REGIMENES_ESPECIALES   = '44';
    case GUBERNAMENTAL          = '45';
    case EXPORTACIONES          = '46';
    case PAGOS_EXTERIOR         = '47';

    public function etiqueta(): string
    {
        return match ($this) {
            self::FACTURA_CREDITO_FISCAL => 'Factura de Crédito Fiscal',
            self::FACTURA_CONSUMO        => 'Factura de Consumo',
            self::NOTA_DEBITO            => 'Nota de Débito',
            self::NOTA_CREDITO           => 'Nota de Crédito',
            self::COMPRAS                => 'Compras',
            self::GASTOS_MENORES         => 'Gastos Menores',
            self::REGIMENES_ESPECIALES   => 'Regímenes Especiales de Tributación',
            self::GUBERNAMENTAL          => 'Gubernamental',
            self::EXPORTACIONES          => 'Exportaciones',
            self::PAGOS_EXTERIOR         => 'Pagos al Exterior',
        };
    }

    public function esConsumo(): bool
    {
        return $this === self::FACTURA_CONSUMO;
    }
}
