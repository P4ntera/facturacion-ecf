<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoArqueoCaja;
use App\Enums\EstadoVenta;
use App\Enums\FormaPago;
use App\Enums\FormaReembolso;
use App\Enums\TipoPago;
use App\Models\ArqueoCaja;
use App\Models\Caja;
use App\Models\Empresa;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ArqueoCajaService
{
    /**
     * Abre un turno de caja nuevo para el usuario. Un usuario solo puede tener uno abierto a la
     * vez. $empresa la resuelve el llamador explícitamente (Filament::getTenant() en el panel) —
     * este service no asume ningún tenant ambiente, igual que VentaService/CompraService.
     *
     * $caja (multi-caja, POS táctil) es opcional: Caja/Facturación siguen abriendo turnos sin
     * caja física. Si viene, además una caja física solo puede tener UN turno abierto a la vez
     * (dos cajeros no pueden cuadrar la misma gaveta), y se revalida que sea de $empresa y esté
     * activa — el id llega de un selector del navegador (client-controllable).
     */
    public function abrir(string $fondoInicial, int $userId, Empresa $empresa, ?Caja $caja = null): ArqueoCaja
    {
        return DB::transaction(function () use ($fondoInicial, $userId, $empresa, $caja) {
            if ($caja !== null) {
                // Se relee de la BD (no se confía en la instancia recibida) y se bloquea la fila:
                // serializa aperturas concurrentes sobre la misma caja — sin el lock, dos cajeros
                // abriendo a la vez podían pasar ambos la verificación de abajo.
                $caja = Caja::query()
                    ->whereKey($caja->id)
                    ->where('empresa_id', $empresa->id)
                    ->where('activo', true)
                    ->lockForUpdate()
                    ->first();

                if ($caja === null) {
                    throw new RuntimeException('La caja indicada no existe o está inactiva.');
                }

                $abiertoEnCaja = $this->arqueoAbiertoEnCaja($caja);

                if ($abiertoEnCaja !== null) {
                    throw new RuntimeException(
                        $abiertoEnCaja->user_id === $userId
                            ? 'Ya tienes un arqueo de caja abierto.'
                            : "La caja {$caja->nombre} ya tiene un arqueo abierto por otro cajero."
                    );
                }
            }

            if ($this->arqueoAbiertoDe($userId, $empresa) !== null) {
                throw new RuntimeException('Ya tienes un arqueo de caja abierto.');
            }

            return ArqueoCaja::create([
                'empresa_id' => $empresa->id,
                'caja_id' => $caja?->id,
                'user_id' => $userId,
                'fondo_inicial' => $this->aMoneda($fondoInicial),
                'abierto_en' => now(),
                'estado' => EstadoArqueoCaja::ABIERTO,
            ]);
        });
    }

    /**
     * Cierra el turno: calcula (y guarda, como snapshot) las ventas del turno agrupadas por
     * forma de pago, excluyendo anuladas, y compara el efectivo esperado (fondo inicial + ventas
     * en efectivo) contra lo contado físicamente. Por defecto solo quien abrió el turno puede
     * cerrarlo; $puedeCerrarAjena la resuelve el llamador a partir del permiso
     * 'arqueo.cerrar_ajeno' (Administrador), para que un supervisor pueda cerrar la caja de un
     * cajero que ya se fue o se le olvidó.
     */
    public function cerrar(ArqueoCaja $arqueo, string $efectivoContado, ?string $notas, int $userId, bool $puedeCerrarAjena = false): ArqueoCaja
    {
        if ($arqueo->estaCerrado()) {
            throw new RuntimeException('Este arqueo ya fue cerrado.');
        }

        if ($arqueo->user_id !== $userId && ! $puedeCerrarAjena) {
            throw new RuntimeException('Solo quien abrió la caja puede cerrarla.');
        }

        return DB::transaction(function () use ($arqueo, $efectivoContado, $notas) {
            $sumasPorFormaPago = $arqueo->ventas()
                ->where('estado', '!=', EstadoVenta::ANULADA)
                // Ventas a crédito no representan efectivo/tarjeta recibido hoy: se cobran
                // después vía CuentaPorCobrarService, no deben inflar el efectivo esperado.
                ->where('tipo_pago', TipoPago::CONTADO)
                // Las notas (devoluciones de hoy) no son ventas: se restan aparte, abajo.
                ->whereNull('venta_modificada_id')
                ->selectRaw('forma_pago, COALESCE(SUM(total), 0) as total')
                ->groupBy('forma_pago')
                ->pluck('total', 'forma_pago');

            $totalEfectivo = $this->aMoneda($sumasPorFormaPago[FormaPago::EFECTIVO->value] ?? '0');
            $totalTarjeta = $this->aMoneda($sumasPorFormaPago[FormaPago::TARJETA->value] ?? '0');
            $totalTransferencia = $this->aMoneda($sumasPorFormaPago[FormaPago::TRANSFERENCIA->value] ?? '0');

            // Devoluciones reembolsadas en efectivo desde esta caja: ese dinero salió del cajón.
            $devolucionesEfectivo = $this->aMoneda((string) $arqueo->ventas()
                ->where('estado', '!=', EstadoVenta::ANULADA)
                ->whereNotNull('venta_modificada_id')
                ->where('forma_reembolso', FormaReembolso::EFECTIVO)
                ->sum('monto_reembolso'));

            $efectivoContadoNormalizado = $this->aMoneda($efectivoContado);
            $efectivoEsperado = bcsub(bcadd((string) $arqueo->fondo_inicial, $totalEfectivo, 2), $devolucionesEfectivo, 2);
            $diferencia = bcsub($efectivoContadoNormalizado, $efectivoEsperado, 2);

            $arqueo->update([
                'total_ventas_efectivo' => $totalEfectivo,
                'total_ventas_tarjeta' => $totalTarjeta,
                'total_ventas_transferencia' => $totalTransferencia,
                'total_devoluciones_efectivo' => $devolucionesEfectivo,
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

    public function arqueoAbiertoDe(int $userId, Empresa $empresa): ?ArqueoCaja
    {
        return ArqueoCaja::query()
            ->where('empresa_id', $empresa->id)
            ->where('user_id', $userId)
            ->where('estado', EstadoArqueoCaja::ABIERTO)
            ->first();
    }

    public function arqueoAbiertoEnCaja(Caja $caja): ?ArqueoCaja
    {
        return ArqueoCaja::query()
            ->where('empresa_id', $caja->empresa_id)
            ->where('caja_id', $caja->id)
            ->where('estado', EstadoArqueoCaja::ABIERTO)
            ->first();
    }

    /** Normaliza un valor de dinero (string|int|float) a una cadena con escala 2, vía bcmath. */
    private function aMoneda(string|int|float $valor): string
    {
        return bcadd((string) $valor, '0', 2);
    }
}
