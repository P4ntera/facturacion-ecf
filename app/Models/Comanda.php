<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoComanda;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comanda extends Model
{
    protected $fillable = [
        'empresa_id', 'mesa_id', 'mesero_id', 'numero',
        'comensales', 'estado', 'notas', 'venta_id', 'cerrada_en',
    ];

    protected $casts = [
        'estado' => EstadoComanda::class,
        'cerrada_en' => 'datetime',
    ];

    protected $attributes = [
        'estado' => 'abierta',
        'comensales' => 1,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function mesa(): BelongsTo
    {
        return $this->belongsTo(Mesa::class);
    }

    public function mesero(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mesero_id');
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(ComandaDetalle::class);
    }

    public static function generarNumero(int $empresaId): string
    {
        $ultimo = static::where('empresa_id', $empresaId)
            ->orderByDesc('id')
            ->value('numero');

        $secuencia = $ultimo ? ((int) str_replace('COM-', '', $ultimo)) + 1 : 1;

        return 'COM-'.str_pad((string) $secuencia, 5, '0', STR_PAD_LEFT);
    }
}
