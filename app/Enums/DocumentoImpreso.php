<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Empresa;

/**
 * Catálogo de documentos impresos/PDF cuyos textos configura cada empresa en
 * Configuración → Facturación → "Textos en documentos" (columna JSON
 * empresa_configuracion.textos_documentos). Única fuente de verdad: para sumar un documento
 * nuevo, agrega su caso aquí y lee el texto en su vista con
 * $empresa->config()->textoDocumento(DocumentoImpreso::X).
 *
 * Cada documento tiene al menos el campo 'pie'; algunos tienen campos extra (camposExtra()).
 */
enum DocumentoImpreso: string
{
    case VENTA_FACTURA = 'venta_factura';
    case VENTA_TICKET = 'venta_ticket';
    case COTIZACION = 'cotizacion';
    case ORDEN_COMPRA = 'orden_compra';
    case PEDIDO_COMPRA = 'pedido_compra';
    case LAVANDERIA_RECIBO = 'lavanderia_recibo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::VENTA_FACTURA => 'Factura de venta (PDF)',
            self::VENTA_TICKET => 'Ticket de venta (POS)',
            self::COTIZACION => 'Cotización (PDF)',
            self::ORDEN_COMPRA => 'Orden de compra (PDF)',
            self::PEDIDO_COMPRA => 'Pedido de compra (PDF)',
            self::LAVANDERIA_RECIBO => 'Recibo de lavandería',
        };
    }

    /** Módulo que debe estar activo para que el documento aparezca en la configuración. */
    public function modulo(): Modulo
    {
        return match ($this) {
            self::VENTA_FACTURA => Modulo::VENTAS_LISTADO,
            self::VENTA_TICKET => Modulo::VENTAS_POS,
            self::COTIZACION => Modulo::COTIZACIONES,
            self::ORDEN_COMPRA => Modulo::COMPRAS_PEDIDOS,
            self::PEDIDO_COMPRA => Modulo::COMPRAS,
            self::LAVANDERIA_RECIBO => Modulo::LAVANDERIA_TICKETS,
        };
    }

    public function disponiblePara(Empresa $empresa): bool
    {
        return $empresa->tieneModulo($this->modulo());
    }

    /**
     * Campos además de 'pie': clave => etiqueta.
     *
     * @return array<string, string>
     */
    public function camposExtra(): array
    {
        return match ($this) {
            self::COTIZACION => [
                'incluye' => 'Incluye',
                'no_incluye' => 'No incluye',
                'observaciones' => 'Observaciones por defecto (si la cotización no trae notas)',
            ],
            default => [],
        };
    }

    /** @return array<int, string> */
    public function campos(): array
    {
        return ['pie', ...array_keys($this->camposExtra())];
    }

    /**
     * Texto que sale mientras la empresa no haya configurado ese campo (los textos que antes
     * estaban fijos en las vistas). Si la empresa lo deja en blanco a propósito, no sale nada.
     */
    public function textoPorDefecto(string $campo = 'pie'): ?string
    {
        return match ([$this, $campo]) {
            [self::VENTA_TICKET, 'pie'] => '¡Gracias por su compra!',
            [self::LAVANDERIA_RECIBO, 'pie'] => '¡Gracias por preferirnos!',
            [self::COTIZACION, 'pie'] => 'Gracias por considerar nuestros servicios.',
            [self::COTIZACION, 'incluye'] => 'Instalación completa del sistema, capacitación y 30 días de soporte post-instalación por WhatsApp o asistencia remota para dudas y ajustes.',
            [self::COTIZACION, 'no_incluye'] => 'Mejoras, nuevas funcionalidades o modificaciones futuras no contempladas en el alcance inicial. Estos trabajos se cotizarán y cobrarán por separado.',
            [self::COTIZACION, 'observaciones'] => 'Los plazos de entrega, forma de pago y vigencia de esta cotización quedan sujetos a confirmación entre las partes.',
            default => null,
        };
    }

    public function ayudaPie(): string
    {
        return match ($this) {
            self::VENTA_TICKET, self::LAVANDERIA_RECIBO => 'Se imprime al final del ticket. Ej.: "Prendas no retiradas en 30 días no son responsabilidad del negocio."',
            default => 'Se imprime al final del documento (políticas, garantía, cuentas bancarias, etc.).',
        };
    }
}
