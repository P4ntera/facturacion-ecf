<?php

namespace Tests\Feature;

use App\Console\Commands\VerificarInstalacionCommand;
use App\Services\RespaldoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RespaldoTest extends TestCase
{
    use RefreshDatabase;

    private string $directorio;

    private string $copia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directorio = sys_get_temp_dir().DIRECTORY_SEPARATOR.'erp-respaldo-test-'.uniqid();
        $this->copia = $this->directorio.'-copia';
        File::ensureDirectoryExists($this->directorio);

        config([
            'instalacion.respaldo.directorio' => $this->directorio,
            'instalacion.respaldo.copia_directorio' => null,
            'instalacion.respaldo.retencion_dias' => 14,
            'instalacion.respaldo.pg_dump' => 'pg_dump',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directorio);
        File::deleteDirectory($this->copia);

        parent::tearDown();
    }

    /** pg_dump tiene que existir y ser de la misma versión mayor (o superior) que el servidor. */
    private function requierePgDumpCompatible(): void
    {
        try {
            $proceso = new Process(['pg_dump', '--version']);
            $proceso->run();
        } catch (\Throwable) {
            $this->markTestSkipped('pg_dump no está instalado en este entorno.');
        }

        if (! $proceso->isSuccessful() || preg_match('/(\d+)(?:\.\d+)?/', $proceso->getOutput(), $m) !== 1) {
            $this->markTestSkipped('pg_dump no está instalado en este entorno.');
        }

        $servidor = intdiv((int) DB::selectOne('show server_version_num')->server_version_num, 10000);

        if ((int) $m[1] < $servidor) {
            $this->markTestSkipped("pg_dump {$m[1]} es más viejo que el servidor PostgreSQL {$servidor}.");
        }
    }

    public function test_crea_el_volcado_de_la_base_y_el_zip_de_archivos(): void
    {
        $this->requierePgDumpCompatible();

        $resultado = app(RespaldoService::class)->crear();

        $this->assertFileExists($resultado['base']);
        $this->assertGreaterThan(0, filesize($resultado['base']));
        $this->assertStringStartsWith('PGDMP', (string) file_get_contents($resultado['base'], length: 5));
        $this->assertFileExists((string) $resultado['archivos']);
        $this->assertNull($resultado['copia']);
        $this->assertNotEmpty($resultado['avisos'], 'Debe avisar que el respaldo quedó solo en este equipo.');
        $this->assertNotNull(app(RespaldoService::class)->ultimoRespaldo());
    }

    public function test_copia_el_respaldo_a_la_segunda_ubicacion(): void
    {
        $this->requierePgDumpCompatible();
        File::ensureDirectoryExists($this->copia);
        config(['instalacion.respaldo.copia_directorio' => $this->copia]);

        $resultado = app(RespaldoService::class)->crear();

        $this->assertSame($this->copia, $resultado['copia']);
        $this->assertFileExists($this->copia.DIRECTORY_SEPARATOR.basename($resultado['base']));
        $this->assertSame([], $resultado['avisos']);
    }

    public function test_si_la_segunda_ubicacion_no_esta_disponible_avisa_pero_no_falla(): void
    {
        $this->requierePgDumpCompatible();
        config(['instalacion.respaldo.copia_directorio' => $this->copia.'-desconectado']);

        $resultado = app(RespaldoService::class)->crear();

        $this->assertFileExists($resultado['base']);
        $this->assertNull($resultado['copia']);
        $this->assertStringContainsString('No se pudo copiar', implode(' ', $resultado['avisos']));
    }

    public function test_depura_solo_los_respaldos_propios_vencidos(): void
    {
        $viejo = $this->directorio.DIRECTORY_SEPARATOR.'erp-20200101-000000.dump';
        $viejoZip = $this->directorio.DIRECTORY_SEPARATOR.'erp-20200101-000000-archivos.zip';
        $reciente = $this->directorio.DIRECTORY_SEPARATOR.'erp-20991231-000000.dump';
        $ajeno = $this->directorio.DIRECTORY_SEPARATOR.'contabilidad-2020.dump';

        foreach ([$viejo, $viejoZip, $reciente, $ajeno] as $archivo) {
            file_put_contents($archivo, 'x');
        }

        $hace30Dias = now()->subDays(30)->getTimestamp();
        touch($viejo, $hace30Dias);
        touch($viejoZip, $hace30Dias);
        touch($ajeno, $hace30Dias);

        $eliminados = app(RespaldoService::class)->depurar($this->directorio);

        $this->assertSame(2, $eliminados);
        $this->assertFileDoesNotExist($viejo);
        $this->assertFileDoesNotExist($viejoZip);
        $this->assertFileExists($reciente);
        $this->assertFileExists($ajeno, 'Nunca debe borrar archivos que no generó.');
    }

    public function test_sin_pg_dump_el_comando_falla_limpio_y_no_deja_archivos_a_medias(): void
    {
        config(['instalacion.respaldo.pg_dump' => 'pg_dump_que_no_existe']);

        $this->artisan('erp:respaldo')->assertFailed();

        $this->assertSame([], glob($this->directorio.DIRECTORY_SEPARATOR.'*.dump'));
        $this->assertNull(app(RespaldoService::class)->ultimoRespaldo());
    }

    public function test_el_verificador_exige_la_version_minima_de_postgres(): void
    {
        $this->assertTrue(VerificarInstalacionCommand::postgresCumple(180001, 18));
        $this->assertTrue(VerificarInstalacionCommand::postgresCumple(190000, 18));
        $this->assertFalse(VerificarInstalacionCommand::postgresCumple(160015, 18));
        $this->assertFalse(VerificarInstalacionCommand::postgresCumple(170004, 18));
    }

    public function test_el_verificador_corre_sin_modificar_nada(): void
    {
        $this->artisan('erp:verificar-instalacion')
            ->expectsOutputToContain('PostgreSQL')
            ->expectsOutputToContain('Último respaldo: nunca');

        $this->assertSame([], glob($this->directorio.DIRECTORY_SEPARATOR.'*'));
    }
}
