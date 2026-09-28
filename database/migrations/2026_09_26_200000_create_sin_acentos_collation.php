<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Collation ICU no determinista que ignora acentos y mayúsculas ("und-u-ks-level1": solo compara
 * la letra base, así "José" = "jose" y "Peña" = "pena"). Se usa para búsquedas: Filament la aplica
 * a todas sus búsquedas vía config('database.connections.pgsql.search_collation'), y el macro
 * whereLikeSinAcentos (AppServiceProvider) a las consultas manuales del POS y modales.
 *
 * Se prefirió sobre la extensión unaccent porque Filament solo expone este gancho (la collation):
 * con unaccent habría que reescribir a mano cada ->searchable() y los Selects buscables. LIKE con
 * collations no deterministas requiere PostgreSQL 18+.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("CREATE COLLATION IF NOT EXISTS sin_acentos (provider = icu, locale = 'und-u-ks-level1', deterministic = false)");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP COLLATION IF EXISTS sin_acentos');
    }
};
