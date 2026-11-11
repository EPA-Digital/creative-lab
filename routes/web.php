<?php

use App\Http\Controllers\AjustesController;
use App\Http\Controllers\AnalisisCreativoController;
use App\Http\Controllers\CuentasController;
use App\Http\Controllers\DatosMensualesController;
use App\Http\Controllers\EvaluacionCreativoController;
use App\Http\Controllers\GestionNombresController;
use App\Http\Controllers\ImportarDatosController;
use App\Http\Controllers\InteligenciaController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LogoCuentaController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SinCreativoController;
use App\Http\Controllers\TareaMediosController;
use App\Http\Controllers\UsuariosController;
use App\Http\Controllers\VideoCreativoController;
use App\Models\Cuenta;
use Illuminate\Http\Request;
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

    // URLs de antes de multi-cuenta (/pais/{pais}/...) -- todo era TaDa:
    // se redirigen a /tada/pais/... para no romper links guardados.
    Route::get('/pais/{pais}/{resto?}', function (Request $request, string $pais, ?string $resto = null) {
        $query = $request->getQueryString();

        return redirect('/'.Cuenta::SLUG_TADA."/pais/{$pais}".($resto ? "/{$resto}" : '').($query ? "?{$query}" : ''));
    })->where('resto', '.*');

    // Scope por cuenta y país (2026-09-23 país, 2026-11-04 cuenta) --
    // aplica a TODOS los roles por igual, incluido 'director' (ver
    // EnsureAccesoPais). Se asigna desde /usuarios, ver Gate
    // 'gestionar-usuarios' más abajo. {cuenta} la fija ResolverCuentaActual
    // y EnsureAccesoPais la saca de los parámetros: los controllers siguen
    // recibiendo ($pais, ...).
    Route::prefix('{cuenta}/pais/{pais}')->where(['cuenta' => '[a-z0-9-]+'])->middleware('acceso-pais')->group(function () {
        // Lectura -- cualquier usuario logueado con acceso a este país,
        // 'cliente' incluido (solo lectura, ver User::esEpa()).
        Route::get('/analisis/{plataforma?}', [AnalisisCreativoController::class, 'index'])
            ->name('analisis-creativo');
        Route::get('/inteligencia', [InteligenciaController::class, 'index'])->name('inteligencia');
        // Video del creativo para el modal (2026-10-06) -- ver VideoCreativoController.
        Route::get('/creativos/{creativo}/video', [VideoCreativoController::class, 'show'])->name('creativos.video');

        // Escritura / herramientas de gestión -- EPA únicamente (Gate
        // 'epa', ver AppServiceProvider). Un 'cliente' nunca llega acá ni
        // por UI (DashboardLayout.vue las oculta) ni por URL directa (403).
        Route::middleware('can:epa')->group(function () {
            Route::post('/creativos/{creativo}/evaluar', [EvaluacionCreativoController::class, 'evaluar'])
                ->name('creativos.evaluar');

            Route::get('/ajustes/nombres', [GestionNombresController::class, 'index'])->name('gestion-nombres');
            // Cola "Sin creativo" (Spark Ads y demás): asignar crea una regla
            // en el catálogo de la cuenta (ver SinCreativoController).
            Route::get('/ajustes/sin-creativo', [SinCreativoController::class, 'index'])->name('sin-creativo');
            Route::post('/ajustes/sin-creativo', [SinCreativoController::class, 'asignar'])->name('sin-creativo.asignar');
            Route::post('/creativos/{creativo}/nombre', [GestionNombresController::class, 'store'])
                ->name('creativos.nombre.store');

            Route::get('/importar', [ImportarDatosController::class, 'show'])->name('importar-datos');
            Route::post('/importar/previsualizar', [ImportarDatosController::class, 'previsualizar'])->name('importar-datos.previsualizar');
            Route::post('/importar', [ImportarDatosController::class, 'importar'])->name('importar-datos.importar');
            Route::get('/importar/estado/{id}', [ImportarDatosController::class, 'estadoImportacion'])->name('importar-datos.estado');
            Route::post('/importar/api', [ImportarDatosController::class, 'importarApi'])->name('importar-datos.importar-api');
            Route::get('/importar/resumen', [ImportarDatosController::class, 'resumenPorArte'])->name('importar-datos.resumen');

            // Datos por mes (2026-10-05) -- ver/descargar es EPA, eliminar
            // un mes es solo superadmin.
            Route::get('/importar/mensual', [DatosMensualesController::class, 'index'])->name('importar-datos.mensual');
            // Reporte del mes en Excel/PDF con imágenes (2026-10-07).
            Route::get('/importar/mensual/{mes}/exportar', [DatosMensualesController::class, 'exportar'])->name('importar-datos.mensual.exportar');
            // Imágenes/videos en background (2026-10-07) -- avance de una
            // tarea y "Recuperar imágenes" de un mes.
            Route::get('/medios/tareas/{tarea}', [TareaMediosController::class, 'show'])->name('medios.tareas.show');
            Route::post('/importar/mensual/{mes}/recuperar-imagenes', [TareaMediosController::class, 'recuperarImagenes'])->name('importar-datos.mensual.recuperar-imagenes');
            Route::delete('/importar/mensual/{mes}', [DatosMensualesController::class, 'destroy'])
                ->middleware('can:eliminar-mes-importado')
                ->name('importar-datos.mensual.destroy');

            Route::get('/ajustes', [AjustesController::class, 'index'])->name('ajustes');
            Route::post('/ajustes/appsflyer-apps', [AjustesController::class, 'storeAppsflyerApp'])->name('ajustes.appsflyer-apps.store');

            // Cuentas publicitarias (2026-10-05) -- ver la lista es EPA,
            // agregar/editar es solo superadmin.
            Route::middleware('can:gestionar-cuentas-publicitarias')->group(function () {
                Route::post('/ajustes/cuentas-publicitarias', [AjustesController::class, 'storeCuentaPublicitaria'])
                    ->name('ajustes.cuentas-publicitarias.store');
                Route::patch('/ajustes/cuentas-publicitarias/{cuentaPublicitaria}', [AjustesController::class, 'updateCuentaPublicitaria'])
                    ->name('ajustes.cuentas-publicitarias.update');
                Route::post('/ajustes/tipos-cambio', [AjustesController::class, 'storeTipoCambio'])->name('ajustes.tipos-cambio.store');
                Route::delete('/ajustes/tipos-cambio/{tipoCambio}', [AjustesController::class, 'destroyTipoCambio'])->name('ajustes.tipos-cambio.destroy');
            });
        });
    });

    // Transversal a países -- no lleva 'acceso-pais'. Ver la lista e
    // invitar clientes es más estricto que el resto de /usuarios (pedido
    // explícito 2026-09-24: junior/senior no pueden ni ver ni invitar,
    // ver Gate 'ver-usuarios'); el resto sigue la jerarquía completa (ver
    // User::puedeGestionarA()) o restricciones más estrictas puntuales
    // (desactivar, aprobar invitaciones) -- ver UsuariosController.
    // Cuentas (multi-cuenta, 2026-11-04) -- cualquier persona de EPA da de
    // alta y edita; desactivar/reactivar es director/superadmin. El
    // parámetro {cuenta} fija además la cuenta actual (ResolverCuentaActual),
    // así las conexiones que se crean al editar nacen en esa cuenta.
    // Logo de la cuenta: lo ve quien tiene la cuenta (también un cliente,
    // en el nav) o EPA; subirlo y quitarlo es EPA (ver LogoCuentaController).
    Route::get('/cuentas/{cuenta}/logo', [LogoCuentaController::class, 'show'])->name('cuentas.logo');

    Route::middleware('can:epa')->group(function () {
        Route::post('/cuentas/{cuenta}/logo', [LogoCuentaController::class, 'store'])->name('cuentas.logo.store');
        Route::delete('/cuentas/{cuenta}/logo', [LogoCuentaController::class, 'destroy'])->name('cuentas.logo.destroy');
        Route::get('/cuentas', [CuentasController::class, 'index'])->name('cuentas.index');
        Route::get('/cuentas/nueva', [CuentasController::class, 'create'])->name('cuentas.create');
        Route::post('/cuentas', [CuentasController::class, 'store'])->name('cuentas.store');
        // Nomenclatura: proponer llama a la IA (con tope por minuto), probar no.
        Route::post('/cuentas/nomenclatura/proponer', [CuentasController::class, 'proponerNomenclatura'])
            ->middleware('throttle:10,1')->name('cuentas.nomenclatura.proponer');
        Route::post('/cuentas/nomenclatura/probar', [CuentasController::class, 'probarNomenclatura'])->name('cuentas.nomenclatura.probar');
        Route::post('/cuentas/nomenclatura/desde-formula', [CuentasController::class, 'nomenclaturaDesdeFormula'])->name('cuentas.nomenclatura.desde-formula');
        Route::post('/cuentas/fuentes/bigquery/probar', [CuentasController::class, 'probarBigQuery'])->middleware('throttle:20,1')->name('cuentas.bigquery.probar');
        Route::get('/cuentas/{cuenta}/editar', [CuentasController::class, 'edit'])->name('cuentas.edit');
        Route::put('/cuentas/{cuenta}', [CuentasController::class, 'update'])->name('cuentas.update');

        Route::middleware('can:desactivar-cuentas')->group(function () {
            Route::post('/cuentas/{cuenta}/desactivar', [CuentasController::class, 'desactivar'])->name('cuentas.desactivar');
            Route::post('/cuentas/{cuenta}/reactivar', [CuentasController::class, 'reactivar'])->name('cuentas.reactivar');
        });
    });

    Route::middleware('can:ver-usuarios')->group(function () {
        Route::get('/usuarios', [UsuariosController::class, 'index'])->name('usuarios.index');
        Route::post('/usuarios', [UsuariosController::class, 'store'])->name('usuarios.store');
    });

    Route::middleware('can:epa')->group(function () {
        Route::middleware('can:desactivar-usuarios')->group(function () {
            Route::delete('/usuarios/{usuario}', [UsuariosController::class, 'destroy'])->name('usuarios.destroy');

            // Inverso de desactivar -- mismo Gate (director/superadmin),
            // pedido explícito 2026-09-24.
            Route::post('/usuarios/{usuario}/reactivar', [UsuariosController::class, 'reactivar'])->name('usuarios.reactivar');
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

            // "Olvidé mi contraseña" sin SMTP real (pedido explícito
            // 2026-09-24) -- mismo Gate, mismo patrón que resetear-totp.
            Route::post('/usuarios/{usuario}/resetear-password', [UsuariosController::class, 'resetearPassword'])->name('usuarios.resetear-password');

            // Cliente que quedó en metodo_auth=google -> contraseña + TOTP
            // (pedido explícito 2026-10-21), mismo Gate y mismo link.
            Route::post('/usuarios/{usuario}/activar-password', [UsuariosController::class, 'activarPassword'])->name('usuarios.activar-password');
        });
    });

    // "Editar perfil" del nav: cada quien el suyo (ver PerfilController).
    Route::patch('/perfil', [PerfilController::class, 'update'])->name('perfil.update');
    Route::post('/perfil/foto', [PerfilController::class, 'subirFoto'])->name('perfil.foto.store');
    Route::delete('/perfil/foto', [PerfilController::class, 'quitarFoto'])->name('perfil.foto.destroy');
    Route::get('/usuarios/{usuario}/foto', [PerfilController::class, 'foto'])->name('usuarios.foto');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
