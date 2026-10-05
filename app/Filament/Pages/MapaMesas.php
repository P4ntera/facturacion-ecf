<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\EstadoComanda;
use App\Enums\EstadoMesa;
use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Models\AreaRestaurante;
use App\Models\Comanda;
use App\Models\Mesa;
use App\Models\Producto;
use App\Services\ComandaService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use RuntimeException;
use UnitEnum;

class MapaMesas extends Page
{
    use RestringidoPorModulo;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Mapa de Mesas';

    protected static ?string $title = 'Mapa de Mesas';

    protected string $view = 'filament.pages.mapa-mesas';

    public static function modulo(): Modulo
    {
        return Modulo::RESTAURANTE_COMANDAS;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('comandas.ver') ?? false;
    }

    // Livewire polling
    public int $pollInterval = 15;

    public function getAreas(): Collection
    {
        $empresaId = Filament::getTenant()?->id;

        return AreaRestaurante::where('empresa_id', $empresaId)
            ->where('activa', true)
            ->orderBy('orden')
            ->with(['mesas' => fn ($q) => $q->where('activa', true)->orderBy('numero')])
            ->get()
            ->each(function (AreaRestaurante $area) {
                $area->mesas->each(function (Mesa $mesa) {
                    $mesa->load(['comandaActiva.mesero', 'comandaActiva.detalles.producto']);
                });
            });
    }

    public function abrirComanda(int $mesaId, int $comensales = 1): void
    {
        $empresaId = Filament::getTenant()?->id;
        $mesa = Mesa::where('empresa_id', $empresaId)->findOrFail($mesaId);

        try {
            app(ComandaService::class)->abrir(
                Filament::getTenant(),
                $mesa,
                auth()->user(),
                $comensales,
            );

            Notification::make()->title('Comanda abierta')->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function agregarProducto(int $comandaId, int $productoId, float $cantidad = 1, ?string $notas = null): void
    {
        $empresaId = Filament::getTenant()?->id;
        $comanda = Comanda::where('empresa_id', $empresaId)->findOrFail($comandaId);
        $producto = Producto::where('empresa_id', $empresaId)->findOrFail($productoId);

        try {
            app(ComandaService::class)->agregarProducto($comanda, $producto, $cantidad, $notas);
            Notification::make()->title('Producto agregado')->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function enviarACocina(int $comandaId): void
    {
        $empresaId = Filament::getTenant()?->id;
        $comanda = Comanda::where('empresa_id', $empresaId)->findOrFail($comandaId);

        $enviados = app(ComandaService::class)->enviarACocina($comanda);

        if ($enviados > 0) {
            Notification::make()->title("{$enviados} ítem(s) enviados a cocina")->success()->send();
        } else {
            Notification::make()->title('No hay ítems pendientes para enviar')->warning()->send();
        }
    }

    public function cerrarComanda(int $comandaId, array $datosVenta = []): void
    {
        $empresaId = Filament::getTenant()?->id;
        $comanda = Comanda::where('empresa_id', $empresaId)->findOrFail($comandaId);

        try {
            $venta = app(ComandaService::class)->cerrar($comanda, auth()->user(), $datosVenta);
            Notification::make()->title("Venta #{$venta->id} generada")->success()->send();
        } catch (RuntimeException|\App\Exceptions\VentaInvalidaException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function cancelarComanda(int $comandaId): void
    {
        $empresaId = Filament::getTenant()?->id;
        $comanda = Comanda::where('empresa_id', $empresaId)->findOrFail($comandaId);

        try {
            app(ComandaService::class)->cancelar($comanda);
            Notification::make()->title('Comanda cancelada')->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function transferirComanda(int $comandaId, int $nuevaMesaId): void
    {
        $empresaId = Filament::getTenant()?->id;
        $comanda = Comanda::where('empresa_id', $empresaId)->findOrFail($comandaId);
        $nuevaMesa = Mesa::where('empresa_id', $empresaId)->findOrFail($nuevaMesaId);

        try {
            app(ComandaService::class)->transferir($comanda, $nuevaMesa);
            Notification::make()->title("Comanda transferida a mesa {$nuevaMesa->numero}")->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function getProductosDisponibles(): Collection
    {
        $empresaId = Filament::getTenant()?->id;

        return Producto::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'precio', 'categoria_id']);
    }

    public function getMesasDisponibles(): Collection
    {
        $empresaId = Filament::getTenant()?->id;

        return Mesa::where('empresa_id', $empresaId)
            ->where('activa', true)
            ->where('estado', EstadoMesa::DISPONIBLE)
            ->orderBy('numero')
            ->get(['id', 'numero']);
    }
}
