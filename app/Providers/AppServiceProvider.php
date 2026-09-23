<?php

namespace App\Providers;

use Google\Cloud\Storage\StorageClient;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // league/flysystem-google-cloud-storage no trae auto-registro de
        // Laravel -- disco 'gcs' (ver config/filesystems.php), usado por
        // ImagenCacheService. Autentica vía Application Default Credentials
        // (StorageClient sin key file explícito) -- en Cloud Run eso es la
        // service account de runtime del servicio, ya con los permisos
        // sobre el bucket.
        Storage::extend('gcs', function ($app, array $config) {
            $client = new StorageClient(array_filter(['projectId' => $config['project_id'] ?? null]));
            $bucket = $client->bucket($config['bucket']);
            $adapter = new GoogleCloudStorageAdapter($bucket, $config['path_prefix'] ?? '');

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
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
