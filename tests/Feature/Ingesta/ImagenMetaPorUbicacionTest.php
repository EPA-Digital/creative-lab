<?php

use App\Services\Ingesta\EnriquecedorCostosMeta;

/**
 * Elegir la imagen de un anuncio Advantage+ por UBICACIÓN y luego por
 * prioridad (2026-11-13) -- caso real Ecuador, ad 120251883716490661.
 */
function elegirHash(array $assetFeedSpec): ?string
{
    return (new ReflectionMethod(EnriquecedorCostosMeta::class, 'elegirHashAssetFeedSpec'))->invoke(null, $assetFeedSpec);
}

function imagenConEtiqueta(string $hash, string $label): array
{
    return ['hash' => $hash, 'adlabels' => [['id' => $label]]];
}

it('prefiere el feed de FB/IG aunque Messenger tenga prioridad 1', function () {
    $spec = [
        'images' => [
            imagenConEtiqueta('viejo-julio', 'L0'),
            imagenConEtiqueta('six-pack-septiembre', 'L1'),
            imagenConEtiqueta('verde-feed', 'L2'),
            imagenConEtiqueta('verde-story', 'L3'),
        ],
        'asset_customization_rules' => [
            ['priority' => 1, 'image_label' => ['id' => 'L1'], 'customization_spec' => ['publisher_platforms' => ['messenger'], 'messenger_positions' => ['story']]],
            ['priority' => 2, 'image_label' => ['id' => 'L2'], 'customization_spec' => ['publisher_platforms' => ['facebook', 'instagram'], 'facebook_positions' => ['feed'], 'instagram_positions' => ['stream']]],
            ['priority' => 3, 'image_label' => ['id' => 'L3'], 'customization_spec' => ['publisher_platforms' => ['facebook', 'instagram'], 'facebook_positions' => ['story'], 'instagram_positions' => ['story', 'reels']]],
        ],
    ];

    expect(elegirHash($spec))->toBe('verde-feed');
});

it('sin feed usa stories/reels antes que otras ubicaciones o el catch-all', function () {
    $spec = [
        'images' => [imagenConEtiqueta('generico', 'A'), imagenConEtiqueta('audience', 'B'), imagenConEtiqueta('reels', 'C')],
        'asset_customization_rules' => [
            ['priority' => 1, 'image_label' => ['id' => 'A'], 'customization_spec' => ['age_min' => 18]],
            ['priority' => 2, 'image_label' => ['id' => 'B'], 'customization_spec' => ['publisher_platforms' => ['audience_network']]],
            ['priority' => 3, 'image_label' => ['id' => 'C'], 'customization_spec' => ['publisher_platforms' => ['instagram'], 'instagram_positions' => ['reels']]],
        ],
    ];

    expect(elegirHash($spec))->toBe('reels');
});

it('sin reglas de ubicación usa la primera imagen', function () {
    expect(elegirHash(['images' => [['hash' => 'uno'], ['hash' => 'dos']]]))->toBe('uno');
});
