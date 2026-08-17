<x-layouts.app title="Meeting unavailable">
    <div class="flex min-h-dvh flex-col items-center justify-center gap-6 px-6 text-center">
        <div class="rounded-full border border-white/10 bg-white/5 p-4">
            <x-icon name="video-off" class="size-8 text-slate-500" aria-hidden="true" />
        </div>
        <div>
            <h1 class="text-xl font-semibold text-slate-100">{{ $reason }}</h1>
            <p class="mt-1 text-sm text-slate-400">Ask the host for a new meeting link.</p>
        </div>
        <a href="{{ route('home') }}" class="rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400">
            Back to home
        </a>
    </div>
</x-layouts.app>
