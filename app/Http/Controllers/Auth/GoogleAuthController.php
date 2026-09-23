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
 * Login EPA vía Google -- único camino de acceso completo (rol !==
 * 'cliente', ver User::esEpa()). Restringido a @epa.digital: cualquier
 * otra cuenta de Google queda rechazada ANTES de crear sesión o usuario,
 * nunca se auto-provisiona una cuenta EPA por accidente.
 *
 * Auto-provisiona en el primer login -- "EPA puede todo de momento" (pedido
 * explícito) significa que cualquier @epa.digital real entra solo, sin
 * invitación manual de por medio (a diferencia de 'cliente', ver
 * UsuarioInvitacionController).
 */
class GoogleAuthController extends Controller
{
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

        $email = $googleUser->getEmail();
        if (! $email || ! str_ends_with(Str::lower($email), '@'.User::DOMINIO_EPA)) {
            return redirect()->route('login')->withErrors([
                'email' => 'Solo cuentas @'.User::DOMINIO_EPA.' pueden entrar con Google.',
            ]);
        }

        $user = User::where('email', $email)->first();

        if ($user && ! $user->activo) {
            return redirect()->route('login')->withErrors(['email' => 'Esta cuenta está desactivada.']);
        }

        if ($user) {
            $user->update(['google_id' => $googleUser->getId()]);
        } else {
            $user = User::create([
                'name' => $googleUser->getName() ?: $email,
                'email' => $email,
                'google_id' => $googleUser->getId(),
                'rol' => 'junior',
                'activo' => true,
                'email_verified_at' => now(),
                'password' => null,
            ]);
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('landing', absolute: false));
    }
}
