<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoOrdenCompra;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrdenCompra extends Model
{
    use SoftDeletes;

    protected $table = 'ordenes_compra';

    protected $fillable = [
        'empresa_id', 'proveedor_id', 'user_id', 'numero', 'fecha',
        'fecha_esperada', 'notas', 'subtotal', 'itbis', 'total',
        'estado', 'aprobado_por', 'aprobado_en',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_esperada' => 'date',
        'aprobado_en' => 'datetime',
        'estado' => EstadoOrdenCompra::class,
        'subtotal' => 'decimal:2',
        'itbis' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    protected $attributes = [
        'subtotal' => 0,
        'itbis' => 0,
        'total' => 0,
        'estado' => 'borrador',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleOrdenCompra::class);
    }

    public function recepciones(): HasMany
    {
        return $this->hasMany(RecepcionCompra::class);
    }

    public function compras(): HasMany
    {
        return $this->hasMany(Compra::class);
    }

    public function porcentajeRecibido(): float
    {
        $solicitado = $this->detalles->sum('cantidad_solicitada');
        $recibido = $this->detalles->sum('cantidad_recibida');

        return $solicitado > 0 ? round(($recibido / $solicitado) * 100, 1) : 0;
    }

    public function estaCompleta(): bool
    {
        return $this->detalles->every(fn (DetalleOrdenCompra $d) => bccomp((string) $d->cantidad_recibida, (string) $d->cantidad_solicitada, 4) >= 0);
    }

    public function puedeRecibir(): bool
    {
        return $this->estado->puedeRecibir();
    }
}
