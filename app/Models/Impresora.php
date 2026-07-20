<?php

namespace App\Models;

use App\Enums\AnchoPapel;
use App\Enums\ModuloImpresion;
use App\Enums\TipoConexionImpresora;
use App\Observers\ImpresoraObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[ObservedBy(ImpresoraObserver::class)]
class Impresora extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'empresa_id', 'nombre', 'descripcion', 'tipo_conexion', 'ip', 'puerto',
        'ancho_papel', 'modulo', 'predeterminada', 'activa',
    ];

    // Reflejan los defaults de la columna en la migración: sin esto, un Impresora::create() que
    // omite estos campos deja el modelo en memoria con null hasta refrescarlo desde la BD.
    protected $attributes = [
        'puerto' => 9100,
        'ancho_papel' => '80',
        'predeterminada' => false,
        'activa' => true,
    ];

    protected $casts = [
        'tipo_conexion' => TipoConexionImpresora::class,
        'ancho_papel' => AnchoPapel::class,
        'modulo' => ModuloImpresion::class,
        'puerto' => 'integer',
        'predeterminada' => 'boolean',
        'activa' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    public function scopePorModulo(Builder $query, ModuloImpresion $modulo): Builder
    {
        return $query->where('modulo', $modulo);
    }

    public function esDeRed(): bool
    {
        return $this->tipo_conexion === TipoConexionImpresora::RED;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nombre', 'tipo_conexion', 'ip', 'puerto', 'ancho_papel', 'modulo', 'predeterminada', 'activa'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Impresoras');
    }
}
