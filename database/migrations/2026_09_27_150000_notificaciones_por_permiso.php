<?php

use App\Enums\TipoNotificacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Notificaciones controladas por permiso (notificaciones.*) + preferencias por usuario para
 * silenciarlas. Para las empresas que ya existen, reparte los permisos nuevos SIN tocar el
 * resto de cada rol (nada de syncPermissions: los roles pueden estar personalizados) y de modo
 * que nadie deje de recibir una alerta que hoy recibe:
 *
 * - stock bajo: hoy le llega a quien tiene inventario.ajustar -> esos roles.
 * - NCF por agotarse: hoy le llega a quien tiene secuencias.administrar -> esos roles.
 * - Administrador: todas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preferencias_notificacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tipo');
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'tipo']);
        });

        $permisos = collect(TipoNotificacion::cases())->mapWithKeys(
            fn (TipoNotificacion $tipo) => [$tipo->value => Permission::findOrCreate($tipo->permiso(), 'web')->id]
        );

        $this->otorgarARolesCon('inventario.ajustar', $permisos[TipoNotificacion::STOCK_BAJO->value]);
        $this->otorgarARolesCon('secuencias.administrar', $permisos[TipoNotificacion::NCF_AGOTANDOSE->value]);

        $administradores = DB::table('roles')->where('name', 'Administrador')->pluck('id');

        foreach ($administradores as $rolId) {
            foreach ($permisos as $permisoId) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $rolId, 'permission_id' => $permisoId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', collect(TipoNotificacion::cases())->map->permiso())->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::dropIfExists('preferencias_notificacion');
    }

    private function otorgarARolesCon(string $permisoExistente, int $permisoNuevoId): void
    {
        $roles = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('permissions.name', $permisoExistente)
            ->pluck('role_has_permissions.role_id');

        foreach ($roles as $rolId) {
            DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $rolId, 'permission_id' => $permisoNuevoId]);
        }
    }
};
