<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable SOLO para el super-admin: un usuario normal siempre pertenece a una
            // empresa (se exige en el formulario/acción de alta, no a nivel de columna, porque
            // el super-admin es la única excepción legítima).
            $table->foreignId('empresa_id')
                ->after('id')
                ->nullable()
                ->constrained('empresas')
                ->restrictOnDelete();

            $table->boolean('es_super_admin')->default(false)->after('empresa_id');

            $table->index('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('empresa_id');
            $table->dropColumn('es_super_admin');
        });
    }
};
