<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Modulo;
use App\Enums\TipoComprobante;
use App\Enums\TipoVenta;
use App\Models\Caja as CajaRegistradora;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\ArqueoCajaService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * POS táctil (tablets / pantallas touch de supermercado) con soporte multi-caja y display del
 * cliente. Igual que Caja, hereda TODA la lógica de PuntoDeVenta (carrito, escaneo, arqueo,
 * cobrar vía VentaService::registrar(), impresión vía ImpresionService): solo cambia la vista y
 * agrega la caja registradora física.
 *
 * Es una página del panel (no un componente Livewire suelto con ruta propia) a propósito: así
 * conserva la identificación del tenant, el contexto de permisos persistente
 * (EstablecerEmpresaPermisos) y las notificaciones de Filament en cada petición /livewire/update.
 * El layout 'base' quita sidebar/topbar para que la pantalla sea completa.
 *
 * Cada cambio del carrito se publica en cache (ver publicarEnDisplay()) para que el display del
 * cliente de la caja (App\Livewire\DisplayCliente) lo lea por polling.
 */
class PuntoDeVentaTouch extends PuntoDeVenta
{
    /** Tiempo que el display muestra "¡Gracias por su compra!" tras cobrar, antes de limpiarse. */
    public const SEGUNDOS_GRACIAS = 8;

    /** Únicos tipos que cobra el modal del POS táctil (el resto sigue en Facturación). */
    private const TIPOS_PERMITIDOS = [
        self::SIN_COMPROBANTE,
        TipoComprobante::FACTURA_CREDITO_FISCAL->value,
        TipoComprobante::FACTURA_CONSUMO->value,
        TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA->value,
        TipoComprobante::FACTURA_CONSUMO_FISICA->value,
    ];

    protected string $view = 'filament.pages.punto-de-venta-touch';

    protected static string $layout = 'filament-panels::components.layout.base';

    protected static ?string $slug = 'pos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDeviceTablet;

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'POS táctil';

    protected static ?string $title = 'POS táctil';

    public ?int $cajaId = null;

    /** null = todas las categorías. */
    public ?int $categoriaId = null;

    /** Índice de la línea del carrito sobre la que actúa el teclado numérico. */
    public ?int $lineaSeleccionada = null;

    public static function modulo(): Modulo
    {
        return Modulo::VENTAS_CAJAS;
    }

    /** Además de Cajas registradoras (modulo()), requiere el Punto de Venta encendido. */
    public static function canAccess(): bool
    {
        return parent::canAccess()
            && (Filament::getTenant()?->tieneModulo(Modulo::VENTAS_POS) ?? false);
    }

    public static function puedeAccederPorPermiso(): bool
    {
        return auth()->user()?->can('pos.acceder') ?? false;
    }

    public function mount(): void
    {
        parent::mount();

        // Venta rápida al portador: si la empresa habilitó las ventas sin comprobante, es el
        // default del POS táctil; el cajero elige B02/E32... solo si el cliente pide factura.
        if ($this->permiteSinComprobante()) {
            $this->tipoComprobante = self::SIN_COMPROBANTE;
        }

        // Si el cajero ya tiene un turno abierto en una caja física, entra directo a ella: su
        // gaveta es esa hasta que la cierre.
        $this->cajaId = $this->arqueoAbierto()?->caja_id;
        $this->publicarEnDisplay();
    }

    // ---------------------------------------------------------------- Caja registradora

    /** @return Collection<int, CajaRegistradora> */
    public function cajasDisponibles(): Collection
    {
        return CajaRegistradora::query()
            ->where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();
    }

    /** Re-valida $cajaId (propiedad pública, client-controllable) contra empresa y estado en cada uso. */
    public function cajaSeleccionada(): ?CajaRegistradora
    {
        if ($this->cajaId === null) {
            return null;
        }

        return CajaRegistradora::query()
            ->where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->find($this->cajaId);
    }

    public function seleccionarCaja(int $cajaId): void
    {
        $caja = CajaRegistradora::query()
            ->where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->find($cajaId);

        if ($caja === null) {
            Notification::make()->title('La caja indicada no existe o está inactiva.')->danger()->send();

            return;
        }

        $arqueo = $this->arqueoAbierto();

        if ($arqueo !== null && $arqueo->caja_id !== $caja->id) {
            Notification::make()
                ->title($arqueo->caja_id === null
                    ? 'Tienes un turno abierto desde Caja/Facturación sin caja registradora asignada.'
                    : "Tienes un turno abierto en {$arqueo->caja?->nombre}.")
                ->body('Ciérralo en Arqueos de Caja antes de trabajar en otra caja.')
                ->danger()
                ->send();

            return;
        }

        $this->limpiarDisplay();
        $this->cajaId = $caja->id;
        $this->publicarEnDisplay();
    }

    /** Vuelve al selector de caja. Solo sin turno abierto (la caja queda fija al turno) y con el carrito vacío. */
    public function cambiarCaja(): void
    {
        if ($this->arqueoAbierto()?->caja_id !== null) {
            Notification::make()->title('Cierra tu turno en Arqueos de Caja antes de cambiar de caja.')->warning()->send();

            return;
        }

        if (! empty($this->carrito)) {
            Notification::make()->title('Cobra o cancela la venta en curso antes de cambiar de caja.')->warning()->send();

            return;
        }

        $this->limpiarDisplay();
        $this->cajaId = null;
    }

    /** El turno sirve para vender en esta pantalla solo si es de la caja seleccionada. */
    public function turnoEnCajaSeleccionada(): bool
    {
        $arqueo = $this->arqueoAbierto();

        return $arqueo !== null && $this->cajaId !== null && $arqueo->caja_id === $this->cajaId;
    }

    public function abrirCaja(string $fondoInicial): void
    {
        $caja = $this->cajaSeleccionada();

        if ($caja === null) {
            Notification::make()->title('Selecciona una caja primero.')->danger()->send();

            return;
        }

        try {
            app(ArqueoCajaService::class)->abrir($fondoInicial, auth()->id(), $this->empresa(), $caja);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title("Turno abierto en {$caja->nombre}")->success()->send();
    }

    // ---------------------------------------------------------------- Grid de productos

    /** Solo categorías activas que tengan al menos un producto activo de la empresa. */
    public function categorias(): Collection
    {
        return Categoria::query()
            ->where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->whereHas('productos', fn (Builder $q) => $q->where('empresa_id', $this->empresaId())->where('activo', true))
            ->orderBy('nombre')
            ->get();
    }

    public function seleccionarCategoria(?int $categoriaId): void
    {
        $this->categoriaId = $categoriaId;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function productosGrid(): Collection
    {
        return Producto::query()
            ->where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->when($this->categoriaId !== null, fn (Builder $q) => $q->where('categoria_id', $this->categoriaId))
            ->with(['presentaciones' => fn ($q) => $q->where('activa', true)->where('es_base', true)])
            ->orderBy('nombre')
            ->limit(60)
            ->get()
            ->map(function (Producto $producto) {
                $esPesado = $producto->tipo_venta === TipoVenta::PESADO;
                $precio = $esPesado
                    ? $producto->precio_por_peso
                    : ($producto->presentaciones->first()?->precio ?? $producto->precio);

                return [
                    'id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'precio_texto' => number_format((float) $precio, 2).($esPesado ? ' / '.$producto->unidad_base : ''),
                    'pesado' => $esPesado,
                    // Con "vender sin stock" activo el botón no se bloquea (al tocarlo sale el aviso).
                    'agotado' => $producto->controla_stock && (float) $producto->stock <= 0 && ! $this->permiteVenderSinStock(),
                ];
            });
    }

    /**
     * Tap en un producto del grid. $cantidad es lo que haya tecleado el cajero en el teclado
     * numérico ANTES de tocarlo (convención de POS: "3" + producto = 3 unidades). Para productos
     * por peso es obligatorio: es el peso.
     */
    public function tocarProducto(int $productoId, ?string $cantidad = null): void
    {
        $producto = Producto::query()
            ->where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->find($productoId);

        if ($producto === null) {
            return;
        }

        $cantidad = filled($cantidad) ? (float) $cantidad : null;

        if ($producto->tipo_venta === TipoVenta::PESADO) {
            if ($cantidad === null || $cantidad <= 0) {
                Notification::make()
                    ->title("«{$producto->nombre}» se vende por peso")
                    ->body("Teclea el peso ({$producto->unidad_base}) en el teclado numérico y vuelve a tocar el producto.")
                    ->warning()
                    ->send();

                return;
            }

            $antes = count($this->carrito);
            $this->agregarProductoPorPeso($producto->id, (string) $cantidad);

            if (count($this->carrito) > $antes) {
                $this->lineaSeleccionada = array_key_last($this->carrito);
                $this->dispatch('item-agregado');
            }

            return;
        }

        if (! $this->validarProductoParaVenta($producto)) {
            return;
        }

        $this->agregarLineaContable($producto, $producto->presentacionBase());
        $indice = $this->indiceDeLinea($producto->id, $producto->presentacionBase()?->id);

        if ($indice !== null && $cantidad !== null && (int) floor($cantidad) > 1) {
            // agregarLineaContable() ya sumó 1: se suman las restantes.
            $this->carrito[$indice]['cantidad'] = (int) $this->carrito[$indice]['cantidad'] + (int) floor($cantidad) - 1;
            $this->recalcularTotales();
        }

        $this->lineaSeleccionada = $indice;
        $this->dispatch('item-agregado');
    }

    private function indiceDeLinea(int $productoId, ?int $presentacionId): ?int
    {
        foreach ($this->carrito as $indice => $linea) {
            if ($linea['producto_id'] === $productoId && ($linea['presentacion_id'] ?? null) === $presentacionId) {
                return $indice;
            }
        }

        return null;
    }

    public function escanearOBuscar(?string $texto = null): void
    {
        $antes = count($this->carrito);
        $totalAntes = $this->totales['total'] ?? '0.00';

        parent::escanearOBuscar($texto);

        if (count($this->carrito) !== $antes || ($this->totales['total'] ?? '0.00') !== $totalAntes) {
            $this->lineaSeleccionada = array_key_last($this->carrito);
            $this->dispatch('item-agregado');
        }
    }

    // ---------------------------------------------------------------- Líneas (teclado numérico)

    public function seleccionarLinea(int $indice): void
    {
        $this->lineaSeleccionada = isset($this->carrito[$indice]) ? $indice : null;
    }

    public function establecerCantidadLinea(int $indice, string $valor): void
    {
        if (! isset($this->carrito[$indice])) {
            return;
        }

        $esPesado = ($this->carrito[$indice]['tipo_venta'] ?? null) === TipoVenta::PESADO->value;
        $cantidad = $esPesado ? round((float) $valor, 3) : (int) floor((float) $valor);

        if ($cantidad <= 0) {
            $this->quitarLinea($indice);

            return;
        }

        $this->carrito[$indice]['cantidad'] = $esPesado ? (string) $cantidad : $cantidad;
        $this->recalcularTotales();
    }

    public function sumarALinea(int $indice, int $delta): void
    {
        if (! isset($this->carrito[$indice]) || ($this->carrito[$indice]['tipo_venta'] ?? null) === TipoVenta::PESADO->value) {
            return;
        }

        $this->establecerCantidadLinea($indice, (string) ((int) $this->carrito[$indice]['cantidad'] + $delta));
    }

    /** Descuento en pesos de la línea; nunca negativo ni mayor que precio × cantidad. */
    public function establecerDescuentoLinea(int $indice, string $valor): void
    {
        if (! isset($this->carrito[$indice])) {
            return;
        }

        $linea = $this->carrito[$indice];
        $bruto = bcmul((string) $linea['precio_unitario'], (string) $linea['cantidad'], 2);
        $descuento = bcadd((string) max(0, (float) $valor), '0', 2);

        if (bccomp($descuento, $bruto, 2) >= 0) {
            Notification::make()->title('El descuento no puede ser igual o mayor que el importe de la línea.')->danger()->send();

            return;
        }

        $this->carrito[$indice]['descuento'] = $descuento;
        $this->recalcularTotales();
    }

    public function quitarLinea(int $indice): void
    {
        parent::quitarLinea($indice);
        $this->lineaSeleccionada = null;
    }

    public function cancelarVenta(): void
    {
        $this->carrito = [];
        $this->descuentoId = '';
        $this->busquedaProducto = '';
        $this->lineaSeleccionada = null;
        $this->quitarCliente();
        $this->recalcularTotales();
    }

    // ---------------------------------------------------------------- Cobro

    /**
     * El modal de cobro del POS táctil solo ofrece Consumo y Crédito Fiscal (electrónicos o
     * físicos, según la empresa): notas de crédito/débito, regímenes especiales, etc. siguen en
     * Facturación.
     *
     * @return array<string, string>
     */
    public function tiposComprobante(): array
    {
        return collect(parent::tiposComprobante())
            ->filter(fn (string $etiqueta, string $valor) => in_array($valor, self::TIPOS_PERMITIDOS, true))
            ->all();
    }

    public function puedeCobrar(): bool
    {
        return $this->cajaSeleccionada() !== null
            && $this->turnoEnCajaSeleccionada()
            && parent::puedeCobrar();
    }

    public function cobrar(): void
    {
        if ($this->cajaSeleccionada() === null || ! $this->turnoEnCajaSeleccionada()) {
            Notification::make()->title('Selecciona tu caja y abre el turno antes de cobrar.')->danger()->send();

            return;
        }

        // Un tipo fuera de la lista del modal (manipulado desde el navegador) no se cobra aquí. Se
        // compara contra la lista fija, no contra tiposComprobante(): si la secuencia del tipo
        // elegido se agotó durante el turno, VentaService/SecuenciaNcfService dan el error real.
        if (! in_array($this->tipoComprobante, self::TIPOS_PERMITIDOS, true)) {
            Notification::make()->title('Tipo de comprobante no válido para el POS táctil.')->danger()->send();

            return;
        }

        parent::cobrar();

        if (empty($this->carrito)) {
            $this->lineaSeleccionada = null;
            $this->quitarCliente();
            $this->dispatch('venta-cobrada');
        }
    }

    protected function datosAdicionalesVenta(): array
    {
        return ['caja_id' => $this->cajaSeleccionada()?->id];
    }

    protected function notificarVentaRegistradaEImprimirTicket(Venta $venta): void
    {
        $this->publicarEnDisplay(gracias: (string) $venta->total);

        parent::notificarVentaRegistradaEImprimirTicket($venta);
    }

    // ---------------------------------------------------------------- Display del cliente

    protected function recalcularTotales(): void
    {
        parent::recalcularTotales();

        if ($this->lineaSeleccionada !== null && ! isset($this->carrito[$this->lineaSeleccionada])) {
            $this->lineaSeleccionada = null;
        }

        $this->publicarEnDisplay();
    }

    /**
     * Publica el carrito en curso para el display del cliente de la caja seleccionada. Solo
     * viajan datos que el cliente ya ve en la caja (descripción, cantidades, precios, totales):
     * nada de stock, costos ni ids internos, porque el display es una URL pública por token.
     */
    protected function publicarEnDisplay(?string $gracias = null): void
    {
        if ($this->cajaId === null) {
            return;
        }

        $caja = $this->cajaSeleccionada();

        if ($caja === null) {
            return;
        }

        $totales = $this->totales ?: $this->totalesVacios();

        Cache::put($caja->claveCacheDisplay(), [
            'estado' => $gracias !== null ? 'gracias' : (empty($this->carrito) ? 'libre' : 'venta'),
            'items' => collect($this->carrito)->map(fn (array $linea, int $indice) => [
                'clave' => $indice.'-'.$linea['producto_id'].'-'.($linea['presentacion_id'] ?? 'x'),
                'nombre' => $linea['nombre'],
                'cantidad' => ($linea['tipo_venta'] ?? null) === TipoVenta::PESADO->value
                    ? number_format((float) $linea['cantidad'], 3).' '.$linea['unidad_base']
                    : (string) (int) $linea['cantidad'],
                'precio' => (string) $linea['precio_unitario'],
                'descuento' => (string) $linea['descuento'],
                'importe' => $this->subtotalLinea($linea),
            ])->values()->all(),
            'subtotal' => $totales['subtotal'],
            'descuento' => $totales['descuento'],
            'itbis' => $totales['total_itbis'],
            'total' => $gracias ?? $totales['total'],
            'cajero' => auth()->user()?->name,
            'updated_at' => now()->timestamp,
        ], now()->addHour());
    }

    protected function limpiarDisplay(): void
    {
        $caja = $this->cajaSeleccionada();

        if ($caja !== null) {
            Cache::forget($caja->claveCacheDisplay());
        }
    }
}
