<?php

use App\Http\Controllers\AjustesController;
use App\Http\Controllers\AnalisisCreativoController;
use App\Http\Controllers\EvaluacionCreativoController;
use App\Http\Controllers\GestionNombresController;
use App\Http\Controllers\ImportarDatosController;
use App\Http\Controllers\InteligenciaController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UsuariosController;
use Illuminate\Support\Facades\Route;

// Todo el negocio detrás de login (2026-09-23) -- antes nada tenía
// middleware auth, dependía 100% de que Cloud Run fuera privado. Con login
// real (Google @epa.digital / correo-contraseña para 'cliente', ver
// routes/auth.php), el servicio pasa a ser público y la protección vive
// acá.
Route::middleware('auth')->group(function () {
    // Puerto de index.html -- selector de país, entrada real de la app.
    Route::get('/', [LandingController::class, 'index'])->name('landing');

    // Lectura -- accesible a cualquier usuario logueado, incluido 'cliente'
    // (solo lectura, ver User::esEpa()).
    Route::get('/pais/{pais}/analisis/{plataforma?}', [AnalisisCreativoController::class, 'index'])
        ->name('analisis-creativo');
    Route::get('/pais/{pais}/inteligencia', [InteligenciaController::class, 'index'])->name('inteligencia');

    // Escritura / herramientas de gestión -- EPA únicamente (Gate 'epa',
    // ver AppServiceProvider). Un 'cliente' nunca llega acá ni por UI
    // (DashboardLayout.vue las oculta) ni por URL directa (403 acá).
    Route::middleware('can:epa')->group(function () {
        Route::post('/pais/{pais}/creativos/{creativo}/evaluar', [EvaluacionCreativoController::class, 'evaluar'])
            ->name('creativos.evaluar');

        Route::get('/pais/{pais}/ajustes/nombres', [GestionNombresController::class, 'index'])->name('gestion-nombres');
        Route::post('/pais/{pais}/creativos/{creativo}/nombre', [GestionNombresController::class, 'store'])
            ->name('creativos.nombre.store');

        Route::get('/pais/{pais}/importar', [ImportarDatosController::class, 'show'])->name('importar-datos');
        Route::post('/pais/{pais}/importar/previsualizar', [ImportarDatosController::class, 'previsualizar'])->name('importar-datos.previsualizar');
        Route::post('/pais/{pais}/importar', [ImportarDatosController::class, 'importar'])->name('importar-datos.importar');
        Route::get('/pais/{pais}/importar/estado/{id}', [ImportarDatosController::class, 'estadoImportacion'])->name('importar-datos.estado');
        Route::post('/pais/{pais}/importar/api', [ImportarDatosController::class, 'importarApi'])->name('importar-datos.importar-api');
        Route::get('/pais/{pais}/importar/resumen', [ImportarDatosController::class, 'resumenPorArte'])->name('importar-datos.resumen');

        Route::get('/pais/{pais}/ajustes', [AjustesController::class, 'index'])->name('ajustes');
        Route::post('/pais/{pais}/ajustes/appsflyer-apps', [AjustesController::class, 'storeAppsflyerApp'])->name('ajustes.appsflyer-apps.store');

        Route::get('/usuarios', [UsuariosController::class, 'index'])->name('usuarios.index');
        Route::post('/usuarios', [UsuariosController::class, 'store'])->name('usuarios.store');
        Route::delete('/usuarios/{usuario}', [UsuariosController::class, 'destroy'])->name('usuarios.destroy');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
