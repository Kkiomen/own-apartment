<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- A private tool on a personal VPS: it has no business being indexed. --}}
        <meta name="robots" content="noindex, nofollow">
        @php($description = 'Ogłoszenia mieszkań na sprzedaż z pięciu portali, w jednym miejscu, z historią zmian cen.')
        <meta name="description" content="{{ $description }}">

        {{-- Matches --ui-canvas, so the mobile browser chrome blends with the page. --}}
        <meta name="theme-color" content="#f6f7f9" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#171a20" media="(prefers-color-scheme: dark)">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="{{ config('app.name') }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:image" content="{{ url('/icon-512.png') }}">

        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
