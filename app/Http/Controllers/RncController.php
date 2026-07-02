<?php

namespace App\Http\Controllers;

use App\Services\DgiiRncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RncController extends Controller
{
    public function __construct(private DgiiRncService $dgiiService) {}

    /**
     * GET /api/rnc/{rnc}
     * Consulta un RNC o Cédula y retorna los datos del contribuyente.
     */
    public function consultar(string $rnc): JsonResponse
    {
        $rnc = preg_replace('/[^0-9]/', '', $rnc);

        if (! $this->dgiiService->esRncValido($rnc)) {
            return response()->json([
                'success' => false,
                'message' => 'El RNC debe tener 9 dígitos o la Cédula 11 dígitos.',
            ], 422);
        }

        try {
            $datos = $this->dgiiService->consultarRnc($rnc);

            if (! $datos) {
                return response()->json([
                    'success' => false,
                    'message' => 'RNC/Cédula no encontrado en el registro de la DGII.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data'    => $datos,
            ]);

        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 429);
        }
    }
}
