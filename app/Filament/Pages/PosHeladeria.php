<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\FormaPago;
use App\Enums\ModoPrecioHelado;
use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Models\ComboHelado;
use App\Models\Empresa;
use App\Models\PresentacionHelado;
use App\Models\Producto;
use App\Models\SaborHelado;
use App\Models\ToppingHelado;
use App\Services\HeladeriaService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * POS de heladería con soporte táctil y mouse. Dos modos de operación:
 * 1. "Helado armado": elige presentación → sabores → toppings → agregar al carrito
 * 2. "Producto directo": toca y va directo al carrito (paletas, snacks, bebidas, dulces)
 *
 * Delega la facturación a HeladeriaService → VentaService (bridge pattern).
 */
class PosHeladeria extends Page
{
    use RestringidoPorModulo;

    protected static string $layout = 'filament-panels::components.layout.base';

    protected static ?string $slug = 'pos-heladeria';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|\UnitEnum|null $navigationGroup = 'Heladería';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'POS Heladería';

    protected static ?string $title = 'POS Heladería';

    protected string $view = 'filament.pages.pos-heladeria';

    // ---------------------------------------------------------------- Estado Livewire

    /** Tab activo: 'armado' | 'combos' | 'directo' */
    public string $tab = 'armado';

    /** Paso del builder: 'presentacion' | 'sabores' | 'toppings' | 'resumen' */
    public string $paso = 'presentacion';

    /** ID de la presentación seleccionada */
    public ?int $presentacionId = null;

    /** IDs de sabores seleccionados */
    public array $saboresSeleccionados = [];

    /** IDs de toppings seleccionados */
    public array $toppingsSeleccionados = [];

    /** Carrito de la venta */
    public array $carrito = [];

    /** Filtro de categoría de sabor */
    public ?string $filtroCategoria = null;

    /** Forma de pago seleccionada */
    public string $formaPago = 'efectivo';

    /** Color accent del POS (hex) */
    public string $colorAccent = '#e85d9a';

    public function mount(): void
    {
        $this->colorAccent = $this->config()->color_accent_pos ?? '#e85d9a';
    }

    public static function modulo(): Modulo
    {
        return Modulo::HELADERIA_SABORES;
    }

    public static function puedeAccederPorPermiso(): bool
    {
        return auth()->user()?->can('pos.acceder') ?? false;
    }

    // ---------------------------------------------------------------- Helpers

    public function empresa(): Empresa
    {
        return Filament::getTenant();
    }

    public function empresaId(): int
    {
        return $this->empresa()->id;
    }

    public function config(): \App\Models\EmpresaConfiguracion
    {
        return $this->empresa()->config();
    }

    public function modoPrecio(): ModoPrecioHelado
    {
        return $this->config()->modo_precio_helado;
    }

    // ---------------------------------------------------------------- Data loaders

    /** @return Collection<int, PresentacionHelado> */
    public function presentaciones(): Collection
    {
        return PresentacionHelado::where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->orderBy('orden')
            ->get();
    }

    /** @return Collection<int, SaborHelado> */
    public function sabores(): Collection
    {
        return SaborHelado::where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->where('disponible', true)
            ->when($this->filtroCategoria, fn ($q) => $q->where('categoria', $this->filtroCategoria))
            ->orderBy('orden')
            ->get();
    }

    /** @return Collection<int, ToppingHelado> */
    public function toppings(): Collection
    {
        return ToppingHelado::where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->orderBy('orden')
            ->get();
    }

    /** @return Collection<int, ComboHelado> */
    public function combos(): Collection
    {
        return ComboHelado::where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->orderBy('orden')
            ->get();
    }

    /** Productos directos (tipo PRODUCTO, activos, con stock, de la empresa). */
    public function productosDirectos(): Collection
    {
        return Producto::where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->where('tipo', \App\Enums\TipoProducto::PRODUCTO)
            ->orderBy('nombre')
            ->get();
    }

    public function presentacionActual(): ?PresentacionHelado
    {
        if (! $this->presentacionId) {
            return null;
        }

        return PresentacionHelado::where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->find($this->presentacionId);
    }

    // ---------------------------------------------------------------- Builder actions

    public function cambiarTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetBuilder();
    }

    public function resetBuilder(): void
    {
        $this->paso = 'presentacion';
        $this->presentacionId = null;
        $this->saboresSeleccionados = [];
        $this->toppingsSeleccionados = [];
        $this->filtroCategoria = null;
    }

    public function seleccionarPresentacion(int $id): void
    {
        $presentacion = PresentacionHelado::where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->find($id);

        if (! $presentacion) {
            return;
        }

        $this->presentacionId = $id;
        $this->saboresSeleccionados = [];
        $this->toppingsSeleccionados = [];
        $this->paso = 'sabores';
    }

    public function toggleSabor(int $id): void
    {
        $presentacion = $this->presentacionActual();
        if (! $presentacion) {
            return;
        }

        if (in_array($id, $this->saboresSeleccionados)) {
            $this->saboresSeleccionados = array_values(array_diff($this->saboresSeleccionados, [$id]));
        } else {
            $max = $presentacion->scoops_incluidos;
            $config = $this->config();

            // Permitir extras si está habilitado
            if (! $config->permitir_scoop_extra) {
                if (count($this->saboresSeleccionados) >= $max) {
                    Notification::make()
                        ->title("Máximo {$max} scoops para esta presentación")
                        ->warning()
                        ->send();

                    return;
                }
            }

            $this->saboresSeleccionados[] = $id;
        }
    }

    public function irAToppings(): void
    {
        if (empty($this->saboresSeleccionados)) {
            Notification::make()->title('Selecciona al menos un sabor')->warning()->send();

            return;
        }

        $this->paso = 'toppings';
    }

    public function toggleTopping(int $id): void
    {
        if (in_array($id, $this->toppingsSeleccionados)) {
            $this->toppingsSeleccionados = array_values(array_diff($this->toppingsSeleccionados, [$id]));
        } else {
            $this->toppingsSeleccionados[] = $id;
        }
    }

    public function agregarArmadoAlCarrito(): void
    {
        $presentacion = $this->presentacionActual();
        if (! $presentacion || empty($this->saboresSeleccionados)) {
            return;
        }

        $service = app(HeladeriaService::class);
        $precio = $service->calcularPrecioArmado(
            $presentacion,
            [
                'sabores' => $this->saboresSeleccionados,
                'toppings' => $this->toppingsSeleccionados,
            ],
            $this->empresa(),
        );

        $this->carrito[] = [
            'tipo' => 'armado',
            'descripcion' => $precio['descripcion'],
            'precio' => $precio['total'],
            'cantidad' => 1,
            'detalle' => [
                'presentacion_id' => $presentacion->id,
                'sabores' => $this->saboresSeleccionados,
                'toppings' => $this->toppingsSeleccionados,
            ],
        ];

        $this->resetBuilder();
        $this->dispatch('item-agregado');
    }

    // ---------------------------------------------------------------- Combo actions

    public function agregarCombo(int $id): void
    {
        $combo = ComboHelado::where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->find($id);

        if (! $combo) {
            return;
        }

        $this->carrito[] = [
            'tipo' => 'combo',
            'descripcion' => $combo->nombre,
            'precio' => (float) $combo->precio,
            'cantidad' => 1,
            'detalle' => ['combo_id' => $combo->id],
        ];

        $this->dispatch('item-agregado');
    }

    // ---------------------------------------------------------------- Producto directo

    public function agregarProductoDirecto(int $productoId): void
    {
        $producto = Producto::where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->find($productoId);

        if (! $producto) {
            return;
        }

        // Si ya está en el carrito, incrementar cantidad
        foreach ($this->carrito as $i => $item) {
            if (($item['detalle']['producto_id'] ?? null) === $producto->id) {
                $this->carrito[$i]['cantidad']++;
                $this->dispatch('item-agregado');

                return;
            }
        }

        $this->carrito[] = [
            'tipo' => 'directo',
            'descripcion' => $producto->nombre,
            'precio' => (float) $producto->precio,
            'cantidad' => 1,
            'detalle' => ['producto_id' => $producto->id],
        ];

        $this->dispatch('item-agregado');
    }

    // ---------------------------------------------------------------- Carrito

    public function quitarDelCarrito(int $index): void
    {
        unset($this->carrito[$index]);
        $this->carrito = array_values($this->carrito);
    }

    public function incrementarCantidad(int $index): void
    {
        if (isset($this->carrito[$index])) {
            $this->carrito[$index]['cantidad']++;
        }
    }

    public function decrementarCantidad(int $index): void
    {
        if (isset($this->carrito[$index]) && $this->carrito[$index]['cantidad'] > 1) {
            $this->carrito[$index]['cantidad']--;
        }
    }

    public function totalCarrito(): float
    {
        return collect($this->carrito)->sum(fn ($item) => $item['precio'] * $item['cantidad']);
    }

    public function vaciarCarrito(): void
    {
        $this->carrito = [];
    }

    public function cantidadItems(): int
    {
        return collect($this->carrito)->sum('cantidad');
    }

    // ---------------------------------------------------------------- Color accent

    public function guardarColorAccent(string $color): void
    {
        if (! auth()->user()?->can('heladeria.configurar')) {
            return;
        }

        // Validar formato hex
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            Notification::make()->title('Color inválido')->warning()->send();

            return;
        }

        $config = $this->empresa()->config();
        $config->update(['color_accent_pos' => $color]);

        $this->colorAccent = $color;

        $this->dispatch('color-actualizado');

        Notification::make()->title('Color actualizado')->success()->send();
    }

    // ---------------------------------------------------------------- Cobrar

    public function cobrar(): void
    {
        if (empty($this->carrito)) {
            Notification::make()->title('El carrito está vacío')->warning()->send();

            return;
        }

        try {
            $service = app(HeladeriaService::class);
            $venta = $service->facturar($this->carrito, $this->empresa(), [
                'forma_pago' => FormaPago::from($this->formaPago),
            ]);

            $this->carrito = [];
            $this->formaPago = 'efectivo';
            $this->resetBuilder();

            Notification::make()
                ->title('¡Venta registrada!')
                ->body("Total: RD$ " . number_format($venta->total, 2))
                ->success()
                ->send();

            $this->dispatch('venta-cobrada');
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error al registrar la venta')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
