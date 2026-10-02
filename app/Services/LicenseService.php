<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Empresa;
use App\Models\EmpresaLicenseState;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integración con la API de licencias (Supabase Edge Function).
 *
 * Responsabilidades:
 * - Llamar a /validate con la license_key de la empresa y el device_id.
 * - Verificar localmente la firma RS256 del JWT devuelto.
 * - Almacenar el estado de la licencia en empresa_license_states.
 * - Exponer el estado actual para que el middleware y el dashboard lo consulten.
 *
 * Regla de tenancy: recibe Empresa explícitamente, nunca usa Filament::getTenant().
 */
class LicenseService
{
    /** Umbral en días para mostrar el warning de vencimiento próximo en el dashboard. */
    public const DAYS_WARNING_THRESHOLD = 7;

    /**
     * Indica si la validación de licencias está activada globalmente.
     */
    public function isEnabled(): bool
    {
        return (bool) config('license.enabled', false);
    }

    /**
     * Genera un device_id estable para esta instalación.
     * Usa app.key + hostname, de modo que solo cambia si se regenera la key o se cambia de servidor.
     */
    public function deviceId(): string
    {
        return hash('sha256', config('app.key').':'.gethostname());
    }

    /**
     * Refresca la licencia de una empresa llamando a la API de validación.
     * Actualiza el estado local en empresa_license_states.
     *
     * @return array{valid: bool, status: string, reason: ?string}
     */
    public function refreshForEmpresa(Empresa $empresa): array
    {
        $state = $this->getOrCreateState($empresa);

        if (blank($state->license_key)) {
            $state->update([
                'status' => 'unknown',
                'last_checked_at' => now(),
            ]);

            return ['valid' => false, 'status' => 'unknown', 'reason' => 'no_license_key'];
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.config('license.anon_key'),
                ])
                ->post(config('license.api_url'), [
                    'license_key' => $state->license_key,
                    'device_id' => $this->deviceId(),
                    'hostname' => gethostname(),
                ]);

            $data = $response->json();

            if (($data['valid'] ?? false) === true && isset($data['token'])) {
                return $this->processValidToken($state, $data['token'], $data['expires_at'] ?? null);
            }

            $reason = $data['reason'] ?? 'unknown';

            $state->update([
                'status' => $this->reasonToStatus($reason),
                'last_reason' => $reason,
                'last_checked_at' => now(),
            ]);

            return ['valid' => false, 'status' => $state->status, 'reason' => $reason];

        } catch (\Throwable $e) {
            Log::warning('License validation failed for empresa '.$empresa->id.': '.$e->getMessage());

            // Si ya tenemos un token válido cacheado, no invalidamos por un error de red.
            $state->update(['last_checked_at' => now()]);

            return [
                'valid' => $state->isValid(),
                'status' => $state->status,
                'reason' => 'network_error',
            ];
        }
    }

    /**
     * Devuelve el estado actual de la licencia de una empresa, sin llamar a la API.
     * Verifica localmente el token cacheado si existe.
     *
     * @return array{valid: bool, status: string, days_left: int, license_expires_at: ?string}
     */
    public function stateForEmpresa(Empresa $empresa): array
    {
        if (! $this->isEnabled()) {
            return [
                'valid' => true,
                'status' => 'valid',
                'days_left' => 999,
                'license_expires_at' => null,
            ];
        }

        $state = EmpresaLicenseState::where('empresa_id', $empresa->id)->first();

        if ($state === null || blank($state->license_key)) {
            return [
                'valid' => false,
                'status' => 'unknown',
                'days_left' => 0,
                'license_expires_at' => null,
            ];
        }

        // Si hay token, verificar firma localmente
        if (filled($state->token)) {
            $localCheck = $this->verifyLocal($state->token);

            if (! $localCheck['valid']) {
                // Token expirado (exp pasado): el estado real depende de license_expires_at
                // No invalidamos la licencia si license_expires_at sigue vigente, solo indica
                // que necesita revalidar. Si license_expires_at pasó, sí está vencida.
            }
        }

        return [
            'valid' => $state->isValid(),
            'status' => $state->status,
            'days_left' => $state->daysLeft(),
            'license_expires_at' => $state->license_expires_at?->toIso8601String(),
        ];
    }

    /**
     * Verifica localmente la firma RS256 de un JWT con la clave pública.
     *
     * @return array{valid: bool, claims: ?object}
     */
    public function verifyLocal(string $token): array
    {
        try {
            $publicKey = config('license.public_key');

            if (blank($publicKey)) {
                return ['valid' => false, 'claims' => null];
            }

            $claims = JWT::decode($token, new Key($publicKey, 'RS256'));

            return ['valid' => true, 'claims' => $claims];
        } catch (\Throwable) {
            return ['valid' => false, 'claims' => null];
        }
    }

    /**
     * Obtiene o crea la fila de estado de licencia para una empresa.
     */
    private function getOrCreateState(Empresa $empresa): EmpresaLicenseState
    {
        return EmpresaLicenseState::firstOrCreate(
            ['empresa_id' => $empresa->id],
            ['status' => 'unknown']
        );
    }

    /**
     * Procesa un token válido devuelto por la API: verifica firma, extrae claims, actualiza estado.
     *
     * @return array{valid: bool, status: string, reason: ?string}
     */
    private function processValidToken(EmpresaLicenseState $state, string $token, ?string $expiresAt): array
    {
        $verification = $this->verifyLocal($token);

        if (! $verification['valid']) {
            Log::error('License token signature verification failed for empresa '.$state->empresa_id);

            $state->update([
                'status' => 'invalid',
                'last_reason' => 'signature_verification_failed',
                'last_checked_at' => now(),
            ]);

            return ['valid' => false, 'status' => 'invalid', 'reason' => 'signature_verification_failed'];
        }

        $claims = $verification['claims'];

        // Distinción crítica (ver §5 de la documentación):
        // - exp (~48h) es el ciclo de revalidación, NO el vencimiento.
        // - license_expires_at es el vencimiento REAL de la licencia.
        $licenseExpiresAt = isset($claims->license_expires_at)
            ? \Carbon\Carbon::parse($claims->license_expires_at)
            : ($expiresAt !== null ? \Carbon\Carbon::parse($expiresAt) : null);

        $validUntil = isset($claims->exp)
            ? \Carbon\Carbon::createFromTimestamp($claims->exp)
            : null;

        $status = ($licenseExpiresAt !== null && $licenseExpiresAt->isFuture()) ? 'valid' : 'expired';

        $state->update([
            'token' => $token,
            'valid_until' => $validUntil,
            'license_expires_at' => $licenseExpiresAt,
            'status' => $status,
            'last_reason' => null,
            'last_checked_at' => now(),
        ]);

        return ['valid' => $status === 'valid', 'status' => $status, 'reason' => null];
    }

    /**
     * Mapea el reason de la API a un status local.
     */
    private function reasonToStatus(string $reason): string
    {
        return match ($reason) {
            'expired' => 'expired',
            'revoked', 'suspended' => 'invalid',
            'not_found', 'domain_mismatch', 'seat_limit', 'server_error' => 'invalid',
            default => 'unknown',
        };
    }
}
