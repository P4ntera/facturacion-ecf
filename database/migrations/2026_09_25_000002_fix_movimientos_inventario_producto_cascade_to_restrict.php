<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->dropForeign(['producto_id']);
            $table->foreignId('producto_id')
                ->change()
                ->constrained('productos')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->dropForeign(['producto_id']);
            $table->foreignId('producto_id')
                ->change()
                ->constrained('productos')
                ->cascadeOnDelete();
        });
    }
};
