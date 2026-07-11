<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CanalRecepcionEcf;
use App\Enums\EstadoReenvioPac;
use App\Models\DocumentoRecibido;
use App\Services\Dgii\RecepcionEcfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base común de los dos endpoints públicos que se registran en la DGII (recepción y aprobación
 * comercial): ambos solo difieren en el canal. Acepta application/xml (cuerpo crudo) o multipart
 * con un campo 'xml'. La API Key nunca pasa por aquí ni se refleja en la respuesta.
 */
abstract class RecepcionEcfControllerBase extends Controller
{
    public function __construct(private readonly RecepcionEcfService $servicio) {}

    abstract protected function canal(): CanalRecepcionEcf;

    public function __invoke(Request $request): JsonResponse
    {
        $xml = $this->extraerXml($request);

        if ($xml === null) {
            return response()->json(['error' => 'No se recibió un XML válido (application/xml o multipart "xml").'], 422);
        }

        $documento = $this->servicio->procesar($this->canal(), $xml, $request->ip());

        return $this->respuestaHttp($documento);
    }

    private function extraerXml(Request $request): ?string
    {
        if ($request->hasFile('xml')) {
            $contenido = file_get_contents($request->file('xml')->getRealPath());

            return blank($contenido) ? null : $contenido;
        }

        $cuerpo = $request->getContent();

        return blank($cuerpo) ? null : $cuerpo;
    }

    private function respuestaHttp(DocumentoRecibido $documento): JsonResponse
    {
        return match ($documento->estado_reenvio) {
            EstadoReenvioPac::REENVIADO => response()->json(['status' => 'recibido'], 200),
            EstadoReenvioPac::RECHAZADO_VALIDACION => response()->json(['error' => $documento->error], 422),
            EstadoReenvioPac::ERROR_REENVIO => response()->json(['error' => $documento->error], 502),
        };
    }
}
