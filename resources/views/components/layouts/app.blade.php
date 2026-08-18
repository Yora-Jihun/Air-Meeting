<!DOCTYPE html>
{{--
    color-scheme: dark tells the browser itself this whole page is
    dark-themed, not just its own CSS — there's no light mode here to
    branch on. Without it, native form chrome the app's own utility classes
    can't reach (a <select>'s open dropdown popup, date/time pickers,
    scrollbars) renders with the OS's default light styling regardless of
    how dark everything around it is.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full [color-scheme:dark]">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name') }}</title>
        <meta name="description" content="{{ $description ?? 'Create a meeting, share the link, and talk face to face. Camera, microphone, screen sharing, and chat, right in the browser — no accounts, no downloads.' }}">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="h-full bg-slate-950 text-slate-100 antialiased selection:bg-brand-600/30 selection:text-white">
        {{ $slot }}

        @livewireScripts
    </body>
</html>
