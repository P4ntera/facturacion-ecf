<?php

namespace App\Filament\Resources\PedidoCompraResource\Pages;

use App\Filament\Resources\PedidoCompraResource;
use App\Models\Producto;
use App\Services\PedidoCompraService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

class CreatePedidoCompra extends CreateRecord
{
    protected static string $resource = PedidoCompraResource::class;

    /**
     * Permite llegar precargado desde el botón "Crear pedido" de la página Stock Bajo:
     * toma proveedor_id y producto_ids (lista separada por comas) de la query string y
     * arma las líneas con la cantidad sugerida (stock_minimo - stock actual).
     */
    public function mount(): void
    {
        parent::mount();

        $proveedorId = request()->query('proveedor_id');
        $productoIds = request()->query('producto_ids');

        if (! $proveedorId) {
            return;
        }

        $lineas = [];

        if (filled($productoIds)) {
            $ids = array_filter(explode(',', (string) $productoIds));

            foreach (Producto::whereIn('id', $ids)->get() as $producto) {
                $pivot = $producto->proveedores()->where('proveedores.id', $proveedorId)->first()?->pivot;
                $sugerida = max(1, (float) $producto->stock_minimo - (float) $producto->stock);

                $lineas[(string) Str::uuid()] = [
                    'producto_id'    => $producto->id,
                    'cantidad'       => $sugerida,
                    'costo_unitario' => $pivot?->costo_referencia ?? $producto->costo,
                ];
            }
        }

        $this->form->fill([
            ...$this->form->getState(),
            'proveedor_id' => (int) $proveedorId,
        ]);

        // El Repeater no adopta bien su estado vía form->fill() en mount(); se asigna
        // directo a $this->data, igual que hace agregarLinea() al agregar una línea.
        $this->data['lineas'] = $lineas;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Pedido de compra registrado exitosamente';
    }

    /**
     * Toma los campos fijos de "Agregar producto" y los agrega como una nueva línea
     * a la tabla de detalle, luego limpia esos campos para la siguiente captura.
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

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PedidoCompraService::class)->crear([
                'proveedor_id' => $data['proveedor_id'],
                'fecha'        => $data['fecha'],
                'notas'        => $data['notas'] ?? null,
                'lineas'       => $data['lineas'],
            ], auth()->id());
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt();
        }
    }
}
