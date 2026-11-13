<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Lo que ve el usuario cuando algo falla tiene que estar en español y decirle qué hacer. */
class PaginasDeErrorTest extends TestCase
{
    public function test_una_direccion_inexistente_muestra_la_pagina_404_en_espanol(): void
    {
        $this->get('/esta-direccion-no-existe')
            ->assertNotFound()
            ->assertSee('Página no encontrada')
            ->assertSee('Ir al inicio')
            ->assertSee('lang="es"', false);
    }

    public function test_cada_pagina_de_error_explica_que_paso_sin_detalles_tecnicos(): void
    {
        $esperado = [
            403 => 'No tiene acceso a esta página',
            404 => 'Página no encontrada',
            429 => 'Demasiados intentos',
            500 => 'Ocurrió un error inesperado',
            503 => 'Sistema en mantenimiento',
        ];

        foreach ($esperado as $codigo => $titulo) {
            $html = view("errors.{$codigo}")->render();

            $this->assertStringContainsString($titulo, $html);
            $this->assertStringContainsString("Código {$codigo}", $html);
            $this->assertStringNotContainsStringIgnoringCase('exception', $html);
            $this->assertStringNotContainsStringIgnoringCase('stack', $html);
        }
    }

    public function test_el_panel_incluye_el_aviso_de_errores_de_livewire_en_espanol(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Sin conexión con el servidor')
            ->assertSee('Su sesión expiró por inactividad', false);
    }
}
