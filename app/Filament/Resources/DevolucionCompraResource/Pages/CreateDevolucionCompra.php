<?php

namespace App\Filament\Resources\DevolucionCompraResource\Pages;

use App\Exceptions\StockInsuficienteException;
use App\Filament\Resources\DevolucionCompraResource;
use App\Models\DetalleCompra;
use App\Services\DevolucionCompraService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

class CreateDevolucionCompra extends CreateRecord
{
    protected static string $resource = DevolucionCompraResource::class;

    /** Permite llegar precargado desde el botón "Registrar devolución" de ViewCompra. */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $compraId = request()->query('compra_id');

        $this->form->fill($compraId ? ['compra_id' => (int) $compraId] : []);

        $this->callHook('afterFill');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Devolución registrada exitosamente';
    }

    /**
     * Toma la línea seleccionada en "Agregar línea a devolver" y la agrega a la tabla
     * de líneas, validando que no exceda lo disponible para devolver (lo comprado menos
     * lo ya devuelto en otras devoluciones, y menos lo que ya se agregó en esta captura).
     */
    public function agregarLinea(): void
    {
        $detalleCompraId = $this->data['nueva_linea_detalle_compra_id'] ?? null;
        $cantidad         = $this->data['nueva_linea_cantidad'] ?? null;

        if (! $detalleCompraId || ! $cantidad) {
            Notification::make()->title('Selecciona un producto e indica la cantidad a devolver.')->warning()->send();

            return;
        }

        $detalle = DetalleCompra::find($detalleCompraId);

        if (! $detalle) {
            return;
        }

        $lineas = $this->data['lineas'] ?? [];

        $yaEnCaptura = collect($lineas)
            ->where('detalle_compra_id', $detalleCompraId)
            ->sum('cantidad');

        $disponible = $detalle->cantidadDisponibleParaDevolver() - $yaEnCaptura;

        if ((float) $cantidad > $disponible) {
            Notification::make()->title("No puedes devolver más de lo disponible ({$disponible}) para «{$detalle->producto->nombre}».")->danger()->send();

            return;
        }

        $lineas[(string) Str::uuid()] = [
            'detalle_compra_id' => $detalleCompraId,
            'cantidad'          => $cantidad,
        ];

        $this->data['lineas'] = $lineas;
        $this->data['nueva_linea_detalle_compra_id'] = null;
        $this->data['nueva_linea_cantidad']          = null;
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(DevolucionCompraService::class)->crear([
                'compra_id' => $data['compra_id'],
                'fecha'     => $data['fecha'],
                'motivo'    => $data['motivo'],
                'lineas'    => collect($data['lineas'])
                    ->map(fn ($l) => [
                        'detalle_compra_id' => $l['detalle_compra_id'],
                        'cantidad'          => $l['cantidad'],
                    ])
                    ->values()
                    ->all(),
            ], auth()->id());
        } catch (RuntimeException|StockInsuficienteException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt();
        }
    }
}
