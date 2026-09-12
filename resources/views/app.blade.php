<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'LexIA') }}</title>

    {{-- Antes da primeira pintura: o tema escolhido vira classe na raiz aqui,
         senão a tela pisca em claro antes do React assumir. --}}
    <script>
        (() => {
            const saved = localStorage.getItem('appearance')
            const dark = saved === 'dark'
                || (saved !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches)

            document.documentElement.classList.toggle('dark', dark)
        })()
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.tsx'])

    {{-- Component form rather than @inertiaHead/@inertia: the legacy
         directives have no SSR fallback. --}}
    <x-inertia::head />
</head>
<body class="h-full">
    <x-inertia::app />
</body>
</html>
