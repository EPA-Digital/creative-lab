<?php

use App\Http\Controllers\AnalisisCreativoController;
use App\Http\Controllers\ImportarDatosController;
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

// Puerto del panel "Cargar datos" (import-panel de meta.html/tiktok.html) --
// deja de depender de correr importar:csv de memoria por consola.
Route::get('/pais/{pais}/importar', [ImportarDatosController::class, 'show'])->name('importar-datos');
Route::post('/pais/{pais}/importar/previsualizar', [ImportarDatosController::class, 'previsualizar'])->name('importar-datos.previsualizar');
Route::post('/pais/{pais}/importar', [ImportarDatosController::class, 'importar'])->name('importar-datos.importar');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
