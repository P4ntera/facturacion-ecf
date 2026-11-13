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

Schedule::command('license:refresh')
    ->everyFourHours()
    ->description('Revalidar licencias de todas las empresas');

// Red de seguridad del envío de e-CF: reencola los pendientes y consulta los "en proceso".
// withoutOverlapping: si una corrida tarda (PAC lento), la siguiente no arranca encima.
Schedule::command('ecf:procesar-pendientes')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->description('Reintentar e-CF pendientes y refrescar los que están en proceso');

// Respaldo diario de la base de datos y los archivos (logos, certificados). La hora por defecto
// es a mediodía porque la PC de un negocio suele estar apagada de madrugada.
if (config('instalacion.respaldo.activo')) {
    Schedule::command('erp:respaldo')
        ->dailyAt((string) config('instalacion.respaldo.hora', '12:30'))
        ->withoutOverlapping()
        ->description('Respaldo de la base de datos y archivos');
}
