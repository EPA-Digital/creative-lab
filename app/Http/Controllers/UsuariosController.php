<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-only (->middleware('can:epa'), ver routes/web.php). Único lugar
 * donde se crean usuarios 'cliente' -- no hay auto-registro (ver
 * InvitacionController), un EPA siempre invita a mano.
 *
 * Sin envío de correo automático a propósito -- MAIL_MAILER=log hoy no
 * entrega nada real y montar un transporte real es alcance aparte. store()
 * devuelve el link de invitación para que el EPA que invita lo copie y lo
 * mande por el canal que quiera (Slack, WhatsApp, correo manual).
 */
class UsuariosController extends Controller
{
    public function index(): Response
    {
        $usuarios = User::orderByDesc('id')->get(['id', 'name', 'email', 'rol', 'activo', 'invitacion_token', 'creado_por']);

        // '/usuarios' es transversal a países, pero DashboardLayout.vue
        // (el rail de nav) necesita un país de contexto para armar sus
        // hrefs -- primer país habilitado, sin significado especial acá.
        $paisContexto = collect(config('paises'))->firstWhere('habilitado', true);

        return Inertia::render('Usuarios/Index', [
            'usuarios' => $usuarios,
            'pais' => array_search($paisContexto, config('paises'), true) ?: 'ecuador',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
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

        return response()->json([
            'usuario' => $usuario->only(['id', 'name', 'email', 'rol', 'activo']),
            'linkInvitacion' => route('invitaciones.show', $token),
        ], 201);
    }

    public function destroy(Request $request, User $usuario): JsonResponse
    {
        abort_if($usuario->id === $request->user()->id, 422, 'No podés desactivarte a vos mismo.');

        $usuario->update(['activo' => false]);

        return response()->json(['ok' => true]);
    }
}
