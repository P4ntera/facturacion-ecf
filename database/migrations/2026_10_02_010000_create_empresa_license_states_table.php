<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_license_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')
                ->unique()
                ->constrained('empresas')
                ->restrictOnDelete();

            $table->string('license_key')->nullable();
            $table->text('token')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('license_expires_at')->nullable();
            $table->string('status', 20)->default('unknown'); // valid, expired, invalid, unknown
            $table->string('last_reason')->nullable(); // reason devuelto por la API cuando falla
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_license_states');
    }
};
