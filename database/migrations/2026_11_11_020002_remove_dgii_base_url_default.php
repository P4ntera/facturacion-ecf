<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->string('dgii_base_url')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('empresa_configuracion', function (Blueprint $table) {
            $table->string('dgii_base_url')->default('https://sandbox.pac-ecf.example.do/api/v1')->nullable(false)->change();
        });
    }
};
