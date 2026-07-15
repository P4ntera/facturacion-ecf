<?php

declare(strict_types=1);

namespace App\Services\Impresion;

use App\Enums\AnchoPapel;
use App\Enums\ModuloImpresion;
use App\Models\Impresora;
use App\Models\User;
use App\Models\Venta;
use App\Settings\EmpresaSettings;
use Illuminate\Support\Str;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\Printer;
use Throwable;

class ImpresionService
{
    /**
     * Cascada de resolución: impresora del usuario (si tiene y está activa) -> predeterminada
     * del módulo -> null. Un null lo resuelve el llamador cayendo al modo navegador y avisando
     * (ver imprimirTicket(), que ya hace exactamente eso).
     */
    public function resolverImpresora(ModuloImpresion $modulo, ?User $usuario): ?Impresora
    {
        if ($modulo === ModuloImpresion::FACTURACION && $usuario !== null) {
            $impresora = $usuario->impresoraFacturacion;

            if ($impresora !== null && $impresora->activa) {
                return $impresora;
            }
        }

        return Impresora::query()
            ->activas()
            ->porModulo($modulo)
            ->where('predeterminada', true)
            ->first();
    }

    /**
     * RED: manda los bytes ESC/POS directo al socket, sin diálogo — un fallo de conexión NUNCA
     * revierte ni afecta la venta (ya está registrada), solo se reporta para reintentar o caer a
     * navegador. NAVEGADOR (o sin impresora resuelta): no hay nada que el servidor pueda mandar
     * —el navegador decide la impresora física—, así que se devuelve la URL del ticket para que
     * el llamador la abra y dispare el diálogo de impresión.
     *
     * @return array{modo: 'red'|'navegador', exito: bool, error: ?string, url: ?string}
     */
    public function imprimirTicket(Venta $venta, ?Impresora $impresora): array
    {
        if ($impresora !== null && $impresora->esDeRed()) {
            return $this->imprimirPorRed($venta, $impresora);
        }

        return [
            'modo' => 'navegador',
            'exito' => true,
            'error' => null,
            'url' => $this->urlTicket($venta, $impresora?->ancho_papel ?? AnchoPapel::MM80),
        ];
    }

    public function urlTicket(Venta $venta, AnchoPapel $anchoPapel): string
    {
        return route('ventas.ticket', ['venta' => $venta, 'ancho' => $anchoPapel->value]);
    }

    /**
     * @return array{modo: 'red', exito: bool, error: ?string, url: ?string}
     */
    private function imprimirPorRed(Venta $venta, Impresora $impresora): array
    {
        try {
            $conector = new NetworkPrintConnector($impresora->ip, $impresora->puerto ?? 9100, 5);
            $printer = new Printer($conector);
            $this->escribirTicket($printer, $venta, $impresora->ancho_papel);
            $printer->cut();
            $printer->close();
        } catch (Throwable $e) {
            return [
                'modo' => 'red',
                'exito' => false,
                'error' => "No se pudo imprimir en \"{$impresora->nombre}\" ({$impresora->ip}:{$impresora->puerto}): {$e->getMessage()}",
                'url' => $this->urlTicket($venta, $impresora->ancho_papel),
            ];
        }

        return ['modo' => 'red', 'exito' => true, 'error' => null, 'url' => null];
    }

    private function escribirTicket(Printer $printer, Venta $venta, AnchoPapel $anchoPapel): void
    {
        $empresa = app(EmpresaSettings::class);
        $columnas = $anchoPapel->columnas();

        $printer->setJustification(Printer::JUSTIFY_CENTER);

        if ($venta->estaAnulada()) {
            $printer->setEmphasis(true);
            $printer->text("*** COMPROBANTE ANULADO ***\n");
            $printer->setEmphasis(false);
        }

        $printer->setEmphasis(true);
        $printer->text(($empresa->nombre_comercial ?: $empresa->razon_social)."\n");
        $printer->setEmphasis(false);

        if ($empresa->nombre_comercial && $empresa->nombre_comercial !== $empresa->razon_social) {
            $printer->text("{$empresa->razon_social}\n");
        }

        $printer->text("RNC: {$empresa->rnc}\n");

        if ($empresa->direccion) {
            $printer->text(wordwrap($empresa->direccion, $columnas, "\n", true)."\n");
        }

        if ($empresa->telefono) {
            $printer->text("Tel: {$empresa->telefono}\n");
        }

        $printer->text(str_repeat('-', $columnas)."\n");

        $printer->setEmphasis(true);
        $printer->text($venta->tipo_comprobante->etiqueta()."\n");

        if ($venta->ncf) {
            $printer->text("{$venta->ncf}\n");
        }
        $printer->setEmphasis(false);

        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text('Fecha: '.$venta->fecha->format('d/m/Y H:i')."\n");
        $printer->text('Cliente: '.Str::limit($venta->cliente->nombre, $columnas - 9)."\n");

        if ($venta->cliente->documento) {
            $printer->text("Doc: {$venta->cliente->documento}\n");
        }

        $printer->text(str_repeat('-', $columnas)."\n");

        foreach ($venta->detalles as $detalle) {
            $printer->text(wordwrap($detalle->descripcion, $columnas, "\n", true)."\n");

            $cantidad = rtrim(rtrim(number_format((float) $detalle->cantidad, 3, '.', ''), '0'), '.');
            $izquierda = "{$cantidad} x ".number_format((float) $detalle->precio_unitario, 2);
            $derecha = number_format((float) $detalle->subtotal, 2);
            $printer->text($this->lineaAlineada($izquierda, $derecha, $columnas)."\n");
        }

        $printer->text(str_repeat('-', $columnas)."\n");
        $printer->text($this->lineaAlineada('Subtotal', number_format((float) $venta->subtotal, 2), $columnas)."\n");
        $printer->text($this->lineaAlineada('ITBIS', number_format((float) $venta->total_itbis, 2), $columnas)."\n");
        $printer->text(str_repeat('-', $columnas)."\n");

        $printer->setEmphasis(true);
        $printer->text($this->lineaAlineada('TOTAL', "{$venta->moneda} ".number_format((float) $venta->total, 2), $columnas)."\n");
        $printer->setEmphasis(false);

        if ($venta->dgii_url !== null) {
            $printer->text(str_repeat('-', $columnas)."\n");
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->qrCode($venta->dgii_url, Printer::QR_ECLEVEL_L, 5);
            $printer->text("Codigo de seguridad:\n");
            $printer->setEmphasis(true);
            $printer->text("{$venta->codigo_seguridad}\n");
            $printer->setEmphasis(false);
        }

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text(str_repeat('-', $columnas)."\n");
        $printer->text("Gracias por su compra!\n");
        $printer->feed(2);
    }

    private function lineaAlineada(string $izquierda, string $derecha, int $columnas): string
    {
        $espacio = max(1, $columnas - strlen($izquierda) - strlen($derecha));

        return $izquierda.str_repeat(' ', $espacio).$derecha;
    }
}
