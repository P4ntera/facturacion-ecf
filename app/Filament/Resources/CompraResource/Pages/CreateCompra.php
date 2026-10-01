<?php

namespace App\Filament\Resources\CompraResource\Pages;

use App\Enums\TipoComprobante;
use App\Exceptions\StockInsuficienteException;
use App\Filament\Resources\CompraResource;
use App\Models\Compra;
use App\Models\PedidoCompra;
use App\Services\CompraService;
use App\Services\CostoPrecioService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use RuntimeException;

class CreateCompra extends CreateRecord
{
    protected static string $resource = CompraResource::class;

    /** ?pedido={id}: viene de "Recibir" en Pedidos de Compra. */
    #[Url(as: 'pedido')]
    public ?string $pedidoId = null;

    public function mount(): void
    {
        parent::mount();

        if (blank($this->pedidoId)) {
            return;
        }

        // El id viene de la URL: solo pedidos pendientes de la empresa activa. Si no aplica, se
        // avisa y queda el formulario vacío en vez de prellenar con datos ajenos.
        $pedido = PedidoCompra::query()
            ->where('empresa_id', Filament::getTenant()->id)
            ->with('detalles')
            ->find($this->pedidoId);

        if ($pedido === null || ! $pedido->estaPendiente()) {
            Notification::make()->title('El pedido de compra no existe o ya no está pendiente.')->warning()->send();

            return;
        }

        $lineas = [];
        foreach ($pedido->detalles as $detalle) {
            $lineas[(string) Str::uuid()] = [
                'producto_id'    => $detalle->producto_id,
                'cantidad'       => (float) $detalle->cantidad,
                'costo_unitario' => (float) $detalle->costo_unitario,
            ];
        }

        $this->form->fill([
            ...$this->data,
            'pedido_compra_id' => $pedido->id,
            'proveedor_id'     => $pedido->proveedor_id,
            'lineas'           => $lineas,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verListado')
                ->label('Ver todas las compras')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->modalHeading('Compras registradas')
                ->modalWidth('7xl')
                ->modalSubmitAction(false)
                ->modalCancelAction(false)
                ->modalContent(fn () => view('filament.compras.listado-modal-content')),
        ];
    }

    public function getFooter(): ?View
    {
        return view('filament.compras.create-footer-script');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Compra registrada exitosamente';
    }

    /**
     * Toma los campos fijos de "Agregar producto" y los agrega como una nueva línea
     * a la tabla de detalle, luego limpia esos campos para la siguiente captura.
     * Se invoca al hacer clic en "Agregar" o al presionar Enter en el costo unitario.
     */
    public function agregarLinea(): void
    {
        $productoId    = $this->data['nueva_linea_producto_id'] ?? null;
        $cantidad      = $this->data['nueva_linea_cantidad'] ?? null;
        $costoUnitario = $this->data['nueva_linea_costo_unitario'] ?? null;

        if (! $productoId || ! $cantidad || $costoUnitario === null || $costoUnitario === '') {
            Notification::make()->title('Selecciona un producto e indica cantidad y costo.')->warning()->send();

            return;
        }

        $lineas = $this->data['lineas'] ?? [];
        $lineas[(string) Str::uuid()] = [
            'producto_id'    => $productoId,
            'cantidad'       => $cantidad,
            'costo_unitario' => $costoUnitario,
        ];

        $this->data['lineas'] = $lineas;
        $this->data['nueva_linea_producto_id']    = null;
        $this->data['nueva_linea_cantidad']       = 1;
        $this->data['nueva_linea_costo_unitario'] = null;
    }

    /**
     * Delega la creación completa (cabecera + detalles + inventario) a CompraService,
     * en vez del guardado Eloquent por defecto de Filament.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            $compra = app(CompraService::class)->crear([
                'proveedor_id'         => $data['proveedor_id'],
                'pedido_compra_id'     => $data['pedido_compra_id'] ?? null,
                'tipo_comprobante'     => filled($data['tipo_comprobante'] ?? null) ? TipoComprobante::from($data['tipo_comprobante']) : null,
                'ncf'                  => $data['ncf'] ?? null,
                'fecha'                => $data['fecha'],
                'itbis_incluido'       => $data['itbis_incluido'] ?? false,
                'monto_total_factura'  => $data['monto_total_factura'] ?? null,
                'tipo_pago'            => $data['tipo_pago'] ?? null,
                'fecha_vencimiento'    => $data['fecha_vencimiento'] ?? null,
                'lineas'               => $data['lineas'],
            ], auth()->id(), Filament::getTenant());
        } catch (RuntimeException|StockInsuficienteException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt();
        }

        $this->avisarPreciosSugeridos($compra);

        return $compra;
    }

    /**
     * Si la compra dejó productos con un precio sugerido distinto al actual (costo nuevo + % de
     * ganancia), avisa con un botón a "Revisar precios". Persistente: el formulario redirige y el
     * aviso tiene que seguir ahí.
     */
    private function avisarPreciosSugeridos(Compra $compra): void
    {
        if (! (auth()->user()?->can('productos.editar') ?? false)) {
            return;
        }

        $cantidad = app(CostoPrecioService::class)->sugerenciasParaCompra($compra)->count();

        if ($cantidad === 0) {
            return;
        }

        Notification::make()
            ->title($cantidad === 1 ? '1 producto tiene un precio sugerido nuevo' : "{$cantidad} productos tienen un precio sugerido nuevo")
            ->body('El costo cambió con esta compra. Revisa los precios antes de seguir vendiendo.')
            ->warning()
            ->persistent()
            ->actions([
                Action::make('revisarPrecios')
                    ->label('Revisar precios')
                    ->button()
                    ->url(CompraResource::getUrl('view', ['record' => $compra])),
            ])
            ->send();
    }
}
