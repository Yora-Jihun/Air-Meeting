<x-layouts.app title="Air Meet — video calls without accounts">
    <div class="flex min-h-dvh flex-col">
        <header class="flex items-center gap-2 border-b border-white/10 px-6 py-5 sm:px-10">
            <x-icon name="video" class="size-6 text-brand-500" aria-hidden="true" />
            <span class="text-base font-semibold tracking-tight text-slate-100">Air Meet</span>
        </header>

        <main class="flex flex-1 items-center justify-center px-6 py-16">
            <div class="grid w-full max-w-4xl gap-12 sm:grid-cols-2 sm:items-center">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-slate-100 sm:text-4xl">
                        Video calls, <span class="text-brand-500">no sign-up required</span>
                    </h1>
                    <p class="mt-4 max-w-sm text-sm leading-relaxed text-slate-400">
                        Create a meeting, share the link, and talk face to face. Camera, microphone,
                        and screen sharing — right in the browser.
                    </p>
                </div>

                <div class="flex flex-col gap-6 rounded-xl border border-white/10 bg-white/5 p-8">
                    @livewire('meeting.create')

                    <div class="flex items-center gap-3 text-xs text-slate-500" role="separator">
                        <div class="h-px flex-1 bg-white/10"></div>
                        or
                        <div class="h-px flex-1 bg-white/10"></div>
                    </div>

                    <form
                        x-data="{ code: '' }"
                        @submit.prevent="
                            const match = code.trim().match(/[0-9a-fA-F-]{36}$/);
                            if (match) window.location.href = '/meet/' + match[0];
                        "
                        class="flex gap-2"
                    >
                        <label for="meeting-code" class="sr-only">Meeting link or code</label>
                        <input
                            id="meeting-code"
                            type="text"
                            x-model="code"
                            placeholder="Paste a meeting link or code"
                            class="w-full rounded-lg border border-white/10 bg-white/5 px-4 py-2.5 text-sm text-slate-100 placeholder-slate-500 focus:border-brand-500 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-brand-400"
                        >
                        <button
                            type="submit"
                            class="shrink-0 rounded-lg bg-white/10 px-5 py-2.5 text-sm font-medium text-slate-200 transition hover:bg-white/15 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
                        >
                            Join
                        </button>
                    </form>
                </div>
            </div>
        </main>
    </div>
</x-layouts.app>
