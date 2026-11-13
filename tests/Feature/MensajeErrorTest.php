<?php

namespace Tests\Feature;

use App\Exceptions\StockInsuficienteException;
use App\Exceptions\VentaInvalidaException;
use App\Support\MensajeError;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class MensajeErrorTest extends TestCase
{
    public function test_las_excepciones_de_negocio_muestran_su_propio_mensaje(): void
    {
        Log::spy();

        $this->assertSame('La caja está cerrada.', MensajeError::para(new RuntimeException('La caja está cerrada.')));
        $this->assertSame('Sin comprobante.', MensajeError::para(new VentaInvalidaException('Sin comprobante.')));
        $this->assertTrue(MensajeError::esDeNegocio(new \InvalidArgumentException('Cantidad inválida.')));

        Log::shouldNotHaveReceived('error');
    }

    public function test_un_error_de_base_de_datos_nunca_muestra_sql_al_usuario(): void
    {
        Log::spy();

        try {
            DB::select('select * from tabla_que_no_existe');
            $this->fail('La consulta debió fallar.');
        } catch (QueryException $e) {
            // QueryException ES un RuntimeException: los catch (RuntimeException) la atrapan.
            $this->assertInstanceOf(RuntimeException::class, $e);

            $mensaje = MensajeError::para($e);
        }

        $this->assertStringStartsWith(MensajeError::GENERICO, $mensaje);
        $this->assertStringNotContainsStringIgnoringCase('select', $mensaje);
        $this->assertStringNotContainsString('tabla_que_no_existe', $mensaje);
        $this->assertStringNotContainsString('SQLSTATE', $mensaje);
        $this->assertMatchesRegularExpression('/\(ref\. [A-Z0-9]{6}\)\.$/', $mensaje);

        Log::shouldHaveReceived('error')->once();
    }

    public function test_un_bug_de_programacion_tampoco_se_muestra_y_queda_en_el_log(): void
    {
        Log::spy();

        $mensaje = MensajeError::para(new \TypeError('Argument #1 ($x) must be of type int, string given'));

        $this->assertStringStartsWith(MensajeError::GENERICO, $mensaje);
        $this->assertStringNotContainsString('Argument', $mensaje);

        Log::shouldHaveReceived('error')->once();
    }

    public function test_la_referencia_mostrada_es_la_misma_que_queda_en_el_log(): void
    {
        $registrado = null;
        Log::shouldReceive('error')->once()->withArgs(function (string $texto) use (&$registrado) {
            $registrado = $texto;

            return true;
        });

        $mensaje = MensajeError::para(new \ErrorException('fallo interno'));

        preg_match('/ref\. ([A-Z0-9]{6})/', $mensaje, $m);

        $this->assertStringContainsString("[ref {$m[1]}]", (string) $registrado);
    }

    public function test_una_validacion_muestra_su_primer_mensaje(): void
    {
        $e = ValidationException::withMessages(['cantidad' => ['La cantidad debe ser mayor que cero.']]);

        $this->assertSame('La cantidad debe ser mayor que cero.', MensajeError::para($e));
    }

    public function test_las_subclases_de_infraestructura_no_cuentan_como_negocio(): void
    {
        $this->assertFalse(MensajeError::esDeNegocio(new \PDOException('SQLSTATE[08006]')));
        $this->assertFalse(MensajeError::esDeNegocio(new ConnectionException('cURL error 6')));
        $this->assertFalse(MensajeError::esDeNegocio(new RuntimeException('')));
        $this->assertTrue(MensajeError::esDeNegocio(new StockInsuficienteException('Stock insuficiente.')));
    }
}
