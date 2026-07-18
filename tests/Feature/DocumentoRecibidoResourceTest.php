<?php

namespace Tests\Feature;

use App\Enums\CanalRecepcionEcf;
use App\Enums\EstadoAprobacionComercial;
use App\Enums\EstadoReenvioPac;
use App\Filament\Resources\DocumentoRecibidoResource;
use App\Filament\Resources\DocumentoRecibidoResource\Pages\ListDocumentosRecibidos;
use App\Models\DocumentoRecibido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentoRecibidoResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'ecf.gestionar', 'guard_name' => 'web']);
    }

    private function usuarioConPermiso(): User
    {
        $rol = Role::firstOrCreate(['name' => 'Rol-gestionar-ecf', 'guard_name' => 'web']);
        $rol->syncPermissions(['ecf.gestionar']);

        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    private function crearRecibido(array $atributos = []): DocumentoRecibido
    {
        return DocumentoRecibido::create(array_merge([
            'canal' => CanalRecepcionEcf::RECEPCION,
            'rnc_destino' => '130000000',
            'rnc_emisor' => '999888777',
            'razon_social_emisor' => 'Proveedor ABC',
            'encf' => 'E320000000123',
            'tipo_comprobante' => '32',
            'monto_total' => '1180.00',
            'fecha_emision' => '2026-07-01',
            'xml' => '<ECF></ECF>',
            'estado_reenvio' => EstadoReenvioPac::REENVIADO,
        ], $atributos));
    }

    public function test_un_usuario_sin_permiso_no_puede_acceder(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(DocumentoRecibidoResource::getUrl())
            ->assertForbidden();
    }

    public function test_lista_solo_documentos_del_canal_recepcion(): void
    {
        $recibido = $this->crearRecibido();
        $aprobacion = $this->crearRecibido(['canal' => CanalRecepcionEcf::APROBACION_COMERCIAL, 'encf' => 'E320000000999']);

        Livewire::actingAs($this->usuarioConPermiso())
            ->test(ListDocumentosRecibidos::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$recibido])
            ->assertCanNotSeeTableRecords([$aprobacion]);
    }

    public function test_filtra_por_tipo_de_comprobante_y_por_emisor(): void
    {
        $consumo = $this->crearRecibido(['tipo_comprobante' => '32', 'razon_social_emisor' => 'Proveedor Uno']);
        $creditoFiscal = $this->crearRecibido(['tipo_comprobante' => '31', 'razon_social_emisor' => 'Proveedor Dos', 'encf' => 'E310000000001']);

        Livewire::actingAs($this->usuarioConPermiso())
            ->test(ListDocumentosRecibidos::class)
            ->filterTable('tipo_comprobante', '31')
            ->assertCanSeeTableRecords([$creditoFiscal])
            ->assertCanNotSeeTableRecords([$consumo])
            ->removeTableFilter('tipo_comprobante')
            ->filterTable('emisor', ['valor' => 'Proveedor Uno'])
            ->assertCanSeeTableRecords([$consumo])
            ->assertCanNotSeeTableRecords([$creditoFiscal]);
    }

    public function test_aprobacion_comercial_actualiza_el_registro(): void
    {
        $recibido = $this->crearRecibido();

        Livewire::actingAs($this->usuarioConPermiso())
            ->test(ListDocumentosRecibidos::class)
            ->callTableAction('aprobarComercialmente', $recibido, data: ['decision' => EstadoAprobacionComercial::ACEPTADO->value])
            ->assertHasNoTableActionErrors();

        $this->assertSame(EstadoAprobacionComercial::ACEPTADO, $recibido->refresh()->aprobacion_comercial);
    }
}
