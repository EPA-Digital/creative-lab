<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    /**
     * Solo los campos que el front realmente usa (auth-prompt.md Fase 6)
     * -- nunca el modelo completo. `$hidden` en User ya excluye password/
     * google_id/totp_* de la serialización por defecto, pero compartir el
     * modelo entero de todas formas expondría cualquier campo sensible
     * que se agregue después y alguien olvide sumar a $hidden. only()
     * fuerza a decidir explícitamente qué campo nuevo se comparte.
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email', 'rol', 'email_verified_at']),
            ],
        ];
    }
}
