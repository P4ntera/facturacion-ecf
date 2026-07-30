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

            $cuenta->update(['monto_pagado' => (float) $cuenta->monto_pagado + $monto]);

            return $pago;
        });
    }

    public function anularPago(PagoRecibido $pago, string $motivo): PagoRecibido
    {
        if ($pago->estaAnulado()) {
            throw new RuntimeException('Este pago ya está anulado.');
        }

        return DB::transaction(function () use ($pago, $motivo) {
            $cuenta = $pago->cuentaPorCobrar;
            $cuenta->update(['monto_pagado' => (float) $cuenta->monto_pagado - (float) $pago->monto]);

            $pago->update([
                'estado' => EstadoPago::ANULADO,
                'motivo_anulacion' => $motivo,
                'anulado_en' => now(),
            ]);

            return $pago->refresh();
        });
    }
}
