<?php

namespace App\Filament\Resources\CompraResource\Pages;

use App\Enums\TipoComprobante;
use App\Exceptions\StockInsuficienteException;
use App\Filament\Resources\CompraResource;
use App\Services\CompraService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

class CreateCompra extends CreateRecord
{
    protected static string $resource = CompraResource::class;

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
            return app(CompraService::class)->crear([
                'proveedor_id'         => $data['proveedor_id'],
                'tipo_comprobante'     => filled($data['tipo_comprobante'] ?? null) ? TipoComprobante::from($data['tipo_comprobante']) : null,
                'ncf'                  => $data['ncf'] ?? null,
                'fecha'                => $data['fecha'],
                'itbis_incluido'       => $data['itbis_incluido'] ?? false,
                'monto_total_factura'  => $data['monto_total_factura'] ?? null,
                'lineas'               => $data['lineas'],
            ], auth()->id());
        } catch (RuntimeException|StockInsuficienteException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt();
        }
    }
}
