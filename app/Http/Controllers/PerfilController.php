<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auditoria;
use App\Support\DiscoMedios;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Editar perfil" del nav (diseño 2026-11-05): nombre, puesto, color del
 * avatar y foto -- lo que ve el equipo en el dashboard. Cada quien edita el
 * suyo; el correo, el rol y el acceso siguen en /usuarios y /profile.
 *
 * La foto solo PNG/JPG/WebP (un SVG puede llevar scripts) y se sirve desde
 * aquí a cualquier usuario con sesión: es la cara del equipo, no un dato
 * de cliente.
 */
class PerfilController extends Controller
{
    public const COLORES = ['#9184d9', '#59e0bd', '#4fb6ff', '#d8a94a', '#ff8f84'];

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'puesto' => ['nullable', 'string', 'max:80'],
            'avatar_color' => ['nullable', Rule::in(self::COLORES)],
        ], [
            'name.required' => 'Escribe tu nombre.',
            'puesto.max' => 'El puesto puede tener hasta 80 caracteres.',
        ]);

        $request->user()->update([
            'name' => trim($data['name']),
            'puesto' => trim($data['puesto'] ?? '') ?: null,
            'avatar_color' => $data['avatar_color'] ?? null,
        ]);
        Auditoria::registrar('perfil.actualizado', $request->user());

        return response()->json(['usuario' => $request->user()->fresh()->perfilPublico()]);
    }

    public function subirFoto(Request $request): JsonResponse
    {
        $request->validate([
            'foto' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:min_width=32,min_height=32'],
        ], [
            'foto.required' => 'Elige una imagen.',
            'foto.mimes' => 'La foto debe ser PNG, JPG o WebP.',
            'foto.max' => 'La foto pesa más de 2 MB. Prueba con una más ligera.',
            'foto.dimensions' => 'La foto es muy chica; usa al menos 32 × 32 px.',
        ]);

        $usuario = $request->user();
        $archivo = $request->file('foto');
        $ruta = $archivo->storeAs('fotos-perfil', "{$usuario->id}-".Str::random(12).'.'.$archivo->extension(), DiscoMedios::nombre());
        $this->borrarFoto($usuario);
        $usuario->update(['foto_path' => $ruta]);

        return response()->json(['usuario' => $usuario->fresh()->perfilPublico()]);
    }

    public function quitarFoto(Request $request): JsonResponse
    {
        $this->borrarFoto($request->user());
        $request->user()->update(['foto_path' => null]);

        return response()->json(['usuario' => $request->user()->fresh()->perfilPublico()]);
    }

    public function foto(User $usuario): Response
    {
        $disco = Storage::disk(DiscoMedios::nombre());
        abort_unless($usuario->foto_path && $disco->exists($usuario->foto_path), 404);

        return $disco->response($usuario->foto_path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function borrarFoto(User $usuario): void
    {
        if ($usuario->foto_path) {
            Storage::disk(DiscoMedios::nombre())->delete($usuario->foto_path);
        }
    }
}
