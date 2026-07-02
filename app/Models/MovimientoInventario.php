<?php

namespace App\Models;

use App\Enums\OrigenMovimiento;
use App\Enums\TipoMovimiento;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoInventario extends Model
{
    use HasFactory;

    protected $table = 'movimientos_inventario';

    protected $fillable = [
        'producto_id', 'tipo', 'origen', 'referencia_id',
        'cantidad', 'stock_anterior', 'stock_nuevo',
        'user_id', 'observacion',
    ];

    protected $casts = [
        'tipo'           => TipoMovimiento::class,
        'origen'         => OrigenMovimiento::class,
        'cantidad'       => 'decimal:3',
        'stock_anterior' => 'decimal:3',
        'stock_nuevo'    => 'decimal:3',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
