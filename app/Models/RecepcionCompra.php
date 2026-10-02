<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una entrega de mercancía de una orden de compra. Se crea al registrar la compra que la recibe
 * (compra_id): el stock lo mueve la compra, nunca la recepción. Si esa compra se anula, la
 * recepción queda con anulada_en y deja de contar como recibida.
 */
class RecepcionCompra extends Model
{
    protected $table = 'recepciones_compra';

    protected $fillable = [
        'orden_compra_id', 'compra_id', 'empresa_id', 'user_id', 'fecha', 'notas', 'anulada_en',
    ];

    protected $casts = [
        'fecha' => 'date',
        'anulada_en' => 'datetime',
    ];

    public function ordenCompra(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class);
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleRecepcionCompra::class);
    }

    public function estaAnulada(): bool
    {
        return $this->anulada_en !== null;
    }
}
