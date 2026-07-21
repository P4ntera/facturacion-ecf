<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    use LogsActivity;

    protected $fillable = [
        'empresa_id',
        'es_super_admin',
        'name',
        'email',
        'password',
        'impresora_facturacion_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    // Refleja el default de la columna en la migración: sin esto, un User::create() que omite
    // este campo deja el modelo en memoria con null (no false) hasta refrescarlo desde la BD,
    // rompiendo el tipo bool que exige EmpresaPolicy::viewAny() (mismo patrón que Impresora).
    protected $attributes = [
        'es_super_admin' => false,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'es_super_admin' => 'boolean',
        ];
    }

    /**
     * Cualquier permiso (de cualquier rol, incluidos los creados desde RoleResource) basta para
     * entrar al panel: la lista fija de roles ('Administrador', 'Vendedor', 'Almacenista') dejaba
     * fuera a cualquier rol nuevo creado desde la matriz de permisos, aunque tuviera acceso
     * legítimo a alguna pantalla. Cada Resource/Page sigue gateando su propia pantalla. Además,
     * fuera del super-admin, la empresa del usuario debe estar activa: desactivarla es la forma
     * de suspender el acceso de todos sus usuarios sin tener que revocar rol por rol.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->getAllPermissions()->isEmpty()) {
            return false;
        }

        return $this->es_super_admin || ($this->empresa?->activa ?? false);
    }

    /**
     * @return Collection<int, Empresa>
     */
    public function getTenants(Panel $panel): Collection
    {
        if ($this->es_super_admin) {
            return Empresa::where('activa', true)->get();
        }

        return $this->empresa ? collect([$this->empresa]) : collect();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->es_super_admin) {
            return true;
        }

        return $tenant->is($this->empresa);
    }

    /** Sin esto, un usuario normal vería el selector de empresas aunque solo tenga una. */
    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->empresa;
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * true si $empresaId es la empresa de este usuario. Para rutas FUERA del panel (descargas de
     * PDF/XML/ticket, reportes): ahí Filament::getTenant() no existe, así que el scoping
     * automático de Filament (global scope por tenant) no aplica y hay que comparar a mano contra
     * la empresa del usuario autenticado. El super-admin no tiene empresa propia —no se le da
     * acceso implícito a estas rutas fuera del panel; entra por el panel, con tenant explícito—.
     */
    public function perteneceAEmpresa(?int $empresaId): bool
    {
        return $empresaId !== null && $this->empresa_id === $empresaId;
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function compras(): HasMany
    {
        return $this->hasMany(Compra::class);
    }

    public function impresoraFacturacion(): BelongsTo
    {
        return $this->belongsTo(Impresora::class, 'impresora_facturacion_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'empresa_id', 'es_super_admin', 'impresora_facturacion_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Usuarios');
    }
}
