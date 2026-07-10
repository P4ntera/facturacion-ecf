<?php

namespace App\Services\Dgii;

use App\Enums\TipoDocumentoCliente;

/**
 * Busca un documento en la DGII (RNC, 9 dígitos) o la JCE (Cédula, 11 dígitos) a través del PAC,
 * y normaliza el resultado a una forma única (documento/nombre/tipo) para que quien la use
 * (ClienteResource, Punto de Venta) no tenga que distinguir entre las dos fuentes.
 */
class ConsultaContribuyenteService
{
    public function __construct(private readonly DgiiGatewayInterface $gateway) {}

    /** @return array{documento: string, nombre: string, tipo: TipoDocumentoCliente}|null */
    public function buscar(string $documento): ?array
    {
        $documento = preg_replace('/[^0-9]/', '', $documento) ?? '';

        return match (strlen($documento)) {
            9 => $this->normalizar($this->gateway->buscarContribuyente($documento), $documento, 'rnc', TipoDocumentoCliente::RNC),
            11 => $this->normalizar($this->gateway->buscarCedulaJce($documento), $documento, 'cedula', TipoDocumentoCliente::CEDULA),
            default => null,
        };
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
