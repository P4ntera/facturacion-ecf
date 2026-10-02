<?php

use App\Services\CotizacionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(CotizacionService::class)->vencerExpiradas())
    ->dailyAt('00:00')
    ->description('Vencer cotizaciones expiradas');

// Red de seguridad del envío de e-CF: reencola los pendientes y consulta los "en proceso".
// withoutOverlapping: si una corrida tarda (PAC lento), la siguiente no arranca encima.
Schedule::command('ecf:procesar-pendientes')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->description('Reintentar e-CF pendientes y refrescar los que están en proceso');
