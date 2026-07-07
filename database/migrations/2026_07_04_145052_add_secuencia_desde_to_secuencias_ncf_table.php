<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secuencias_ncf', function (Blueprint $table) {
            $table->unsignedBigInteger('secuencia_desde')->nullable()->after('prefijo');
        });
    }

    public function down(): void
    {
        Schema::table('secuencias_ncf', function (Blueprint $table) {
            $table->dropColumn('secuencia_desde');
        });
    }
};
