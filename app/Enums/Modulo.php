<?php

namespace App\Enums;

/**
 * Catálogo de módulos funcionales que una empresa puede habilitar o deshabilitar (T4). Cada caso
 * corresponde a un Resource o Page real de Filament — no incluye Configuración/Empresa, Usuarios
 * ni Roles porque esos SIEMPRE deben estar disponibles (sin ellos la empresa se queda sin forma
 * de administrarse), así que nunca pasan por RestringidoPorModulo. Tampoco incluye Reportes (son
 * rutas planas fuera del panel de Filament, no un Resource/Page con canAccess()) ni un "ECF
 * monitoreo" separado (el estado fiscal de una venta se ve dentro de VENTAS_LISTADO, no en una
 * pantalla propia).
 */
enum Modulo: string
{
    case MAESTROS_CLIENTES = 'maestros_clientes';
    case MAESTROS_PROVEEDORES = 'maestros_proveedores';
    case MAESTROS_PRODUCTOS = 'maestros_productos';
    case MAESTROS_CATEGORIAS = 'maestros_categorias';

    case VENTAS_POS = 'ventas_pos';
    case VENTAS_LISTADO = 'ventas_listado';
    case VENTAS_ARQUEO_CAJA = 'ventas_arqueo_caja';

    case INVENTARIO_KARDEX = 'inventario_kardex';

    case COMPRAS = 'compras';
    case COMPRAS_PEDIDOS = 'compras_pedidos';
    case COMPRAS_STOCK_BAJO = 'compras_stock_bajo';
    case DEVOLUCIONES = 'devoluciones';

    case ECF_SECUENCIAS = 'ecf_secuencias';
    case ECF_RECIBIDOS = 'ecf_recibidos';

    case IMPRESORAS = 'impresoras';
    case AUDITORIA = 'auditoria';

    public function etiqueta(): string
    {
        return match ($this) {
            self::MAESTROS_CLIENTES => 'Clientes',
            self::MAESTROS_PROVEEDORES => 'Proveedores',
            self::MAESTROS_PRODUCTOS => 'Productos',
            self::MAESTROS_CATEGORIAS => 'Categorías',
            self::VENTAS_POS => 'Punto de Venta',
            self::VENTAS_LISTADO => 'Ventas',
            self::VENTAS_ARQUEO_CAJA => 'Arqueos de Caja',
            self::INVENTARIO_KARDEX => 'Kardex',
            self::COMPRAS => 'Compras',
            self::COMPRAS_PEDIDOS => 'Pedidos de Compra',
            self::COMPRAS_STOCK_BAJO => 'Stock Bajo',
            self::DEVOLUCIONES => 'Devoluciones a Proveedor',
            self::ECF_SECUENCIAS => 'Secuencias NCF',
            self::ECF_RECIBIDOS => 'e-CF Recibidos',
            self::IMPRESORAS => 'Impresoras',
            self::AUDITORIA => 'Auditoría',
        };
    }

    /** Agrupa los módulos en la UI de gestión (PASO 4) y no depende de $navigationGroup de Filament. */
    public function grupo(): string
    {
        return match ($this) {
            self::MAESTROS_CLIENTES, self::MAESTROS_PROVEEDORES,
            self::MAESTROS_PRODUCTOS, self::MAESTROS_CATEGORIAS => 'Maestros',

            self::VENTAS_POS, self::VENTAS_LISTADO, self::VENTAS_ARQUEO_CAJA => 'Ventas',

            self::INVENTARIO_KARDEX => 'Inventario',

            self::COMPRAS, self::COMPRAS_PEDIDOS,
            self::COMPRAS_STOCK_BAJO, self::DEVOLUCIONES => 'Compras',

            self::ECF_SECUENCIAS, self::ECF_RECIBIDOS => 'e-CF',

            self::IMPRESORAS, self::AUDITORIA => 'Configuración',
        };
    }

    /**
     * Ningún caso de este enum es bloqueado: Configuración/Empresa, Usuarios y Roles —los únicos
     * módulos que nunca pueden apagarse— directamente no están representados aquí (ver la nota de
     * clase). El método existe para que RestringidoPorModulo tenga un único punto de verdad si
     * algún día se agrega aquí un módulo que también deba quedar siempre activo.
     */
    public function esBloqueado(): bool
    {
        return false;
    }

    /**
     * Módulos mínimos sin los que este no tiene sentido: no se puede vender sin productos, ni
     * devolver algo a un proveedor sin el módulo de Compras (las devoluciones de este sistema son
     * siempre CONTRA una compra registrada, ver DevolucionCompra::compra()).
     *
     * @return array<Modulo>
     */
    public function dependeDe(): array
    {
        return match ($this) {
            self::VENTAS_POS => [self::MAESTROS_PRODUCTOS],
            self::DEVOLUCIONES => [self::COMPRAS],
            default => [],
        };
    }

    /**
     * Los módulos e-CF no son solo un toggle de empresa_modulos: además exigen usa_ecf=true (T3).
     * Empresa::tieneModulo() consulta esto para que, con usa_ecf=false, queden ocultos sin
     * importar lo que diga su fila en empresa_modulos.
     */
    public function requiereEcf(): bool
    {
        return match ($this) {
            self::ECF_SECUENCIAS, self::ECF_RECIBIDOS => true,
            default => false,
        };
    }
}
