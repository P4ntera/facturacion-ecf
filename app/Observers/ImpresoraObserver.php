<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\TipoConexionImpresora;
use App\Exceptions\ImpresoraInvalidaException;
use App\Models\Impresora;
use Illuminate\Support\Facades\DB;

class ImpresoraObserver
{
    /**
     * Reglas de conexión, exigidas también aquí (no solo en el formulario del Resource) para
     * que se cumplan sin importar cómo se cree/edite el registro.
     */
    public function saving(Impresora $impresora): void
    {
        if ($impresora->tipo_conexion === TipoConexionImpresora::RED) {
            if (blank($impresora->ip) || filter_var($impresora->ip, FILTER_VALIDATE_IP) === false) {
                throw new ImpresoraInvalidaException('Una impresora de Red requiere una dirección IP válida.');
            }

            if (blank($impresora->puerto)) {
                throw new ImpresoraInvalidaException('Una impresora de Red requiere un puerto.');
            }

            return;
        }

        // NAVEGADOR: el navegador decide el destino físico, ip/puerto no aplican.
        $impresora->ip = null;
        $impresora->puerto = null;
    }

    /**
     * Solo una impresora predeterminada por módulo: al marcar una, desmarca las demás del
     * mismo módulo dentro de una transacción.
     */
    public function saved(Impresora $impresora): void
    {
        if (! $impresora->predeterminada) {
            return;
        }

        DB::transaction(function () use ($impresora): void {
            Impresora::query()
                ->where('modulo', $impresora->modulo)
                ->where('id', '!=', $impresora->id)
                ->where('predeterminada', true)
                ->update(['predeterminada' => false]);
        });
    }
}
