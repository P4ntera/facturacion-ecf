<?php

namespace App\Services;

use App\Enums\EstadoPago;
use App\Exceptions\PagoExcedeSaldoException;
use App\Models\Compra;
use App\Models\CuentaPorPagar;
use App\Models\PagoRealizado;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CuentaPorPagarService
{
    /**
     * Crea la CxP de una compra a crédito. Se invoca DENTRO de la transacción de
     * CompraService::crear(); no abre su propia transacción.
     */
    public function crearDesdeCompra(Compra $compra): CuentaPorPagar
    {
        return CuentaPorPagar::create([
            'empresa_id' => $compra->empresa_id,
            'compra_id' => $compra->id,
            'proveedor_id' => $compra->proveedor_id,
            'monto_total' => $compra->total,
            'monto_pagado' => 0,
            'fecha_emision' => $compra->fecha,
            'fecha_vencimiento' => $compra->fecha_vencimiento,
        ]);
    }

    /**
     * Registra un pago a proveedor. Permite pagos parciales; no permite exceder el monto
     * pendiente.
     */
    public function registrarPago(CuentaPorPagar $cuenta, array $datos, int $userId): PagoRealizado
    {
        $monto = (float) ($datos['monto'] ?? 0);

        if ($monto <= 0) {
            throw new RuntimeException('El monto del pago debe ser mayor a cero.');
        }

        if ($monto > $cuenta->montoPendiente()) {
            throw new PagoExcedeSaldoException(
                "El pago (RD$" . number_format($monto, 2) . ') supera el monto pendiente (RD$'
                . number_format($cuenta->montoPendiente(), 2) . ').'
            );
        }

        return DB::transaction(function () use ($cuenta, $datos, $monto, $userId) {
            $pago = PagoRealizado::create([
                'empresa_id' => $cuenta->empresa_id,
                'cuenta_por_pagar_id' => $cuenta->id,
                'monto' => $monto,
                'fecha' => $datos['fecha'],
                'forma_pago' => $datos['forma_pago'],
                'referencia' => $datos['referencia'] ?? null,
                'user_id' => $userId,
                'estado' => EstadoPago::REGISTRADO,
            ]);

            $cuenta->update(['monto_pagado' => (float) $cuenta->monto_pagado + $monto]);

            return $pago;
        });
    }

    public function anularPago(PagoRealizado $pago, string $motivo): PagoRealizado
    {
        if ($pago->estaAnulado()) {
            throw new RuntimeException('Este pago ya está anulado.');
        }

        return DB::transaction(function () use ($pago, $motivo) {
            $cuenta = $pago->cuentaPorPagar;
            $cuenta->update(['monto_pagado' => (float) $cuenta->monto_pagado - (float) $pago->monto]);

            $pago->update([
                'estado' => EstadoPago::ANULADO,
                'motivo_anulacion' => $motivo,
                'anulado_en' => now(),
            ]);

            return $pago->refresh();
        });
    }

    /**
     * Una devolución a proveedor reduce lo que hay que pagarle. No permite bajar el total por
     * debajo de lo que ya se le pagó (ese caso necesitaría reembolso, fuera de alcance aquí).
     */
    public function reducirPorDevolucion(CuentaPorPagar $cuenta, float $monto): void
    {
        $nuevoTotal = round((float) $cuenta->monto_total - $monto, 2);

        if ($nuevoTotal < (float) $cuenta->monto_pagado) {
            throw new RuntimeException(
                'No se puede reducir la cuenta por pagar por debajo de lo ya pagado; requiere reembolso manual.'
            );
        }

        $cuenta->update(['monto_total' => $nuevoTotal]);
    }

    /** Revierte reducirPorDevolucion() cuando se anula la devolución que la originó. */
    public function restaurarPorAnulacionDevolucion(CuentaPorPagar $cuenta, float $monto): void
    {
        $cuenta->update(['monto_total' => round((float) $cuenta->monto_total + $monto, 2)]);
    }
}
