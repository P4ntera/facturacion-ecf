<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\FormaPago;
use App\Enums\Modulo;
use App\Enums\ModuloImpresion;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoPago;
use App\Exceptions\SecuenciaNcfAgotadaException;
use App\Exceptions\StockInsuficienteException;
use App\Exceptions\VentaInvalidaException;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Models\ArqueoCaja;
use App\Models\Cliente;
use App\Models\Descuento;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\ArqueoCajaService;
use App\Services\Dgii\ConsultaContribuyenteService;
use App\Services\Impresion\ImpresionService;
use App\Services\SecuenciaNcfService;
use App\Services\VentaService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;
use UnitEnum;

class PuntoDeVenta extends Page
{
    use RestringidoPorModulo;

    protected string $view = 'filament.pages.punto-de-venta';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Facturación';

    protected static ?string $title = 'Facturación';

    public ?int $clienteId = null;

    public string $tipoComprobante = '';

    public string $busquedaCliente = '';

    public string $busquedaProducto = '';

    /** @var array<int, array<string, mixed>> */
    public array $carrito = [];

    /** Id (como string) del Descuento elegido en el <select>; '' = sin descuento. */
    public string $descuentoId = '';

    /**
     * Monto en pesos del descuento global, calculado a partir de $descuentoId y el subtotal del
     * carrito (ver recalcularTotales()). Ya no es un input libre: el usuario elige un Descuento
     * configurado por el Administrador (Mantenimiento de Descuentos) y esto se deriva solo.
     */
    public string $descuentoGlobal = '0.00';

    public string $formaPago = 'efectivo';

    public bool $ventaACredito = false;

    public string $fechaLimitePago = '';

    /** @var array<string, string> */
    public array $totales = [];

    public static function modulo(): Modulo
    {
        return Modulo::VENTAS_POS;
    }

    public static function puedeAccederPorPermiso(): bool
    {
        return auth()->user()?->can('facturacion.acceder') ?? false;
    }

    public function mount(): void
    {
        $this->tipoComprobante = $this->empresa()->config()->tipo_comprobante_defecto;
        $this->clienteId = $this->clienteConsumidorFinal()->id;
        $this->recalcularTotales();
    }

    public function updated(string $name): void
    {
        if ($name === 'descuentoId' || str($name)->startsWith('carrito.')) {
            $this->recalcularTotales();
        }
    }

    /**
     * El POS es una página propia (no un Resource ni un ->relationship() de Filament), así que
     * sus consultas directas a Cliente/Producto no heredan el scoping automático por tenant:
     * hay que filtrar por empresa_id explícitamente en cada una (ver docs/estilos.md... el
     * comentario real: PASO 5 del prompt de tenancy — este es justo el punto que advertía que
     * se filtran datos entre empresas si se olvida).
     */
    public function usaEcf(): bool
    {
        return $this->empresa()->usaEcf();
    }

    protected function empresa(): Empresa
    {
        /** @var Empresa */
        return Filament::getTenant();
    }

    protected function empresaId(): int
    {
        return $this->empresa()->id;
    }

    public function clienteSeleccionado(): ?Cliente
    {
        return $this->clienteId
            ? Cliente::query()->where('empresa_id', $this->empresaId())->find($this->clienteId)
            : null;
    }

    /** @return Collection<int, Cliente> */
    public function clientesSugeridos(): Collection
    {
        if (blank($this->busquedaCliente)) {
            return collect();
        }

        return Cliente::query()
            ->where('empresa_id', $this->empresaId())
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
        $resultado = app(ConsultaContribuyenteService::class)->buscar($this->busquedaCliente, $this->empresa());

        if ($resultado === null) {
            Notification::make()
                ->title('No encontrado')
                ->body('Ingresa un RNC (9 dígitos) o Cédula (11 dígitos) válido y registrado en la DGII/JCE.')
                ->warning()
                ->send();

            return;
        }

        // documento no es único entre empresas: sin empresa_id en la búsqueda, esto podría
        // encontrar (y reutilizar) el cliente de OTRA empresa con el mismo documento.
        $cliente = Cliente::query()->firstOrCreate(
            ['empresa_id' => $this->empresaId(), 'documento' => $resultado['documento']],
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
        if (blank($this->tipoComprobante) || ! $this->usaEcf()) {
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
            ->where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->where(fn (Builder $q) => $q
                ->where('codigo', 'ilike', "%{$this->busquedaProducto}%")
                ->orWhere('nombre', 'ilike', "%{$this->busquedaProducto}%"))
            ->orderBy('nombre')
            ->limit(10)
            ->get();
    }

    /**
     * Un lector de código de barras funciona como un teclado: "teclea" el código muy rápido y
     * termina con Enter — no hace falta driver ni integración especial, basta con escuchar el
     * mismo evento que dispararía un cajero al terminar de escribir a mano (wire:keydown.enter
     * en el buscador). Si el texto coincide EXACTO con el código de barras o el código de un
     * producto, se agrega directo al carrito y el campo queda listo para el siguiente escaneo.
     * Si no hay coincidencia exacta, no se hace nada más: la búsqueda por nombre/código de abajo
     * ya es reactiva sola (wire:model.live) y sigue mostrando resultados sin que esto interfiera.
     */
    public function escanearOBuscar(): void
    {
        $texto = trim($this->busquedaProducto);

        if ($texto === '') {
            return;
        }

        $producto = Producto::query()
            ->where('empresa_id', $this->empresaId())
            ->where(fn (Builder $q) => $q->where('codigo_barra', 'ilike', $texto)->orWhere('codigo', 'ilike', $texto))
            ->first();

        if ($producto === null) {
            return;
        }

        if (! $producto->activo) {
            Notification::make()->title("«{$producto->nombre}» está inactivo y no se puede vender")->danger()->send();

            return;
        }

        if ($producto->controla_stock && (float) $producto->stock <= 0) {
            Notification::make()->title("«{$producto->nombre}» no tiene stock disponible")->danger()->send();

            return;
        }

        $this->agregarProducto($producto->id);
        $this->dispatch('producto-escaneado');
    }

    public function agregarProducto(int $productoId): void
    {
        $producto = Producto::query()
            ->where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->find($productoId);

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

        return (float) (Producto::query()->where('empresa_id', $this->empresaId())->find($linea['producto_id'])?->stock ?? 0);
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
        if (blank($this->tipoComprobante) || ! $this->usaEcf()) {
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

    /** @return Collection<int, Descuento> */
    public function descuentosDisponibles(): Collection
    {
        return Descuento::query()
            ->where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Re-valida $descuentoId contra empresa_id/activo en cada uso (no confía en lo que llegó del
     * navegador): un id de otra empresa, o de un descuento ya desactivado, simplemente no aplica
     * ningún descuento en vez de filtrar datos entre empresas.
     */
    public function descuentoSeleccionado(): ?Descuento
    {
        if (blank($this->descuentoId)) {
            return null;
        }

        return Descuento::query()
            ->where('empresa_id', $this->empresaId())
            ->where('activo', true)
            ->find((int) $this->descuentoId);
    }

    /** Turno de caja abierto del usuario actual, si tiene uno. Lookup fresco, sin cachear. */
    public function arqueoAbierto(): ?ArqueoCaja
    {
        return app(ArqueoCajaService::class)->arqueoAbiertoDe(auth()->id(), $this->empresa());
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
            app(ArqueoCajaService::class)->abrir($fondoInicial, auth()->id(), $this->empresa());
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Caja abierta')->success()->send();
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
                'tipo_pago' => $this->ventaACredito ? TipoPago::CREDITO->value : TipoPago::CONTADO->value,
                'fecha_limite_pago' => $this->ventaACredito && filled($this->fechaLimitePago) ? $this->fechaLimitePago : null,
                'lineas' => $this->lineasParaService(),
            ], $this->empresa());
        } catch (VentaInvalidaException|StockInsuficienteException|SecuenciaNcfAgotadaException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->carrito = [];
        $this->descuentoId = '';
        $this->descuentoGlobal = '0.00';
        $this->busquedaProducto = '';
        $this->ventaACredito = false;
        $this->fechaLimitePago = '';
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
    protected function notificarVentaRegistradaEImprimirTicket(Venta $venta): void
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

    protected function recalcularTotales(): void
    {
        if (empty($this->carrito)) {
            $this->totales = $this->totalesVacios();
            $this->descuentoGlobal = '0.00';

            return;
        }

        try {
            $lineas = $this->lineasParaService();

            // El % del descuento seleccionado se aplica sobre el subtotal (ya con descuentos de
            // línea aplicados, antes de ITBIS): primero se necesita ese subtotal sin descuento
            // global para poder calcular el monto en pesos que se le pasa a VentaService, que
            // sigue trabajando con un monto fijo (descuento_global), no con un porcentaje.
            $sinDescuento = app(VentaService::class)->previsualizar(['descuento_global' => '0', 'lineas' => $lineas], $this->empresa());
            $this->descuentoGlobal = $this->calcularMontoDescuento($sinDescuento['subtotal']);

            $this->totales = app(VentaService::class)->previsualizar([
                'descuento_global' => $this->descuentoGlobal,
                'lineas' => $lineas,
            ], $this->empresa());
        } catch (VentaInvalidaException) {
            $this->totales = $this->totalesVacios();
            $this->descuentoGlobal = '0.00';
        }
    }

    private function calcularMontoDescuento(string $subtotal): string
    {
        $descuento = $this->descuentoSeleccionado();

        if ($descuento === null) {
            return '0.00';
        }

        return bcdiv(bcmul($subtotal, (string) $descuento->porcentaje, 4), '100', 2);
    }

    /** @return array<string, string> */
    protected function totalesVacios(): array
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
    protected function lineasParaService(): array
    {
        return collect($this->carrito)->map(fn (array $linea) => [
            'producto_id' => $linea['producto_id'],
            'cantidad' => (float) $linea['cantidad'],
            'precio_unitario' => $linea['precio_unitario'],
            'descuento' => $linea['descuento'],
        ])->all();
    }

    protected function clienteConsumidorFinal(): Cliente
    {
        // Sin empresa_id en la búsqueda, todas las empresas colisionarían en el mismo
        // "Consumidor Final" (nombre no es único): cada una necesita el suyo propio.
        return Cliente::query()->firstOrCreate(
            ['empresa_id' => $this->empresaId(), 'nombre' => 'Consumidor Final'],
            ['tipo_documento' => TipoDocumentoCliente::SIN_DOCUMENTO, 'activo' => true],
        );
    }
}
