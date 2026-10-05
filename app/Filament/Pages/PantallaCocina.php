<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\EstadoPreparacion;
use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Models\ComandaDetalle;
use App\Services\ComandaService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

class PantallaCocina extends Page
{
    use RestringidoPorModulo;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Cocina (KDS)';

    protected static ?string $title = 'Pantalla de Cocina';

    protected string $view = 'filament.pages.pantalla-cocina';

    public static function modulo(): Modulo
    {
        return Modulo::RESTAURANTE_COCINA;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('cocina.ver') ?? false;
    }

    public function getItemsPorEstado(EstadoPreparacion $estado): Collection
    {
        $empresaId = Filament::getTenant()?->id;

        return ComandaDetalle::whereHas('comanda', fn ($q) => $q->where('empresa_id', $empresaId))
            ->where('estado_preparacion', $estado)
            ->with(['comanda.mesa', 'producto'])
            ->orderBy('enviado_cocina_en')
            ->orderBy('created_at')
            ->get();
    }

    public function avanzarEstado(int $detalleId): void
    {
        $empresaId = Filament::getTenant()?->id;
        $detalle = ComandaDetalle::whereHas('comanda', fn ($q) => $q->where('empresa_id', $empresaId))
            ->findOrFail($detalleId);

        if ($detalle->estado_preparacion === EstadoPreparacion::EN_PREPARACION) {
            app(ComandaService::class)->marcarPreparado($detalle);
            Notification::make()->title('Ítem marcado como listo')->success()->send();
        } elseif ($detalle->estado_preparacion === EstadoPreparacion::PENDIENTE) {
            $detalle->update([
                'estado_preparacion' => EstadoPreparacion::EN_PREPARACION,
                'enviado_cocina_en' => $detalle->enviado_cocina_en ?? now(),
            ]);
            Notification::make()->title('Ítem en preparación')->success()->send();
        }
    }
}
