<?php

/*
 * Channel (2026-11-17, pedido explícito): réplica de channel.monthly.2026 y
 * channel.weekly.2026 de los "Daily Cuentas" de cada país. Cada canal es la
 * suma de AF data por media source -- sin filtro de fuente final, igual que
 * la hoja: SUMIFS(..., mediasource, "Apple Search Ads", date.af, ...).
 *
 * Los medios cambian por país; el orden de la lista es el de la hoja.
 * Jampp aparece en las hojas de Panamá, Perú y México pero el equipo no lo
 * cuenta como canal (2026-11-17), así que no está aquí (sus datos de 2025 sí
 * se guardaron, por si se vuelve a contar: basta agregarlo).
 *
 * - media: media sources de AppsFlyer que suman al canal (sin distinguir
 *   mayúsculas).
 * - datos: cómo viene el medio en data.medios (Supermetrics), también sin
 *   distinguir mayúsculas -- para Diario medio / Diario campaña.
 * - color: token de serie de app.css para el encabezado del canal.
 */
$meta = ['id' => 'meta', 'nombre' => 'Meta', 'media' => ['Facebook Ads'], 'datos' => ['facebook', 'meta', 'facebook ads'], 'color' => 'serie-1'];
$tiktok = ['id' => 'tiktok', 'nombre' => 'TikTok', 'media' => ['tiktokglobal_int'], 'datos' => ['tiktok', 'tiktok ads', 'tik tok'], 'color' => 'serie-6'];
$asa = ['id' => 'asa', 'nombre' => 'ASA', 'media' => ['Apple Search Ads'], 'datos' => ['asa', 'apple', 'apple ads', 'apple search ads'], 'color' => 'serie-5'];
$rtb = ['id' => 'rtb', 'nombre' => 'RTB', 'media' => ['rtbhouse_int'], 'datos' => ['rtb house', 'rtb', 'rtbhouse'], 'color' => 'serie-2'];
$twitter = ['id' => 'twitter', 'nombre' => 'Twitter', 'media' => ['Twitter', 'twitterads_int'], 'datos' => ['twitter', 'x', 'twitter ads'], 'color' => 'serie-4'];
$google = ['id' => 'google', 'nombre' => 'Google', 'media' => ['googleadwords_int'], 'datos' => ['google ads', 'google', 'adwords'], 'color' => 'serie-3'];

return [
    'ecuador' => [$meta, $tiktok, $asa, $rtb],
    'panama' => [$meta, $tiktok, $twitter, $google],
    'peru' => [$meta, $tiktok, $twitter, $google, $asa, $rtb],
    'mexico' => [$meta, $tiktok, $twitter, $google],
];
