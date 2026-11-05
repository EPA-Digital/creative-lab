<?php

namespace App\Http\Controllers;

use App\Models\Cuenta;
use App\Services\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logo propio de cada cuenta (2026-11-05). Se guarda en el bucket de GCS en
 * producción y en disco en local (Cuenta::discoLogos), y se sirve desde
 * aquí -- mismo origen, así no toca la CSP ni depende de URLs firmadas, y
 * solo lo ve quien tiene la cuenta (o EPA, que administra todas).
 *
 * Solo PNG/JPG/WebP: un SVG puede llevar scripts.
 */
class LogoCuentaController extends Controller
{
    public function show(Request $request, Cuenta $cuenta): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->esEpa() || $usuario->cuentas()->whereKey($cuenta->id)->exists(), 403);
        abort_unless($cuenta->logo_url && Storage::disk(Cuenta::discoLogos())->exists($cuenta->logo_url), 404);

        return Storage::disk(Cuenta::discoLogos())->response($cuenta->logo_url, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, Cuenta $cuenta): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:min_width=32,min_height=32'],
        ], [
            'logo.required' => 'Elige una imagen.',
            'logo.mimes' => 'El logo debe ser PNG, JPG o WebP.',
            'logo.max' => 'El logo pesa más de 1 MB. Prueba con una versión más ligera.',
            'logo.dimensions' => 'El logo es muy chico; usa al menos 32 × 32 px.',
        ]);

        $archivo = $request->file('logo');
        $ruta = $archivo->storeAs('logos-cuentas', "{$cuenta->id}-".Str::random(12).'.'.$archivo->extension(), Cuenta::discoLogos());
        $this->borrarArchivo($cuenta);
        $cuenta->update(['logo_url' => $ruta]);
        Auditoria::registrar('cuenta.logo_actualizado', $request->user(), detalle: ['cuenta_id' => $cuenta->id]);

        return response()->json(['logo' => $cuenta->fresh()->logoSrc()]);
    }

    public function destroy(Request $request, Cuenta $cuenta): JsonResponse
    {
        $this->borrarArchivo($cuenta);
        $cuenta->update(['logo_url' => null]);
        Auditoria::registrar('cuenta.logo_quitado', $request->user(), detalle: ['cuenta_id' => $cuenta->id]);

        return response()->json(['logo' => null]);
    }

    private function borrarArchivo(Cuenta $cuenta): void
    {
        if ($cuenta->logo_url) {
            Storage::disk(Cuenta::discoLogos())->delete($cuenta->logo_url);
        }
    }
}
