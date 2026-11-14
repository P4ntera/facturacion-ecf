<?php

namespace App\Services\Dgii;

use App\Enums\TipoDocumentoCliente;
use App\Models\Empresa;
use App\Support\ConsultaExterna;

/**
 * Busca un documento en la DGII (RNC, 9 dígitos) o la JCE (Cédula, 11 dígitos) a través del PAC,
 * y normaliza el resultado a una forma única (documento/nombre/tipo) para que quien la use
 * (ClienteResource, Punto de Venta) no tenga que distinguir entre las dos fuentes.
 */
class ConsultaContribuyenteService
{
    public function __construct(private readonly DgiiGatewayFactory $gatewayFactory) {}

    /**
     * $empresa la resuelve el llamador (Filament::getTenant() en el panel): este service no
     * asume ningún contexto ambiente, para poder usarse igual desde un job o un comando.
     *
     * @return array{documento: string, nombre: string, tipo: TipoDocumentoCliente}|null
     */
    public function buscar(string $documento, Empresa $empresa): ?array
    {
        $documento = preg_replace('/[^0-9]/', '', $documento) ?? '';
        if (! in_array(strlen($documento), [9, 11], true)) {
            return null;
        }

        // Caché de un día + límite por usuario: ver App\Support\ConsultaExterna.
        return ConsultaExterna::conCacheYLimite('contribuyente', $documento, function () use ($documento, $empresa): ?array {
            $gateway = $this->gatewayFactory->make($empresa);

            return match (strlen($documento)) {
                9 => $this->normalizar($gateway->buscarContribuyente($documento), $documento, 'rnc', TipoDocumentoCliente::RNC),
                default => $this->normalizar($gateway->buscarCedulaJce($documento), $documento, 'cedula', TipoDocumentoCliente::CEDULA),
            };
        });
    }

    /** @param  array<string, mixed>|null  $datos */
    private function normalizar(?array $datos, string $documento, string $clave, TipoDocumentoCliente $tipo): ?array
    {
        if ($datos === null || blank($datos['nombre'] ?? null)) {
            return null;
        }

        return [
            'documento' => $datos[$clave] ?? $documento,
            'nombre' => $datos['nombre'],
            'tipo' => $tipo,
        ];
    }
}
