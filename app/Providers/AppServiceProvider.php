<?php

namespace App\Providers;

use App\Models\User;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility;

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
        //
        // UniformBucketLevelAccessVisibility (no el PortableVisibilityHandler
        // default del paquete) -- el bucket tiene uniform bucket-level access
        // (acceso público vía IAM a nivel bucket, no ACLs por objeto, ver
        // epa-safe-vibe/epa-deploy). El handler default intenta setear un ACL
        // legacy en cada write y GCS lo rechaza con 400 "Cannot insert legacy
        // ACL for an object when uniform bucket-level access is enabled" --
        // confirmado en un smoke test real contra el bucket
        // zx-dashboard-creative-images (2026-09-23).
        Storage::extend('gcs', function ($app, array $config) {
            $client = new StorageClient(array_filter(['projectId' => $config['project_id'] ?? null]));
            $bucket = $client->bucket($config['bucket']);
            $adapter = new GoogleCloudStorageAdapter(
                $bucket,
                $config['path_prefix'] ?? '',
                new UniformBucketLevelAccessVisibility,
            );

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

        // Rutas de escritura (importar, evaluar IA, ajustes, invitar
        // usuarios) -- ver routes/web.php ->middleware('can:epa'). Un
        // 'cliente' (correo/contraseña, solo lectura) nunca pasa esto.
        Gate::define('epa', fn (User $user) => $user->esEpa());

        // Editar rol/países de OTRO usuario -- jerarquía completa (ver
        // User::puedeGestionarA()): gerente administra senior/junior/
        // cliente, senior administra junior/cliente, etc. Nunca del mismo
        // nivel ni hacia arriba. Otorgar 'director'/'superadmin' en sí es
        // más estricto todavía -- ver UsuariosController::ROLES_SOLO_
        // SUPERADMIN. can:gestionar-usuarios,usuario en la ruta pasa el
        // {usuario} del route model binding como $objetivo acá.
        Gate::define('gestionar-usuarios', fn (User $user, User $objetivo) => $user->puedeGestionarA($objetivo));

        // Desactivar es más estricto que la jerarquía general -- solo
        // director/superadmin (pedido explícito 2026-09-23), un gerente
        // no desactiva ni a su propio junior.
        Gate::define('desactivar-usuarios', fn (User $user) => $user->puedeDesactivarUsuarios());

        // Aprobar una invitación que un junior/senior propuso con países --
        // gerente/director/superadmin (ver User::puedeAprobarInvitaciones()
        // y UsuariosController::store()/aprobar()).
        Gate::define('aprobar-invitaciones', fn (User $user) => $user->puedeAprobarInvitaciones());
    }
}
