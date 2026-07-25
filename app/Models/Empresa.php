<?php

namespace App\Models;

use App\Enums\Modulo;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Empresa extends Model implements HasName
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'slug', 'rnc', 'razon_social', 'nombre_comercial',
        'direccion', 'telefono', 'email', 'logo',
        'usa_ecf', 'activa',
    ];

    protected $casts = [
        'usa_ecf' => 'boolean',
        'activa' => 'boolean',
    ];

    /** @var Collection<string, EmpresaModulo>|null */
    private ?Collection $modulosCache = null;

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

    public function configuracion(): HasOne
    {
        return $this->hasOne(EmpresaConfiguracion::class);
    }

    /** Configuración fiscal de la empresa; la crea con los defaults de la migración si no existe. */
    public function config(): EmpresaConfiguracion
    {
        return $this->configuracion ?? $this->configuracion()->firstOrCreate([]);
    }

    /**
     * Único punto de verdad para "¿esta empresa factura electrónicamente?": todo lo que muestre
     * u oculte el módulo fiscal (menú, POS, envío a la DGII) debe consultar esto, no
     * $empresa->usa_ecf directo, por si algún día la regla necesita más que un booleano.
     */
    public function usaEcf(): bool
    {
        return $this->usa_ecf;
    }

    public function modulos(): HasMany
    {
        return $this->hasMany(EmpresaModulo::class);
    }

    /**
     * Único punto de verdad para "¿esta empresa tiene encendido este módulo?": los bloqueados
     * siempre están disponibles; el resto se lee de empresa_modulos, con "habilitado" por
     * defecto si aún no tiene fila propia (empresas sembradas antes de que existiera el módulo,
     * o alta fuera de CreateEmpresa::afterCreate()). Se cachea en la instancia para no repetir
     * la consulta en cada canAccess()/shouldRegisterNavigation() de la misma request.
     */
    public function tieneModulo(Modulo $modulo): bool
    {
        if ($modulo->esBloqueado()) {
            return true;
        }

        if ($modulo->requiereEcf() && ! $this->usaEcf()) {
            return false;
        }

        if ($this->modulosCache === null) {
            $this->modulosCache = $this->modulos()->get()->keyBy(fn (EmpresaModulo $m) => $m->modulo->value);
        }

        return $this->modulosCache->get($modulo->value)?->habilitado ?? true;
    }

    /** Nombre que Filament usa en el switcher/menú de tenant (Empresa no tiene columna "name"). */
    public function getFilamentName(): string
    {
        return $this->nombre_comercial ?: $this->razon_social;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'slug', 'rnc', 'razon_social', 'nombre_comercial',
                'direccion', 'telefono', 'email', 'usa_ecf', 'activa',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Empresas');
    }
}
