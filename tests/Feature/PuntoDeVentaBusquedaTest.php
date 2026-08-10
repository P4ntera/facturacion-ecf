<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoProducto;
use App\Enums\TipoVenta;
use App\Filament\Pages\PuntoDeVenta;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PuntoDeVentaBusquedaTest extends TestCase
{
    use RefreshDatabase;

    private function vendedor(): User
    {
        Permission::firstOrCreate(['name' => 'pos.acceder', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'facturacion.acceder', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']);
        $rol->syncPermissions(['pos.acceder', 'facturacion.acceder']);

        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        return $usuario;
    }

    private function refrescoConTresPresentaciones(): Producto
    {
        $producto = Producto::create([
            'empresa_id' => $this->empresaDefault->id,
            'codigo' => 'REF-001',
            'nombre' => 'Refresco Cola',
            'tipo' => TipoProducto::PRODUCTO,
            'tipo_venta' => TipoVenta::CONTABLE,
            'costo' => 30,
            'precio' => 50,
            'tasa_itbis' => TasaItbis::CERO,
            'controla_stock' => true,
            'stock' => 100,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $producto->presentaciones()->create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Unidad',
            'factor' => 1,
            'codigo_barra' => '750101',
            'precio' => 50,
            'es_base' => true,
            'activa' => true,
        ]);

        $producto->presentaciones()->create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Six-pack',
            'factor' => 6,
            'codigo_barra' => '750102',
            'precio' => 270,
            'es_base' => false,
            'activa' => true,
        ]);

        $producto->presentaciones()->create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Caja',
            'factor' => 24,
            'codigo_barra' => '750103',
            'precio' => 1000,
            'es_base' => false,
            'activa' => true,
        ]);

        return $producto;
    }

    private function habichuelas(): Producto
    {
        return Producto::create([
            'empresa_id' => $this->empresaDefault->id,
            'codigo' => 'HAB-001',
            'nombre' => 'Habichuelas',
            'tipo' => TipoProducto::PRODUCTO,
            'tipo_venta' => TipoVenta::PESADO,
            'unidad_base' => 'libra',
            'precio_por_peso' => 45,
            'costo' => 20,
            'precio' => 0,
            'tasa_itbis' => TasaItbis::CERO,
            'controla_stock' => true,
            'stock' => 50,
            'stock_minimo' => 0,
            'activo' => true,
        ]);
    }

    /** 1. Buscar por nombre trae una fila por cada presentación, cada una con su etiqueta y precio. */
    public function test_buscar_por_nombre_devuelve_una_fila_por_presentacion(): void
    {
        $this->refrescoConTresPresentaciones();

        $componente = Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', 'Refresco');

        $filas = $componente->instance()->resultadosBusqueda();

        $this->assertCount(3, $filas);
        // La base va primero; el resto, alfabético ("Caja" antes que "Six-pack").
        $this->assertSame(
            ['Refresco Cola · Unidad', 'Refresco Cola · Caja', 'Refresco Cola · Six-pack'],
            $filas->pluck('etiqueta')->all(),
        );
        $this->assertSame('RD$ 1,000.00', $filas->firstWhere('etiqueta', 'Refresco Cola · Caja')['precio_texto']);
    }

    /** 1b. Elegir la fila "Caja" desde la lista agrega esa presentación (no la base). */
    public function test_elegir_la_fila_de_una_presentacion_la_agrega_al_carrito(): void
    {
        $producto = $this->refrescoConTresPresentaciones();
        $caja = $producto->presentaciones()->where('nombre', 'Caja')->first();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', 'Refresco')
            ->call('agregarPresentacion', $caja->id)
            ->assertSet('carrito.0.presentacion_id', $caja->id)
            ->assertSet('carrito.0.nombre', 'Refresco Cola - Caja')
            ->assertSet('carrito.0.precio_unitario', '1000.00');
    }

    /** 2. Escanear el código del six-pack agrega directo, sin lista, con el foco de vuelta al buscador. */
    public function test_escanear_codigo_de_presentacion_agrega_directo_sin_mostrar_lista(): void
    {
        $producto = $this->refrescoConTresPresentaciones();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '750102')
            ->call('escanearOBuscar', '750102')
            ->assertSet('carrito.0.producto_id', $producto->id)
            ->assertSet('carrito.0.nombre', 'Refresco Cola - Six-pack')
            ->assertSet('carrito.0.precio_unitario', '270.00')
            ->assertSet('busquedaProducto', '')
            ->assertDispatched('producto-escaneado');
    }

    /**
     * El input manda $event.target.value en el keydown.enter, no depende de que el debounce del
     * wire:model.live ya haya sincronizado busquedaProducto — así no falla el escaneo rápido.
     * Aquí se simula la propiedad "atrasada" (vacía) para probar que igual encuentra el six-pack
     * porque usa el valor que llega por parámetro.
     */
    public function test_escanear_usa_el_valor_del_evento_aunque_la_propiedad_este_atrasada_por_el_debounce(): void
    {
        $producto = $this->refrescoConTresPresentaciones();

        Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->assertSet('busquedaProducto', '')
            ->call('escanearOBuscar', '750102')
            ->assertSet('carrito.0.producto_id', $producto->id)
            ->assertSet('carrito.0.nombre', 'Refresco Cola - Six-pack');
    }

    /**
     * 3. Escribir el código de barras completo de la unidad la encuentra igual que buscar por
     * nombre: el producto entra a la lista (con todas sus presentaciones como filas, igual que
     * buscando "Refresco") en vez de quedar en blanco como pasaba antes cuando la búsqueda solo
     * miraba codigo/nombre del producto.
     */
    public function test_escribir_codigo_de_barras_completo_de_una_presentacion_la_encuentra_en_la_lista(): void
    {
        $this->refrescoConTresPresentaciones();

        $componente = Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '750101');

        $filas = $componente->instance()->resultadosBusqueda();

        $this->assertCount(3, $filas);
        $this->assertTrue($filas->pluck('etiqueta')->contains('Refresco Cola · Unidad'));
    }

    /** Un código de barras a medias (escaneo en curso) no da falsos positivos: solo el exacto. */
    public function test_codigo_de_barras_parcial_no_encuentra_nada_en_la_lista(): void
    {
        $this->refrescoConTresPresentaciones();

        $componente = Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', '75010');

        $this->assertCount(0, $componente->instance()->resultadosBusqueda());
    }

    /** 4. Producto pesado: una sola fila (sin presentaciones), marcada como tipo "pesado". */
    public function test_buscar_producto_pesado_devuelve_una_sola_fila_marcada_como_pesado(): void
    {
        $this->habichuelas();

        $componente = Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', 'Habichuelas');

        $filas = $componente->instance()->resultadosBusqueda();

        $this->assertCount(1, $filas);
        $this->assertSame('pesado', $filas->first()['tipo']);
        $this->assertSame('Habichuelas', $filas->first()['etiqueta']);
        $this->assertSame('RD$ 45.00 / libra', $filas->first()['precio_texto']);
    }

    /** Compatibilidad: un producto CONTABLE sin ninguna presentación cargada sigue apareciendo (una fila suelta). */
    public function test_producto_contable_sin_presentaciones_aparece_como_una_fila_suelta(): void
    {
        $producto = Producto::create([
            'empresa_id' => $this->empresaDefault->id,
            'codigo' => 'LEG-001',
            'nombre' => 'Producto legado',
            'tipo' => TipoProducto::PRODUCTO,
            'tipo_venta' => TipoVenta::CONTABLE,
            'costo' => 5,
            'precio' => 20,
            'tasa_itbis' => TasaItbis::CERO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $componente = Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', 'legado');

        $filas = $componente->instance()->resultadosBusqueda();

        $this->assertCount(1, $filas);
        $this->assertNull($filas->first()['presentacion_id']);
        $this->assertSame($producto->id, $filas->first()['producto_id']);
    }

    /** Buscar por el nombre de una presentación (no solo del producto) también encuentra el producto. */
    public function test_buscar_por_nombre_de_presentacion_encuentra_la_fila(): void
    {
        $this->refrescoConTresPresentaciones();

        $componente = Livewire::actingAs($this->vendedor())
            ->test(PuntoDeVenta::class)
            ->set('busquedaProducto', 'six-pack');

        $filas = $componente->instance()->resultadosBusqueda();

        $this->assertTrue($filas->pluck('etiqueta')->contains('Refresco Cola · Six-pack'));
    }
}
