@props(['name'])

@php
    // A small stroke-based icon set in the widely-used open-source
    // Feather/Lucide style (24x24 viewBox, round caps/joins, MIT-licensed
    // conventions) — hand-drawn here to avoid an extra npm dependency for
    // a dozen icons.
    $paths = [
        'video' => '<rect x="3" y="6" width="13" height="12" rx="3" /><path d="M16 10.5 21 7v10l-5-3.5Z" />',
        'video-off' => '<rect x="3" y="6" width="13" height="12" rx="3" /><path d="M16 10.5 21 7v10l-5-3.5Z" /><line x1="2" y1="3" x2="22" y2="21" />',
        'mic' => '<rect x="9" y="2" width="6" height="12" rx="3" /><path d="M5 10a7 7 0 0 0 14 0" /><line x1="12" y1="17" x2="12" y2="21" /><line x1="8" y1="21" x2="16" y2="21" />',
        'mic-off' => '<rect x="9" y="2" width="6" height="12" rx="3" /><path d="M5 10a7 7 0 0 0 14 0" /><line x1="12" y1="17" x2="12" y2="21" /><line x1="8" y1="21" x2="16" y2="21" /><line x1="2" y1="2" x2="22" y2="22" />',
        'screen-share' => '<rect x="3" y="4" width="18" height="12" rx="2" /><line x1="8" y1="20" x2="16" y2="20" /><line x1="12" y1="16" x2="12" y2="20" /><path d="M9 11l3-3 3 3" /><line x1="12" y1="8" x2="12" y2="13" />',
        'lock-closed' => '<rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" />',
        'lock-open' => '<rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V7a4 4 0 0 1 7.2-2.4" />',
        'users' => '<circle cx="9" cy="7" r="4" /><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /><path d="M23 21v-2a4 4 0 0 0-3-3.87" />',
        // Lucide's own "user-plus" glyph verbatim, not a hand-tweaked
        // variant — the "+" sits at bottom-right, clear of the head
        // entirely, instead of crowding the temple the way a top-right
        // plus does next to a face. A well-established, widely-recognized
        // shape reads as more deliberate than an approximation of one.
        'user-plus' => '<path d="M2 21a8 8 0 0 1 13.292-6" /><circle cx="10" cy="8" r="5" /><path d="M19 16v6" /><path d="M22 19h-6" />',
        'link' => '<path d="M9 15l6-6" /><path d="M11 6l1-1a3.5 3.5 0 0 1 5 5l-1 1" /><path d="M13 18l-1 1a3.5 3.5 0 0 1-5-5l1-1" />',
        'x-mark' => '<line x1="6" y1="6" x2="18" y2="18" /><line x1="18" y1="6" x2="6" y2="18" />',
        // A downward-pointing handset — the universal "hang up" glyph used
        // by FaceTime/Meet/Zoom, distinct from a generic "phone" icon.
        'phone-hangup' => '<path d="M3.5 15.5c3-6 14-6 17 0" stroke-linecap="round" /><path d="M9 15.2v2.6a1.2 1.2 0 0 1-1.72 1.08l-2.1-1.05a1.2 1.2 0 0 1-.65-1.2l.2-1.75a1.2 1.2 0 0 1 .96-1.04L8 13.4a1.2 1.2 0 0 1 1 .5Z" /><path d="M15 15.2v2.6a1.2 1.2 0 0 0 1.72 1.08l2.1-1.05a1.2 1.2 0 0 0 .65-1.2l-.2-1.75a1.2 1.2 0 0 0-.96-1.04L16 13.4a1.2 1.2 0 0 0-1 .5Z" />',
        'power' => '<path d="M12 3v7" /><path d="M18.36 6.64a9 9 0 1 1-12.73 0" />',
        'check' => '<path d="M5 12l5 5L20 7" />',
        'alert' => '<path d="M12 9v4" /><path d="M12 17h.01" /><path d="M10.3 3.9 2.5 18a2 2 0 0 0 1.7 3h15.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />',
        'chat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z" />',
        'send' => '<path d="m22 2-7 20-4-9-9-4Z" /><path d="M22 2 11 13" />',
        'chevron-down' => '<path d="m6 9 6 6 6-6" />',
        'play' => '<path d="M7 4v16l13-8L7 4Z" />',
        'fullscreen-enter' => '<path d="M8 3H5a2 2 0 0 0-2 2v3" /><path d="M21 8V5a2 2 0 0 0-2-2h-3" /><path d="M3 16v3a2 2 0 0 0 2 2h3" /><path d="M16 21h3a2 2 0 0 0 2-2v-3" />',
        'fullscreen-exit' => '<path d="M8 3v3a2 2 0 0 1-2 2H3" /><path d="M21 8h-3a2 2 0 0 1-2-2V3" /><path d="M3 16h3a2 2 0 0 1 2 2v3" /><path d="M16 21v-3a2 2 0 0 1 2-2h3" />',
        // Lucide's "hand" glyph — used for Raise Hand.
        'hand' => '<path d="M18 11V6a2 2 0 0 0-2-2a2 2 0 0 0-2 2" /><path d="M14 10V4a2 2 0 0 0-2-2a2 2 0 0 0-2 2v2" /><path d="M10 10.5V6a2 2 0 0 0-2-2a2 2 0 0 0-2 2v8" /><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15" />',
    ];
@endphp

<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" {{ $attributes }}>
    {!! $paths[$name] ?? '' !!}
</svg>
