<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auditoria;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Valida contraseña + metodo_auth SIN loguear -- auth-prompt.md Fase 3
     * exige TOTP antes de abrir sesión, así que acá solo se confirma que
     * la contraseña es correcta y que el usuario es de tipo
     * metodo_auth=password (un @epa.digital jamás matchea esto, entra
     * solo por Google). El estado activo/pendiente se resuelve después,
     * en el controller, para poder dar el mensaje de "pendiente de
     * aprobación" en vez de un genérico "credenciales inválidas" -- ya se
     * sabe que la contraseña es correcta en ese punto.
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::validate([...$this->only('email', 'password'), 'metodo_auth' => User::METODO_PASSWORD])) {
            RateLimiter::hit($this->throttleKey());
            Auditoria::registrar('login.password.rechazado', emailIntentado: Str::lower(trim($this->string('email'))), detalle: ['razon' => 'credenciales_invalidas']);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return User::where('email', Str::lower(trim($this->string('email'))))->firstOrFail();
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
