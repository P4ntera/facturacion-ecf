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
