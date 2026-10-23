<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleTicketLavanderia extends Model
{
    protected $table = 'detalle_tickets_lavanderia';

    protected $fillable = [
        'ticket_lavanderia_id', 'tipo_prenda_id', 'servicio_lavanderia_id',
        'cantidad', 'precio_unitario', 'subtotal', 'observaciones',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'precio_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    protected $attributes = [
        'cantidad' => 1,
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketLavanderia::class, 'ticket_lavanderia_id');
    }

    public function tipoPrenda(): BelongsTo
    {
        return $this->belongsTo(TipoPrenda::class);
    }

    public function servicioLavanderia(): BelongsTo
    {
        return $this->belongsTo(ServicioLavanderia::class);
    }
}
