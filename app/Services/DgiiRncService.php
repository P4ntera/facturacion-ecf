<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DgiiRncService
{
    private const BASE_URL = 'https://rnc.megaplus.com.do';

    /**
     * Consulta un RNC o Cédula en la API de MegaPlus.
     * Retorna array con los datos o null si no se encuentra.
     */
    public function consultarRnc(string $rnc): ?array
    {
        $rnc = preg_replace('/[^0-9]/', '', $rnc);

        if (!$this->esRncValido($rnc)) {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->get(self::BASE_URL . '/api/consulta', ['rnc' => $rnc]);

            $data = $response->json();

            if ($response->successful() && isset($data['error']) && $data['error'] === false) {
                return [
                    'rnc'                 => preg_replace('/[^0-9]/', '', $data['cedula_rnc'] ?? $rnc),
                    'nombre'              => $data['nombre_razon_social'] ?? '',
                    'nombre_comercial'    => $data['nombre_comercial'] ?? null,
                    'actividad_economica' => $data['actividad_economica'] ?? null,
                    'estado'              => $data['estado'] ?? 'ACTIVO',
                ];
            }

            if ($response->status() === 404) {
                return null;
            }
        } catch (\Exception $e) {
            Log::error('DgiiRncService error: ' . $e->getMessage());
        }

        return null;
    }

    public function esRncValido(string $rnc): bool
    {
        $rnc = preg_replace('/[^0-9]/', '', $rnc);
        return in_array(strlen($rnc), [9, 11]);
    }
}
