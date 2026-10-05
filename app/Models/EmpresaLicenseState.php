<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado local de la licencia por empresa. Almacena el JWT firmado devuelto por la API de
 * Supabase y los datos derivados de su payload (valid_until = exp, license_expires_at =
 * vencimiento real). Una fila por empresa, creada la primera vez que se asigna una license_key.
 *
 * El token se guarda cifrado (cast 'encrypted') — contiene claims que revelan el plan y el
 * vencimiento de la licencia.
 */
class EmpresaLicenseState extends Model
{
    protected $table = 'empresa_license_states';

    protected $fillable = [
        'empresa_id',
        'license_key',
        'token',
        'valid_until',
        'license_expires_at',
        'status',
        'last_reason',
        'last_checked_at',
    ];

    protected $casts = [
        'token' => 'encrypted',
        'valid_until' => 'datetime',
        'license_expires_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /** true si la licencia está vigente (status = valid y license_expires_at en el futuro). */
    public function isValid(): bool
    {
        return $this->status === 'valid'
            && $this->license_expires_at !== null
            && $this->license_expires_at->isFuture();
    }

    /** Días naturales hasta el vencimiento real de la licencia, o 0 si ya venció. */
    public function daysLeft(): int
    {
        if ($this->license_expires_at === null || $this->license_expires_at->isPast()) {
            return 0;
        }

        return (int) now()->diffInDays($this->license_expires_at, absolute: false);
    }
}
