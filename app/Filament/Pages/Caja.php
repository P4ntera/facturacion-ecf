<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Modulo;
use App\Enums\TipoComprobante;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Caja: la operación diaria de una cajera — escanear/agregar productos y cobrar, sin exponer el
 * detalle técnico de comprobantes fiscales (tipo de e-CF, disponibilidad de NCF, etc.). Reutiliza
 * TODA la lógica de PuntoDeVenta (carrito, arqueo, escaneo, cobrar) por herencia; solo cambia la
 * vista y cómo se decide tipoComprobante: en vez de un selector técnico, un toggle sí/no de
 * "crédito fiscal" (igual que preguntan en caja de un supermercado), que Caja traduce a 31/32 por
 * dentro. Facturación (la clase padre) sigue siendo la pantalla completa para quien necesita más
 * control sobre el comprobante.
 */
class Caja extends PuntoDeVenta
{
    protected string $view = 'filament.pages.caja';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Caja';

    protected static ?string $title = 'Caja';

    /** "¿Con crédito fiscal?" — se traduce a tipoComprobante (31/32) en vez de digitarlo a mano. */
    public bool $creditoFiscal = false;

    public static function modulo(): Modulo
    {
        return Modulo::VENTAS_POS;
    }

    public static function puedeAccederPorPermiso(): bool
    {
        return auth()->user()?->can('pos.acceder') ?? false;
    }

    public function mount(): void
    {
        parent::mount();

        $this->aplicarTipoComprobanteSegunToggle();
    }

    public function updated(string $name): void
    {
        parent::updated($name);

        if ($name === 'creditoFiscal') {
            $this->aplicarTipoComprobanteSegunToggle();

            return;
        }

        if (str($name)->startsWith('carrito.') && str($name)->endsWith('.cantidad')) {
            $this->normalizarCantidadDeLinea((int) explode('.', $name)[1]);
        }
    }

    private function aplicarTipoComprobanteSegunToggle(): void
    {
        $this->tipoComprobante = $this->creditoFiscal
            ? TipoComprobante::FACTURA_CREDITO_FISCAL->value
            : TipoComprobante::FACTURA_CONSUMO->value;
    }

    /**
     * En Caja la cantidad es un stepper entero (flechas nativas del input, de 1 en 1): a
     * diferencia de Facturación, aquí no se venden cantidades fraccionarias. El navegador no
     * bloquea que se digite un decimal a mano, así que igual se trunca aquí. Llegar a 0 (o menos,
     * si alguien fuerza el campo) quita la línea del carrito en vez de dejarla en cero.
     */
    private function normalizarCantidadDeLinea(int $indice): void
    {
        if (! isset($this->carrito[$indice])) {
            return;
        }

        $original = $this->carrito[$indice]['cantidad'];
        $cantidad = (int) floor((float) $original);

        if ($cantidad <= 0) {
            $this->quitarLinea($indice);

            return;
        }

        // Comparación estricta a propósito: "2.9" (string, tal como llega del input) nunca es
        // === al 2 (int) ya normalizado, así que esto SIEMPRE corrige un valor no entero, sin
        // importar si llegó como string, float o int — a diferencia de comparar dos casts, que
        // truncarían ambos lados por igual y ocultarían que hacía falta corregir el dato guardado.
        if ($original !== $cantidad) {
            $this->carrito[$indice]['cantidad'] = $cantidad;
            $this->recalcularTotales();
        }
    }
}
