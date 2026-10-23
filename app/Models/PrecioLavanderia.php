<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrecioLavanderia extends Model
{
    protected $table = 'precios_lavanderia';

    protected $fillable = [
        'empresa_id',
        'tipo_prenda_id',
        'servicio_lavanderia_id',
        'precio',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function tipoPrenda(): BelongsTo
    {
        return $this->belongsTo(TipoPrenda::class);
    }

    public function servicioLavanderia(): BelongsTo
    {
        return $this->belongsTo(ServicioLavanderia::class);
    }

    /**
     * Busca el precio configurado para la combinación prenda+servicio en la empresa.
     * Devuelve null si no hay precio configurado (se usaría el precio_base del servicio como fallback).
     */
    public static function precioParaCombinacion(int $empresaId, int $tipoPrendaId, int $servicioId): ?string
    {
        return static::where('empresa_id', $empresaId)
            ->where('tipo_prenda_id', $tipoPrendaId)
            ->where('servicio_lavanderia_id', $servicioId)
            ->value('precio');
    }
}
