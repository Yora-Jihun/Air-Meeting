/**
 * Alpine component backing the in-call room UI.
 *
 * Registered via Alpine.data('meetingRoom', ...) in app.js rather than
 * written inline in the Blade x-data attribute. Two real bugs came from
 * the inline version: a stray literal `"` inside it (once in a JS comment,
 * once in a JS string) silently truncated the double-quoted HTML attribute
 * mid-way through, leaking the rest onto the page as plain text. Living in
 * a real .js file removes that failure mode entirely — there's no HTML
 * attribute for a stray quote to break out of — and keeps this testable
 * and lintable like normal code.
 */
export function meetingRoom({ meetingUuid, participantId, displayName, joinedAt, createdAt, isHost, initialMessages = [] }) {
    return {
        meetingUuid,
        participantId,
        displayName,
        joinedAt,
        isHost,

        controller: null,
        copied: false,
        inviteOpen: false,
        // The sidebar is a persistent column on sm+ (matches the `sm:static`
        // breakpoint on <aside> below) but a full-screen overlay on mobile —
        // defaulting it open there would cover the video on load instead of
        // showing the call. Only auto-open where it actually docks beside
        // the video instead of on top of it.
        sidebarOpen: window.matchMedia('(min-width: 640px)').matches,
        sidebarTab: 'participants',
        chatDraft: '',
        // How many of $store.room.chatMessages the user has already seen —
        // not a boolean per message, since messages only ever append and
        // are never marked individually read/unread.
        readChatCount: 0,
        elapsedSeconds: 0,
        // Drive the Leave/End meeting buttons' spinners instead of
        // wire:loading — both actions redirect on success, and
        // wire:loading clears in the same synchronous tick Livewire fires
        // that redirect (see leaveCall()/endMeeting() below).
        leaving: false,
        endingMeeting: false,

        participantsList() {
            const peers = Object.entries(this.$store.room.peers).map(([id, p]) => ({
                id,
                name: p.name,
                isSelf: false,
                isHost: p.isHost ?? false,
                micOn: p.micOn ?? true,
                camOn: p.camOn ?? true,
                connectionState: p.connectionState ?? 'connecting',
                joinedAt: p.joinedAt,
            }));

            return [
                {
                    id: this.controller?.participantId,
                    name: this.displayName || 'You',
                    isSelf: true,
                    isHost: this.isHost,
                    micOn: this.$store.room.micOn,
                    camOn: this.$store.room.camOn,
                    connectionState: 'connected',
                    joinedAt: this.$store.room.selfJoinedAt,
                },
                ...peers,
            ].sort((a, b) => new Date(a.joinedAt ?? 0) - new Date(b.joinedAt ?? 0));
        },

        /** "Active" / "Connecting…" instead of a relative timestamp that's
         * confusing for someone who's plainly right there in the tile next
         * to it — the timestamp only earns its keep for people who left. */
        statusText(p) {
            if (p.isSelf) {
                return this.isHost ? 'You · Host' : 'You';
            }

            switch (p.connectionState) {
                case 'connected': return 'Active';
                case 'disconnected':
                case 'failed': return 'Reconnecting…';
                case 'closed': return 'Left';
                default: return 'Connecting…';
            }
        },

        gridColsClass() {
            if (this.$store.room.presenterId) {
                // Unlike the cases below, this was a flat 110-140px minimum
                // regardless of viewport — fine on desktop, but on a narrow
                // phone that only leaves room for ~2 oversized thumbnails
                // per row while the presentation is meant to dominate.
                // Scaling the floor down with vw keeps it a genuine
                // thumbnail strip on small screens too.
                return 'grid-cols-[repeat(auto-fill,minmax(min(26vw,110px),140px))]';
            }

            // Fewer faces means each one should actually fill the room
            // instead of floating small in a mostly empty canvas — a solo
            // call gets one big tile, a 1:1 call gets two large ones, and
            // it steps back down to a compact thumbnail grid once there's
            // a real crowd.
            switch (this.$store.room.participantCount) {
                case 1: return 'grid-cols-[minmax(280px,min(85vw,760px))]';
                case 2: return 'grid-cols-[repeat(auto-fit,minmax(300px,min(46vw,560px)))]';
                default: return 'grid-cols-[repeat(auto-fit,minmax(240px,360px))]';
            }
        },

        formattedDuration() {
            const h = Math.floor(this.elapsedSeconds / 3600);
            const m = Math.floor((this.elapsedSeconds % 3600) / 60);
            const s = this.elapsedSeconds % 60;
            const pad = (n) => String(n).padStart(2, '0');

            return h > 0 ? `${pad(h)}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
        },

        startDurationTimer() {
            const startedAt = new Date(createdAt).getTime();
            const tick = () => (this.elapsedSeconds = Math.max(0, Math.floor((Date.now() - startedAt) / 1000)));

            tick();
            setInterval(tick, 1000);
        },

        async init() {
            this.startDurationTimer();

            // The initial open/closed state above is only decided once, at
            // load. If the sidebar was open on a wide screen and the
            // viewport then narrows into mobile (resize, rotation, devtools),
            // it would otherwise stay open as a full-screen overlay on top
            // of the video instead of closing with the layout.
            const mobileQuery = window.matchMedia('(max-width: 639px)');
            mobileQuery.addEventListener('change', (e) => {
                if (e.matches) {
                    this.sidebarOpen = false;
                }
            });

            // Chat messages land outside Alpine's own reactivity (pushed
            // imperatively by RoomController, like peer/media state), so a
            // message arriving while the chat tab is already open still
            // needs an explicit nudge to mark itself read and scroll into
            // view.
            this.$watch('$store.room.chatMessages.length', () => {
                if (this.sidebarOpen && this.sidebarTab === 'chat') {
                    this.markChatRead();
                }
            });

            // Seeds chat history exactly once. Re-running init() (the
            // duplicate-init bug this file's docblock describes) must not
            // wipe out messages received since the page loaded — guarding
            // on an empty list makes reseeding a harmless no-op rather than
            // a data loss the second time around.
            if (this.$store.room.chatMessages.length === 0) {
                this.$store.room.chatMessages = initialMessages.map((m) => ({
                    id: m.id,
                    from: m.participant_id,
                    name: m.name,
                    message: m.message,
                    isSelf: m.participant_id === this.participantId,
                }));
            }

            if (this.controller) {
                return;
            }

            this.controller = new window.AirMeet.RoomController({
                meetingUuid: this.meetingUuid,
                participantId: this.participantId,
                displayName: this.displayName,
                joinedAt: this.joinedAt,
                elements: {
                    grid: this.$refs.grid,
                    stage: this.$refs.stage,
                    stageVideo: this.$refs.stageVideo,
                    stageLabel: this.$refs.stageLabel,
                },
                isHost: this.isHost,
                onKick: (peerId) => this.$wire.kick(peerId),
            });

            await this.controller.start();
        },

        async leaveCall() {
            this.leaving = true;
            await this.controller?.stop();
            // Awaited (not fire-and-forget): only once this resolves —
            // strictly after Livewire has processed the redirect effect —
            // does `leaving` reset, so the button's own spinner (see
            // busy="leaving" in room.blade.php) stays up through the exact
            // window where wire:loading would already have reverted.
            await this.$wire.leave();
            this.leaving = false;
        },

        /** Host-only "End meeting" — tears down the host's own call state
         * first, same as leaveCall(), since Room::endMeeting() now
         * navigates (swaps the DOM) instead of a hard page reload, which
         * would otherwise leave the host's camera/mic/WebRTC/Echo state
         * running in the background after the redirect. The confirmation
         * moved here (native confirm(), replacing wire:confirm on the
         * button) so it happens before that teardown, not after. */
        async endMeeting() {
            if (! confirm('End this meeting for everyone?')) {
                return;
            }

            this.endingMeeting = true;
            await this.controller?.stop();
            await this.$wire.endMeeting();
            this.endingMeeting = false;
        },

        /**
         * The Present button's click handler — Google Meet's takeover
         * flow: at most one presenter, and starting a share while someone
         * else already has the stage prompts to confirm taking over
         * rather than either silently stealing it or being blocked
         * outright. (The other half — actually tearing down the displaced
         * presenter's own stream once this happens — is handled
         * reactively on *their* client by RoomController.
         * handlePresentationSignal(), not here.)
         */
        togglePresenting() {
            const presenterId = this.$store.room.presenterId;

            if (presenterId === this.controller?.participantId) {
                this.controller.stopPresenting();
                return;
            }

            if (presenterId && ! confirm(`${this.$store.room.presenterName} is presenting. Stop their presentation and share your screen instead?`)) {
                return;
            }

            this.controller?.startPresenting();
        },

        /** "Allow access" on the permission-blocked banner — re-checks
         * getUserMedia and, if it now succeeds, hot-swaps the new stream
         * into the self tile and every already-connected peer. */
        async retryMedia() {
            const stream = await window.AirMeet.media.retry();

            this.$store.room.micOn = window.AirMeet.media.micOn;
            this.$store.room.camOn = window.AirMeet.media.camOn;
            this.$store.room.selfError = window.AirMeet.media.error
                ? this.controller?.describeMediaError(window.AirMeet.media.error)
                : null;

            this.controller?.replaceLocalStream(stream);
            this.controller?.setTileMediaState(this.participantId, this.$store.room.micOn, this.$store.room.camOn);
            this.controller?.signaling.announceMediaState(this.$store.room.micOn, this.$store.room.camOn);
        },

        /** Switch the sidebar to a tab and make sure it's open. */
        openSidebar(tab) {
            this.sidebarTab = tab;
            this.sidebarOpen = true;

            if (tab === 'chat') {
                this.markChatRead();
            }
        },

        /** The footer's Participants/Chat buttons: re-clicking the tab
         * that's already showing closes the sidebar instead of doing
         * nothing, matching a normal toggle button. */
        toggleSidebar(tab) {
            if (this.sidebarOpen && this.sidebarTab === tab) {
                this.sidebarOpen = false;
                return;
            }

            this.openSidebar(tab);
        },

        markChatRead() {
            this.readChatCount = this.$store.room.chatMessages.length;
            this.$nextTick(() => this.scrollChatToBottom());
        },

        scrollChatToBottom() {
            if (this.$refs.chatList) {
                this.$refs.chatList.scrollTop = this.$refs.chatList.scrollHeight;
            }
        },

        unreadChatCount() {
            return Math.max(0, this.$store.room.chatMessages.length - this.readChatCount);
        },

        // Goes through Livewire (persisted + broadcast — see App\Livewire\
        // Meeting\Room::sendChat), not the WebRTC signaling channel: unlike
        // mic/cam state or presentation, a chat message has to survive a
        // refresh. There's no local optimistic append here either — the
        // sender sees their own message the same way everyone else does,
        // via the chatMessages.length watcher above once the broadcast
        // comes back.
        sendChatMessage() {
            const text = this.chatDraft.trim();

            if (! text) {
                return;
            }

            this.$wire.sendChat(text);
            this.chatDraft = '';
        },

        /**
         * Both the header's Invite popover and the sidebar's "Invite
         * others" panel have their own link input + copy button — pass
         * which x-ref to read from since $refs can't hold two elements
         * under the same name.
         */
        async copyLink(refName) {
            const input = this.$refs[refName];
            const link = input.value;

            try {
                // Only available in secure contexts (HTTPS, or the host
                // localhost) — accessing the app via a LAN IP or plain
                // HTTP leaves this undefined, and calling it would throw
                // before `copied` ever gets set, silently doing nothing.
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(link);
                } else {
                    input.select();
                    document.execCommand('copy');
                }

                this.copied = true;
                setTimeout(() => (this.copied = false), 2000);
            } catch (err) {
                console.error('Could not copy the meeting link automatically', err);
                input.select();
            }
        },
    };
}
