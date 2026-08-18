<div
    x-data="{
        loading: true,
        async init() {
            await window.AirMeet.media.acquire();
            this.$refs.preview.srcObject = window.AirMeet.media.stream;
            this.$store.room.micOn = window.AirMeet.media.micOn;
            this.$store.room.camOn = window.AirMeet.media.camOn;
            this.loading = false;
        },
        toggleMic() {
            this.$store.room.micOn = window.AirMeet.media.toggleMic();
        },
        toggleCam() {
            this.$store.room.camOn = window.AirMeet.media.toggleCam();
        },
    }"
    x-init="init()"
    class="flex min-h-dvh items-center justify-center bg-slate-950 px-4 py-8 sm:px-6 sm:py-10"
>
    <div class="grid w-full max-w-3xl gap-8 sm:grid-cols-2 sm:items-center">
        <div class="relative aspect-video overflow-hidden rounded-2xl border border-white/10 bg-slate-900">
            <video
                x-ref="preview"
                autoplay
                playsinline
                muted
                class="h-full w-full -scale-x-100 object-cover"
                :class="! $store.room.camOn && 'hidden'"
            ></video>

            <div x-show="! loading && ! $store.room.camOn" class="absolute inset-0 flex items-center justify-center">
                <x-icon name="video-off" class="size-8 text-slate-600" />
            </div>

            <div x-show="loading" class="absolute inset-0 flex items-center justify-center text-sm text-slate-400" aria-live="polite">
                Requesting camera &amp; microphone…
            </div>

            <span class="absolute bottom-3 left-3 rounded bg-black/50 px-2 py-0.5 text-xs text-white">
                {{ $displayName ?: 'You' }}
            </span>

            <div class="absolute bottom-3 right-3 flex items-center gap-2">
                <button
                    type="button"
                    @click="toggleMic()"
                    :aria-pressed="! $store.room.micOn"
                    :aria-label="$store.room.micOn ? 'Mute microphone' : 'Unmute microphone'"
                    class="flex size-9 items-center justify-center rounded-full transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
                    :class="$store.room.micOn ? 'bg-black/50 text-white hover:bg-black/70' : 'bg-red-500 text-white hover:bg-red-600'"
                >
                    <x-icon name="mic" class="size-4" x-show="$store.room.micOn" />
                    <x-icon name="mic-off" class="size-4" x-show="! $store.room.micOn" x-cloak />
                </button>

                <button
                    type="button"
                    @click="toggleCam()"
                    :aria-pressed="! $store.room.camOn"
                    :aria-label="$store.room.camOn ? 'Turn off camera' : 'Turn on camera'"
                    class="flex size-9 items-center justify-center rounded-full transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
                    :class="$store.room.camOn ? 'bg-black/50 text-white hover:bg-black/70' : 'bg-red-500 text-white hover:bg-red-600'"
                >
                    <x-icon name="video" class="size-4" x-show="$store.room.camOn" />
                    <x-icon name="video-off" class="size-4" x-show="! $store.room.camOn" x-cloak />
                </button>
            </div>
        </div>

        <div>
            <h1 class="text-xl font-semibold text-slate-100">{{ $meeting->title ?: 'Join meeting' }}</h1>
            <p class="mt-1 text-sm text-slate-400">Choose how you want to join.</p>

            <form wire:submit="join" class="mt-5 space-y-3">
                <div>
                    <label for="display-name" class="sr-only">Your name</label>
                    <input
                        id="display-name"
                        type="text"
                        wire:model="displayName"
                        maxlength="50"
                        placeholder="Your name"
                        autofocus
                        required
                        @error('displayName')
                            aria-invalid="true"
                            aria-describedby="display-name-error"
                        @enderror
                        class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-slate-100 placeholder-slate-500 focus:border-brand-500 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-brand-400"
                    >
                    @error('displayName') <p id="display-name-error" class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>

                @if ($this->requiresPassword)
                    <div>
                        <label for="meeting-password" class="sr-only">Meeting password</label>
                        <input
                            id="meeting-password"
                            type="password"
                            wire:model="password"
                            placeholder="Meeting password"
                            required
                            class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-slate-100 placeholder-slate-500 focus:border-brand-500 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-brand-400"
                        >
                    </div>
                @endif

                @if ($error)
                    <p role="alert" class="flex items-center gap-2 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400">
                        <x-icon name="alert" class="size-4 shrink-0" />
                        {{ $error }}
                    </p>
                @endif

                <x-button
                    type="submit"
                    variant="primary"
                    class="w-full py-2.5 text-sm"
                    target="join"
                >
                    Join now
                </x-button>
            </form>
        </div>
    </div>
</div>
