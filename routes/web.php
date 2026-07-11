<?php

use App\Http\Controllers\AprobacionComercialEcfController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecepcionEcfController;
use App\Http\Controllers\RncController;
use App\Http\Controllers\VentaComprobanteController;
use App\Http\Controllers\VentaEcfXmlController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
Route::get('/rnc/{rnc}', [RncController::class, 'consultar'])
    ->where('rnc', '[0-9]{9,11}')
    ->name('rnc.consultar');

Route::get('/ventas/{venta}/pdf', VentaComprobanteController::class)
    ->middleware(['auth', 'can:registrar_ventas'])
    ->name('ventas.pdf');

Route::get('/ventas/{venta}/xml', VentaEcfXmlController::class)
    ->middleware(['auth', 'can:registrar_ventas'])
    ->name('ventas.ecf.xml');

// URLs públicas que se registran en el portal de la DGII (ella misma las llama, sin sesión ni
// CSRF — ver bootstrap/app.php). Seguridad: RNC/tamaño/rate limit/registro en RecepcionEcfService.
// Ver docs/dgii-recepcion.md para qué URL de producción registrar.
Route::post('/fe/recepcion/api/ecf', RecepcionEcfController::class)
    ->middleware('throttle:30,1')
    ->name('dgii.recepcion');

Route::post('/fe/aprobacioncomercial/api/ecf', AprobacionComercialEcfController::class)
    ->middleware('throttle:30,1')
    ->name('dgii.aprobacioncomercial');

require __DIR__.'/auth.php';
