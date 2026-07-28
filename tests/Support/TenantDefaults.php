<?php

namespace Tests\Support;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\DevolucionCompra;
use App\Models\Empresa;
use App\Models\Impresora;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Model;

/**
 * La mayoría de los tests existentes se escribieron antes del tenancy y crean sus modelos con
 * Model::create([...]) sin empresa_id. En vez de tocar archivo por archivo, Tests\TestCase pide
 * aquí una empresa por defecto en cada setUp() y esta clase rellena empresa_id con ella en
 * cualquier creación que no lo traiga explícito (nunca pisa un valor ya puesto): así, todo lo que
 * un test crea sin pensar en tenancy cae junto en la misma empresa "de fondo", y lo que sí
 * necesita varias empresas (tests de aislamiento) sigue funcionando con normalidad porque solo
 * rellena huecos.
 */
class TenantDefaults
{
    private const MODELOS = [
        Categoria::class,
        Producto::class,
        Cliente::class,
        Proveedor::class,
        SecuenciaNcf::class,
        Venta::class,
        Compra::class,
        MovimientoInventario::class,
        Impresora::class,
        DevolucionCompra::class,
        User::class,
    ];

    private static ?int $empresaId = null;

    public static function reiniciar(Empresa $empresa): void
    {
        self::$empresaId = $empresa->id;
        self::registrarHooks();
    }

    /**
     * Cada test arranca con un Model::setEventDispatcher() nuevo (ver
     * InteractsWithTestCaseLifecycle::setUpTheTestEnvironment(), llamado dentro de
     * parent::setUp() antes de este método): los listeners de Model::creating() del test
     * anterior quedan huérfanos en el dispatcher viejo, así que hay que volver a registrarlos
     * en cada test, no una sola vez.
     */
    private static function registrarHooks(): void
    {
        foreach (self::MODELOS as $modelo) {
            $modelo::creating(function (Model $registro): void {
                if (blank($registro->getAttribute('empresa_id')) && self::$empresaId !== null) {
                    $registro->setAttribute('empresa_id', self::$empresaId);
                }
            });
        }
    }
}
