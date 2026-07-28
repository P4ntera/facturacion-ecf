<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** La app es 100% panel: la raíz redirige a /admin en vez de servir una vista propia. */
    public function test_la_raiz_redirige_al_panel(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/admin');
    }
}
