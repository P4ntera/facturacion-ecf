<?php

namespace Tests\Feature;

use App\Enums\TipoComprobante;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Services\SecuenciaNcfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecuenciaNcfServiceTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioAutorizado(): User
    {
        Permission::firstOrCreate(['name' => 'secuencias.administrar', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web']);
        $rol->syncPermissions(['secuencias.administrar']);

        $usuario = User::factory()->create();
        $usuario->assignRole('Administrador');

        return $usuario;
    }

    /** Secuencia que arranca ya bajo el umbral de alerta (50 restantes por defecto). */
    private function secuenciaCercaDeAgotarse(): SecuenciaNcf
    {
        return SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO->value,
            'prefijo' => 'E32',
            'secuencia_desde' => 1,
            'secuencia_actual' => 951, // quedan 50 de 1000 -> justo en el umbral
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);
    }

    public function test_alerta_por_agotarse_se_envia_una_sola_vez(): void
    {
        $this->usuarioAutorizado();
        $secuencia = $this->secuenciaCercaDeAgotarse();

        app(SecuenciaNcfService::class)->siguiente(TipoComprobante::FACTURA_CONSUMO, $this->empresaDefault);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotNull($secuencia->fresh()->alerta_agotamiento_enviada_en);

        // Segundo y tercer consumo, todavía bajo el umbral: no debe generar notificaciones nuevas.
        app(SecuenciaNcfService::class)->siguiente(TipoComprobante::FACTURA_CONSUMO, $this->empresaDefault);
        app(SecuenciaNcfService::class)->siguiente(TipoComprobante::FACTURA_CONSUMO, $this->empresaDefault);

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_flag_reseteado_no_dispara_alerta_si_el_rango_extendido_ya_tiene_margen(): void
    {
        $this->usuarioAutorizado();
        $secuencia = $this->secuenciaCercaDeAgotarse();

        app(SecuenciaNcfService::class)->siguiente(TipoComprobante::FACTURA_CONSUMO, $this->empresaDefault);
        $this->assertDatabaseCount('notifications', 1);

        // Simula lo que EditSecuenciaNcf hace al extender el rango: resetea el flag.
        $secuencia->update(['secuencia_hasta' => 2000, 'alerta_agotamiento_enviada_en' => null]);

        // Sigue bajo el (nuevo) umbral respecto al límite anterior... pero ahora hay margen de
        // sobra (quedan 1049 de 2000), así que no se re-dispara todavía.
        app(SecuenciaNcfService::class)->siguiente(TipoComprobante::FACTURA_CONSUMO, $this->empresaDefault);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNull($secuencia->fresh()->alerta_agotamiento_enviada_en);
    }
}
