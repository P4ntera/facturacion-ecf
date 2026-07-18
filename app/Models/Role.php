<?php

declare(strict_types=1);

namespace App\Models;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Extiende el Role de spatie/permission solo para auditar sus cambios (LogsActivity): el
 * paquete no expone un punto de extensión más liviano para esto. Registrado como el modelo de
 * rol real vía config/permission.php (models.role), así que sustituye al de spatie en todo el
 * proyecto, no lo complementa.
 */
class Role extends SpatieRole
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'guard_name'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Roles');
    }
}
