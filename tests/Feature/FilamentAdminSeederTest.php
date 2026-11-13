<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\FilamentAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class FilamentAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    private function enProduccion(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
    }

    public function test_fuera_de_produccion_usa_la_contrasena_demo(): void
    {
        (new FilamentAdminSeeder)->run();

        $admins = User::where('es_super_admin', true)->get();

        $this->assertCount(2, $admins);
        $this->assertTrue(Hash::check(FilamentAdminSeeder::PASSWORD_DEMO, $admins->first()->password));
    }

    public function test_en_produccion_sin_contrasena_configurada_no_crea_nada(): void
    {
        $this->enProduccion();
        config(['instalacion.super_admin_password' => null]);

        try {
            (new FilamentAdminSeeder)->run();
            $this->fail('El seeder debió abortar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SUPER_ADMIN_PASSWORD', $e->getMessage());
        }

        $this->assertSame(0, User::where('es_super_admin', true)->count());
    }

    public function test_en_produccion_rechaza_contrasena_corta_o_la_demo(): void
    {
        $this->enProduccion();

        foreach (['corta123', FilamentAdminSeeder::PASSWORD_DEMO] as $invalida) {
            config(['instalacion.super_admin_password' => $invalida]);

            try {
                (new FilamentAdminSeeder)->run();
                $this->fail("El seeder aceptó «{$invalida}».");
            } catch (RuntimeException) {
                $this->assertSame(0, User::where('es_super_admin', true)->count());
            }
        }
    }

    public function test_en_produccion_crea_los_super_admins_con_la_contrasena_configurada(): void
    {
        $this->enProduccion();
        config(['instalacion.super_admin_password' => 'Una-Clave-Larga-2026']);

        (new FilamentAdminSeeder)->run();

        $admin = User::where('es_super_admin', true)->firstOrFail();

        $this->assertTrue(Hash::check('Una-Clave-Larga-2026', $admin->password));
        $this->assertFalse(Hash::check(FilamentAdminSeeder::PASSWORD_DEMO, $admin->password));
    }

    public function test_no_pisa_la_contrasena_de_un_super_admin_que_ya_existe(): void
    {
        (new FilamentAdminSeeder)->run();
        $admin = User::where('es_super_admin', true)->firstOrFail();
        $admin->update(['password' => Hash::make('cambiada-por-el-usuario')]);

        config(['instalacion.super_admin_password' => 'Otra-Clave-Larga-2026']);
        (new FilamentAdminSeeder)->run();

        $this->assertTrue(Hash::check('cambiada-por-el-usuario', $admin->fresh()->password));
    }
}
