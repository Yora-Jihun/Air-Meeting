<div class="w-full max-w-sm">
    <form wire:submit="create" class="space-y-4">
        <x-button
            type="submit"
            variant="primary"
            class="w-full py-3 text-sm"
            wire:loading.attr="disabled"
            wire:target="create"
        >
            <x-icon name="video" class="size-5" />
            New meeting
        </x-button>

        <button
            type="button"
            x-data
            @click="$wire.showOptions = ! $wire.showOptions"
            aria-controls="meeting-options"
            aria-expanded="{{ $showOptions ? 'true' : 'false' }}"
            class="w-full rounded-lg text-center text-xs text-slate-400 transition hover:text-slate-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
        >
            {{ $showOptions ? 'Hide options' : 'Meeting options (title, password, expiry)' }}
        </button>

        @if ($showOptions)
            <div id="meeting-options" class="space-y-3 rounded-xl border border-white/10 bg-white/5 p-4">
                <div>
                    <label for="meeting-title" class="mb-1 block text-xs font-medium text-slate-400">Title (optional)</label>
                    <input
                        id="meeting-title"
                        type="text"
                        wire:model="title"
                        maxlength="100"
                        placeholder="Weekly sync"
                        class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-slate-100 placeholder-slate-500 focus:border-brand-500 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-brand-400"
                    >
                    @error('title') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>

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
                    <label for="meeting-expires" class="mb-1 block text-xs font-medium text-slate-400">Expires</label>
                    <select
                        id="meeting-expires"
                        wire:model="expiresInHours"
                        class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-slate-100 focus:border-brand-500 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-brand-400"
                    >
                        <option value="">Never</option>
                        <option value="1">In 1 hour</option>
                        <option value="24">In 24 hours</option>
                        <option value="168">In 7 days</option>
                    </select>
                </div>
            </div>
        @endif
    </form>
</div>
