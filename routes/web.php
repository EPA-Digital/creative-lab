<?php

use App\Http\Controllers\AjustesController;
use App\Http\Controllers\AnalisisCreativoController;
use App\Http\Controllers\EvaluacionCreativoController;
use App\Http\Controllers\GestionNombresController;
use App\Http\Controllers\ImportarDatosController;
use App\Http\Controllers\InteligenciaController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Puerto de index.html -- selector de país, entrada real de la app (antes
// era el Welcome de scaffold de Breeze, sin relación con el sistema real).
Route::get('/', [LandingController::class, 'index'])->name('landing');

// Fase 3, paso 1 -- flujo de datos mínimo, sin diseño. Sin middleware auth a
// propósito por ahora (Modelo A de invitación todavía no tiene login
// funcional armado) -- se gatea detrás de auth cuando llegue la UI real.
Route::get('/pais/{pais}/analisis/{plataforma?}', [AnalisisCreativoController::class, 'index'])
    ->name('analisis-creativo');

// Inteligencia -- comparación head-to-head de 2-4 creativos (2026-08-27,
// ver plan). Manda el historial COMPLETO de resultados por creativo (a
// diferencia de analisis-creativo, que acota a un mes) para el gráfico de
// tendencia mensual.
Route::get('/pais/{pais}/inteligencia', [InteligenciaController::class, 'index'])->name('inteligencia');

// Evaluación IA on-demand del modal de detalle (2026-08-26, ver plan) --
// "Evaluar por métricas"/"Evaluar por arte", cacheada server-side.
Route::post('/pais/{pais}/creativos/{creativo}/evaluar', [EvaluacionCreativoController::class, 'evaluar'])
    ->name('creativos.evaluar');

// Taxonomía legible (2026-08-27, ver plan del rediseño) -- nombre amigable
// por creativo, cablea correcciones_nombres (existía sin usar). El endpoint
// de guardado vive bajo /creativos/{creativo}/nombre para poder llamarse
// tanto desde el lápiz inline del modal de detalle como desde la página de
// gestión completa.
Route::get('/pais/{pais}/ajustes/nombres', [GestionNombresController::class, 'index'])->name('gestion-nombres');
Route::post('/pais/{pais}/creativos/{creativo}/nombre', [GestionNombresController::class, 'store'])
    ->name('creativos.nombre.store');

// Puerto del panel "Cargar datos" (import-panel de meta.html/tiktok.html) --
// deja de depender de correr importar:csv de memoria por consola.
Route::get('/pais/{pais}/importar', [ImportarDatosController::class, 'show'])->name('importar-datos');
Route::post('/pais/{pais}/importar/previsualizar', [ImportarDatosController::class, 'previsualizar'])->name('importar-datos.previsualizar');
Route::post('/pais/{pais}/importar', [ImportarDatosController::class, 'importar'])->name('importar-datos.importar');
Route::get('/pais/{pais}/importar/estado/{id}', [ImportarDatosController::class, 'estadoImportacion'])->name('importar-datos.estado');
Route::post('/pais/{pais}/importar/api', [ImportarDatosController::class, 'importarApi'])->name('importar-datos.importar-api');
Route::get('/pais/{pais}/importar/resumen', [ImportarDatosController::class, 'resumenPorArte'])->name('importar-datos.resumen');

// Gestión de apps de AppsFlyer por país (tabla appsflyer_apps) -- el país en
// la URL es solo contexto visual del rail (de dónde volver), la gestión en
// sí abarca TODOS los países.
Route::get('/pais/{pais}/ajustes', [AjustesController::class, 'index'])->name('ajustes');
Route::post('/pais/{pais}/ajustes/appsflyer-apps', [AjustesController::class, 'storeAppsflyerApp'])->name('ajustes.appsflyer-apps.store');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
