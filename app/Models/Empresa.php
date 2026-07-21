<?php

namespace App\Models;

use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Empresa extends Model implements HasName
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'slug', 'rnc', 'razon_social', 'nombre_comercial', 'usa_ecf', 'activa',
    ];

    protected $casts = [
        'usa_ecf' => 'boolean',
        'activa' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Empresa $empresa) {
            if (blank($empresa->slug)) {
                $empresa->slug = static::slugUnico($empresa->razon_social);
            }
        });
    }

    private static function slugUnico(string $razonSocial): string
    {
        $base = Str::slug($razonSocial);
        $slug = $base;
        $sufijo = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-".++$sufijo;
        }

        return $slug;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Nombre que Filament usa en el switcher/menú de tenant (Empresa no tiene columna "name"). */
    public function getFilamentName(): string
    {
        return $this->nombre_comercial ?: $this->razon_social;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['slug', 'rnc', 'razon_social', 'nombre_comercial', 'usa_ecf', 'activa'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Empresas');
    }
}
