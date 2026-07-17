<?php

namespace App\Models;

use App\Enums\TipoProveedor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Proveedor extends Model
{
    use HasFactory, SoftDeletes;
    use LogsActivity;

    protected $table = 'proveedores';

    protected $fillable = [
        'rnc',
        'tipo',
        'nombre',
        'nombre_comercial',
        'actividad_economica',
        'telefono',
        'email',
        'direccion',
        'estado',
        'activo',
    ];

    protected $casts = [
        'tipo'   => TipoProveedor::class,
        'activo' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['rnc', 'nombre', 'nombre_comercial', 'actividad_economica', 'telefono', 'email', 'direccion', 'estado', 'activo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Proveedores');
    }

    public function esInformal(): bool
    {
        return $this->tipo === TipoProveedor::INFORMAL;
    }

    public function compras(): HasMany
    {
        return $this->hasMany(Compra::class);
    }

    public function pedidosCompra(): HasMany
    {
        return $this->hasMany(PedidoCompra::class);
    }

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'producto_proveedor')
            ->using(ProductoProveedor::class)
            ->withPivot(['es_principal', 'costo_referencia', 'codigo_proveedor'])
            ->withTimestamps();
    }
}
