<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'LexIA') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.tsx'])

    {{-- Component form rather than @inertiaHead/@inertia: the legacy
         directives have no SSR fallback. --}}
    <x-inertia::head />
</head>
<body class="h-full">
    <x-inertia::app />
</body>
</html>
