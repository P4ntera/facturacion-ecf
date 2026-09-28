<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Filament\Pages\PuntoDeVentaTouch;
use App\Models\Caja;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Pantalla del cliente de una caja registradora (segundo monitor, tablet montada, TV): muestra en
 * vivo lo que el cajero va agregando en el POS táctil. NO requiere login — la única credencial es
 * el display_token de la caja (64 caracteres aleatorios, regenerable desde CajaResource), así que
 * solo muestra lo que el cliente ya ve en la caja: descripción, cantidades, precios y totales.
 *
 * Lee por polling (wire:poll en la vista) el carrito que PuntoDeVentaTouch::publicarEnDisplay()
 * deja en cache. La caja se re-resuelve por token en cada render: si se desactiva o se regenera
 * su token, el display deja de mostrar datos al siguiente poll.
 */
#[Layout('layouts.display')]
class DisplayCliente extends Component
{
    #[Locked]
    public string $token;

    public function mount(string $token): void
    {
        $this->token = $token;

        abort_if($this->caja() === null, 404);
    }

    protected function caja(): ?Caja
    {
        return Caja::query()
            ->where('display_token', $this->token)
            ->where('activo', true)
            ->with('empresa')
            ->first();
    }

    /** @return array<string, mixed> */
    protected function estadoVacio(): array
    {
        return ['estado' => 'libre', 'items' => [], 'subtotal' => '0.00', 'descuento' => '0.00', 'itbis' => '0.00', 'total' => '0.00'];
    }

    /** @return array<string, mixed> */
    protected function datos(Caja $caja): array
    {
        $datos = Cache::get($caja->claveCacheDisplay());

        if (! is_array($datos)) {
            return $this->estadoVacio();
        }

        // Pasado el agradecimiento, el display vuelve solo a la pantalla de espera.
        if (($datos['estado'] ?? null) === 'gracias'
            && now()->timestamp - (int) ($datos['updated_at'] ?? 0) > PuntoDeVentaTouch::SEGUNDOS_GRACIAS) {
            return $this->estadoVacio();
        }

        return $datos + $this->estadoVacio();
    }

    public function render(): View
    {
        $caja = $this->caja();

        return view('livewire.display-cliente', [
            'caja' => $caja,
            'empresa' => $caja?->empresa,
            'datos' => $caja ? $this->datos($caja) : $this->estadoVacio(),
        ])->title($caja ? "Display — {$caja->nombre}" : 'Display');
    }
}
