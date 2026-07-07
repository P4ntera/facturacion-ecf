<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoCliente;
use App\Exceptions\SecuenciaNcfAgotadaException;
use App\Exceptions\StockInsuficienteException;
use App\Exceptions\VentaInvalidaException;
use App\Models\Cliente;
use App\Models\Producto;
use App\Services\SecuenciaNcfService;
use App\Services\VentaService;
use App\Settings\FacturacionSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

class PuntoDeVenta extends Page
{
    protected string $view = 'filament.pages.punto-de-venta';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?string $navigationLabel = 'Punto de Venta';

    protected static ?string $title = 'Punto de Venta';

    public ?int $clienteId = null;

    public string $tipoComprobante = '';

    public string $busquedaCliente = '';

    public string $busquedaProducto = '';

    /** @var array<int, array<string, mixed>> */
    public array $carrito = [];

    public string $descuentoGlobal = '0.00';

    /** @var array<string, string> */
    public array $totales = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('registrar_ventas') ?? false;
    }

    public function mount(): void
    {
        $this->tipoComprobante = app(FacturacionSettings::class)->tipo_comprobante_defecto;
        $this->clienteId = $this->clienteConsumidorFinal()->id;
        $this->recalcularTotales();
    }

    public function updated(string $name): void
    {
        if ($name === 'descuentoGlobal' || str($name)->startsWith('carrito.')) {
            $this->recalcularTotales();
        }
    }

    public function clienteSeleccionado(): ?Cliente
    {
        return $this->clienteId ? Cliente::find($this->clienteId) : null;
    }

    /** @return Collection<int, Cliente> */
    public function clientesSugeridos(): Collection
    {
        if (blank($this->busquedaCliente)) {
            return collect();
        }

        return Cliente::query()
            ->where('activo', true)
            ->where(fn (Builder $q) => $q
                ->where('nombre', 'ilike', "%{$this->busquedaCliente}%")
                ->orWhere('documento', 'ilike', "%{$this->busquedaCliente}%"))
            ->orderBy('nombre')
            ->limit(10)
            ->get();
    }

    public function seleccionarCliente(int $clienteId): void
    {
        $this->clienteId = $clienteId;
        $this->busquedaCliente = '';
    }

    public function seleccionarConsumidorFinal(): void
    {
        $this->clienteId = $this->clienteConsumidorFinal()->id;
        $this->busquedaCliente = '';
    }

    public function quitarCliente(): void
    {
        $this->clienteId = null;
    }

    /** @return Collection<int, Producto> */
    public function productosSugeridos(): Collection
    {
        if (blank($this->busquedaProducto)) {
            return collect();
        }

        return Producto::query()
            ->where('activo', true)
            ->where(fn (Builder $q) => $q
                ->where('codigo', 'ilike', "%{$this->busquedaProducto}%")
                ->orWhere('nombre', 'ilike', "%{$this->busquedaProducto}%"))
            ->orderBy('nombre')
            ->limit(10)
            ->get();
    }

    public function agregarProducto(int $productoId): void
    {
        $producto = Producto::query()->where('activo', true)->find($productoId);

        if ($producto === null) {
            return;
        }

        foreach ($this->carrito as $indice => $linea) {
            if ($linea['producto_id'] === $productoId) {
                $this->carrito[$indice]['cantidad']++;
                $this->busquedaProducto = '';
                $this->recalcularTotales();

                return;
            }
        }

        $this->carrito[] = [
            'producto_id' => $producto->id,
            'codigo' => $producto->codigo,
            'nombre' => $producto->nombre,
            'precio_unitario' => (string) $producto->precio,
            'cantidad' => 1,
            'descuento' => '0.00',
            'controla_stock' => $producto->controla_stock,
        ];

        $this->busquedaProducto = '';
        $this->recalcularTotales();
    }

    public function quitarLinea(int $indice): void
    {
        unset($this->carrito[$indice]);
        $this->carrito = array_values($this->carrito);
        $this->recalcularTotales();
    }

    public function stockDeLinea(array $linea): ?float
    {
        if (! $linea['controla_stock']) {
            return null;
        }

        return (float) (Producto::find($linea['producto_id'])?->stock ?? 0);
    }

    public function lineaConStockInsuficiente(array $linea): bool
    {
        $stock = $this->stockDeLinea($linea);

        return $stock !== null && (float) $linea['cantidad'] > $stock;
    }

    public function hayLineasConStockInsuficiente(): bool
    {
        foreach ($this->carrito as $linea) {
            if ($this->lineaConStockInsuficiente($linea)) {
                return true;
            }
        }

        return false;
    }

    public function subtotalLinea(array $linea): string
    {
        return bcsub(bcmul((string) $linea['precio_unitario'], (string) $linea['cantidad'], 2), (string) $linea['descuento'], 2);
    }

    public function proximoNcf(): ?string
    {
        if (blank($this->tipoComprobante)) {
            return null;
        }

        return app(SecuenciaNcfService::class)->previsualizarSiguiente(TipoComprobante::from($this->tipoComprobante));
    }

    /** @return array<string, string> */
    public function tiposComprobante(): array
    {
        return collect(TipoComprobante::cases())
            ->mapWithKeys(fn (TipoComprobante $tipo) => [$tipo->value => "{$tipo->value} — {$tipo->etiqueta()}"])
            ->all();
    }

    public function puedeCobrar(): bool
    {
        return $this->clienteId !== null
            && ! empty($this->carrito)
            && ! $this->hayLineasConStockInsuficiente()
            && collect($this->carrito)->every(fn (array $linea) => (float) $linea['cantidad'] > 0);
    }

    public function cobrar(): void
    {
        if (! $this->puedeCobrar()) {
            Notification::make()
                ->title('Revisa el carrito antes de cobrar: cliente, líneas y stock.')
                ->danger()
                ->send();

            return;
        }

        try {
            $venta = app(VentaService::class)->registrar([
                'cliente_id' => $this->clienteId,
                'user_id' => auth()->id(),
                'tipo_comprobante' => $this->tipoComprobante,
                'descuento_global' => $this->descuentoGlobal,
                'lineas' => $this->lineasParaService(),
            ]);
        } catch (VentaInvalidaException|StockInsuficienteException|SecuenciaNcfAgotadaException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->carrito = [];
        $this->descuentoGlobal = '0.00';
        $this->busquedaProducto = '';
        $this->recalcularTotales();

        Notification::make()
            ->title("Venta {$venta->ncf} registrada")
            ->success()
            ->actions([
                Action::make('imprimir')
                    ->label('Imprimir comprobante')
                    ->url(route('ventas.pdf', $venta), shouldOpenInNewTab: true)
                    ->button(),
            ])
            ->send();
    }

    private function recalcularTotales(): void
    {
        if (empty($this->carrito)) {
            $this->totales = $this->totalesVacios();

            return;
        }

        try {
            $this->totales = app(VentaService::class)->previsualizar([
                'descuento_global' => $this->descuentoGlobal,
                'lineas' => $this->lineasParaService(),
            ]);
        } catch (VentaInvalidaException) {
            $this->totales = $this->totalesVacios();
        }
    }

    /** @return array<string, string> */
    private function totalesVacios(): array
    {
        return [
            'subtotal' => '0.00',
            'descuento' => '0.00',
            'monto_gravado_18' => '0.00',
            'monto_gravado_16' => '0.00',
            'monto_gravado_0' => '0.00',
            'itbis_18' => '0.00',
            'itbis_16' => '0.00',
            'total_itbis' => '0.00',
            'total' => '0.00',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function lineasParaService(): array
    {
        return collect($this->carrito)->map(fn (array $linea) => [
            'producto_id' => $linea['producto_id'],
            'cantidad' => (float) $linea['cantidad'],
            'precio_unitario' => $linea['precio_unitario'],
            'descuento' => $linea['descuento'],
        ])->all();
    }

    private function clienteConsumidorFinal(): Cliente
    {
        return Cliente::query()->firstOrCreate(
            ['nombre' => 'Consumidor Final'],
            ['tipo_documento' => TipoDocumentoCliente::SIN_DOCUMENTO, 'activo' => true],
        );
    }
}
