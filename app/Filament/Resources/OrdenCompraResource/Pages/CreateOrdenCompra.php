<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrdenCompraResource\Pages;

use App\Filament\Resources\OrdenCompraResource;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\OrdenCompraService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Str;
use RuntimeException;

class CreateOrdenCompra extends CreateRecord
{
    protected static string $resource = OrdenCompraResource::class;

    /**
     * Llega precargada desde "Crear orden de compra" en Stock Bajo: proveedor_id y producto_ids
     * (separados por coma) en la query string. Cantidad sugerida = lo que falta para el stock
     * mínimo; precio = el costo de referencia con ese proveedor, o el costo del producto.
     */
    public function mount(): void
    {
        parent::mount();

        $proveedorId = request()->query('proveedor_id');
        $productoIds = array_filter(explode(',', (string) request()->query('producto_ids', '')));

        if (blank($proveedorId)) {
            return;
        }

        // Los ids vienen de la URL: solo proveedor y productos de la empresa activa.
        $empresaId = Filament::getTenant()->id;
        $proveedor = Proveedor::where('empresa_id', $empresaId)->find($proveedorId);

        if ($proveedor === null) {
            return;
        }

        $lineas = [];
        foreach (Producto::where('empresa_id', $empresaId)->whereIn('id', $productoIds)->get() as $producto) {
            $pivot = $producto->proveedores()->where('proveedores.id', $proveedor->id)->first()?->pivot;

            $lineas[(string) Str::uuid()] = [
                'producto_id' => $producto->id,
                'cantidad_solicitada' => max(1, (float) $producto->stock_minimo - (float) $producto->stock),
                'precio_unitario' => (float) ($pivot?->costo_referencia ?? $producto->costo),
            ];
        }

        // fill() (no asignación directa a $this->data): el repeater de líneas tiene campos con
        // estado propio (Select de producto) que solo se hidratan pasando por fill().
        $this->form->fill([
            ...$this->data,
            'proveedor_id' => $proveedor->id,
            'lineas' => $lineas,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Orden de compra creada exitosamente';
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(OrdenCompraService::class)->crear(
                [
                    'proveedor_id' => $data['proveedor_id'],
                    'fecha' => $data['fecha'],
                    'fecha_esperada' => $data['fecha_esperada'] ?? null,
                    'notas' => $data['notas'] ?? null,
                    'lineas' => $data['lineas'] ?? [],
                ],
                auth()->id(),
                Filament::getTenant(),
            );
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
