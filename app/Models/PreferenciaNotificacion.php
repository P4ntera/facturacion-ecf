<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoNotificacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preferencia de un usuario sobre un tipo de notificación. Sin fila = activa (el permiso del
 * rol ya decidió que puede recibirla); solo se guarda para silenciar o reactivar.
 */
class PreferenciaNotificacion extends Model
{
    protected $table = 'preferencias_notificacion';

    protected $fillable = ['user_id', 'tipo', 'activa'];

    protected $casts = [
        'tipo' => TipoNotificacion::class,
        'activa' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
