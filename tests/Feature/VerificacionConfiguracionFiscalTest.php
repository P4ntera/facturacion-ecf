<?php

namespace Tests\Feature;

use App\Enums\AmbienteEcf;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Filament\Pages\ManageEmpresa;
use App\Jobs\EnviarEcfJob;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Services\Dgii\EnvioEcfService;
use App\Services\VentaService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cierre de T3: junto con DgiiGatewayTest, CertificadoP12Test, UsaEcfTest y
 * ConfiguracionSettingsTest, cubre el checklist de verificación pedido — dos empresas con
 * configuración distinta, la cola usando la api key de la empresa correcta (no la del tenant
 * activo), certificado privado/no reutilizable entre empresas, usa_ecf=false ocultando el
 * módulo fiscal, y aislamiento general — con foco en lo que ningún test anterior probaba todavía:
 * el comportamiento de la cola y el cruce de certificados entre empresas.
 */
class VerificacionConfiguracionFiscalTest extends TestCase
{
    use RefreshDatabase;

    private function secuencia(Empresa $empresa, string $prefijo): void
    {
        SecuenciaNcf::create([
            'empresa_id' => $empresa->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'prefijo' => $prefijo,
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);
    }

    private function producto(Empresa $empresa, string $codigo): Producto
    {
        return Producto::create([
            'empresa_id' => $empresa->id,
            'codigo' => $codigo,
            'nombre' => "Producto {$codigo}",
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 50,
            'precio' => 100,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true,
            'stock' => 100,
            'stock_minimo' => 1,
            'activo' => true,
        ]);
    }

    public function test_dos_empresas_conservan_configuracion_fiscal_independiente(): void
    {
        $empresaA = $this->empresaDefault;
        $empresaA->config()->update(['aplica_itbis' => true, 'moneda' => 'DOP']);

        $empresaB = Empresa::factory()->create();
        $empresaB->config()->update(['aplica_itbis' => false, 'moneda' => 'USD']);

        $this->secuencia($empresaA, 'E32A');
        $this->secuencia($empresaB, 'E32B');

        $productoA = $this->producto($empresaA, 'CFG-A');
        $productoB = $this->producto($empresaB, 'CFG-B');

        $clienteA = Cliente::create(['empresa_id' => $empresaA->id, 'nombre' => 'Cliente A', 'activo' => true]);
        $clienteB = Cliente::create(['empresa_id' => $empresaB->id, 'nombre' => 'Cliente B', 'activo' => true]);

        $ventaA = app(VentaService::class)->registrar([
            'cliente_id' => $clienteA->id,
            'lineas' => [['producto_id' => $productoA->id, 'cantidad' => 1]],
        ], $empresaA);

        $ventaB = app(VentaService::class)->registrar([
            'cliente_id' => $clienteB->id,
            'lineas' => [['producto_id' => $productoB->id, 'cantidad' => 1]],
        ], $empresaB);

        // A aplica ITBIS 18%: 100 + 18 = 118.00. B no aplica ITBIS: 100.00 llano, y en su moneda.
        $this->assertSame('118.00', $ventaA->total);
        $this->assertSame('DOP', $ventaA->moneda);
        $this->assertSame('100.00', $ventaB->total);
        $this->assertSame('USD', $ventaB->moneda);
    }

    /**
     * El caso crítico de "cola por empresa": el job se procesa con la empresa de la VENTA, nunca
     * con el tenant que esté activo en el proceso que lo ejecuta (en un worker real no hay tenant
     * activo en absoluto). Se fuerza el gateway real (no el fake) para verificar la petición HTTP.
     */
    public function test_el_job_de_cola_usa_la_api_key_y_base_url_de_la_empresa_de_la_venta(): void
    {
        config(['dgii.fake' => false]);
        Queue::fake();

        $empresaA = $this->empresaDefault;
        $empresaA->config()->update([
            'dgii_api_key' => 'clave-empresa-a',
            'dgii_base_url' => 'https://pac-a.test',
            'dgii_ambiente' => AmbienteEcf::TESTECF,
        ]);

        $empresaB = Empresa::factory()->create();
        $empresaB->config()->update([
            'dgii_api_key' => 'clave-empresa-b',
            'dgii_base_url' => 'https://pac-b.test',
            'dgii_ambiente' => AmbienteEcf::TESTECF,
        ]);

        $this->secuencia($empresaA, 'E32');

        $producto = $this->producto($empresaA, 'COLA-A');
        $cliente = Cliente::create(['empresa_id' => $empresaA->id, 'nombre' => 'Cliente cola', 'activo' => true]);

        $venta = app(VentaService::class)->registrar([
            'cliente_id' => $cliente->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ], $empresaA);

        // Simula un worker real: sin tenant activo (o, peor caso, con el tenant equivocado
        // activo) al momento de procesar el job — el job debe ignorarlo por completo.
        Filament::setTenant($empresaB, isQuiet: true);

        Http::fake(['*' => Http::response(['estado' => 'Aceptado', 'trackId' => 'T-A', 'pacId' => 'P-A'], 200)]);

        (new EnviarEcfJob($venta->fresh()))->handle(app(EnvioEcfService::class));

        Http::assertSent(fn ($request) => $request->url() === 'https://pac-a.test/ecf/send'
            && $request->hasHeader('X-API-Key', 'clave-empresa-a'));

        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://pac-b.test'));
    }

    /** Genera un .p12 real y autofirmado (sin fixture binaria en el repo). */
    private function generarP12(string $password): string
    {
        $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'Empresa A'], $llave);
        $cert = openssl_csr_sign($csr, null, $llave, 365);

        openssl_pkcs12_export($cert, $p12, $llave, $password);

        return $p12;
    }

    private function usuarioConfigurador(Empresa $empresa): User
    {
        Permission::firstOrCreate(['name' => 'empresa.administrar', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'facturacion.administrar', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web']);
        $rol->syncPermissions(['empresa.administrar', 'facturacion.administrar']);

        $usuario = User::factory()->create(['empresa_id' => $empresa->id]);
        $usuario->assignRole('Administrador');

        return $usuario;
    }

    /**
     * Un usuario de la empresa B no puede "adoptar" el certificado ya subido por la empresa A
     * manipulando el estado del campo de subida (el state de un FileUpload es client-controllable
     * en Livewire): la validación en ManageEmpresa::validarCertificado() exige que la ruta caiga
     * dentro del directorio propio de la empresa activa.
     */
    public function test_el_certificado_de_una_empresa_no_puede_ser_adoptado_por_otra(): void
    {
        Storage::fake('local');

        $empresaA = $this->empresaDefault;
        $empresaB = Empresa::factory()->create();

        // Empresa A sube su propio certificado legítimamente.
        Livewire::actingAs($this->usuarioConfigurador($empresaA))
            ->test(ManageEmpresa::class)
            ->fillForm([
                'razon_social' => 'Empresa A',
                'nombre_comercial' => 'A',
                'rnc' => '130123456',
                'dgii_ambiente' => AmbienteEcf::TESTECF->value,
                'dgii_base_url' => 'https://pac.test',
                'certificado_upload' => UploadedFile::fake()->createWithContent('a.p12', $this->generarP12('clave-a')),
                'certificado_password' => 'clave-a',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $rutaCertificadoA = $empresaA->config()->fresh()->certificado_path;
        $this->assertNotNull($rutaCertificadoA);

        // Empresa B intenta, en su propia página de configuración, apuntar el campo de subida a
        // la ruta ya existente del certificado de A (simulando un state de Livewire manipulado).
        Filament::setTenant($empresaB, isQuiet: true);
        setPermissionsTeamId($empresaB->id);

        $componente = Livewire::actingAs($this->usuarioConfigurador($empresaB))
            ->test(ManageEmpresa::class)
            ->fillForm([
                'razon_social' => 'Empresa B',
                'nombre_comercial' => 'B',
                'rnc' => '130654321',
                'dgii_ambiente' => AmbienteEcf::TESTECF->value,
                'dgii_base_url' => 'https://pac.test',
                'certificado_password' => 'clave-a',
            ]);

        // El FileUpload guarda su estado interno como array [uuid => ruta] (ver
        // FileUploadStateCast::set()); un cliente manipulado enviaría exactamente esta forma.
        $componente->set('data.certificado_upload', ['tampered-uuid' => $rutaCertificadoA]);
        $componente->call('save');

        $this->assertFalse($empresaB->config()->fresh()->tieneCertificado());
        // El certificado legítimo de A no se ve afectado por el intento de adopción de B.
        Storage::disk('local')->assertExists($rutaCertificadoA);
    }
}
