<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <!-- Aplica el tema guardado ANTES del primer paint -- sin esto se
             alcanza a ver un flash del tema por defecto mientras carga Vue.
             Misma clave sessionStorage ('tada-theme') que el toggle real en
             DashboardLayout.vue -- duplicada a propósito, tiene que correr ya. -->
        <script>try{document.documentElement.setAttribute('data-theme', sessionStorage.getItem('tada-theme')||'dark');}catch(e){}</script>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        <!-- Análisis creativo (Fase 3) -- Space Grotesk (títulos/números),
             Inter (texto), JetBrains Mono (métricas/labels chicos) -->
        <link href="https://fonts.bunny.net/css?family=space-grotesk:500,600,700|inter:400,500,600|jetbrains-mono:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
