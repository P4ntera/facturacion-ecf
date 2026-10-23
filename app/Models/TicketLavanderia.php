<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPagoTicket;
use App\Enums\EstadoTicketLavanderia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketLavanderia extends Model
{
    protected $table = 'tickets_lavanderia';

    protected $fillable = [
        'empresa_id', 'cliente_id', 'recepcionista_id', 'venta_id',
        'numero', 'estado', 'estado_pago', 'total', 'itbis',
        'observaciones', 'fecha_prometida', 'entregado_en',
    ];

    protected $casts = [
        'estado' => EstadoTicketLavanderia::class,
        'estado_pago' => EstadoPagoTicket::class,
        'total' => 'decimal:2',
        'itbis' => 'decimal:2',
        'fecha_prometida' => 'date',
        'entregado_en' => 'datetime',
    ];

    protected $attributes = [
        'estado' => 'recibido',
        'estado_pago' => 'pendiente',
        'total' => '0.00',
        'itbis' => '0.00',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function recepcionista(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recepcionista_id');
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleTicketLavanderia::class);
    }

    public static function generarNumero(int $empresaId): string
    {
        $ultimo = static::where('empresa_id', $empresaId)
            ->orderByDesc('id')
            ->value('numero');

        $secuencia = $ultimo ? ((int) str_replace('TK-', '', $ultimo)) + 1 : 1;

        return 'TK-'.str_pad((string) $secuencia, 4, '0', STR_PAD_LEFT);
    }
}
