<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Caja registradora física (multi-caja de supermercado). Cada venta del POS táctil y cada arqueo
 * abierto desde él quedan asociados a una; además, cada caja tiene su propio display del cliente
 * (pantalla pública por token, ver App\Livewire\DisplayCliente).
 */
class Caja extends Model
{
    use LogsActivity;

    protected $fillable = ['empresa_id', 'nombre', 'codigo', 'mensaje_display', 'activo'];

    // El token nunca viaja en toArray()/JSON de rutina: es la credencial del display público.
    protected $hidden = ['display_token'];

    protected $casts = [
        'activo' => 'boolean',
    ];

    // Espejo del DEFAULT de la migración: sin esto, Caja::create() sin 'activo' deja null en
    // memoria (Postgres no lo refleja hasta un refresh()).
    protected $attributes = [
        'activo' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (Caja $caja) {
            $caja->display_token ??= static::nuevoToken();
        });
    }

    public static function nuevoToken(): string
    {
        return Str::random(64);
    }

    /** Invalida la URL del display anterior (p. ej. si se filtró): el viejo deja de funcionar al instante. */
    public function regenerarDisplayToken(): void
    {
        $this->forceFill(['display_token' => static::nuevoToken()])->save();
    }

    public function urlDisplay(): string
    {
        return route('display.cliente', $this->display_token);
    }

    /** Clave de cache donde el POS táctil publica el carrito en curso para el display. */
    public function claveCacheDisplay(): string
    {
        return "display:caja:{$this->id}";
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function arqueos(): HasMany
    {
        return $this->hasMany(ArqueoCaja::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nombre', 'codigo', 'mensaje_display', 'activo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Cajas');
    }
}
