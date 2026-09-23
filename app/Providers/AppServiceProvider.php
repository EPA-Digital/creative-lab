<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // URLs de assets relativas ("/build/assets/x.js") en vez de absolutas
        // con dominio completo -- el servicio es privado (--no-allow-
        // unauthenticated) y se accede vía `gcloud run services proxy`, que
        // preserva el Host real para que Cloud Run enrute. Con URL absoluta,
        // el navegador pide el JS directo al dominio real sin pasar por el
        // proxy y recibe 403 (página en blanco, Vue nunca monta). Vite (esta
        // versión de Laravel) no trae Vite::useRelativeUrls() -- se logra lo
        // mismo con el resolver de paths.
        Vite::createAssetPathsUsing(fn (string $path, ?bool $secure = null) => '/'.ltrim($path, '/'));
    }
}
