<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\FormaPago;
use App\Enums\ModuloImpresion;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoCliente;
use App\Exceptions\SecuenciaNcfAgotadaException;
use App\Exceptions\StockInsuficienteException;
use App\Exceptions\VentaInvalidaException;
use App\Models\ArqueoCaja;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\ArqueoCajaService;
use App\Services\Dgii\ConsultaContribuyenteService;
use App\Services\Impresion\ImpresionService;
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
use RuntimeException;
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

    public string $formaPago = 'efectivo';

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

    /**
     * Busca el documento tecleado en busquedaCliente contra la DGII/JCE (vía el PAC); si lo
     * encuentra, crea (o reutiliza, si ya existe con ese documento) el Cliente y lo selecciona.
     */
    public function buscarClienteEnDgii(): void
    {
        $resultado = app(ConsultaContribuyenteService::class)->buscar($this->busquedaCliente);

        if ($resultado === null) {
            Notification::make()
                ->title('No encontrado')
                ->body('Ingresa un RNC (9 dígitos) o Cédula (11 dígitos) válido y registrado en la DGII/JCE.')
                ->warning()
                ->send();

            return;
        }

        $cliente = Cliente::query()->firstOrCreate(
            ['documento' => $resultado['documento']],
            ['nombre' => $resultado['nombre'], 'tipo_documento' => $resultado['tipo'], 'activo' => true],
        );

        $this->clienteId = $cliente->id;
        $this->busquedaCliente = '';

        Notification::make()->title("Cliente cargado desde la DGII/JCE: {$cliente->nombre}")->success()->send();
    }

    /**
     * Crédito Fiscal (31) siempre exige RNC del comprador; Consumo (32) lo exige en vivo en
     * cuanto el total del carrito cruza Venta::UMBRAL_CONSUMO. Misma regla que
     * VentaService::registrar()/EcfBuilder (Venta::requiereComprador), evaluada aquí sobre una
     * Venta "en memoria" con el tipo y el total actuales del carrito.
     */
    public function requiereRncComprador(): bool
    {
        if (blank($this->tipoComprobante)) {
            return false;
        }

        return (new Venta([
            'tipo_comprobante' => $this->tipoComprobante,
            'total' => $this->totales['total'] ?? '0.00',
        ]))->requiereComprador();
    }

    public function faltaRncComprador(): bool
    {
        return $this->requiereRncComprador() && blank($this->clienteSeleccionado()?->documento);
    }

    /** Mismo mensaje (y motivo) que bloquearía VentaService::registrar() al intentar cobrar. */
    public function mensajeFaltaRncComprador(): ?string
    {
        if (! $this->faltaRncComprador()) {
            return null;
        }

        return $this->tipoComprobante === TipoComprobante::FACTURA_CREDITO_FISCAL->value
            ? 'La Factura de Crédito Fiscal (e-CF 31) requiere el RNC del comprador. Cambia el cliente o búscalo por RNC en la DGII abajo.'
            : 'Para facturas de consumo de RD$250,000 o más, el cliente con RNC/Cédula es obligatorio. Cambia el cliente o búscalo por RNC en la DGII abajo.';
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

    /** @return array<string, string> */
    public function formasPago(): array
    {
        return collect(FormaPago::cases())
            ->mapWithKeys(fn (FormaPago $forma) => [$forma->value => $forma->etiqueta()])
            ->all();
    }

    /** Turno de caja abierto del usuario actual, si tiene uno. Lookup fresco, sin cachear. */
    public function arqueoAbierto(): ?ArqueoCaja
    {
        return app(ArqueoCajaService::class)->arqueoAbiertoDe(auth()->id());
    }

    public function puedeCobrar(): bool
    {
        return $this->arqueoAbierto() !== null
            && $this->clienteId !== null
            && ! empty($this->carrito)
            && ! $this->hayLineasConStockInsuficiente()
            && ! $this->faltaRncComprador()
            && collect($this->carrito)->every(fn (array $linea) => (float) $linea['cantidad'] > 0);
    }

    public function abrirCaja(string $fondoInicial): void
    {
        try {
            app(ArqueoCajaService::class)->abrir($fondoInicial, auth()->id());
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Caja abierta')->success()->send();
    }

    public function cerrarCaja(string $efectivoContado, ?string $notas = null): void
    {
        $arqueo = $this->arqueoAbierto();

        if ($arqueo === null) {
            return;
        }

        try {
            app(ArqueoCajaService::class)->cerrar($arqueo, $efectivoContado, $notas, auth()->id());
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Caja cerrada')->success()->send();
    }

    public function cobrar(): void
    {
        if ($this->arqueoAbierto() === null) {
            Notification::make()->title('Debes abrir la caja antes de cobrar.')->danger()->send();

            return;
        }

        if (! $this->puedeCobrar()) {
            $mensaje = $this->mensajeFaltaRncComprador() ?? 'Revisa el carrito antes de cobrar: cliente, líneas y stock.';

            Notification::make()->title($mensaje)->danger()->send();

            return;
        }

        try {
            $venta = app(VentaService::class)->registrar([
                'cliente_id' => $this->clienteId,
                'user_id' => auth()->id(),
                'tipo_comprobante' => $this->tipoComprobante,
                'descuento_global' => $this->descuentoGlobal,
                'forma_pago' => $this->formaPago,
                'arqueo_caja_id' => $this->arqueoAbierto()?->id,
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

        $this->notificarVentaRegistradaEImprimirTicket($venta);
    }

    /**
     * La venta ya quedó registrada (atómica, vía VentaService) antes de llegar aquí: un fallo de
     * impresión de aquí en adelante NUNCA la revierte ni la afecta, solo se notifica.
     *
     * RED -> el servidor manda los bytes ESC/POS directo al socket, sin diálogo. NAVEGADOR (o sin
     * impresora configurada) -> el navegador decide la impresora física, así que solo podemos
     * abrir la vista del ticket y dejar que window.print() (en la propia vista) dispare el diálogo.
     */
    private function notificarVentaRegistradaEImprimirTicket(Venta $venta): void
    {
        $impresora = app(ImpresionService::class)->resolverImpresora(ModuloImpresion::FACTURACION, auth()->user());
        $resultado = app(ImpresionService::class)->imprimirTicket($venta, $impresora);

        $accionComprobante = Action::make('comprobante')
            ->label('Comprobante PDF')
            ->url(route('ventas.pdf', $venta), shouldOpenInNewTab: true)
            ->button();

        if ($resultado['modo'] === 'navegador') {
            $this->dispatch('abrir-ticket', url: $resultado['url']);

            Notification::make()
                ->title("Venta {$venta->ncf} registrada")
                ->body($impresora === null
                    ? 'No hay impresora configurada para Facturación: se abrió el ticket para imprimir desde el navegador.'
                    : null)
                ->success()
                ->actions([
                    Action::make('ticket')->label('Imprimir ticket de nuevo')->url($resultado['url'], shouldOpenInNewTab: true)->button(),
                    $accionComprobante,
                ])
                ->send();

            return;
        }

        if ($resultado['exito']) {
            Notification::make()
                ->title("Venta {$venta->ncf} registrada")
                ->body('Ticket impreso.')
                ->success()
                ->actions([$accionComprobante])
                ->send();

            return;
        }

        Notification::make()
            ->title("Venta {$venta->ncf} registrada, pero el ticket no se pudo imprimir")
            ->body($resultado['error'])
            ->danger()
            ->actions([
                Action::make('ticketNavegador')
                    ->label('Reintentar por navegador')
                    ->url($resultado['url'], shouldOpenInNewTab: true)
                    ->button(),
                $accionComprobante,
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
