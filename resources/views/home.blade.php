<x-layouts.app title="Air Meet: video calls without accounts">
    <div class="flex min-h-dvh flex-col">
        <header class="flex items-center gap-2 border-b border-white/10 px-6 py-5 sm:px-10">
            <x-icon name="video" class="size-6 text-brand-500" aria-hidden="true" />
            <span class="text-base font-semibold tracking-tight text-slate-100">Air Meet</span>
        </header>

        <main class="flex flex-1 flex-col items-center px-6 py-16 sm:py-20">
            <div class="grid w-full max-w-4xl gap-12 sm:grid-cols-2 sm:items-center">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-slate-100 sm:text-4xl">
                        Video calls, <span class="text-brand-500">no sign-up required</span>
                    </h1>
                    <p class="mt-4 max-w-sm text-sm leading-relaxed text-slate-400">
                        Create a meeting, share the link, and talk face to face. Camera, microphone,
                        screen sharing, and chat, right in the browser.
                    </p>

                    {{-- A plain list, not <dl>/<dt>/<dd>: that markup was
                         invalid HTML — a <dl>'s direct <div> children may
                         only contain a <dt>/<dd> pair, and putting the icon
                         next to that pair (not inside it) broke the
                         required content model. dt/dd was also never quite
                         the right semantic here anyway (that's for
                         term/definition pairs, not feature bullets); a list
                         reads correctly to a screen reader either way. --}}
                    <ul class="mt-8 grid grid-cols-2 gap-x-6 gap-y-5 max-w-sm">
                        <li class="flex items-start gap-2.5">
                            <x-icon name="video" class="mt-0.5 size-5 shrink-0 text-brand-400" aria-hidden="true" />
                            <div>
                                <p class="text-sm font-medium text-slate-200">Face to face</p>
                                <p class="text-xs text-slate-400">Camera and mic, one click</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <x-icon name="chat" class="mt-0.5 size-5 shrink-0 text-brand-400" aria-hidden="true" />
                            <div>
                                <p class="text-sm font-medium text-slate-200">Chat built in</p>
                                <p class="text-xs text-slate-400">Message everyone, live</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <x-icon name="screen-share" class="mt-0.5 size-5 shrink-0 text-brand-400" aria-hidden="true" />
                            <div>
                                <p class="text-sm font-medium text-slate-200">Screen sharing</p>
                                <p class="text-xs text-slate-400">Show your screen instantly</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <x-icon name="lock-closed" class="mt-0.5 size-5 shrink-0 text-brand-400" aria-hidden="true" />
                            <div>
                                <p class="text-sm font-medium text-slate-200">Lock it anytime</p>
                                <p class="text-xs text-slate-400">Block new joins mid-call</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <x-icon name="link" class="mt-0.5 size-5 shrink-0 text-brand-400" aria-hidden="true" />
                            <div>
                                <p class="text-sm font-medium text-slate-200">Just a link</p>
                                <p class="text-xs text-slate-400">Private, nothing to install</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <x-icon name="power" class="mt-0.5 size-5 shrink-0 text-brand-400" aria-hidden="true" />
                            <div>
                                <p class="text-sm font-medium text-slate-200">Ends clean</p>
                                <p class="text-xs text-slate-400">Chat clears when the call does</p>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="flex flex-col gap-6 rounded-xl border border-white/10 bg-white/5 p-8">
                    @livewire('meeting.create')

                    <div class="flex items-center gap-3 text-xs text-slate-400" role="separator">
                        <div class="h-px flex-1 bg-white/10"></div>
                        or
                        <div class="h-px flex-1 bg-white/10"></div>
                    </div>

                    {{-- Join sits *inside* the input's own box (like a
                         search bar) instead of as a sibling next to it —
                         a sibling button eats into the row, leaving this
                         input visibly narrower than Title/Password above
                         it despite all three being `w-full` at the same
                         nesting level. Overlaying it keeps the input's own
                         width genuinely equal to theirs. --}}
                    <form
                        x-data="{ code: '' }"
                        @submit.prevent="
                            const match = code.trim().match(/[0-9a-fA-F-]{36}$/);
                            if (match) window.location.href = '/meet/' + match[0];
                        "
                        class="relative"
                    >
                        <label for="meeting-code" class="sr-only">Meeting link or code</label>
                        {{-- Shorter than "Paste a meeting link or code":
                             on a narrow phone the input's usable text area
                             (full width minus the embedded button's
                             reserved space) is only ~160-180px, and that
                             longer placeholder ran past it, reading as if
                             it were tucked under the Join button. --}}
                        <input
                            id="meeting-code"
                            type="text"
                            x-model="code"
                            placeholder="Paste link or code"
                            class="w-full rounded-lg border border-white/10 bg-white/5 py-2.5 pl-4 pr-16 text-sm text-slate-100 placeholder-slate-500 focus:border-brand-500 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-brand-400"
                        >
                        <button
                            type="submit"
                            class="absolute right-1.5 top-1/2 -translate-y-1/2 rounded-md bg-white/10 px-3 py-1.5 text-xs font-medium text-slate-200 transition hover:bg-white/15 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
                        >
                            Join
                        </button>
                    </form>
                </div>
            </div>
        </main>

        <footer class="border-t border-white/10 px-6 py-6 text-center text-xs text-slate-400 sm:px-10">
            No accounts, no downloads. When the host ends a meeting, its chat and participant list are deleted immediately.
        </footer>
    </div>
</x-layouts.app>
