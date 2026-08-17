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
export function meetingRoom({ meetingUuid, participantId, displayName, joinedAt, createdAt, isHost }) {
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
        participantsOpen: window.matchMedia('(min-width: 640px)').matches,
        elapsedSeconds: 0,

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
                    this.participantsOpen = false;
                }
            });

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
            await this.controller?.stop();
            this.$wire.leave();
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
