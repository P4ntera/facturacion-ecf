<?php

use App\Http\Controllers\ProfileController;
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

require __DIR__.'/auth.php';
