<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelvePaisDeContexto;
use App\Models\Pais;
use App\Models\User;
use App\Services\Auditoria;
use App\Services\Presencia\Presencia;
use App\Services\SesionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-only (->middleware('can:epa'), ver routes/web.php). Único lugar
 * donde se crean usuarios 'cliente' -- no hay auto-registro (ver
 * InvitacionController), un EPA siempre invita a mano.
 *
 * Jerarquía completa (2026-09-23, pedido explícito): superadmin > director
 * > gerente > senior > junior > cliente. Cada quien administra (rol,
 * países) SOLO a los que están estrictamente por debajo (ver
 * User::puedeGestionarA(), Gate 'gestionar-usuarios') y en index() solo VE
 * a los que están por debajo -- nunca a su propio nivel ni arriba. Dos
 * excepciones a la jerarquía general:
 * - Desactivar: solo director/superadmin (Gate 'desactivar-usuarios'),
 *   un gerente no desactiva ni a su propio junior.
 * - Otorgar 'director'/'superadmin': solo superadmin (ROLES_SOLO_
 *   SUPERADMIN más abajo), un director no puede crear otro director.
 *
 * Invitar con países propuestos (store()) por un junior/senior queda
 * PENDIENTE (activo=false, pais_ids_propuestos poblado) hasta que alguien
 * con puedeAprobarInvitaciones() (gerente/director/superadmin) lo apruebe
 * -- ver aprobar(). Un invitado pendiente ni siquiera puede loguearse.
 *
 * Todo pais_ids (acá y en aprobar()) queda limitado a los países que el
 * propio actor tiene asignados -- nadie puede otorgar acceso a un país
 * que ni él mismo puede ver. Lo mismo con cuenta_ids (multi-cuenta,
 * 2026-11-04); además, al guardar se conservan las cuentas del usuario que
 * el actor no ve (no puede quitar lo que no puede otorgar).
 *
 * Sin envío de correo automático a propósito -- MAIL_MAILER=log hoy no
 * entrega nada real y montar un transporte real es alcance aparte. store()
 * devuelve el link de invitación para que el EPA que invita lo copie y lo
 * mande por el canal que quiera (Slack, WhatsApp, correo manual).
 */
class UsuariosController extends Controller
{
    use ResuelvePaisDeContexto;

    private const ROLES = ['superadmin', 'director', 'gerente', 'senior', 'junior', 'cliente'];

    private const ROLES_SOLO_SUPERADMIN = ['superadmin', 'director'];

    public function index(Request $request): Response
    {
        $yo = $request->user();

        $usuarios = User::orderByDesc('id')
            ->with(['paises:id,codigo,nombre', 'cuentas:id,slug,nombre,color,logo_url,actualizado_en'])
            ->get()
            // Jerarquía (ver User::puedeGestionarA()) -- solo lo que está
            // estrictamente por debajo, más la propia fila (para verse a
            // sí mismo en la lista, nunca para autoadministrarse).
            ->filter(fn (User $u) => $u->id === $yo->id || $yo->puedeGestionarA($u))
            ->values();
        $presencia = Presencia::de($usuarios);
        $usuarios = $usuarios
            ->map(fn (User $u) => [
                'presencia' => $presencia[$u->id],
                ...$u->only(['id', 'name', 'email', 'rol', 'activo', 'metodo_auth', 'pais_ids_propuestos', 'cuenta_ids_propuestos']),
                ...$u->perfilPublico(),
                'pendiente' => $u->estaPendienteDeAprobacion(),
                // Invitación o link de contraseña sin usar todavía.
                'invitado' => $u->invitacion_token !== null,
                'tiene_2fa' => $u->tieneTotpConfirmado(),
                'es_dominio_epa' => str_ends_with(Str::lower($u->email), '@'.User::DOMINIO_EPA),
                'editable' => $yo->puedeGestionarA($u),
                'paises' => $u->paises->map(fn (Pais $p) => $p->only(['id', 'codigo', 'nombre']))->values(),
                'cuentas' => $u->cuentas->map(fn ($c) => ['id' => $c->id, 'slug' => $c->slug, 'nombre' => $c->nombre, 'color' => $c->color, 'logo' => $c->logoSrc()])->values(),
            ]);

        return Inertia::render('Usuarios/Index', [
            'usuarios' => $usuarios,
            'paises' => Pais::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            // Solo lo que quien edita puede otorgar (ver store()/actualizarRolYPaises()).
            'paisesOtorgables' => $yo->paises()->pluck('paises.id'),
            'cuentas' => $yo->cuentas()->orderBy('nombre')->get(['cuentas.id', 'slug', 'nombre', 'color', 'logo_url', 'actualizado_en'])
                ->map(fn ($c) => ['id' => $c->id, 'slug' => $c->slug, 'nombre' => $c->nombre, 'color' => $c->color, 'logo' => $c->logoSrc()]),
            'roles' => self::ROLES,
            'pais' => $this->paisDeContexto($request),
        ]);
    }

    /**
     * Presencia de los usuarios que quien pregunta ve en la lista (la página
     * la vuelve a pedir cada minuto para que el círculo se mantenga al día).
     */
    public function presencia(Request $request): JsonResponse
    {
        $yo = $request->user();
        $usuarios = User::all(['id', 'rol', 'ultima_actividad_en'])
            ->filter(fn (User $u) => $u->id === $yo->id || $yo->puedeGestionarA($u))
            ->values();

        return response()->json(Presencia::de($usuarios));
    }

    public function store(Request $request): JsonResponse
    {
        $yo = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'pais_ids' => ['array'],
            'pais_ids.*' => [
                'integer',
                // Nadie propone/otorga acceso a un país que ni él mismo
                // tiene asignado.
                Rule::in($yo->paises()->pluck('paises.id')),
            ],
            'cuenta_ids' => ['array'],
            'cuenta_ids.*' => ['integer', Rule::in($yo->cuentas()->pluck('cuentas.id'))],
            // metodo/motivo (auth-prompt.md Fase 3) -- default google
            // (opción preferida). Elegir 'password' exige guardar el
            // motivo -- ese usuario va a tener TOTP obligatorio.
            'metodo' => ['nullable', Rule::in([User::METODO_GOOGLE, User::METODO_PASSWORD])],
            'motivo' => ['required_if:metodo,'.User::METODO_PASSWORD, 'nullable', 'string', 'max:500'],
        ]);

        $metodo = $data['metodo'] ?? User::METODO_GOOGLE;

        // Regla dura (auth-prompt.md Fase 1) validada también acá, no solo
        // en el modelo -- un @epa.digital nunca puede invitarse con
        // contraseña, entra siempre por Google.
        if ($metodo === User::METODO_PASSWORD && str_ends_with(Str::lower($data['email']), '@'.User::DOMINIO_EPA)) {
            throw ValidationException::withMessages([
                'metodo' => 'Una cuenta @'.User::DOMINIO_EPA.' no puede invitarse con contraseña.',
            ]);
        }

        // junior/senior: pendiente de aprobación (ver
        // User::puedeAprobarInvitaciones()) -- activo=false, los países
        // propuestos NO se sincronizan todavía a usuario_pais.
        $esPropuesta = ! $yo->puedeAprobarInvitaciones();

        $tokenPlano = Str::random(40);

        $usuario = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'rol' => 'cliente',
            'activo' => ! $esPropuesta,
            'password' => null,
            'metodo_auth' => $metodo,
            'motivo_password' => $metodo === User::METODO_PASSWORD ? $data['motivo'] : null,
            'invitacion_token' => hash('sha256', $tokenPlano),
            'invitacion_expira_en' => now()->addHours(72),
            'creado_por' => $yo->id,
            'pais_ids_propuestos' => $esPropuesta ? ($data['pais_ids'] ?? []) : null,
            'cuenta_ids_propuestos' => $esPropuesta ? ($data['cuenta_ids'] ?? []) : null,
        ]);

        if (! $esPropuesta && ! empty($data['pais_ids'])) {
            $usuario->paises()->sync($data['pais_ids']);
        }
        if (! $esPropuesta && ! empty($data['cuenta_ids'])) {
            $usuario->cuentas()->sync($data['cuenta_ids']);
        }

        Auditoria::registrar('invitacion.creada', $usuario, detalle: ['creado_por' => $yo->id, 'metodo' => $metodo, 'pendiente' => $esPropuesta]);

        return response()->json([
            'usuario' => [...$usuario->only(['id', 'name', 'email', 'rol', 'activo', 'pais_ids_propuestos', 'cuenta_ids_propuestos', 'metodo_auth']), 'paises' => $usuario->paises, 'cuentas' => $usuario->cuentas()->get(['cuentas.id', 'cuentas.nombre'])],
            'linkInvitacion' => route('invitaciones.show', $tokenPlano),
            'pendiente' => $esPropuesta,
        ], 201);
    }

    public function destroy(Request $request, User $usuario): JsonResponse
    {
        abort_if($usuario->id === $request->user()->id, 422, 'No podés desactivarte a vos mismo.');

        $usuario->update(['activo' => false]);
        SesionService::invalidarSesionesDe($usuario);
        Auditoria::registrar('usuario.desactivado', $usuario, detalle: ['desactivado_por' => $request->user()->id]);

        return response()->json(['ok' => true]);
    }

    /**
     * Inverso de destroy() -- vuelve a activar a alguien que se había
     * desactivado (pedido explícito 2026-09-24). No sirve para una
     * invitación todavía pendiente de aprobación (esa pasa por aprobar(),
     * que además sincroniza los países propuestos) -- acá se rechaza ese
     * caso para no confundir los dos flujos.
     */
    public function reactivar(Request $request, User $usuario): JsonResponse
    {
        abort_if($usuario->estaPendienteDeAprobacion(), 422, 'Este usuario tiene una invitación pendiente -- usá "Aprobar", no "Reactivar".');

        $usuario->update(['activo' => true]);
        Auditoria::registrar('usuario.reactivado', $usuario, detalle: ['reactivado_por' => $request->user()->id]);

        return response()->json(['ok' => true]);
    }

    /**
     * Borra la fila de `users` de verdad -- exclusivo de superadmin
     * (Gate 'eliminar-usuarios', pedido explícito 2026-09-24). Distinto
     * de destroy(): eso desactiva (reversible, el historial queda), esto
     * es irreversible. Las tablas que referencian al usuario ya están
     * armadas para esto (nullOnDelete/cascadeOnDelete en sus migraciones)
     * -- no hace falta borrar nada más a mano.
     */
    public function eliminar(Request $request, User $usuario): JsonResponse
    {
        abort_if($usuario->id === $request->user()->id, 422, 'No podés eliminarte a vos mismo.');

        SesionService::invalidarSesionesDe($usuario);
        Auditoria::registrar('usuario.eliminado', $usuario, detalle: ['eliminado_por' => $request->user()->id]);
        $usuario->delete();

        return response()->json(['ok' => true]);
    }

    public function actualizarRolYPaises(Request $request, User $usuario): JsonResponse
    {
        $yo = $request->user();

        $data = $request->validate([
            'rol' => ['required', Rule::in(self::ROLES)],
            'pais_ids' => ['array'],
            'pais_ids.*' => ['integer', Rule::in($yo->paises()->pluck('paises.id'))],
            'cuenta_ids' => ['array'],
            'cuenta_ids.*' => ['integer', Rule::in($yo->cuentas()->pluck('cuentas.id'))],
        ]);

        // Solo superadmin puede otorgar 'director'/'superadmin' -- ni
        // siquiera un director puede crear otro director. (El Gate
        // 'gestionar-usuarios' de la ruta ya validó la jerarquía general;
        // esto es una restricción adicional sobre el ROL DESTINO.)
        abort_if(
            in_array($data['rol'], self::ROLES_SOLO_SUPERADMIN, true) && ! $yo->esSuperadmin(),
            403,
            'Solo un superadmin puede otorgar director/superadmin.'
        );

        $rolAnterior = $usuario->rol;
        $usuario->update(['rol' => $data['rol']]);
        $usuario->paises()->sync($data['pais_ids'] ?? []);
        if (array_key_exists('cuenta_ids', $data)) {
            $this->sincronizarCuentas($yo, $usuario, $data['cuenta_ids']);
        }
        Auditoria::registrar('usuario.rol_cambiado', $usuario, detalle: [
            'cambiado_por' => $yo->id,
            'rol_anterior' => $rolAnterior,
            'rol_nuevo' => $data['rol'],
            'pais_ids' => $data['pais_ids'] ?? [],
            'cuenta_ids' => $data['cuenta_ids'] ?? null,
        ]);

        return response()->json([
            'usuario' => [...$usuario->only(['id', 'name', 'email', 'rol', 'activo']), 'paises' => $usuario->paises, 'cuentas' => $usuario->cuentas()->get(['cuentas.id', 'cuentas.nombre'])],
        ]);
    }

    /**
     * Aprueba una invitación que un junior/senior dejó pendiente en
     * store() -- activa la cuenta y recién ahí sincroniza los países
     * (propuestos por default, pero quien aprueba puede ajustar la lista
     * antes de confirmar).
     */
    public function aprobar(Request $request, User $usuario): JsonResponse
    {
        $yo = $request->user();

        abort_unless($usuario->estaPendienteDeAprobacion(), 422, 'Este usuario no tiene una invitación pendiente.');

        $data = $request->validate([
            'pais_ids' => ['array'],
            'pais_ids.*' => ['integer', Rule::in($yo->paises()->pluck('paises.id'))],
            'cuenta_ids' => ['array'],
            'cuenta_ids.*' => ['integer', Rule::in($yo->cuentas()->pluck('cuentas.id'))],
        ]);

        $paisIds = $data['pais_ids'] ?? $usuario->pais_ids_propuestos ?? [];
        // Las propuestas también se limitan a las cuentas de quien aprueba.
        $cuentaIds = $data['cuenta_ids'] ?? array_values(array_intersect($usuario->cuenta_ids_propuestos ?? [], $yo->cuentas()->pluck('cuentas.id')->all()));

        $usuario->update(['activo' => true, 'pais_ids_propuestos' => null, 'cuenta_ids_propuestos' => null]);
        $usuario->paises()->sync($paisIds);
        $usuario->cuentas()->sync($cuentaIds);
        Auditoria::registrar('invitacion.aprobada', $usuario, detalle: ['aprobado_por' => $yo->id, 'pais_ids' => $paisIds, 'cuenta_ids' => $cuentaIds]);

        return response()->json([
            'usuario' => [...$usuario->only(['id', 'name', 'email', 'rol', 'activo']), 'paises' => $usuario->paises, 'cuentas' => $usuario->cuentas()->get(['cuentas.id', 'cuentas.nombre'])],
        ]);
    }

    /**
     * Reemplaza las cuentas que el actor puede otorgar y conserva las que no
     * ve -- un gerente con solo TaDa no le quita Openbank a nadie.
     *
     * @param  list<int>  $cuentaIds
     */
    private function sincronizarCuentas(User $yo, User $usuario, array $cuentaIds): void
    {
        $mias = $yo->cuentas()->pluck('cuentas.id')->all();
        $fueraDeMiAlcance = array_diff($usuario->cuentas()->pluck('cuentas.id')->all(), $mias);
        $usuario->cuentas()->sync([...$fueraDeMiAlcance, ...$cuentaIds]);
    }

    /**
     * "TOTP perdido" (auth-prompt.md Fase 3) -- sin autoservicio, un EPA
     * con gerente o superior lo resetea a mano (mismo Gate que aprobar
     * invitaciones, ver AppServiceProvider). El usuario re-enrola en su
     * próximo login por contraseña.
     */
    public function resetearTotp(Request $request, User $usuario): JsonResponse
    {
        abort_if($usuario->metodo_auth !== User::METODO_PASSWORD, 422, 'Este usuario no usa TOTP.');

        $usuario->update([
            'totp_secret' => null,
            'totp_confirmed_at' => null,
            'totp_recovery_codes' => null,
            'totp_last_timestep' => null,
        ]);
        Auditoria::registrar('totp.reseteado', $usuario, detalle: ['reseteado_por' => $request->user()->id]);

        return response()->json(['ok' => true]);
    }

    /**
     * "Olvidé mi contraseña" sin SMTP real (pedido explícito 2026-09-24:
     * "yo como admin la reseteas manualmente") -- reusa el mismo
     * mecanismo de invitación (token hasheado + 72h) en vez de un correo
     * que hoy no se entrega (MAIL_MAILER=log, ver docs/pendientes-datos.md).
     * Un admin genera el link acá y lo manda a mano (Slack/WhatsApp) --
     * mismo patrón que store(). El TOTP existente NO se toca: al aceptar
     * el link, InvitacionController salta el enrolamiento (ya está
     * confirmado) pero sigue pidiendo el código antes de abrir sesión.
     */
    public function resetearPassword(Request $request, User $usuario): JsonResponse
    {
        abort_if($usuario->metodo_auth !== User::METODO_PASSWORD, 422, 'Este usuario entra con Google, no tiene contraseña que resetear.');

        $tokenPlano = Str::random(40);

        $usuario->update([
            'password' => null,
            'invitacion_token' => hash('sha256', $tokenPlano),
            'invitacion_expira_en' => now()->addHours(72),
        ]);
        SesionService::invalidarSesionesDe($usuario);
        Auditoria::registrar('usuario.password_reseteado', $usuario, detalle: ['reseteado_por' => $request->user()->id]);

        return response()->json([
            'ok' => true,
            'linkInvitacion' => route('invitaciones.show', $tokenPlano),
        ]);
    }

    /**
     * Cliente invitado con metodo_auth=google que en realidad necesita
     * entrar con correo/contraseña + Google Authenticator (pedido explícito
     * 2026-10-21 -- el form de invitar nunca mandaba 'metodo', así que todos
     * quedaban en google sin 2FA y sin poder usar la contraseña que ponían).
     * Genera el mismo link de invitación: al aceptarlo pone contraseña,
     * InvitacionController lo manda a enrolar el TOTP y ve sus códigos de
     * recuperación.
     */
    public function activarPassword(Request $request, User $usuario): JsonResponse
    {
        abort_if($usuario->esCorreoEpa(), 422, 'Una cuenta @'.User::DOMINIO_EPA.' solo puede entrar con Google.');
        abort_if($usuario->metodo_auth === User::METODO_PASSWORD, 422, 'Este usuario ya entra con contraseña + 2FA.');

        $tokenPlano = Str::random(40);

        $usuario->update([
            'metodo_auth' => User::METODO_PASSWORD,
            'motivo_password' => 'Cambiado de Google a contraseña + 2FA desde /usuarios.',
            'google_id' => null,
            'password' => null,
            'totp_secret' => null,
            'totp_confirmed_at' => null,
            'totp_recovery_codes' => null,
            'totp_last_timestep' => null,
            'invitacion_token' => hash('sha256', $tokenPlano),
            'invitacion_expira_en' => now()->addHours(72),
        ]);
        SesionService::invalidarSesionesDe($usuario);
        Auditoria::registrar('usuario.metodo_cambiado_a_password', $usuario, detalle: ['cambiado_por' => $request->user()->id]);

        return response()->json([
            'ok' => true,
            'linkInvitacion' => route('invitaciones.show', $tokenPlano),
        ]);
    }
}
