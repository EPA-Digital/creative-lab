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
    // LandingController filtra a los países que el usuario tiene asignados
    // (ver EnsureAccesoPais) -- nunca muestra una tarjeta que después 403ee.
    Route::get('/', [LandingController::class, 'index'])->name('landing');

    // Scope por país (2026-09-23, pedido explícito) -- aplica a TODOS los
    // roles por igual, incluido 'director' (ver EnsureAccesoPais). Se
    // asigna desde /usuarios, ver Gate 'gestionar-usuarios' más abajo.
    Route::prefix('pais/{pais}')->middleware('acceso-pais')->group(function () {
        // Lectura -- cualquier usuario logueado con acceso a este país,
        // 'cliente' incluido (solo lectura, ver User::esEpa()).
        Route::get('/analisis/{plataforma?}', [AnalisisCreativoController::class, 'index'])
            ->name('analisis-creativo');
        Route::get('/inteligencia', [InteligenciaController::class, 'index'])->name('inteligencia');

        // Escritura / herramientas de gestión -- EPA únicamente (Gate
        // 'epa', ver AppServiceProvider). Un 'cliente' nunca llega acá ni
        // por UI (DashboardLayout.vue las oculta) ni por URL directa (403).
        Route::middleware('can:epa')->group(function () {
            Route::post('/creativos/{creativo}/evaluar', [EvaluacionCreativoController::class, 'evaluar'])
                ->name('creativos.evaluar');

            Route::get('/ajustes/nombres', [GestionNombresController::class, 'index'])->name('gestion-nombres');
            Route::post('/creativos/{creativo}/nombre', [GestionNombresController::class, 'store'])
                ->name('creativos.nombre.store');

            Route::get('/importar', [ImportarDatosController::class, 'show'])->name('importar-datos');
            Route::post('/importar/previsualizar', [ImportarDatosController::class, 'previsualizar'])->name('importar-datos.previsualizar');
            Route::post('/importar', [ImportarDatosController::class, 'importar'])->name('importar-datos.importar');
            Route::get('/importar/estado/{id}', [ImportarDatosController::class, 'estadoImportacion'])->name('importar-datos.estado');
            Route::post('/importar/api', [ImportarDatosController::class, 'importarApi'])->name('importar-datos.importar-api');
            Route::get('/importar/resumen', [ImportarDatosController::class, 'resumenPorArte'])->name('importar-datos.resumen');

            Route::get('/ajustes', [AjustesController::class, 'index'])->name('ajustes');
            Route::post('/ajustes/appsflyer-apps', [AjustesController::class, 'storeAppsflyerApp'])->name('ajustes.appsflyer-apps.store');
        });
    });

    // Transversal a países -- no lleva 'acceso-pais'. Ver/invitar usuarios
    // es EPA (Gate 'epa'); el resto sigue la jerarquía completa (ver
    // User::puedeGestionarA()) o restricciones más estrictas puntuales
    // (desactivar, aprobar invitaciones) -- ver UsuariosController.
    Route::middleware('can:epa')->group(function () {
        Route::get('/usuarios', [UsuariosController::class, 'index'])->name('usuarios.index');
        Route::post('/usuarios', [UsuariosController::class, 'store'])->name('usuarios.store');

        Route::middleware('can:desactivar-usuarios')->group(function () {
            Route::delete('/usuarios/{usuario}', [UsuariosController::class, 'destroy'])->name('usuarios.destroy');
        });

        Route::middleware('can:eliminar-usuarios')->group(function () {
            Route::delete('/usuarios/{usuario}/eliminar', [UsuariosController::class, 'eliminar'])->name('usuarios.eliminar');
        });

        Route::middleware('can:gestionar-usuarios,usuario')->group(function () {
            Route::patch('/usuarios/{usuario}', [UsuariosController::class, 'actualizarRolYPaises'])->name('usuarios.actualizar');
        });

        Route::middleware('can:aprobar-invitaciones')->group(function () {
            Route::post('/usuarios/{usuario}/aprobar', [UsuariosController::class, 'aprobar'])->name('usuarios.aprobar');

            // "TOTP perdido" (auth-prompt.md Fase 3) -- sin autoservicio,
            // mismo Gate que aprobar invitaciones.
            Route::post('/usuarios/{usuario}/resetear-totp', [UsuariosController::class, 'resetearTotp'])->name('usuarios.resetear-totp');
        });
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
