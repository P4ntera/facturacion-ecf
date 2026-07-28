<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Extiende el Role de spatie/permission solo para auditar sus cambios (LogsActivity): el
 * paquete no expone un punto de extensión más liviano para esto. Registrado como el modelo de
 * rol real vía config/permission.php (models.role), así que sustituye al de spatie en todo el
 * proyecto, no lo complementa.
 *
 * Con 'teams' => true (empresa_id como team_foreign_key), cada rol pertenece a una empresa: no
 * hay roles globales, cada empresa define los suyos (mismo nombre, empresas distintas, sin
 * conflicto por la unique compuesta [empresa_id, name, guard_name]).
 */
class Role extends SpatieRole
{
    use LogsActivity;

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'guard_name'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Roles');
    }
}
