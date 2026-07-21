<?php

use App\Http\Controllers\AprobacionComercialEcfController;
use App\Http\Controllers\ArqueoCajaPdfController;
use App\Http\Controllers\ImpresoraPruebaController;
use App\Http\Controllers\PedidoCompraPdfController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecepcionEcfController;
use App\Http\Controllers\Reportes\ReporteFiscal607PdfController;
use App\Http\Controllers\Reportes\ReporteInventarioPdfController;
use App\Http\Controllers\Reportes\ReporteTopProductosPdfController;
use App\Http\Controllers\Reportes\ReporteVentasPdfController;
use App\Http\Controllers\Reportes\ReporteVentasPorClientePdfController;
use App\Http\Controllers\Reportes\ReporteVentasPorVendedorPdfController;
use App\Http\Controllers\RncController;
use App\Http\Controllers\VentaComprobanteController;
use App\Http\Controllers\VentaEcfXmlController;
use App\Http\Controllers\VentaTicketController;
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

Route::get('/pedidos-compra/{pedidoCompra}/pdf', PedidoCompraPdfController::class)
    ->middleware(['auth', 'can:gestionar_compras'])
    ->name('pedidos-compra.pdf');

Route::get('/arqueos-caja/{arqueoCaja}/pdf', ArqueoCajaPdfController::class)
    ->middleware(['auth', 'can:gestionar_arqueo_caja'])
    ->name('arqueos-caja.pdf');

Route::get('/ventas/{venta}/ticket', VentaTicketController::class)
    ->middleware(['auth', 'can:registrar_ventas'])
    ->name('ventas.ticket');

Route::get('/impresoras/{impresora}/prueba', ImpresoraPruebaController::class)
    ->middleware(['auth', 'can:administrar_configuracion'])
    ->name('impresoras.prueba');

Route::middleware(['auth', 'can:ver_reportes'])->prefix('reportes')->name('reportes.')->group(function () {
    Route::get('/ventas/pdf', ReporteVentasPdfController::class)->name('ventas.pdf');
    Route::get('/top-productos/pdf', ReporteTopProductosPdfController::class)->name('top-productos.pdf');
    Route::get('/ventas-por-cliente/pdf', ReporteVentasPorClientePdfController::class)->name('ventas-por-cliente.pdf');
    Route::get('/ventas-por-vendedor/pdf', ReporteVentasPorVendedorPdfController::class)->name('ventas-por-vendedor.pdf');
    Route::get('/inventario/pdf', ReporteInventarioPdfController::class)->name('inventario.pdf');
    Route::get('/fiscal-607/pdf', ReporteFiscal607PdfController::class)->name('fiscal-607.pdf');
});

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
