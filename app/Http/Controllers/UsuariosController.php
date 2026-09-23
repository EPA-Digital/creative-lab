<?php

namespace App\Http\Controllers;

use App\Models\Pais;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-only (->middleware('can:epa'), ver routes/web.php). Único lugar
 * donde se crean usuarios 'cliente' -- no hay auto-registro (ver
 * InvitacionController), un EPA siempre invita a mano.
 *
 * actualizarRolYPaises() es más estricto -- superadmin/director (Gate
 * 'gestionar-usuarios', ver User::puedeGestionarUsuarios()). Otorgar
 * 'director'/'superadmin' en sí es MÁS estricto todavía -- solo
 * superadmin ("puede haber muchos directores", pedido explícito
 * 2026-09-23): un director normal administra gerente/senior/junior/
 * cliente, pero no puede crear otro director ni ascenderse. Ver también
 * EnsureAccesoPais, que aplica el scope de país a TODOS por igual.
 *
 * Sin envío de correo automático a propósito -- MAIL_MAILER=log hoy no
 * entrega nada real y montar un transporte real es alcance aparte. store()
 * devuelve el link de invitación para que el EPA que invita lo copie y lo
 * mande por el canal que quiera (Slack, WhatsApp, correo manual).
 */
class UsuariosController extends Controller
{
    private const ROLES = ['superadmin', 'director', 'gerente', 'senior', 'junior', 'cliente'];

    private const ROLES_SOLO_SUPERADMIN = ['superadmin', 'director'];

    public function index(): Response
    {
        $usuarios = User::orderByDesc('id')
            ->with('paises:id,codigo,nombre')
            ->get(['id', 'name', 'email', 'rol', 'activo', 'invitacion_token', 'creado_por']);

        $paises = Pais::orderBy('nombre')->get(['id', 'codigo', 'nombre']);

        // '/usuarios' es transversal a países, pero DashboardLayout.vue
        // (el rail de nav) necesita un país de contexto para armar sus
        // hrefs -- primer país habilitado, sin significado especial acá.
        $paisContexto = collect(config('paises'))->firstWhere('habilitado', true);

        return Inertia::render('Usuarios/Index', [
            'usuarios' => $usuarios,
            'paises' => $paises,
            'roles' => self::ROLES,
            'pais' => array_search($paisContexto, config('paises'), true) ?: 'ecuador',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'pais_ids' => ['array'],
            'pais_ids.*' => ['integer', 'exists:paises,id'],
        ]);

        $token = Str::random(40);

        $usuario = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'rol' => 'cliente',
            'activo' => true,
            'password' => null,
            'invitacion_token' => $token,
            'creado_por' => $request->user()->id,
        ]);

        if (! empty($data['pais_ids'])) {
            $usuario->paises()->sync($data['pais_ids']);
        }

        return response()->json([
            'usuario' => [...$usuario->only(['id', 'name', 'email', 'rol', 'activo']), 'paises' => $usuario->paises],
            'linkInvitacion' => route('invitaciones.show', $token),
        ], 201);
    }

    public function destroy(Request $request, User $usuario): JsonResponse
    {
        abort_if($usuario->id === $request->user()->id, 422, 'No podés desactivarte a vos mismo.');

        $usuario->update(['activo' => false]);

        return response()->json(['ok' => true]);
    }

    public function actualizarRolYPaises(Request $request, User $usuario): JsonResponse
    {
        $data = $request->validate([
            'rol' => ['required', Rule::in(self::ROLES)],
            'pais_ids' => ['array'],
            'pais_ids.*' => ['integer', 'exists:paises,id'],
        ]);

        // Solo superadmin puede otorgar 'director'/'superadmin' -- un
        // director normal ni siquiera puede intentarlo (ni para otro, ni
        // para reasignar el suyo propio a otra persona).
        $tocaRolAlto = in_array($data['rol'], self::ROLES_SOLO_SUPERADMIN, true)
            || in_array($usuario->rol, self::ROLES_SOLO_SUPERADMIN, true);
        abort_if(
            $tocaRolAlto && ! $request->user()->esSuperadmin(),
            403,
            'Solo un superadmin puede otorgar o quitar director/superadmin.'
        );

        // Nadie puede sacarse a sí mismo un rol de gestión -- se quedaría
        // sin poder revertirlo.
        abort_if(
            $usuario->id === $request->user()->id
                && $request->user()->puedeGestionarUsuarios()
                && ! in_array($data['rol'], self::ROLES_SOLO_SUPERADMIN, true),
            422,
            'No podés quitarte tu propio rol de gestión.'
        );

        $usuario->update(['rol' => $data['rol']]);
        $usuario->paises()->sync($data['pais_ids'] ?? []);

        return response()->json([
            'usuario' => [...$usuario->only(['id', 'name', 'email', 'rol', 'activo']), 'paises' => $usuario->paises],
        ]);
    }
}
