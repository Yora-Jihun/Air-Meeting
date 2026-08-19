{{-- No max-w here: this component only ever renders inside home.blade.php's
     card, which already owns the actual width constraint via the page
     grid. A second, independent cap here (max-w-sm, a fixed 384px) used to
     bind tighter than that card in the ~450-640px viewport range —
     specifically where the layout is still single-column (below the sm:
     breakpoint that turns on the two-column grid) but wide enough for the
     card to exceed 384px — capping Title/New meeting/Password a couple
     pixels narrower than their uncapped siblings (the "or" divider, the
     Join row) in that window. --}}
<div
    class="w-full"
    x-data="{ submitting: false, async submit() { this.submitting = true; await this.$wire.create(); this.submitting = false } }"
>
    <form @submit.prevent="submit()" class="space-y-4">
        <div>
            <label for="meeting-title" class="mb-1 block text-xs font-medium text-slate-400">Title (optional)</label>
            <input
                id="meeting-title"
                type="text"
                wire:model="title"
                maxlength="100"
                placeholder="Weekly team sync"
                    class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-slate-100 placeholder-slate-500 focus:border-brand-500 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-brand-400"
            >
            @error('title') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <x-button
            type="submit"
            variant="primary"
            class="w-full py-3 text-sm"
            busy="submitting"
        >
            <x-icon name="video" class="size-5" />
            New meeting
        </x-button>

        <div class="space-y-3 rounded-xl border border-white/10 bg-white/5 p-4">
            <div>
                <label for="meeting-password" class="mb-1 block text-xs font-medium text-slate-400">Password (optional)</label>
                <input
                    id="meeting-password"
                    type="text"
                    wire:model="password"
                    maxlength="50"
                    placeholder="Leave blank for no password"
                    class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-slate-100 placeholder-slate-500 focus:border-brand-500 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-brand-400"
                >
                @error('password') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <span id="meeting-expires-label" class="mb-1 block text-xs font-medium text-slate-400">Expires</span>
                {{-- Not a native <select>: a browser's own dropdown popup is
                     drawn outside the page (OS/browser chrome), and CSS
                     color-scheme is only a hint some browsers honor and
                     others don't — it kept opening as a plain white list
                     regardless of how dark everything around it was. This
                     is a real listbox (button + options list), fully
                     Tailwind-styled like the app's other popovers (see the
                     Invite panel in room.blade.php), so it's guaranteed to
                     match rather than depending on the browser/OS. --}}
                <div
                    x-data="{
                        open: false,
                        options: [
                            { value: '', label: 'Never' },
                            { value: '1', label: 'In 1 hour' },
                            { value: '24', label: 'In 24 hours' },
                            { value: '168', label: 'In 7 days' },
                        ],
                        currentLabel() {
                            const value = this.$wire.expiresInHours ?? '';

                            return this.options.find((o) => o.value === value)?.label ?? 'Never';
                        },
                        isSelected(value) {
                            return (this.$wire.expiresInHours ?? '') === value;
                        },
                        select(value) {
                            this.$wire.expiresInHours = value;
                            this.open = false;
                        },
                    }"
                    @keydown.escape="open = false"
                    @click.outside="open = false"
                    class="relative"
                >
                    {{-- aria-labelledby lists BOTH ids — per the ARIA spec
                         it replaces the button's accessible name entirely
                         (not appends to it), so pointing it at only the
                         "Expires" label would silently drop the current
                         value ("Never" etc.) for screen reader users even
                         though sighted users see it right there as the
                         button's own text. Concatenating both ids makes it
                         announce as e.g. "Expires, Never" instead of just
                         "Expires". --}}
                    <button
                        type="button"
                        @click="open = ! open"
                        aria-haspopup="listbox"
                        :aria-expanded="open"
                        aria-labelledby="meeting-expires-label meeting-expires-value"
                        class="flex w-full items-center justify-between rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-left text-sm text-slate-100 transition focus:border-brand-500 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-brand-400"
                    >
                        <span id="meeting-expires-value" x-text="currentLabel()"></span>
                        <x-icon name="chevron-down" class="size-4 shrink-0 text-slate-400 transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                    </button>

                    <ul
                        x-show="open"
                        x-cloak
                        x-transition
                        role="listbox"
                        aria-labelledby="meeting-expires-label"
                        class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-white/10 bg-slate-900 py-1 shadow-2xl"
                    >
                        <template x-for="option in options" :key="option.value">
                            <li
                                role="option"
                                :aria-selected="isSelected(option.value)"
                                @click="select(option.value)"
                                class="cursor-pointer px-3 py-2 text-sm transition"
                                :class="isSelected(option.value) ? 'bg-brand-500/10 text-brand-300' : 'text-slate-200 hover:bg-white/10'"
                                x-text="option.label"
                            ></li>
                        </template>
                    </ul>
                </div>
            </div>
        </div>
    </form>
</div>
