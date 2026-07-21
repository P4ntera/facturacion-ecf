<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoArqueoCaja;
use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Models\ArqueoCaja;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ArqueoCajaService
{
    /** Abre un turno de caja nuevo para el usuario. Un usuario solo puede tener uno abierto a la vez. */
    public function abrir(string $fondoInicial, int $userId): ArqueoCaja
    {
        if ($this->arqueoAbiertoDe($userId) !== null) {
            throw new RuntimeException('Ya tienes un arqueo de caja abierto.');
        }

        return DB::transaction(fn () => ArqueoCaja::create([
            'user_id' => $userId,
            'fondo_inicial' => $this->aMoneda($fondoInicial),
            'abierto_en' => now(),
            'estado' => EstadoArqueoCaja::ABIERTO,
        ]));
    }

    /**
     * Cierra el turno: calcula (y guarda, como snapshot) las ventas del turno agrupadas por
     * forma de pago, excluyendo anuladas, y compara el efectivo esperado (fondo inicial + ventas
     * en efectivo) contra lo contado físicamente. Solo quien abrió el turno puede cerrarlo.
     */
    public function cerrar(ArqueoCaja $arqueo, string $efectivoContado, ?string $notas, int $userId): ArqueoCaja
    {
        if ($arqueo->estaCerrado()) {
            throw new RuntimeException('Este arqueo ya fue cerrado.');
        }

        if ($arqueo->user_id !== $userId) {
            throw new RuntimeException('Solo quien abrió la caja puede cerrarla.');
        }

        return DB::transaction(function () use ($arqueo, $efectivoContado, $notas) {
            $sumasPorFormaPago = $arqueo->ventas()
                ->where('estado', '!=', EstadoVenta::ANULADA)
                ->selectRaw('forma_pago, COALESCE(SUM(total), 0) as total')
                ->groupBy('forma_pago')
                ->pluck('total', 'forma_pago');

            $totalEfectivo = $this->aMoneda($sumasPorFormaPago[FormaPago::EFECTIVO->value] ?? '0');
            $totalTarjeta = $this->aMoneda($sumasPorFormaPago[FormaPago::TARJETA->value] ?? '0');
            $totalTransferencia = $this->aMoneda($sumasPorFormaPago[FormaPago::TRANSFERENCIA->value] ?? '0');

            $efectivoContadoNormalizado = $this->aMoneda($efectivoContado);
            $efectivoEsperado = bcadd((string) $arqueo->fondo_inicial, $totalEfectivo, 2);
            $diferencia = bcsub($efectivoContadoNormalizado, $efectivoEsperado, 2);

            $arqueo->update([
                'total_ventas_efectivo' => $totalEfectivo,
                'total_ventas_tarjeta' => $totalTarjeta,
                'total_ventas_transferencia' => $totalTransferencia,
                'efectivo_esperado' => $efectivoEsperado,
                'efectivo_contado' => $efectivoContadoNormalizado,
                'diferencia' => $diferencia,
                'notas' => $notas,
                'cerrado_en' => now(),
                'estado' => EstadoArqueoCaja::CERRADO,
            ]);

            return $arqueo->refresh();
        });
    }

    public function arqueoAbiertoDe(int $userId): ?ArqueoCaja
    {
        return ArqueoCaja::query()
            ->where('user_id', $userId)
            ->where('estado', EstadoArqueoCaja::ABIERTO)
            ->first();
    }

    /** Normaliza un valor de dinero (string|int|float) a una cadena con escala 2, vía bcmath. */
    private function aMoneda(string|int|float $valor): string
    {
        return bcadd((string) $valor, '0', 2);
    }
}
