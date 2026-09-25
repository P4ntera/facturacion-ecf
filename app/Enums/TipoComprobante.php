<?php

namespace App\Enums;

use App\Models\EmpresaConfiguracion;

/**
 * Cubre DOS catálogos DGII distintos, no un mismo código con letra intercambiable: el NCF físico
 * (serie B — B01, B02, B11...) y el e-CF electrónico (serie E — códigos 31, 32, 41... sin la
 * letra en el propio código DGII) son numeraciones independientes que casualmente representan el
 * mismo tipo de documento de negocio (p. ej. B01 y "31" son ambos "Factura de Crédito Fiscal").
 * Por eso los casos legacy (31, 32...) guardan el valor numérico desnudo —así lo espera el PAC en
 * IdDoc.TipoeCF, ver EcfBuilder— mientras que los físicos guardan el código completo con su "B":
 * nunca colisionan entre sí y esElectronico() puede distinguirlos con un simple prefijo.
 */
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

    // NCF físico (no electrónico, no pasa por el PAC): mismo documento de negocio que su
    // contraparte electrónica de arriba, código DGII distinto.
    case FACTURA_CREDITO_FISCAL_FISICA = 'B01';
    case FACTURA_CONSUMO_FISICA        = 'B02';
    case REGIMENES_ESPECIALES_FISICA   = 'B14';
    case GUBERNAMENTAL_FISICA          = 'B15';
    case EXPORTACIONES_FISICA          = 'B16';
    case PAGOS_EXTERIOR_FISICA         = 'B17';

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
            self::FACTURA_CREDITO_FISCAL_FISICA => 'Factura de Crédito Fiscal (física)',
            self::FACTURA_CONSUMO_FISICA        => 'Factura de Consumo (física)',
            self::REGIMENES_ESPECIALES_FISICA   => 'Regímenes Especiales de Tributación (física)',
            self::GUBERNAMENTAL_FISICA          => 'Gubernamental (física)',
            self::EXPORTACIONES_FISICA          => 'Exportaciones (física)',
            self::PAGOS_EXTERIOR_FISICA         => 'Pagos al Exterior (física)',
        };
    }

    public function esConsumo(): bool
    {
        return $this === self::FACTURA_CONSUMO || $this === self::FACTURA_CONSUMO_FISICA;
    }

    /**
     * true si el tipo se emite en una VENTA (factura/nota al cliente); false si es un
     * comprobante que la empresa registra al COMPRAR (recibido del proveedor, o autogenerado
     * para uno informal). VentaService::registrar() lo usa para rechazar que una venta consuma
     * la secuencia NCF de un tipo que le pertenece a Compras.
     */
    public function esDeVenta(): bool
    {
        return match ($this) {
            self::FACTURA_CREDITO_FISCAL, self::FACTURA_CONSUMO, self::NOTA_DEBITO, self::NOTA_CREDITO,
            self::REGIMENES_ESPECIALES, self::GUBERNAMENTAL, self::EXPORTACIONES,
            self::FACTURA_CREDITO_FISCAL_FISICA, self::FACTURA_CONSUMO_FISICA,
            self::REGIMENES_ESPECIALES_FISICA, self::GUBERNAMENTAL_FISICA, self::EXPORTACIONES_FISICA => true,
            self::COMPRAS, self::GASTOS_MENORES, self::PAGOS_EXTERIOR, self::PAGOS_EXTERIOR_FISICA => false,
        };
    }

    /**
     * true si este tipo pasa por el PAC/DGII (e-CF real); false si es NCF físico (no electrónico,
     * nunca se transmite). Todos los casos legacy de este enum guardan su código DGII desnudo
     * ('31', '41'...); todos los físicos lo guardan con el prefijo 'B' — de ahí que sea
     * suficiente mirar el primer carácter, sin necesidad de listar cada caso a mano.
     */
    public function esElectronico(): bool
    {
        return ! str_starts_with($this->value, 'B');
    }

    public function esFisico(): bool
    {
        return ! $this->esElectronico();
    }

    /**
     * Resuelve el tipo de comprobante por defecto de una empresa: si el configurado
     * (EmpresaConfiguracion::tipo_comprobante_defecto) es electrónico pero la empresa no tiene
     * e-CF habilitado, usa Factura de Consumo física (B02) en su lugar. Evita que una empresa que
     * nunca actualizó su configuración tras habilitarse el soporte de comprobantes físicos quede
     * bloqueada (VentaInvalidaException) por un default que ya no le corresponde. Compartido por
     * VentaService::registrar() y PuntoDeVenta::mount() para no duplicar la regla.
     */
    public static function defectoParaEmpresa(EmpresaConfiguracion $config, bool $usaEcf): self
    {
        $configurado = self::from($config->tipo_comprobante_defecto);

        return ($configurado->esElectronico() && ! $usaEcf) ? self::FACTURA_CONSUMO_FISICA : $configurado;
    }
}
