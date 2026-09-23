<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'meta' => [
        'access_token' => env('META_ACCESS_TOKEN'),
        'graph_version' => env('META_GRAPH_API_VERSION', 'v21.0'),
    ],

    'tiktok' => [
        'access_token' => env('TIKTOK_ACCESS_TOKEN'),
    ],

    // Para el futuro importar:appsflyer-api (ver plan de 2026-08-11) --
    // buffer_atribucion_dias es cuántos días recientes se excluyen del
    // cálculo automático de "hasta" porque AppsFlyer todavía no terminó de
    // recibir las conversiones postback de esos días (lag de atribución
    // conocido, confirmado con el negocio: 5 días por defecto, ajustable).
    'appsflyer' => [
        'api_token' => env('APPSFLYER_API_TOKEN'),
        'buffer_atribucion_dias' => (int) env('APPSFLYER_BUFFER_ATRIBUCION_DIAS', 5),
    ],

    // Evaluación IA de creativos (2026-08-26, ver plan) -- "Evaluar por
    // métricas"/"Evaluar por arte" en el modal de detalle. Sin esta key el
    // resto del dashboard (Lectura/Score, 100% rule-based) sigue funcionando
    // normal -- solo esos dos botones fallan.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
    ],

    // Login EPA vía Google (Socialite) -- ver GoogleAuthController. redirect
    // debe coincidir EXACTO con el redirect URI configurado en el OAuth
    // Client ID de Google Cloud Console.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

];
