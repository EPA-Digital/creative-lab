<?php

namespace App\Http\Controllers;

use App\Models\Creativo;
use App\Models\Cuenta;
use App\Models\Pais;
use App\Services\Auditoria;
use App\Services\Ingesta\ReglasNomenclatura;
use App\Support\CuentaActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Cola "Sin creativo" (2026-11-05): anuncios que ninguna regla del
 * catálogo de la cuenta pudo asignar a un creativo -- casi siempre Spark Ads
 * de TikTok, cuyo "nombre" es el texto del post o el archivo del video. Se
 * agrupan por post (tiktok_item_id) o, sin post, por texto idéntico: un
 * mismo post en varios anuncios es el mismo creativo.
 *
 * Asignar un creativo NO es una corrección suelta: agrega una regla al
 * catálogo de la cuenta (ReglasNomenclatura, arte.catalogo) -- por post, o
 * por el texto exacto -- y actualiza los anuncios ya importados. Así las
 * importaciones futuras lo heredan solas. Solo para cuentas con
 * clasificación propia: TaDa usa su clasificador (ver Nomenclatura).
 */
class SinCreativoController extends Controller
{
    public function index(string $pais): Response
    {
        [$paisModelo, $cuenta] = $this->contexto($pais);

        $grupos = [];
        if ($cuenta->nomenclatura && $cuenta->slug !== Cuenta::SLUG_TADA) {
            $gastoPorCreativo = DB::table('resultados')->join('creativos', 'creativos.id', '=', 'resultados.creativo_id')
                ->where('creativos.cuenta_id', $cuenta->id)->where('creativos.pais_id', $paisModelo->id)
                ->groupBy('resultados.creativo_id')
                ->selectRaw('resultados.creativo_id, SUM(resultados.cost) gasto, MAX(resultados.mes) ultimo_mes')
                ->get()->keyBy('creativo_id');

            $sinArte = Creativo::where('pais_id', $paisModelo->id)->whereNull('nombre_comun')
                ->get(['id', 'ad_id', 'post_id', 'nombre_completo', 'nombre_campania', 'plataforma']);

            foreach ($sinArte as $c) {
                $clave = $c->post_id ? "post:{$c->post_id}" : 'texto:'.trim((string) $c->nombre_completo);
                $g = $grupos[$clave] ??= [
                    'clave' => $clave,
                    'post_id' => $c->post_id,
                    'texto' => trim((string) $c->nombre_completo),
                    'plataforma' => $c->plataforma,
                    'campania' => $c->nombre_campania,
                    'anuncios' => 0,
                    'gasto' => 0.0,
                    'ultimo_mes' => null,
                ];
                $g['anuncios']++;
                $g['gasto'] += (float) ($gastoPorCreativo[$c->id]->gasto ?? 0);
                $g['ultimo_mes'] = max($g['ultimo_mes'], $gastoPorCreativo[$c->id]->ultimo_mes ?? null);
                $grupos[$clave] = $g;
            }
            usort($grupos, fn ($a, $b) => $b['gasto'] <=> $a['gasto']);
        }

        return Inertia::render('SinCreativo', [
            'pais' => $pais,
            'disponible' => (bool) $cuenta->nomenclatura && $cuenta->slug !== Cuenta::SLUG_TADA,
            'esTada' => $cuenta->slug === Cuenta::SLUG_TADA,
            'grupos' => array_values($grupos),
            // Para sugerir: los creativos que la cuenta ya tiene.
            'creativosExistentes' => collect($cuenta->nomenclatura['arte']['catalogo'] ?? [])->pluck('valor')
                ->merge(Creativo::where('pais_id', $paisModelo->id)->whereNotNull('nombre_comun')->distinct()->pluck('nombre_comun'))
                ->unique()->sort()->values(),
        ]);
    }

    public function asignar(Request $request, string $pais): JsonResponse
    {
        [$paisModelo, $cuenta] = $this->contexto($pais);
        if (! $cuenta->nomenclatura || $cuenta->slug === Cuenta::SLUG_TADA) {
            throw ValidationException::withMessages(['nombre' => 'Primero configura la clasificación de la cuenta (Cuentas → editar → 07).']);
        }

        $data = $request->validate([
            'post_id' => ['nullable', 'string', 'max:40', 'regex:/^\d+$/'],
            'texto' => ['nullable', 'string', 'max:2000'],
            'nombre' => ['required', 'string', 'max:120'],
        ], ['nombre.required' => 'Escribe el nombre del creativo.']);
        $nombre = trim($data['nombre']);
        $postId = $data['post_id'] ?? null;
        $texto = trim($data['texto'] ?? '');
        if (! $postId && $texto === '') {
            throw ValidationException::withMessages(['nombre' => 'Falta el post o el texto del anuncio.']);
        }

        // Regla nueva al principio del catálogo (la más específica primero);
        // si ya había una para ese post o texto, se reemplaza.
        $regla = $postId ? ['post_id' => $postId, 'valor' => $nombre] : ['patron' => '^'.preg_quote($texto).'$', 'valor' => $nombre];
        $reglas = $cuenta->nomenclatura;
        $catalogo = array_values(array_filter($reglas['arte']['catalogo'] ?? [], fn ($r) => $postId
            ? ($r['post_id'] ?? null) !== $postId
            : ($r['patron'] ?? null) !== $regla['patron']));
        $reglas['arte'] = [...($reglas['arte'] ?? []), 'catalogo' => [$regla, ...$catalogo]];
        try {
            $cuenta->update(['nomenclatura' => ReglasNomenclatura::normalizar($reglas)]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['nombre' => $e->getMessage()]);
        }

        $actualizados = Creativo::where('pais_id', $paisModelo->id)->whereNull('nombre_comun')
            ->where(fn ($q) => $postId ? $q->where('post_id', $postId) : $q->whereNull('post_id')->where('nombre_completo', $texto))
            ->update(['nombre_comun' => $nombre]);

        Auditoria::registrar('cuenta.creativo_asignado', $request->user(), detalle: [
            'cuenta_id' => $cuenta->id, 'post_id' => $postId, 'creativo' => $nombre, 'anuncios' => $actualizados,
        ]);

        return response()->json(['ok' => true, 'actualizados' => $actualizados]);
    }

    /** @return array{0: Pais, 1: Cuenta} */
    private function contexto(string $pais): array
    {
        $config = config("paises.{$pais}");
        abort_unless($config, 404, "País \"{$pais}\" no existe en config/paises.php.");

        return [Pais::where('codigo', $config['codigo'])->firstOrFail(), Cuenta::findOrFail(CuentaActual::id())];
    }
}
