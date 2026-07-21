<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductoProveedor extends Pivot
{
    protected $table = 'producto_proveedor';

    public $incrementing = true;

    protected $fillable = [
        'producto_id', 'proveedor_id', 'es_principal', 'costo_referencia', 'codigo_proveedor',
    ];

    protected $casts = [
        'es_principal'     => 'boolean',
        'costo_referencia' => 'decimal:2',
    ];

    /**
     * Solo un proveedor puede ser "principal" por producto. Se apaga la bandera en las demás
     * filas del mismo producto_id en vez de una constraint de BD, igual que
     * SecuenciaNcfService::siguiente() apaga la secuencia anterior al activar la siguiente.
     * Filament's AttachAction/EditAction sobre un belongsToMany con ->using() pasan por
     * save()/update() del pivot, así que este hook cubre ambas vías de escritura.
     */
    protected static function booted(): void
    {
        static::saving(function (self $pivot) {
            if ($pivot->es_principal) {
                static::query()
                    ->where('producto_id', $pivot->producto_id)
                    ->when($pivot->exists, fn ($q) => $q->where('id', '!=', $pivot->id))
                    ->update(['es_principal' => false]);
            }
        });
    }
}
