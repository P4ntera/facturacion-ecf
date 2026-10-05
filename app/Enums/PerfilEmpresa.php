<?php

declare(strict_types=1);

namespace App\Enums;

enum PerfilEmpresa: string
{
    case FACTURACION = 'facturacion';
    case RESTAURANTE = 'restaurante';
    case RETAIL = 'retail';

    public function etiqueta(): string
    {
        return match ($this) {
            self::FACTURACION => 'Facturación',
            self::RESTAURANTE => 'Restaurante',
            self::RETAIL => 'Retail',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::FACTURACION => 'Facturación general, compras, inventario y cuentas',
            self::RESTAURANTE => 'Mesas, comandas, cocina + facturación',
            self::RETAIL => 'Punto de venta con inventario avanzado',
        };
    }

    /** @return array<Modulo> */
    public function modulosPorDefecto(): array
    {
        $base = [
            Modulo::MAESTROS_CLIENTES,
            Modulo::MAESTROS_PROVEEDORES,
            Modulo::MAESTROS_PRODUCTOS,
            Modulo::MAESTROS_CATEGORIAS,
            Modulo::VENTAS_POS,
            Modulo::VENTAS_LISTADO,
            Modulo::VENTAS_CAJAS,
            Modulo::IMPRESORAS,
        ];

        return match ($this) {
            self::FACTURACION => [
                ...$base,
                Modulo::MAESTROS_DESCUENTOS,
                Modulo::MAESTROS_LISTAS_PRECIO,
                Modulo::COTIZACIONES,
                Modulo::VENTAS_ARQUEO_CAJA,
                Modulo::INVENTARIO_KARDEX,
                Modulo::COMPRAS,
                Modulo::COMPRAS_PEDIDOS,
                Modulo::COMPRAS_STOCK_BAJO,
                Modulo::DEVOLUCIONES,
                Modulo::CUENTAS_POR_COBRAR,
                Modulo::CUENTAS_POR_PAGAR,
                Modulo::ECF_SECUENCIAS,
                Modulo::AUDITORIA,
            ],
            self::RESTAURANTE => [
                ...$base,
                Modulo::VENTAS_ARQUEO_CAJA,
                Modulo::INVENTARIO_KARDEX,
                Modulo::COMPRAS,
                Modulo::COMPRAS_PEDIDOS,
                Modulo::CUENTAS_POR_COBRAR,
                Modulo::CUENTAS_POR_PAGAR,
                Modulo::ECF_SECUENCIAS,
                Modulo::RESTAURANTE_MESAS,
                Modulo::RESTAURANTE_COMANDAS,
                Modulo::RESTAURANTE_COCINA,
            ],
            self::RETAIL => $base,
        };
    }
}
