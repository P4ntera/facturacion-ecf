<?php

namespace App\Services;

use App\Enums\EstadoPago;
use App\Exceptions\PagoExcedeSaldoException;
use App\Models\CuentaPorCobrar;
use App\Models\PagoRecibido;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CuentaPorCobrarService
{
    /**
     * Crea la CxC de una venta a crédito. Se invoca DENTRO de la transacción de
     * VentaService::registrar(); no abre su propia transacción.
     */
    public function crearDesdeVenta(Venta $venta): CuentaPorCobrar
    {
        return CuentaPorCobrar::create([
            'empresa_id' => $venta->empresa_id,
            'venta_id' => $venta->id,
            'cliente_id' => $venta->cliente_id,
            'monto_total' => $venta->total,
            'monto_pagado' => 0,
            'fecha_emision' => $venta->fecha,
            'fecha_vencimiento' => $venta->fecha_limite_pago,
        ]);
    }

    /**
     * Registra un abono. Permite pagos parciales; no permite exceder el monto pendiente
     * (evita saldos a favor, que este sistema no maneja todavía).
     */
    public function registrarPago(CuentaPorCobrar $cuenta, array $datos, int $userId): PagoRecibido
    {
        $monto = $datos['monto'] ?? '0';

        if (bccomp($monto, '0', 2) <= 0) {
            throw new RuntimeException('El monto del pago debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($cuenta, $datos, $monto, $userId) {
            // Bloquear la cuenta dentro de la transacción para evitar pagos concurrentes
            $cuenta = CuentaPorCobrar::lockForUpdate()->findOrFail($cuenta->id);

            $pendiente = bcsub((string) $cuenta->monto_total, (string) $cuenta->monto_pagado, 2);

            if (bccomp($monto, $pendiente, 2) > 0) {
                throw new PagoExcedeSaldoException(
                    "El pago (RD$" . number_format((float) $monto, 2) . ') supera el monto pendiente (RD$'
                    . number_format((float) $pendiente, 2) . ').'
                );
            }

            $pago = PagoRecibido::create([
                'empresa_id' => $cuenta->empresa_id,
                'cuenta_por_cobrar_id' => $cuenta->id,
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

    public function anularPago(PagoRecibido $pago, string $motivo): PagoRecibido
    {
        if ($pago->estaAnulado()) {
            throw new RuntimeException('Este pago ya está anulado.');
        }

        return DB::transaction(function () use ($pago, $motivo) {
            $cuenta = CuentaPorCobrar::lockForUpdate()->findOrFail($pago->cuenta_por_cobrar_id);

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
}
