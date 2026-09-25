<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EstadoFiscal;
use App\Models\CuentaPorCobrar;
use App\Models\SecuenciaNcf;
use App\Models\Venta;
use App\Services\ReporteService;
use App\Services\SecuenciaNcfService;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Alertas operativas del dashboard: cada bloque solo se calcula/muestra si el usuario tiene el
 * permiso relacionado (el mismo que gatea el Resource/página donde se resuelve la alerta), para
 * no filtrar conteos de datos que el usuario no podría abrir de todos modos.
 */
class AlertasWidget extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.alertas';

    public static function canView(): bool
    {
        return auth()->user()?->canAny(['productos.ver', 'secuencias.administrar', 'ecf.gestionar', 'cxc.ver']) ?? false;
    }

    /** @return Collection<int, array{color: string, titulo: string, detalle: string}> */
    public function getAlertas(): Collection
    {
        $alertas = collect();
        $usuario = auth()->user();

        if ($usuario?->can('productos.ver')) {
            $alertas = $alertas->merge($this->alertasStockBajo());
        }

        if ($usuario?->can('secuencias.administrar')) {
            $alertas = $alertas->merge($this->alertasSecuenciasPorAgotarse());
        }

        if ($usuario?->can('ecf.gestionar')) {
            $alertas = $alertas->merge($this->alertasEcfPendiente());
        }

        if ($usuario?->can('cxc.ver')) {
            $alertas = $alertas->merge($this->alertasCxcVencidas());
        }

        return $alertas;
    }

    /** @return array<int, array{color: string, titulo: string, detalle: string}> */
    private function alertasStockBajo(): array
    {
        $productos = app(ReporteService::class)->productosBajoMinimo();

        if ($productos->isEmpty()) {
            return [];
        }

        $nombres = $productos->take(3)->pluck('nombre')->implode(', ');
        $restantes = $productos->count() - 3;

        return [[
            'color' => 'rojo',
            'titulo' => 'Stock bajo mínimo',
            'detalle' => $nombres.($restantes > 0 ? " y {$restantes} más" : ''),
        ]];
    }

    /** @return array<int, array{color: string, titulo: string, detalle: string}> */
    private function alertasSecuenciasPorAgotarse(): array
    {
        $servicio = app(SecuenciaNcfService::class);

        $secuencias = SecuenciaNcf::query()
            ->where('activa', true)
            ->get()
            ->filter(fn (SecuenciaNcf $secuencia) => $servicio->restantes($secuencia) <= SecuenciaNcfService::UMBRAL_ALERTA);

        return $secuencias->map(fn (SecuenciaNcf $secuencia) => [
            'color' => 'naranja',
            'titulo' => 'Secuencia NCF por agotarse',
            'detalle' => "{$secuencia->prefijo} ({$secuencia->tipo_comprobante->etiqueta()}) — quedan {$servicio->restantes($secuencia)}",
        ])->all();
    }

    /** @return array<int, array{color: string, titulo: string, detalle: string}> */
    private function alertasEcfPendiente(): array
    {
        $cantidad = Venta::query()
            ->whereIn('estado_fiscal', [EstadoFiscal::PENDIENTE, EstadoFiscal::EN_PROCESO])
            ->whereNotNull('ecf_enviado_en')
            ->where('ecf_enviado_en', '<', Carbon::now()->subDay())
            ->count();

        if ($cantidad === 0) {
            return [];
        }

        return [[
            'color' => 'amarillo',
            'titulo' => 'e-CF pendiente de respuesta del PAC',
            'detalle' => "{$cantidad} comprobante(s) con más de 24h sin respuesta",
        ]];
    }

    /** @return array<int, array{color: string, titulo: string, detalle: string}> */
    private function alertasCxcVencidas(): array
    {
        $vencidas = CuentaPorCobrar::query()
            ->whereColumn('monto_pagado', '<', 'monto_total')
            ->where('fecha_vencimiento', '<', Carbon::today())
            ->selectRaw('COUNT(*) as cantidad')
            ->selectRaw('COALESCE(SUM(monto_total - monto_pagado), 0) as monto')
            ->first();

        $cantidad = (int) $vencidas->cantidad;

        if ($cantidad === 0) {
            return [];
        }

        return [[
            'color' => 'azul',
            'titulo' => 'Cuentas por cobrar vencidas',
            'detalle' => $cantidad.' factura(s) — '.\Illuminate\Support\Number::currency((float) $vencidas->monto, 'DOP'),
        ]];
    }
}
