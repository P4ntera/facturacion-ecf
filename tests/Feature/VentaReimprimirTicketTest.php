<?php

namespace Tests\Feature;

use App\Enums\EstadoFiscal;
use App\Enums\EstadoVenta;
use App\Enums\ModuloImpresion;
use App\Enums\TipoComprobante;
use App\Enums\TipoConexionImpresora;
use App\Enums\TipoDocumentoCliente;
use App\Filament\Resources\VentaResource\Pages\ListVentas;
use App\Models\Cliente;
use App\Models\Impresora;
use App\Models\User;
use App\Models\Venta;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VentaReimprimirTicketTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioConPermiso(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create();
        $usuario->assignRole('Vendedor');

        return $usuario;
    }

    private function crearVenta(): Venta
    {
        $cliente = Cliente::create([
            'tipo_documento' => TipoDocumentoCliente::CEDULA,
            'documento' => '00112345678',
            'nombre' => 'Cliente reimpresión',
            'activo' => true,
        ]);

        return Venta::create([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO,
            'ncf' => 'E320000000060',
            'fecha' => now(),
            'subtotal' => '100.00',
            'total_itbis' => '18.00',
            'total' => '118.00',
            'estado' => EstadoVenta::EMITIDA,
            'estado_fiscal' => EstadoFiscal::NO_APLICA,
        ]);
    }

    public function test_reimprimir_ticket_sin_impresora_configurada_no_rompe_la_pagina(): void
    {
        $venta = $this->crearVenta();

        Livewire::actingAs($this->usuarioConPermiso())
            ->test(ListVentas::class)
            ->callTableAction('reimprimirTicket', $venta)
            ->assertSuccessful();
    }

    public function test_reimprimir_ticket_con_impresora_red_inalcanzable_notifica_el_error(): void
    {
        $venta = $this->crearVenta();

        Impresora::create([
            'nombre' => 'Cocina',
            'tipo_conexion' => TipoConexionImpresora::RED,
            'ip' => '127.0.0.1',
            'puerto' => 1,
            'modulo' => ModuloImpresion::FACTURACION,
            'predeterminada' => true,
        ]);

        Livewire::actingAs($this->usuarioConPermiso())
            ->test(ListVentas::class)
            ->callTableAction('reimprimirTicket', $venta)
            ->assertNotified();
    }
}
