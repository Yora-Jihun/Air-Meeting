@props(['variant' => 'secondary', 'shape' => 'lg', 'target' => null, 'busy' => null])

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
    @if ($target)
        wire:loading.attr="disabled"
        wire:target="{{ $target }}"
    @elseif ($busy)
        :disabled="{{ $busy }}"
    @endif
    {{ $attributes->merge([
        'type' => 'button',
        'class' => 'relative inline-flex items-center justify-center gap-1.5 font-medium transition '
            . 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400 '
            . 'disabled:opacity-60 disabled:pointer-events-none '
            . ($shapes[$shape] ?? $shapes['lg']) . ' '
            . ($variants[$variant] ?? $variants['secondary']),
    ]) }}
>
    @if ($target || $busy)
        {{-- The label stays a normal flex child at all times — only its
             *visibility* toggles, never whether it participates in the
             row's layout. An earlier version swapped it out via a
             `display:contents` wrapper, which is known-fragile with flex
             `gap` in some browsers: the icon+label occasionally lost their
             single-row flex-item treatment and stacked onto two lines
             instead of just hiding. Visibility keeps the button's size
             fixed too, so it never resizes when a request starts. --}}
        @if ($target)
            <span wire:loading.class="invisible" wire:target="{{ $target }}" class="inline-flex items-center gap-1.5">
                {{ $slot }}
            </span>
            <svg wire:loading wire:target="{{ $target }}" class="absolute inset-0 m-auto size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
            </svg>
        @else
            {{-- `busy` drives this from a plain Alpine boolean instead of
                 wire:loading. Needed specifically for actions that redirect
                 (see the target of `busy` in the caller): Livewire clears
                 wire:loading's classes in the exact same synchronous tick it
                 triggers the redirect/navigate effect — window.location and
                 Alpine.navigate() don't pause execution, so wire:loading
                 reliably reverts to "not loading" a beat before the browser
                 has visually acted on the navigation, flashing the default
                 button back. A caller-owned flag, only cleared after
                 `await $wire.method()` resolves (which happens strictly
                 later, past a real await boundary), doesn't have that race. --}}
            <span :class="({{ $busy }}) ? 'invisible' : ''" class="inline-flex items-center gap-1.5">
                {{ $slot }}
            </span>
            <svg x-show="{{ $busy }}" x-cloak class="absolute inset-0 m-auto size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
            </svg>
        @endif
    @else
        {{ $slot }}
    @endif
</button>
