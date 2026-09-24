<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

/**
 * Login vía Google -- dos caminos:
 *
 * - @epa.digital: acceso completo (rol !== 'cliente', ver User::esEpa()),
 *   auto-provisionado como 'junior' en el primer login ("EPA puede todo
 *   de momento", pedido explícito). Exige además el claim `hd` de Google
 *   (Workspace) para que no baste con un alias de correo que termine en
 *
 *   @epa.digital.
 * - Otro dominio: reservado a un 'cliente' que ya fue invitado y aceptó
 *   con metodo_auth=google (ver UsuariosController/InvitacionController).
 *   Nunca se auto-provisiona -- sin invitación previa, se rechaza con un
 *   mensaje genérico que no revela si el correo existe.
 *
 * Ninguno de los dos casos se cruza con metodo_auth='password': ese
 * usuario no puede entrar por Google (ver InvitacionController/TotpController
 * para su propio camino).
 */
class GoogleAuthController extends Controller
{
    private const MENSAJE_GENERICO = 'No se pudo iniciar sesión con esta cuenta de Google.';

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException $e) {
            return redirect()->route('login')->withErrors(['email' => 'La sesión de Google expiró -- intentá de nuevo.']);
        } catch (Throwable $e) {
            Log::warning('GoogleAuthController: fallo el callback de Socialite', ['error' => $e->getMessage()]);

            return redirect()->route('login')->withErrors(['email' => 'No se pudo iniciar sesión con Google -- intentá de nuevo.']);
        }

        $raw = $googleUser->getRaw();
        $email = $googleUser->getEmail();

        if (! $email || ($raw['email_verified'] ?? false) !== true) {
            return redirect()->route('login')->withErrors(['email' => self::MENSAJE_GENERICO]);
        }

        $email = Str::lower(trim($email));
        $esEpa = str_ends_with($email, '@'.User::DOMINIO_EPA);

        if ($esEpa && ($raw['hd'] ?? null) !== User::DOMINIO_EPA) {
            return redirect()->route('login')->withErrors(['email' => self::MENSAJE_GENERICO]);
        }

        $usuario = User::where('email', $email)->first();

        if ($esEpa) {
            $usuario = $this->resolverUsuarioEpa($usuario, $googleUser, $email);
        } else {
            $usuario = $this->resolverUsuarioCliente($usuario, $googleUser);
        }

        if (! $usuario) {
            return redirect()->route('login')->withErrors(['email' => self::MENSAJE_GENERICO]);
        }

        Auth::login($usuario);

        return redirect()->intended(route('landing', absolute: false));
    }

    /**
     * @epa.digital: auto-provisiona si no existe, exige activo +
     * metodo_auth=google + google_id estable en los siguientes logins.
     */
    private function resolverUsuarioEpa(?User $usuario, $googleUser, string $email): ?User
    {
        if (! $usuario) {
            return User::create([
                'name' => $googleUser->getName() ?: $email,
                'email' => $email,
                'google_id' => $googleUser->getId(),
                'metodo_auth' => User::METODO_GOOGLE,
                'rol' => 'junior',
                'activo' => true,
                'email_verified_at' => now(),
                'password' => null,
            ]);
        }

        if (! $usuario->activo || $usuario->metodo_auth !== User::METODO_GOOGLE) {
            return null;
        }

        if ($usuario->google_id && $usuario->google_id !== $googleUser->getId()) {
            return null;
        }

        $usuario->update(['google_id' => $googleUser->getId()]);

        return $usuario;
    }

    /**
     * Cliente (dominio distinto de epa.digital): sin auto-provisionamiento.
     * Solo entra si ya existe, activo, metodo_auth=google, y el google_id
     * coincide con el de un login previo (o todavía no se fijó ninguno).
     */
    private function resolverUsuarioCliente(?User $usuario, $googleUser): ?User
    {
        if (! $usuario || ! $usuario->activo || $usuario->metodo_auth !== User::METODO_GOOGLE) {
            return null;
        }

        if ($usuario->google_id && $usuario->google_id !== $googleUser->getId()) {
            return null;
        }

        if (! $usuario->google_id) {
            $usuario->update(['google_id' => $googleUser->getId()]);
        }

        return $usuario;
    }
}
