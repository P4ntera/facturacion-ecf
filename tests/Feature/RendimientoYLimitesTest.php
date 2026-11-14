<?php

namespace Tests\Feature;

use App\Enums\FormaPago;
use App\Enums\PerfilEmpresa;
use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Filament\Pages\Reportes\ReporteHeladeriaTopProductos;
use App\Filament\Pages\Reportes\ReporteHeladeriaVentasDiarias;
use App\Filament\Pages\Reportes\ReporteHeladeriaVentasPorTipo;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\User;
use App\Services\DgiiRncService;
use App\Services\HeladeriaService;
use App\Services\ModulosEmpresaService;
use App\Services\ReporteService;
use App\Services\RolesEmpresaService;
use App\Support\ConsultaExterna;
use App\Support\RegistroDeLentitud;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

class RendimientoYLimitesTest extends TestCase
{
    use RefreshDatabase;

    private function respuestaRnc(string $nombre = 'COLMADO LA FE SRL'): array
    {
        return ['error' => false, 'cedula_rnc' => '131000001', 'nombre_razon_social' => $nombre, 'estado' => 'ACTIVO'];
    }

    // ----------------------------------------------------------- reportes de heladería

    public function test_los_tres_reportes_de_heladeria_cargan_dentro_del_panel(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $empresa = Empresa::factory()->create(['perfil' => PerfilEmpresa::HELADERIA]);
        app(RolesEmpresaService::class)->sembrarRolesBase($empresa);
        app(ModulosEmpresaService::class)->sembrarModulos($empresa);
        $admin = User::factory()->create(['empresa_id' => $empresa->id]);
        app(RolesEmpresaService::class)->asignarAdministrador($admin, $empresa);

        Filament::setTenant($empresa, isQuiet: true);
        setPermissionsTeamId($empresa->id);

        // Petición HTTP real: es dentro del panel donde Filament agrega el filtro de empresa al
        // modelo Producto, que rompía dos de estos reportes (columna productos.empresa_id).
        // Con ventas reales de los tres tipos (armado, combo y directo): vacíos, los reportes no
        // llegan a hidratar filas y esconden errores de los datos.
        $empresa->config()->update(['permite_ventas_sin_comprobante' => true]);
        $directo = Producto::create([
            'empresa_id' => $empresa->id, 'codigo' => 'PAL-1', 'nombre' => 'Paleta', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 10, 'precio' => 30, 'tasa_itbis' => TasaItbis::DIECIOCHO, 'controla_stock' => false,
            'stock' => 0, 'stock_minimo' => 0, 'activo' => true,
        ]);
        $this->actingAs($admin->fresh());
        app(HeladeriaService::class)->facturar([
            ['tipo' => 'armado', 'descripcion' => 'Cono: Vainilla', 'precio' => 100, 'cantidad' => 2],
            ['tipo' => 'combo', 'descripcion' => 'Combo Familiar', 'precio' => 300, 'cantidad' => 1],
            ['tipo' => 'directo', 'descripcion' => 'Paleta', 'precio' => 30, 'cantidad' => 3, 'detalle' => ['producto_id' => $directo->id]],
        ], $empresa, ['user_id' => $admin->id, 'forma_pago' => FormaPago::EFECTIVO]);

        foreach ([ReporteHeladeriaTopProductos::class, ReporteHeladeriaVentasPorTipo::class, ReporteHeladeriaVentasDiarias::class] as $pagina) {
            $this->actingAs($admin->fresh())->get($pagina::getUrl(tenant: $empresa))->assertOk();
        }

        $porTipo = app(ReporteService::class)
            ->heladeriaVentasPorTipoQuery(now()->subDay(), now()->addDay(), $empresa->id)->get()
            ->pluck('ingresos', 'grupo_heladeria')->map(fn ($v) => (float) $v)->all();

        $this->assertEqualsCanonicalizing(['Armado', 'Combo'], array_keys(array_intersect_key($porTipo, ['Armado' => 1, 'Combo' => 1])));
        $this->assertEqualsWithDelta(200.0, $porTipo['Armado'], 0.01);
        $this->assertEqualsWithDelta(300.0, $porTipo['Combo'], 0.01);

        // Los mismos reportes en PDF (ruta fuera del panel).
        foreach (['heladeria-top-productos', 'heladeria-ventas-tipo', 'heladeria-ventas-diarias'] as $ruta) {
            $this->actingAs($admin->fresh())
                ->get(route("reportes.{$ruta}.pdf", ['empresa' => $empresa->id]))
                ->assertOk();
        }
    }

    // ------------------------------------------------------------- consultas externas

    public function test_la_consulta_de_rnc_se_guarda_en_cache_y_no_vuelve_a_salir_a_internet(): void
    {
        Http::fake(['rnc.megaplus.com.do/*' => Http::response($this->respuestaRnc())]);

        $primera = app(DgiiRncService::class)->consultarRnc('131000001');
        $segunda = app(DgiiRncService::class)->consultarRnc('131-00000-1');

        $this->assertSame('COLMADO LA FE SRL', $primera['nombre']);
        $this->assertSame($primera, $segunda);
        Http::assertSentCount(1);
    }

    public function test_un_rnc_no_encontrado_tambien_se_recuerda_un_momento(): void
    {
        Http::fake(['rnc.megaplus.com.do/*' => Http::response(['error' => true], 404)]);

        $this->assertNull(app(DgiiRncService::class)->consultarRnc('131000002'));
        $this->assertNull(app(DgiiRncService::class)->consultarRnc('131000002'));

        Http::assertSentCount(1);
    }

    public function test_las_consultas_nuevas_tienen_un_limite_por_minuto(): void
    {
        Http::fake(['rnc.megaplus.com.do/*' => Http::response($this->respuestaRnc())]);
        $servicio = app(DgiiRncService::class);

        foreach (range(1, ConsultaExterna::POR_MINUTO) as $i) {
            $servicio->consultarRnc('1310'.str_pad((string) $i, 5, '0', STR_PAD_LEFT));
        }

        // Lo que ya está en caché no gasta cupo...
        $this->assertNotNull($servicio->consultarRnc('131000001'));

        // ...pero una consulta nueva más ya no sale.
        try {
            $servicio->consultarRnc('131099999');
            $this->fail('Debió rechazar la consulta por exceso.');
        } catch (TooManyRequestsHttpException $e) {
            $this->assertStringContainsString('Demasiadas consultas', $e->getMessage());
        }

        Http::assertSentCount(ConsultaExterna::POR_MINUTO);
    }

    // -------------------------------------------------------------------- límites de uso

    public function test_sin_sesion_las_rutas_protegidas_llevan_al_login_en_vez_de_dar_error(): void
    {
        // Antes: error 500 ("Route [login] not defined") al abrir un PDF con la sesión vencida.
        $this->get('/rnc/131000001')->assertRedirect(route('filament.admin.auth.login'));
        $this->get('/reportes/ventas/pdf')->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_la_consulta_de_rnc_por_url_se_corta_al_pasar_el_limite(): void
    {
        Http::fake(['rnc.megaplus.com.do/*' => Http::response($this->respuestaRnc())]);
        $usuario = User::factory()->create();

        foreach (range(1, 10) as $i) {
            $this->actingAs($usuario)->get('/rnc/131000001')->assertOk();
        }

        $this->actingAs($usuario)->get('/rnc/131000001')->assertStatus(429);
    }

    public function test_los_pdf_y_livewire_tienen_limite_de_uso(): void
    {
        $middleware = fn (string $ruta): array => Route::getRoutes()->getByName($ruta)->gatherMiddleware();

        $this->assertContains('throttle:pdf', $middleware('ventas.pdf'));
        $this->assertContains('throttle:pdf', $middleware('ventas.ticket'));
        $this->assertContains('throttle:reportes-pdf', $middleware('reportes.ventas.pdf'));
        $this->assertContains('throttle:livewire', $middleware('livewire.update'));

        foreach (['pdf', 'reportes-pdf', 'livewire'] as $limite) {
            $this->assertNotNull(RateLimiter::limiter($limite), "Falta el límite «{$limite}».");
        }
    }

    public function test_el_limite_es_por_usuario_y_no_compartido(): void
    {
        config(['instalacion.limites.livewire' => 3]);
        $limite = RateLimiter::limiter('livewire');

        $peticion = function (int $usuarioId) {
            $request = request()->duplicate();
            $request->setUserResolver(fn () => (new User)->forceFill(['id' => $usuarioId]));

            return $request;
        };

        $this->assertSame(3, $limite($peticion(1))->maxAttempts);
        $this->assertNotSame($limite($peticion(1))->key, $limite($peticion(2))->key);
    }

    // ------------------------------------------------------------------ registro de lentitud

    public function test_una_consulta_lenta_queda_registrada_sin_los_valores(): void
    {
        config(['instalacion.lento.consulta_ms' => 20, 'instalacion.lento.peticion_ms' => 0]);
        RegistroDeLentitud::registrar();

        $registrado = [];
        Log::shouldReceive('channel')->with('lento')->andReturnUsing(function () use (&$registrado) {
            return new class($registrado)
            {
                public function __construct(private array &$destino) {}

                public function warning(string $mensaje, array $contexto = []): void
                {
                    $this->destino[] = [$mensaje, $contexto];
                }
            };
        });

        DB::select('select pg_sleep(0.05), ?::text as secreto', ['cedula-00112345678']);
        DB::select('select 1');

        $this->assertCount(1, $registrado, 'Solo la consulta lenta debe quedar registrada.');
        $this->assertSame('consulta lenta', $registrado[0][0]);
        $this->assertGreaterThanOrEqual(20, $registrado[0][1]['ms']);
        $this->assertStringContainsString('pg_sleep', $registrado[0][1]['sql']);
        $this->assertStringNotContainsString('00112345678', json_encode($registrado[0][1]));
    }

    public function test_el_resumen_de_lentitud_agrupa_lo_que_mas_se_repite(): void
    {
        $carpeta = sys_get_temp_dir().'/erp-lento-'.uniqid();
        mkdir($carpeta.'/logs', 0777, true);
        $this->app->useStoragePath($carpeta);

        $linea = fn (string $tipo, array $datos) => '['.now()->toDateTimeString().'] testing.WARNING: '.$tipo.' '.json_encode($datos, JSON_UNESCAPED_UNICODE);
        file_put_contents($carpeta.'/logs/lento-'.now()->toDateString().'.log', implode("\n", [
            $linea('consulta lenta', ['ms' => 900, 'sql' => 'select * from "ventas" where "fecha" > ?', 'origen' => 'POST /livewire/update', 'componente' => 'app.filament.pages.reportes.reporte-ventas']),
            $linea('consulta lenta', ['ms' => 1400, 'sql' => 'select * from "ventas" where "fecha" > ?', 'origen' => 'POST /livewire/update', 'componente' => 'app.filament.pages.reportes.reporte-ventas']),
            $linea('petición lenta', ['ms' => 3100, 'origen' => 'GET /reportes/ventas/pdf', 'componente' => null]),
        ])."\n");

        $this->artisan('erp:lentitud')
            ->expectsOutputToContain('3 registro(s) de lentitud')
            ->expectsOutputToContain('reporte-ventas')
            ->assertSuccessful();

        File::deleteDirectory($carpeta);
    }

    public function test_sin_registros_el_resumen_lo_dice(): void
    {
        $carpeta = sys_get_temp_dir().'/erp-lento-'.uniqid();
        mkdir($carpeta.'/logs', 0777, true);
        $this->app->useStoragePath($carpeta);

        $this->artisan('erp:lentitud')->expectsOutputToContain('Sin consultas ni peticiones lentas')->assertSuccessful();

        File::deleteDirectory($carpeta);
    }
}
