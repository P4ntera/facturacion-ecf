<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    use LogsActivity;

    protected $fillable = [
        'name',
        'email',
        'password',
        'impresora_facturacion_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Cualquier permiso (de cualquier rol, incluidos los creados desde RoleResource) basta para
     * entrar al panel: la lista fija de roles ('Administrador', 'Vendedor', 'Almacenista') dejaba
     * fuera a cualquier rol nuevo creado desde la matriz de permisos, aunque tuviera acceso
     * legítimo a alguna pantalla. Cada Resource/Page sigue gateando su propia pantalla.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->getAllPermissions()->isNotEmpty();
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
            ->logOnly(['name', 'email', 'impresora_facturacion_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Usuarios');
    }
}
