<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
<<<<<<< HEAD
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    use HasFactory;

    protected $fillable = [
        'rnc', 'nombre', 'telefono', 'email', 'direccion', 'activo',
=======
use Illuminate\Database\Eloquent\SoftDeletes;

class Proveedor extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'proveedores';

    protected $fillable = [
        'rnc',
        'nombre',
        'nombre_comercial',
        'actividad_economica',
        'telefono',
        'email',
        'direccion',
        'estado',
        'activo',
>>>>>>> Lamar
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
<<<<<<< HEAD

    public function compras(): HasMany
    {
        return $this->hasMany(Compra::class);
    }
=======
>>>>>>> Lamar
}
