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

    // ── Livewire state ──────────────────────────────────────────────

    public int $pollInterval = 15;

    /** ID de la comanda cuyo detalle se muestra en el panel lateral */
    public ?int $comandaAbiertaId = null;

    /** Búsqueda de productos dentro del panel */
    public string $busquedaProducto = '';

    /** Cantidad al agregar producto */
    public float $cantidadProducto = 1;

    /** Notas al agregar producto */
    public string $notasProducto = '';

    /** Comensales para abrir comanda */
    public int $comensalesNueva = 1;

    // ── Queries ─────────────────────────────────────────────────────

    public function getAreas(): Collection
    {
        $empresaId = Filament::getTenant()?->id;

        return AreaRestaurante::where('empresa_id', $empresaId)
            ->where('activa', true)
            ->orderBy('orden')
            ->with([
                'mesas' => fn ($q) => $q->where('activa', true)->orderBy('numero'),
                'mesas.comandaActiva.mesero',
                'mesas.comandaActiva.detalles.producto',
            ])
            ->get();
    }

    public function getComandaActiva(): ?Comanda
    {
        if (! $this->comandaAbiertaId) {
            return null;
        }

        $empresaId = Filament::getTenant()?->id;

        return Comanda::where('empresa_id', $empresaId)
            ->with(['mesa', 'mesero', 'detalles.producto'])
            ->find($this->comandaAbiertaId);
    }

    public function getProductosFiltrados(): Collection
    {
        $empresaId = Filament::getTenant()?->id;

        $query = Producto::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->orderBy('nombre');

        if ($this->busquedaProducto !== '') {
            $query->where('nombre', 'ilike', "%{$this->busquedaProducto}%");
        }

        return $query->limit(20)->get(['id', 'nombre', 'precio', 'categoria_id']);
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

    // ── Acciones de mesa ────────────────────────────────────────────

    /** Clic en mesa verde: abre comanda y muestra el panel */
    public function abrirComanda(int $mesaId): void
    {
        $empresa = Filament::getTenant();
        $mesa = Mesa::where('empresa_id', $empresa->id)->findOrFail($mesaId);

        try {
            $comanda = app(ComandaService::class)->abrir(
                $empresa,
                $mesa,
                auth()->user(),
                max(1, $this->comensalesNueva),
            );

            $this->comandaAbiertaId = $comanda->id;
            $this->comensalesNueva = 1;
            $this->resetProductoForm();
            Notification::make()->title('Comanda abierta')->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    /** Clic en mesa ocupada: muestra el panel de la comanda activa */
    public function verComanda(int $comandaId): void
    {
        $empresaId = Filament::getTenant()?->id;

        $existe = Comanda::where('empresa_id', $empresaId)
            ->where('id', $comandaId)
            ->exists();

        if ($existe) {
            $this->comandaAbiertaId = $comandaId;
            $this->resetProductoForm();
        }
    }

    public function cerrarPanel(): void
    {
        $this->comandaAbiertaId = null;
        $this->resetProductoForm();
    }

    // ── Acciones de comanda ─────────────────────────────────────────

    public function agregarProducto(int $productoId): void
    {
        $empresaId = Filament::getTenant()?->id;
        $comanda = Comanda::where('empresa_id', $empresaId)->findOrFail($this->comandaAbiertaId);
        $producto = Producto::where('empresa_id', $empresaId)->findOrFail($productoId);

        try {
            app(ComandaService::class)->agregarProducto(
                $comanda,
                $producto,
                max(1, $this->cantidadProducto),
                $this->notasProducto !== '' ? $this->notasProducto : null,
            );

            $this->resetProductoForm();
            Notification::make()->title("{$producto->nombre} agregado")->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function quitarDetalle(int $detalleId): void
    {
        $empresaId = Filament::getTenant()?->id;
        $comanda = Comanda::where('empresa_id', $empresaId)->findOrFail($this->comandaAbiertaId);

        $detalle = $comanda->detalles()->findOrFail($detalleId);

        if (! in_array($comanda->estado, [EstadoComanda::ABIERTA, EstadoComanda::EN_PREPARACION])) {
            Notification::make()->title('No se puede modificar esta comanda')->danger()->send();

            return;
        }

        $detalle->delete();
        Notification::make()->title('Producto removido')->success()->send();
    }

    public function enviarACocina(): void
    {
        $empresaId = Filament::getTenant()?->id;
        $comanda = Comanda::where('empresa_id', $empresaId)->findOrFail($this->comandaAbiertaId);

        $enviados = app(ComandaService::class)->enviarACocina($comanda);

        if ($enviados > 0) {
            Notification::make()->title("{$enviados} ítem(s) enviados a cocina")->success()->send();
        } else {
            Notification::make()->title('No hay ítems pendientes para enviar')->warning()->send();
        }
    }

    public function cerrarComanda(): void
    {
        $empresaId = Filament::getTenant()?->id;
        $comanda = Comanda::where('empresa_id', $empresaId)->findOrFail($this->comandaAbiertaId);

        try {
            $venta = app(ComandaService::class)->cerrar($comanda, auth()->user());
            $this->comandaAbiertaId = null;
            Notification::make()->title("Venta #{$venta->id} generada")->success()->send();
        } catch (RuntimeException|\App\Exceptions\VentaInvalidaException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function cancelarComanda(): void
    {
        $empresaId = Filament::getTenant()?->id;
        $comanda = Comanda::where('empresa_id', $empresaId)->findOrFail($this->comandaAbiertaId);

        try {
            app(ComandaService::class)->cancelar($comanda);
            $this->comandaAbiertaId = null;
            Notification::make()->title('Comanda cancelada')->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function transferirComanda(int $nuevaMesaId): void
    {
        $empresaId = Filament::getTenant()?->id;
        $comanda = Comanda::where('empresa_id', $empresaId)->findOrFail($this->comandaAbiertaId);
        $nuevaMesa = Mesa::where('empresa_id', $empresaId)->findOrFail($nuevaMesaId);

        try {
            app(ComandaService::class)->transferir($comanda, $nuevaMesa);
            Notification::make()->title("Comanda transferida a mesa {$nuevaMesa->numero}")->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function resetProductoForm(): void
    {
        $this->busquedaProducto = '';
        $this->cantidadProducto = 1;
        $this->notasProducto = '';
    }
}
