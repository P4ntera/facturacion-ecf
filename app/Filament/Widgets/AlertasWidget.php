<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\TipoNotificacion;
use App\Models\CuentaPorCobrar;
use App\Models\CuentaPorPagar;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\Venta;
use App\Services\LicenseService;
use App\Services\ReporteService;
use App\Services\SecuenciaNcfService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Alertas operativas del dashboard: cada bloque exige DOS permisos — el de su tipo de
 * notificación (notificaciones.*, lo que el rol decide que le interesa) y el que gatea el
 * Resource/página donde se resuelve la alerta (para no filtrar conteos de datos que el usuario
 * no podría abrir de todos modos). La preferencia personal de "Mis notificaciones" solo silencia
 * la campana, no el dashboard.
 */
class AlertasWidget extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.alertas';

    /** Permiso de la pantalla donde se resuelve cada tipo de alerta. */
    private const PERMISO_DE_ACCESO = [
        'stock_bajo' => 'productos.ver',
        'ncf_agotandose' => 'secuencias.administrar',
        'ecf_rechazado' => 'ecf.gestionar',
        'cxc_vencidas' => 'cxc.ver',
        'cxp_vencidas' => 'cxp.ver',
    ];

    public static function canView(): bool
    {
        return collect(TipoNotificacion::cases())->contains(fn (TipoNotificacion $tipo) => self::puedeVer($tipo));
    }

    /**
     * Explícito en cada consulta (regla de tenancy del proyecto): el scope automático de Filament
     * solo existe cuando el panel arrancó en una request; esto no depende de eso.
     */
    private function empresaId(): int
    {
        return Filament::getTenant()->id;
    }

    private static function puedeVer(TipoNotificacion $tipo): bool
    {
        $usuario = auth()->user();

        return $usuario !== null
            && $usuario->can($tipo->permiso())
            && $usuario->can(self::PERMISO_DE_ACCESO[$tipo->value]);
    }

    /** @return Collection<int, array{color: string, titulo: string, detalle: string}> */
    public function getAlertas(): Collection
    {
        $alertas = collect([
            TipoNotificacion::STOCK_BAJO->value => fn () => [...$this->alertasStockNegativo(), ...$this->alertasStockBajo()],
            TipoNotificacion::NCF_AGOTANDOSE->value => fn () => $this->alertasSecuenciasPorAgotarse(),
            TipoNotificacion::ECF_RECHAZADO->value => fn () => [...$this->alertasEcfRechazado(), ...$this->alertasEcfPendiente()],
            TipoNotificacion::CXC_VENCIDAS->value => fn () => $this->alertasCxcVencidas(),
            TipoNotificacion::CXP_VENCIDAS->value => fn () => $this->alertasCxpVencidas(),
        ])
            ->filter(fn ($calcular, string $tipo) => self::puedeVer(TipoNotificacion::from($tipo)))
            ->flatMap(fn ($calcular) => $calcular())
            ->values();

        // Las alertas de licencia se agregan al inicio (son críticas) y no dependen de
        // permisos de notificación — cualquier usuario debe ver si la licencia tiene problemas.
        $licenciaAlertas = $this->alertasLicencia();

        return $licenciaAlertas->isNotEmpty()
            ? $licenciaAlertas->merge($alertas)
            : $alertas;
    }

    /** @return array<int, array{color: string, titulo: string, detalle: string}> */
    private function alertasEcfRechazado(): array
    {
        // Solo los que todavía requieren acción: un rechazado ya anulado no.
        $cantidad = Venta::query()
            ->where('empresa_id', $this->empresaId())
            ->where('estado_fiscal', EstadoFiscal::RECHAZADO)
            ->where('estado', '!=', EstadoVenta::ANULADA)
            ->count();

        if ($cantidad === 0) {
            return [];
        }

        return [[
            'color' => 'rojo',
            'titulo' => 'e-CF rechazado por la DGII',
            'detalle' => "{$cantidad} comprobante(s) por corregir y reenviar, o anular",
        ]];
    }

    /** @return array<int, array{color: string, titulo: string, detalle: string}> */
    private function alertasCxpVencidas(): array
    {
        $vencidas = CuentaPorPagar::query()
            ->where('empresa_id', $this->empresaId())
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
            'titulo' => 'Cuentas por pagar vencidas',
            'detalle' => $cantidad.' factura(s) de proveedor — '.Number::currency((float) $vencidas->monto, 'DOP'),
        ]];
    }

    /**
     * Productos que se vendieron sin stock y siguen en negativo: se queda visible hasta que entre
     * la compra o se ajuste el inventario tras contar.
     *
     * @return array<int, array{color: string, titulo: string, detalle: string}>
     */
    private function alertasStockNegativo(): array
    {
        $productos = Producto::query()
            ->where('empresa_id', $this->empresaId())
            ->where('controla_stock', true)
            ->where('activo', true)
            ->where('stock', '<', 0)
            ->orderBy('stock')
            ->get(['nombre', 'stock']);

        if ($productos->isEmpty()) {
            return [];
        }

        $detalle = $productos->take(3)
            ->map(fn (Producto $p) => "{$p->nombre} (".rtrim(rtrim(number_format((float) $p->stock, 3, '.', ''), '0'), '.').')')
            ->implode(', ');
        $restantes = $productos->count() - 3;

        return [[
            'color' => 'rojo',
            'titulo' => 'Productos en negativo',
            'detalle' => $detalle.($restantes > 0 ? " y {$restantes} más" : '').'. Registra la compra que falta o ajusta tras contar.',
        ]];
    }

    /** @return array<int, array{color: string, titulo: string, detalle: string}> */
    private function alertasStockBajo(): array
    {
        $productos = app(ReporteService::class)->productosBajoMinimoQuery($this->empresaId())->get();

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
            ->where('empresa_id', $this->empresaId())
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
            ->where('empresa_id', $this->empresaId())
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
            ->where('empresa_id', $this->empresaId())
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
            'detalle' => $cantidad.' factura(s) — '.Number::currency((float) $vencidas->monto, 'DOP'),
        ]];
    }

    /** @return Collection<int, array{color: string, titulo: string, detalle: string}> */
    private function alertasLicencia(): Collection
    {
        $service = app(LicenseService::class);

        if (! $service->isEnabled()) {
            return collect();
        }

        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return collect();
        }

        $state = $service->stateForEmpresa($tenant);

        if (! $state['valid']) {
            return collect([[
                'color' => 'rojo',
                'titulo' => 'Licencia no válida',
                'detalle' => 'El sistema está en modo solo lectura. Contacte al administrador para renovar la licencia.',
            ]]);
        }

        if ($state['days_left'] <= LicenseService::DAYS_WARNING_THRESHOLD) {
            return collect([[
                'color' => 'naranja',
                'titulo' => 'Licencia por vencer',
                'detalle' => "La licencia vence en {$state['days_left']} día(s). Renuévela para evitar interrupciones.",
            ]]);
        }

        return collect();
    }
}
