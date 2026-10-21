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
        $monto = $datos['monto'] ?? '0';

        if (bccomp($monto, '0', 2) <= 0) {
            throw new RuntimeException('El monto del pago debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($cuenta, $datos, $monto, $userId) {
            // Bloquear la cuenta dentro de la transacción para evitar pagos concurrentes
            $cuenta = CuentaPorPagar::lockForUpdate()->findOrFail($cuenta->id);

            $pendiente = bcsub((string) $cuenta->monto_total, (string) $cuenta->monto_pagado, 2);

            if (bccomp($monto, $pendiente, 2) > 0) {
                throw new PagoExcedeSaldoException(
                    "El pago (RD$" . number_format((float) $monto, 2) . ') supera el monto pendiente (RD$'
                    . number_format((float) $pendiente, 2) . ').'
                );
            }

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

            $cuenta->update([
                'monto_pagado' => bcadd((string) $cuenta->monto_pagado, $monto, 2),
            ]);

            return $pago;
        });
    }

    public function anularPago(PagoRealizado $pago, string $motivo): PagoRealizado
    {
        if ($pago->estaAnulado()) {
            throw new RuntimeException('Este pago ya está anulado.');
        }

        return DB::transaction(function () use ($pago, $motivo) {
            $cuenta = CuentaPorPagar::lockForUpdate()->findOrFail($pago->cuenta_por_pagar_id);

            $cuenta->update([
                'monto_pagado' => bcsub((string) $cuenta->monto_pagado, (string) $pago->monto, 2),
            ]);

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
    public function reducirPorDevolucion(CuentaPorPagar $cuenta, string $monto): void
    {
        $cuenta = CuentaPorPagar::lockForUpdate()->findOrFail($cuenta->id);

        $nuevoTotal = bcsub((string) $cuenta->monto_total, $monto, 2);

        if (bccomp($nuevoTotal, (string) $cuenta->monto_pagado, 2) < 0) {
            throw new RuntimeException(
                'No se puede reducir la cuenta por pagar por debajo de lo ya pagado; requiere reembolso manual.'
            );
        }

        $cuenta->update(['monto_total' => $nuevoTotal]);
    }

    /** Revierte reducirPorDevolucion() cuando se anula la devolución que la originó. */
    public function restaurarPorAnulacionDevolucion(CuentaPorPagar $cuenta, string $monto): void
    {
        $cuenta = CuentaPorPagar::lockForUpdate()->findOrFail($cuenta->id);
        $cuenta->update(['monto_total' => bcadd((string) $cuenta->monto_total, $monto, 2)]);
    }
}
