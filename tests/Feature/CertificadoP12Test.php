<?php

namespace Tests\Feature;

use App\Enums\AmbienteEcf;
use App\Filament\Pages\ManageEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CertificadoP12Test extends TestCase
{
    use RefreshDatabase;

    private function usuarioAutorizado(): User
    {
        Permission::firstOrCreate(['name' => 'empresa.administrar', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web']);
        $rol->syncPermissions(['empresa.administrar']);

        $usuario = User::factory()->create(['empresa_id' => $this->empresaDefault->id]);
        $usuario->assignRole('Administrador');

        return $usuario;
    }

    /** Certificado .p12 real y autofirmado, generado en memoria (sin fixture binaria en el repo). */
    private function generarP12(string $password): string
    {
        $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'Empresa de Prueba'], $llave);
        $cert = openssl_csr_sign($csr, null, $llave, 365);

        openssl_pkcs12_export($cert, $p12, $llave, $password);

        return $p12;
    }

    /** @return array<string, mixed> */
    private function datosBase(): array
    {
        return [
            'razon_social' => 'Comercial Prueba SRL',
            'nombre_comercial' => 'Prueba',
            'rnc' => '130123456',
            'dgii_ambiente' => AmbienteEcf::TESTECF->value,
            'dgii_base_url' => 'https://pac.test',
        ];
    }

    public function test_sube_y_valida_un_certificado_p12_correcto(): void
    {
        Storage::fake('local');

        $archivo = UploadedFile::fake()->createWithContent('certificado.p12', $this->generarP12('clave-correcta'));

        Livewire::actingAs($this->usuarioAutorizado())
            ->test(ManageEmpresa::class)
            ->fillForm([
                ...$this->datosBase(),
                'certificado_upload' => $archivo,
                'certificado_password' => 'clave-correcta',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $config = $this->empresaDefault->config()->fresh();

        $this->assertTrue($config->tieneCertificado());
        $this->assertNotNull($config->certificado_vence);
        $this->assertSame('clave-correcta', $config->certificado_password);
        $this->assertStringStartsWith("certificados/{$this->empresaDefault->id}/", $config->certificado_path);
        Storage::disk('local')->assertExists($config->certificado_path);
    }

    public function test_rechaza_un_p12_con_contrasena_incorrecta_y_no_deja_archivos_huerfanos(): void
    {
        Storage::fake('local');

        $archivo = UploadedFile::fake()->createWithContent('certificado.p12', $this->generarP12('clave-correcta'));

        Livewire::actingAs($this->usuarioAutorizado())
            ->test(ManageEmpresa::class)
            ->fillForm([
                ...$this->datosBase(),
                'certificado_upload' => $archivo,
                'certificado_password' => 'clave-incorrecta',
            ])
            ->call('save');

        $config = $this->empresaDefault->config()->fresh();

        $this->assertFalse($config->tieneCertificado());
        $this->assertNull($config->certificado_vence);
        Storage::disk('local')->assertDirectoryEmpty("certificados/{$this->empresaDefault->id}");
    }

    public function test_rechaza_un_archivo_que_no_es_un_p12_valido(): void
    {
        Storage::fake('local');

        $archivo = UploadedFile::fake()->createWithContent('certificado.p12', 'esto no es un certificado');

        Livewire::actingAs($this->usuarioAutorizado())
            ->test(ManageEmpresa::class)
            ->fillForm([
                ...$this->datosBase(),
                'certificado_upload' => $archivo,
                'certificado_password' => 'cualquier-cosa',
            ])
            ->call('save');

        $this->assertFalse($this->empresaDefault->config()->fresh()->tieneCertificado());
    }

    public function test_el_certificado_se_guarda_en_disco_privado_y_no_es_descargable_desde_la_ui(): void
    {
        $campo = Livewire::actingAs($this->usuarioAutorizado())
            ->test(ManageEmpresa::class)
            ->instance()
            ->getSchemaComponent('form.certificado_upload');

        $this->assertNotNull($campo);
        $this->assertSame('local', $campo->getDiskName());
        $this->assertSame('private', $campo->getVisibility());
        $this->assertFalse($campo->isDownloadable());
        $this->assertFalse($campo->isOpenable());
        $this->assertFalse($campo->isPreviewable());
    }
}
