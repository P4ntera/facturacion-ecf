<?php

namespace App\Models;

use App\Enums\TipoDocumentoCliente;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Cliente extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'tipo_documento', 'documento', 'nombre',
        'telefono', 'email', 'direccion', 'activo',
    ];

    protected $casts = [
        'tipo_documento' => TipoDocumentoCliente::class,
        'activo' => 'boolean',
    ];

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tipo_documento', 'documento', 'nombre', 'telefono', 'email', 'direccion', 'activo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Clientes');
    }
}
