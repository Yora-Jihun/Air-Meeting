<div>
    @if (! $hasJoined)
        <livewire:meeting.join :meeting="$meeting" :participant-id="$participantId" :key="'join-'.$meeting->uuid" />
    @else
        <div
            x-data="meetingRoom({
                meetingUuid: @js($meeting->uuid),
                participantId: @js($participantId),
                displayName: @js(session('meeting.'.$meeting->uuid.'.display_name')),
                joinedAt: @js($joinedAt),
                createdAt: @js($meeting->created_at->toIso8601String()),
                isHost: @js($isHost),
                initialMessages: @js($initialMessages),
            })"
            x-init="init()"
            @keydown.escape.window="inviteOpen = false"
            wire:key="in-call-{{ $meeting->uuid }}"
            class="relative flex h-dvh flex-col bg-slate-950"
        >
            {{-- Removed / meeting-ended overlay --}}
            <template x-if="$store.room.removedReason">
                <div
                    role="alertdialog"
                    aria-modal="true"
                    aria-labelledby="removed-reason"
                    class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-slate-950/95 text-center"
                >
                    <p id="removed-reason" class="max-w-sm text-lg text-slate-100" x-text="$store.room.removedReason"></p>
                    <a href="{{ route('home') }}" class="rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400">
                        Back to home
                    </a>
                </div>
            </template>

            <header class="relative z-20 flex items-center justify-between gap-4 border-b border-white/10 bg-slate-900 px-4 py-3 sm:px-6">
                <div class="flex min-w-0 items-center gap-3">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/10">
                        <x-icon name="video" class="size-5 text-slate-300" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="truncate text-sm font-semibold text-slate-100">{{ $meeting->title ?: 'Meeting' }}</h1>
                        <p class="font-mono text-xs text-slate-400" x-text="formattedDuration()"></p>
                    </div>
                </div>

                <div class="flex items-center gap-1.5 sm:gap-2">
                    @if ($isHost)
                        <x-button
                            wire:click="toggleLock"
                            target="toggleLock"
                            variant="secondary"
                            class="h-9 px-2.5 text-xs sm:px-3.5"
                            aria-label="{{ $meeting->is_locked ? 'Unlock meeting' : 'Lock meeting' }}"
                        >
                            <x-icon :name="$meeting->is_locked ? 'lock-closed' : 'lock-open'" class="size-3.5" />
                            <span class="hidden sm:inline" aria-hidden="true">{{ $meeting->is_locked ? 'Locked' : 'Lock' }}</span>
                        </x-button>
                        <x-button
                            @click="endMeeting()"
                            busy="endingMeeting"
                            variant="secondary"
                            class="h-9 px-2.5 text-xs sm:px-3.5"
                            aria-label="End meeting for everyone"
                        >
                            <x-icon name="power" class="size-3.5" />
                            <span class="hidden sm:inline" aria-hidden="true">End meeting</span>
                        </x-button>
                    @endif

                    <div class="relative" @click.outside="inviteOpen = false" @keydown.escape="inviteOpen = false">
                        <x-button
                            @click="inviteOpen = ! inviteOpen"
                            variant="primary"
                            class="h-9 px-2.5 text-xs sm:px-3.5"
                            aria-haspopup="true"
                            x-bind:aria-expanded="inviteOpen"
                            aria-label="Invite others"
                        >
                            <x-icon name="user-plus" class="size-3.5" />
                            <span class="hidden sm:inline" aria-hidden="true">Invite</span>
                        </x-button>

                        <div
                            x-show="inviteOpen"
                            x-cloak
                            x-transition
                            role="dialog"
                            aria-label="Invite others"
                            class="absolute right-0 z-30 mt-2 w-72 max-w-[calc(100vw-2rem)] rounded-xl border border-white/10 bg-slate-900/90 p-4 shadow-2xl backdrop-blur-xl"
                        >
                            <p class="text-sm font-medium text-slate-200">Invite others</p>
                            <p class="mt-1 text-xs text-slate-400">Anyone with this link can join the meeting.</p>

                            <div class="mt-3 flex items-center gap-2">
                                <label for="invite-link" class="sr-only">Meeting link</label>
                                <input
                                    id="invite-link"
                                    type="text"
                                    readonly
                                    x-ref="inviteLinkInput"
                                    value="{{ route('meeting.show', $meeting) }}"
                                    @click="$el.select()"
                                    class="w-full truncate rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-slate-300 focus:border-brand-500 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-brand-400"
                                >
                                <button
                                    @click="copyLink('inviteLinkInput')"
                                    aria-live="polite"
                                    class="flex shrink-0 items-center gap-1 rounded-lg px-3 py-2 text-xs font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
                                    :class="copied ? 'bg-brand-500 text-white' : 'bg-white/10 text-slate-200 hover:bg-white/15'"
                                >
                                    <x-icon :name="'check'" class="size-3.5" x-show="copied" />
                                    <x-icon :name="'link'" class="size-3.5" x-show="! copied" x-cloak />
                                    <span x-text="copied ? 'Copied' : 'Copy'"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <div class="flex min-h-0 flex-1 overflow-hidden">
                {{-- Mobile-only dimmed backdrop behind the sliding-in panel below. --}}
                <div
                    x-show="sidebarOpen"
                    x-cloak
                    x-transition.opacity
                    class="fixed inset-0 z-30 bg-black/60 sm:hidden"
                    @click="sidebarOpen = false"
                ></div>

                {{-- Sidebar: open by default, docked on the left as a true
                     side-by-side column on sm+ screens — no click needed to
                     see who's here. On mobile there isn't room to split the
                     screen, so it becomes a dismissible overlay instead.
                     Participants and Chat share this one panel as tabs
                     rather than each getting their own sliding aside, so
                     only one backdrop/z-index stack has to be reasoned
                     about on mobile. --}}
                <aside
                    id="participants-panel"
                    x-show="sidebarOpen"
                    x-cloak
                    x-transition
                    @keydown.escape="sidebarOpen = false"
                    aria-label="Sidebar"
                    class="fixed inset-y-0 left-0 z-40 flex w-80 max-w-[85vw] flex-col border-r border-white/10 bg-slate-900 sm:static sm:z-auto sm:max-w-none"
                >
                    <div class="flex shrink-0 items-center justify-between gap-1 border-b border-white/10 px-2 py-2">
                        <div class="flex items-center gap-1" role="tablist">
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="sidebarTab === 'participants'"
                                @click="openSidebar('participants')"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                                :class="sidebarTab === 'participants' ? 'bg-white/10 text-slate-100' : 'text-slate-400 hover:text-slate-200'"
                            >
                                Participants
                            </button>
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="sidebarTab === 'chat'"
                                @click="openSidebar('chat')"
                                class="relative rounded-lg px-3 py-1.5 text-sm font-medium transition"
                                :class="sidebarTab === 'chat' ? 'bg-white/10 text-slate-100' : 'text-slate-400 hover:text-slate-200'"
                            >
                                Chat
                                <span
                                    x-show="unreadChatCount() > 0"
                                    x-cloak
                                    x-text="unreadChatCount()"
                                    class="ml-1 rounded-full bg-brand-500 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                                ></span>
                            </button>
                        </div>
                        <button
                            @click="sidebarOpen = false"
                            aria-label="Close sidebar"
                            class="flex size-7 shrink-0 items-center justify-center rounded-lg text-slate-500 transition hover:bg-white/10 hover:text-slate-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
                        >
                            <x-icon name="x-mark" class="size-4" />
                        </button>
                    </div>

                    <template x-if="sidebarTab === 'participants'">
                        <div class="flex flex-1 flex-col overflow-hidden">
                            <ul class="flex-1 space-y-0.5 overflow-y-auto p-2">
                                <template x-for="p in participantsList()" :key="p.id">
                                    <li class="flex items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-white/5">
                                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-medium text-slate-300" x-text="p.name.charAt(0).toUpperCase()" aria-hidden="true"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="flex items-center gap-1.5">
                                                <span class="truncate text-sm text-slate-200" x-text="p.name"></span>
                                                <span x-show="p.isSelf" class="shrink-0 text-xs text-slate-400">(you)</span>
                                                <span x-show="p.isHost" x-cloak class="shrink-0 rounded-full bg-white/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-400">Host</span>
                                            </span>
                                            <span class="block text-xs text-slate-400" x-text="statusText(p)"></span>
                                        </span>
                                        <span class="flex shrink-0 items-center gap-1">
                                            <x-icon name="hand" class="size-3.5 text-amber-400" x-show="p.handRaised" aria-label="Hand raised" />
                                            <x-icon name="mic-off" class="size-3.5 text-red-400" x-show="! p.micOn" aria-label="Muted" />
                                            <x-icon name="video-off" class="size-3.5 text-red-400" x-show="! p.camOn" x-cloak aria-label="Camera off" />
                                        </span>
                                    </li>
                                </template>
                            </ul>

                            <div class="shrink-0 border-t border-white/10 p-3">
                                <p class="text-xs font-medium text-slate-300">Invite others</p>
                                <p class="mt-0.5 text-xs text-slate-400">Share this link to invite people</p>

                                <div class="mt-2 flex items-center gap-2">
                                    <label for="sidebar-invite-link" class="sr-only">Meeting link</label>
                                    <input
                                        id="sidebar-invite-link"
                                        type="text"
                                        readonly
                                        x-ref="sidebarInviteLinkInput"
                                        value="{{ route('meeting.show', $meeting) }}"
                                        @click="$el.select()"
                                        class="w-full truncate rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-slate-300 focus:border-brand-500 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-brand-400"
                                    >
                                    <button
                                        @click="copyLink('sidebarInviteLinkInput')"
                                        aria-live="polite"
                                        aria-label="Copy meeting link"
                                        class="flex shrink-0 items-center justify-center rounded-lg p-2.5 transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
                                        :class="copied ? 'bg-brand-500 text-white' : 'bg-white/10 text-slate-200 hover:bg-white/15'"
                                    >
                                        <x-icon :name="'check'" class="size-3.5" x-show="copied" />
                                        <x-icon :name="'link'" class="size-3.5" x-show="! copied" x-cloak />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Chat: persisted server-side (App\Services\ChatService)
                         and broadcast as a real event (ChatMessageSent),
                         unlike the mic/cam state and screen-share signals on
                         this same presence channel, which are ephemeral
                         whispers (see resources/js/webrtc/signaling.js). A
                         message survives a refresh and reaches anyone who
                         joins mid-call — history is seeded once via
                         initialMessages (Room::mount()) and appended to live
                         from there. --}}
                    <template x-if="sidebarTab === 'chat'">
                        <div class="flex flex-1 flex-col overflow-hidden">
                            <ul x-ref="chatList" class="flex-1 space-y-3 overflow-y-auto p-3" aria-label="Chat messages" aria-live="polite">
                                <li x-show="$store.room.chatMessages.length === 0" x-cloak class="pointer-events-none pt-6 text-center text-xs text-slate-400">
                                    No messages yet. Say hello.
                                </li>
                                <template x-for="m in $store.room.chatMessages" :key="m.id">
                                    <li class="flex flex-col" :class="m.isSelf ? 'items-end' : 'items-start'">
                                        <span class="px-1 text-xs text-slate-400" x-text="m.isSelf ? 'You' : m.name"></span>
                                        <p
                                            class="mt-0.5 max-w-[85%] break-words rounded-xl px-3 py-1.5 text-sm"
                                            :class="m.isSelf ? 'bg-brand-500 text-white' : 'bg-white/10 text-slate-100'"
                                            x-text="m.message"
                                        ></p>
                                    </li>
                                </template>
                            </ul>

                            <form @submit.prevent="sendChatMessage()" class="shrink-0 border-t border-white/10 p-3">
                                <label for="chat-input" class="sr-only">Message everyone</label>
                                <div class="flex items-center gap-2">
                                    <input
                                        id="chat-input"
                                        type="text"
                                        x-model="chatDraft"
                                        maxlength="500"
                                        placeholder="Message everyone"
                                        class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-slate-100 placeholder-slate-500 focus:border-brand-500 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-brand-400"
                                    >
                                    <button
                                        type="submit"
                                        :disabled="! chatDraft.trim()"
                                        aria-label="Send message"
                                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-500 text-white transition hover:bg-brand-600 disabled:opacity-40 disabled:pointer-events-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
                                    >
                                        <x-icon name="send" class="size-4" />
                                    </button>
                                </div>
                            </form>
                        </div>
                    </template>
                </aside>

                {{-- Column on mobile always; while presenting, sm+ switches to a
                     row — stage on the left, the participant strip as a
                     narrow column on the right, matching Meet/Zoom's desktop
                     screen-share layout. Staying a column on mobile even
                     while presenting is deliberate: a side-by-side split
                     has no room to breathe on a phone-width screen, so that
                     case keeps the bottom horizontal filmstrip instead (see
                     the grid's own classes below). --}}
                <main
                    class="relative flex min-h-0 flex-1 gap-3 overflow-hidden p-3 sm:p-6"
                    :class="$store.room.presenterId ? 'flex-col sm:flex-row' : 'flex-col'"
                >
                    {{-- A true floating toast — positioned over the video area rather
                         than sitting in normal document flow, so it doesn't shove the
                         video down when it appears or leave a gap when it clears.
                         Scoped to <main> (not the whole page) so it centers against
                         the same box the video grid centers against — the sidebar
                         sits outside <main>, so centering against the full page
                         would pull the toast out of line with the tiles beneath it.
                         Radius matches the video tiles/stage (rounded-xl) instead of
                         the pill shape reserved for chips and controls. aria-live so a
                         screen reader user learns about camera/mic problems without
                         having to go looking for them. --}}
                    <template x-if="$store.room.selfError">
                        <div
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 -translate-y-2"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-2"
                            class="pointer-events-none absolute inset-x-0 top-3 z-20 flex justify-center sm:top-4"
                            aria-live="polite"
                        >
                            {{-- Fully neutral by design — no red/amber anywhere, just
                                 tonal slate/white so it reads as informational rather
                                 than alarming. sm:flex-1 + min-w-0 on the message group
                                 gives the text priority over the button for the row's
                                 width instead of both fighting a fixed-width container,
                                 so the message wraps far less eagerly.

                                 Mobile is a single card with an internal divider (border-t)
                                 rather than two separately-boxed pieces floating in a gap —
                                 the message keeps its own padding, the button becomes a
                                 full-width footer strip flush with the card's own edges
                                 (overflow-hidden clips its corners to match), which reads
                                 as one integrated toast instead of a message-plus-button
                                 stack. sm+ reverts to a single row with the button back to
                                 a compact inline pill beside the message. --}}
                            <div class="pointer-events-auto w-[min(92vw,34rem)] overflow-hidden rounded-xl border border-white/10 bg-slate-900/95 text-left shadow-xl backdrop-blur-xl sm:flex sm:w-auto sm:max-w-xl sm:items-center sm:gap-4 sm:p-4">
                                <div class="flex items-start justify-center gap-2.5 p-4 sm:min-w-0 sm:flex-1 sm:items-center sm:justify-start sm:p-0">
                                    <x-icon name="alert" class="size-4 shrink-0 text-slate-300" aria-hidden="true" />
                                    <span class="text-xs leading-relaxed text-slate-200" x-text="$store.room.selfError"></span>
                                </div>
                                <button
                                    @click="retryMedia()"
                                    class="block w-full border-t border-white/10 px-4 py-2.5 text-xs font-medium text-slate-200 transition hover:bg-white/5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400 sm:ml-1 sm:w-auto sm:shrink-0 sm:rounded-lg sm:border-0 sm:bg-white/10 sm:px-3 sm:py-1.5 sm:hover:bg-white/15"
                                >
                                    Allow access
                                </button>
                            </div>
                        </div>
                    </template>

                    {{-- Presentation stage: hidden until someone shares their screen.
                         wire:ignore — RoomController sets stageVideo.srcObject and
                         stageLabel's text directly, and toggles this wrapper's own
                         `hidden` class imperatively (see showPresenter()/clearStage()
                         in room.js), none of which Livewire's server-rendered HTML
                         ever reflects. Without wire:ignore, any Livewire re-render of
                         this component (e.g. the host clicking Lock) morphs this
                         subtree back to its always-empty server markup, silently
                         losing the live video mid-call. --}}
                    <div wire:ignore x-ref="stage" class="hidden min-h-0 min-w-0 flex-1 overflow-hidden rounded-xl border border-white/10 bg-black">
                        <div class="relative h-full w-full">
                            <video x-ref="stageVideo" autoplay playsinline class="h-full w-full object-contain"></video>
                            <span
                                x-ref="stageLabel"
                                class="absolute left-3 top-3 flex items-center gap-1.5 rounded-lg bg-black/60 px-2.5 py-1 text-xs font-medium text-white"
                            ></span>

                            <button
                                type="button"
                                @click="toggleStageFullscreen()"
                                :aria-label="stageIsFullscreen ? 'Exit full screen' : 'Full screen'"
                                class="absolute bottom-3 right-3 flex size-8 items-center justify-center rounded-lg bg-black/60 text-white transition hover:bg-black/80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
                            >
                                <x-icon name="fullscreen-exit" class="size-4" x-show="stageIsFullscreen" />
                                <x-icon name="fullscreen-enter" class="size-4" x-show="! stageIsFullscreen" x-cloak />
                            </button>

                            {{-- The stage video has no `muted` attribute
                                 (viewers should hear the presenter's shared
                                 audio) and its srcObject is always attached
                                 from an async WebSocket event, not a click —
                                 exactly what browsers block autoplay for,
                                 especially on mobile. When that happens the
                                 video is left silently paused on a black
                                 frame with no explanation; this gives
                                 viewers an actual affordance, and the tap
                                 itself is a real gesture so the retried
                                 play() succeeds. --}}
                            <button
                                type="button"
                                x-show="$store.room.stageBlocked"
                                x-cloak
                                @click="controller.retryStagePlayback()"
                                class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-black/70 text-white transition hover:bg-black/60"
                            >
                                <span class="flex size-14 items-center justify-center rounded-full bg-white/15">
                                    <x-icon name="play" class="size-6" aria-hidden="true" />
                                </span>
                                <span class="text-sm font-medium">Tap to play presentation</span>
                            </button>
                        </div>
                    </div>

                    {{-- Groups the tile grid with its caption so they center and move
                         together as one unit, rather than each being independently
                         positioned against <main> and only coincidentally lining up. --}}
                    {{-- No items-center here: a grid item that isn't stretched to a
                         definite width has an *indefinite* size in the CSS Grid spec's
                         eyes, and repeat(auto-fill/auto-fit, ...) on an indefinite size
                         always resolves to exactly 1 column — regardless of how much
                         space is actually available. That collapsed every multi-tile
                         layout (2-participant view, the presenting thumbnail strip)
                         into a single stacked column. Letting this wrapper stretch to
                         full width (the default) gives the grid a real width to compute
                         columns against; each child still centers itself internally
                         (grid via justify-center on its own tracks, caption via
                         text-center), so the visual result is unchanged for the
                         single-tile case that was already working. --}}
                    <div
                        class="relative flex min-h-0 min-w-0 flex-col gap-3"
                        :class="$store.room.presenterId ? 'flex-none sm:w-72 sm:shrink-0 lg:w-80 xl:w-96' : 'flex-1 overflow-y-auto [justify-content:safe_center]'"
                    >
                        {{-- Participant thumbnails. RoomController appends/removes tiles
                             here directly. auto-fit + centered tracks means a lone
                             participant gets one large, centered tile instead of being
                             stuck in a corner of an empty grid.

                             wire:ignore is load-bearing, not decorative: this div is
                             always empty in Livewire's server-rendered HTML (the tiles
                             only ever exist as live DOM nodes RoomController appended
                             itself), so without it, any Livewire re-render of this
                             component — e.g. the host clicking Lock/Unlock — morphs
                             the grid back to that empty markup, wiping every
                             participant's video off screen until a hard refresh.
                             activeController's guard (room.js) then correctly stops
                             init() from running again, so nothing ever re-populates
                             it on its own. Alpine's own :class binding below is
                             unaffected — wire:ignore only opts this subtree out of
                             Livewire's morph pass, not Alpine's independent
                             reactivity. --}}
                        <div
                            wire:ignore
                            x-ref="grid"
                            role="list"
                            aria-label="Video feeds"
                            class="grid justify-center gap-3"
                            :class="[gridColsClass(), $store.room.presenterId ? 'overflow-hidden sm:h-full sm:w-full sm:overflow-y-auto' : '']"
                        ></div>

                        {{-- Mobile only (sm:hidden) — the desktop column to
                             the side has room to scroll through everyone
                             instead of needing this. Landing exactly on the
                             second tile's top-right corner isn't a
                             coincidence: with the fixed 2-up grid above and
                             the rest of the tiles hidden outright (not just
                             scrolled out of view), this wrapper's own
                             top-right corner IS that tile's top-right
                             corner. --}}
                        <span
                            x-show="$store.room.presenterId && $store.room.participantCount > 2"
                            x-cloak
                            x-text="'+' + ($store.room.participantCount - 2) + ' others'"
                            class="pointer-events-none absolute right-2 top-2 z-10 rounded-full bg-black/60 px-2.5 py-1 text-xs font-medium text-white sm:hidden"
                        ></span>

                        <p
                            x-show="$store.room.participantCount === 1 && ! $store.room.presenterId"
                            x-cloak
                            class="pointer-events-none text-center text-sm text-slate-400"
                        >
                            Waiting for others to join. Share the invite link to bring them in.
                        </p>
                    </div>
                </main>
            </div>

            <footer class="flex items-center justify-center gap-2 border-t border-white/10 bg-slate-900 px-3 py-3 sm:gap-3 sm:px-4 sm:py-4"
                style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom));"
            >
                <button
                    @click="controller.toggleMic()"
                    :aria-pressed="! $store.room.micOn"
                    :aria-label="$store.room.micOn ? 'Mute microphone' : 'Unmute microphone'"
                    class="flex size-11 shrink-0 items-center justify-center rounded-full transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
                    :class="$store.room.micOn ? 'bg-white/10 hover:bg-white/15' : 'bg-red-500 hover:bg-red-600'"
                >
                    <x-icon name="mic" class="size-5" x-show="$store.room.micOn" />
                    <x-icon name="mic-off" class="size-5" x-show="! $store.room.micOn" x-cloak />
                </button>

                <button
                    @click="controller.toggleCam()"
                    :aria-pressed="! $store.room.camOn"
                    :aria-label="$store.room.camOn ? 'Turn off camera' : 'Turn on camera'"
                    class="flex size-11 shrink-0 items-center justify-center rounded-full transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
                    :class="$store.room.camOn ? 'bg-white/10 hover:bg-white/15' : 'bg-red-500 hover:bg-red-600'"
                >
                    <x-icon name="video" class="size-5" x-show="$store.room.camOn" />
                    <x-icon name="video-off" class="size-5" x-show="! $store.room.camOn" x-cloak />
                </button>

                <button
                    @click="togglePresenting()"
                    :aria-pressed="$store.room.presenterId === controller.participantId"
                    :aria-label="$store.room.presenterId === controller.participantId ? 'Stop presenting' : 'Present your screen'"
                    class="flex h-11 shrink-0 items-center gap-2 rounded-full px-3 text-sm font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400 sm:px-4"
                    :class="$store.room.presenterId === controller.participantId ? 'bg-brand-500 text-white hover:bg-brand-600' : 'bg-white/10 text-slate-200 hover:bg-white/15'"
                >
                    <x-icon name="screen-share" class="size-5" />
                    <span class="hidden sm:inline" aria-hidden="true" x-text="$store.room.presenterId === controller.participantId ? 'Stop presenting' : 'Present'"></span>
                </button>

                <button
                    @click="controller.toggleHand()"
                    :aria-pressed="$store.room.handRaised"
                    :aria-label="$store.room.handRaised ? 'Lower hand' : 'Raise hand'"
                    class="flex size-11 shrink-0 items-center justify-center rounded-full transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400"
                    :class="$store.room.handRaised ? 'bg-amber-400 text-slate-900 hover:bg-amber-300' : 'bg-white/10 text-slate-200 hover:bg-white/15'"
                >
                    <x-icon name="hand" class="size-5" />
                </button>

                <button
                    @click="toggleSidebar('participants')"
                    aria-controls="participants-panel"
                    x-bind:aria-expanded="sidebarOpen && sidebarTab === 'participants'"
                    aria-label="Toggle participant list"
                    class="flex h-11 shrink-0 items-center gap-2 rounded-full px-3 text-sm font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400 sm:px-4"
                    :class="sidebarOpen && sidebarTab === 'participants' ? 'bg-white/20 text-white' : 'bg-white/10 text-slate-200 hover:bg-white/15'"
                >
                    <x-icon name="users" class="size-5" />
                    <span class="hidden sm:inline" aria-hidden="true" x-text="'Participants (' + $store.room.participantCount + ')'"></span>
                </button>

                <button
                    @click="toggleSidebar('chat')"
                    aria-controls="participants-panel"
                    x-bind:aria-expanded="sidebarOpen && sidebarTab === 'chat'"
                    aria-label="Toggle chat"
                    class="relative flex h-11 shrink-0 items-center gap-2 rounded-full px-3 text-sm font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400 sm:px-4"
                    :class="sidebarOpen && sidebarTab === 'chat' ? 'bg-white/20 text-white' : 'bg-white/10 text-slate-200 hover:bg-white/15'"
                >
                    <x-icon name="chat" class="size-5" />
                    <span class="hidden sm:inline" aria-hidden="true">Chat</span>
                    <span
                        x-show="unreadChatCount() > 0"
                        x-cloak
                        x-text="unreadChatCount()"
                        class="absolute -right-1 -top-1 flex size-4 items-center justify-center rounded-full bg-brand-500 text-[10px] font-semibold text-white"
                    ></span>
                </button>

                <x-button
                    @click="leaveCall()"
                    busy="leaving"
                    variant="danger"
                    shape="full"
                    class="h-11 px-4 text-sm sm:px-5"
                    aria-label="Leave call"
                >
                    <x-icon name="phone-hangup" class="size-5" />
                    <span class="hidden sm:inline" aria-hidden="true">Leave</span>
                </x-button>
            </footer>
        </div>
    @endif
</div>
