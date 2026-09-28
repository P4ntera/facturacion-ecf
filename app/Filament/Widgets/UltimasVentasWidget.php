<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EstadoFiscal;
use App\Filament\Resources\VentaResource;
use App\Models\Venta;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

class UltimasVentasWidget extends Widget
{
    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.ultimas-ventas';

    public static function canView(): bool
    {
        return auth()->user()?->can('ventas.ver') ?? false;
    }

    /** @return Collection<int, Venta> */
    public function getVentas(): Collection
    {
        return Venta::query()
            ->with('cliente')
            ->latest('fecha')
            ->limit(5)
            ->get();
    }

    public function getVerTodasUrl(): string
    {
        return VentaResource::getUrl('index');
    }

    /** @return array{label: string, class: string} */
    public function estadoFiscalBadge(?EstadoFiscal $estado): array
    {
        return match ($estado) {
            EstadoFiscal::ACEPTADO, EstadoFiscal::RFCE => ['label' => 'Aceptada', 'class' => 'badge-estado-aceptada'],
            EstadoFiscal::PENDIENTE, EstadoFiscal::EN_PROCESO, EstadoFiscal::ACEPTADO_CONDICIONAL => ['label' => 'Pendiente', 'class' => 'badge-estado-pendiente'],
            EstadoFiscal::RECHAZADO => ['label' => 'Rechazada', 'class' => 'badge-estado-rechazada'],
            default => ['label' => 'No aplica', 'class' => 'badge-estado-no-aplica'],
        };
    }
}
