<?php

namespace App\Models;

use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Observers\ProductoObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[ObservedBy(ProductoObserver::class)]
class Producto extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'codigo', 'codigo_barra', 'nombre', 'descripcion', 'tipo', 'categoria_id',
        'costo', 'precio', 'tasa_itbis', 'controla_stock',
        'stock', 'stock_minimo', 'activo',
    ];

    protected $casts = [
        'tipo' => TipoProducto::class,
        'tasa_itbis' => TasaItbis::class,
        'controla_stock' => 'boolean',
        'activo' => 'boolean',
        'costo' => 'decimal:2',
        'precio' => 'decimal:2',
        'stock' => 'decimal:3',
        'stock_minimo' => 'decimal:3',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function detalleVentas(): HasMany
    {
        return $this->hasMany(DetalleVenta::class);
    }

    public function detalleCompras(): HasMany
    {
        return $this->hasMany(DetalleCompra::class);
    }

    public function movimientosInventario(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class);
    }

    public function proveedores(): BelongsToMany
    {
        return $this->belongsToMany(Proveedor::class, 'producto_proveedor')
            ->using(ProductoProveedor::class)
            ->withPivot(['es_principal', 'costo_referencia', 'codigo_proveedor'])
            ->withTimestamps();
    }

    public function proveedorPrincipal(): ?Proveedor
    {
        return $this->proveedores()->wherePivot('es_principal', true)->first();
    }

    public function scopeBajoMinimo(Builder $query): Builder
    {
        return $query->where('controla_stock', true)
            ->whereColumn('stock', '<=', 'stock_minimo');
    }

    public function scopeProductos(Builder $query): Builder
    {
        return $query->where('tipo', TipoProducto::PRODUCTO->value);
    }

    public function scopeServicios(Builder $query): Builder
    {
        return $query->where('tipo', TipoProducto::SERVICIO->value);
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'codigo_barra', 'nombre', 'tipo', 'categoria_id', 'costo', 'precio', 'tasa_itbis', 'controla_stock', 'stock_minimo', 'activo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Productos');
    }
}
