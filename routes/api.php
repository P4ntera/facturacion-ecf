<?php

// Agrega esto en routes/api.php
// (o en routes/web.php si no usas sanctum)

use App\Http\Controllers\RncController;
use Illuminate\Support\Facades\Route;

// Consulta RNC/Cédula en la DGII via intermediario Dominican Technology
Route::get('/rnc/{rnc}', [RncController::class, 'consultar'])
    ->where('rnc', '[0-9]{9,11}')
    ->name('rnc.consultar');
