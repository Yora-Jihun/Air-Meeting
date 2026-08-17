@props(['variant' => 'secondary', 'shape' => 'lg'])

@php
    // Centralizing these here is what actually prevents the shape/color
    // drift this app hit twice already — a button's corner radius or tint
    // living as a copy-pasted utility string in five different files is
    // how "New meeting" and "Join now" ended up a different shape from
    // their own neighboring inputs. `shape` is a prop rather than left to
    // each call site's `class` for the same reason: Blade's attribute
    // merge concatenates class strings instead of replacing them, so a
    // caller passing its own `rounded-*` would just add a second,
    // conflicting radius class rather than override this one.
    $variants = [
        'primary' => 'bg-brand-500 text-white hover:bg-brand-600',
        'secondary' => 'bg-white/10 text-slate-200 hover:bg-white/15',
        'danger' => 'bg-red-500 text-white hover:bg-red-600',
        'danger-subtle' => 'bg-red-500/15 text-red-400 hover:bg-red-500/25',
    ];

    $shapes = [
        'lg' => 'rounded-lg',
        'full' => 'rounded-full',
    ];
@endphp

<button
    {{ $attributes->merge([
        'type' => 'button',
        'class' => 'inline-flex items-center justify-center gap-1.5 font-medium transition '
            . 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400 '
            . 'disabled:opacity-60 disabled:pointer-events-none '
            . ($shapes[$shape] ?? $shapes['lg']) . ' '
            . ($variants[$variant] ?? $variants['secondary']),
    ]) }}
>
    {{ $slot }}
</button>
