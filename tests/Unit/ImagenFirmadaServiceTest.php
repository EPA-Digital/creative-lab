<?php

namespace Tests\Unit;

use App\Services\ImagenFirmadaService;
use Tests\TestCase;

/**
 * auth-prompt.md Fase 6 -- las imágenes se sirven con URLs firmadas de
 * vida corta, nunca con la URL pública fija del bucket. Este entorno no
 * tiene credenciales reales de GCP para firmar de verdad (eso se prueba
 * manualmente contra el bucket real) -- lo que sí se puede probar acá es
 * que NUNCA se filtra la URL sin firmar cuando la firma falla (fail
 * closed), y que una URL que no es de nuestro bucket (ej. el cacheo
 * falló y quedó el CDN remoto de Meta/TikTok) se deja pasar tal cual.
 */
class ImagenFirmadaServiceTest extends TestCase
{
    public function test_una_url_nula_o_vacia_devuelve_null(): void
    {
        $servicio = new ImagenFirmadaService;

        $this->assertNull($servicio->firmar(null));
        $this->assertNull($servicio->firmar(''));
    }

    public function test_una_url_que_no_es_de_nuestro_bucket_se_deja_pasar_tal_cual(): void
    {
        $servicio = new ImagenFirmadaService;
        $urlRemota = 'https://scontent.cdninstagram.com/algun-thumbnail.jpg';

        $this->assertSame($urlRemota, $servicio->firmar($urlRemota));
    }

    public function test_una_url_de_nuestro_bucket_nunca_se_expone_sin_firmar_si_la_firma_falla(): void
    {
        $servicio = new ImagenFirmadaService;
        $urlDelBucket = 'https://storage.googleapis.com/test-bucket/creative-images/ad-1.jpg';

        // Sin credenciales reales de GCP en este entorno, firmar() no
        // puede tener éxito -- la propiedad de seguridad que importa es
        // que NUNCA devuelve la URL pública sin firmar como fallback.
        $this->assertNotSame($urlDelBucket, $servicio->firmar($urlDelBucket));
    }
}
