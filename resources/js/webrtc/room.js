import { media } from './media';
import { SignalingClient } from './signaling';
import { Peer } from './peer';

// Tracks the one RoomController that should ever be live in a tab. Livewire
// re-rendering the room (e.g. the host clicking "Lock") can, depending on
// how its DOM diffing decides to patch that subtree, cause Alpine's
// x-init="init()" to run again on the same page — without this guard that
// silently spun up a second signaling session + a second RTCPeerConnection
// to every peer, doubling every video tile (yours and everyone else's).
let activeController = null;

// Small inline icons for tile status badges — DOM-built here rather than
// through the Blade <x-icon> component since RoomController owns this DOM
// imperatively (see the class doc below). Paths match resources/views/components/icon.blade.php
// so a muted-mic badge on a tile looks identical to the one in the toolbar.
const BADGE_ICONS = {
    'mic-off': '<rect x="9" y="2" width="6" height="12" rx="3" /><path d="M5 10a7 7 0 0 0 14 0" /><line x1="12" y1="17" x2="12" y2="21" /><line x1="8" y1="21" x2="16" y2="21" /><line x1="2" y1="2" x2="22" y2="22" />',
    'video-off': '<rect x="3" y="6" width="13" height="12" rx="3" /><path d="M16 10.5 21 7v10l-5-3.5Z" /><line x1="2" y1="3" x2="22" y2="21" />',
    'hand': '<path d="M18 11V6a2 2 0 0 0-2-2a2 2 0 0 0-2 2" /><path d="M14 10V4a2 2 0 0 0-2-2a2 2 0 0 0-2 2v2" /><path d="M10 10.5V6a2 2 0 0 0-2-2a2 2 0 0 0-2 2v8" /><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15" />',
};

function badgeSvg(name, sizeClass = 'size-3') {
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="${sizeClass}">${BADGE_ICONS[name]}</svg>`;
}

/**
 * Orchestrates one meeting's WebRTC mesh: a direct RTCPeerConnection to
 * every other participant. This is intentionally the only place that
 * knows about the mesh topology — if this app later migrates to an SFU
 * (see README notes), this class is what gets replaced; Livewire, the
 * signaling channel shape, and the Blade UI stay the same.
 *
 * DOM for video tiles is managed imperatively here rather than through
 * Alpine's reactive store, because MediaStream objects don't survive
 * being wrapped in a reactivity Proxy reliably. Alpine (via
 * `window.Alpine.store('room')`) only ever holds plain data: names,
 * mic/cam flags, connection state, presenter id — used for text/class
 * bindings in the Blade template.
 */
export class RoomController {
    constructor({ meetingUuid, participantId, displayName, joinedAt = null, elements, isHost = false, onKick = null }) {
        this.meetingUuid = meetingUuid;
        this.participantId = participantId;
        this.displayName = displayName;
        this.joinedAt = joinedAt;
        this.elements = elements; // { grid, stage, stageVideo, stageLabel }
        this.isHost = isHost;
        this.onKick = onKick;

        this.signaling = new SignalingClient(meetingUuid, participantId);
        this.peers = new Map(); // peerId -> { peer, name, camStreamId, tile }
        this.localStream = null;
        this.screenStream = null;
        this.selfTile = null;
        this.store = null;
        this.heartbeatTimer = null;

        // Bound once here (not inline in start()) so the exact same
        // function reference can be passed to both addEventListener and,
        // in stop(), removeEventListener.
        this.handleVisibilityChange = () => {
            if (document.visibilityState === 'visible') {
                this.signaling.requestResync();
            }
        };
    }

    get alpineStore() {
        this.store ??= window.Alpine.store('room');

        return this.store;
    }

    async start() {
        if (activeController === this) {
            return;
        }

        // Defensively tear down any previous session before this one takes
        // over — this is what actually prevents the duplicate-tile bug,
        // regardless of why init() ran again.
        activeController?.stop();
        activeController = this;

        this.localStream = await media.acquire();
        this.alpineStore.micOn = media.micOn;
        this.alpineStore.camOn = media.camOn;
        this.alpineStore.selfError = media.error ? this.describeMediaError(media.error) : null;
        this.alpineStore.selfJoinedAt = this.joinedAt;

        this.attachSelfTile();
        this.setTileMediaState(this.participantId, this.alpineStore.micOn, this.alpineStore.camOn);

        this.signaling.join({
            onHere: (members) => members.forEach((m) => this.connectTo(m)),
            onJoining: (member) => {
                this.connectTo(member);
                // Whispers aren't replayed — a member who joins after us
                // never saw our earlier announcements, so re-send current
                // state whenever someone new shows up.
                this.announceOwnState();
            },
            onLeaving: (member) => this.disconnectFrom(member.id),
            onSignal: (payload) => this.peers.get(payload.from)?.peer.handleSignal(payload),
            onPresentation: (payload) => this.handlePresentationSignal(payload),
            onMediaState: (payload) => this.handleMediaState(payload),
            onSpeaking: (payload) => this.handleSpeaking(payload.from, payload.speaking),
            onChat: (payload) => this.handleChat(payload),
            onKicked: () => this.handleRemoved('You were removed from the meeting by the host.'),
            onMeetingEnded: () => this.handleRemoved('The host ended this meeting.'),
            onHostPromoted: (payload) => this.handleHostPromoted(payload.participant_id),
            onResyncRequest: () => this.announceOwnState(),
            onHandRaised: (payload) => this.handleHandRaised(payload.from, payload.raised),
        });

        // A backgrounded mobile tab can leave the WebSocket looking
        // "connected" from the JS side while the OS has actually paused it
        // — no error, no disconnect event, just silently missed whispers
        // until something asks again. Coming back to the foreground is the
        // one moment we can reliably detect client-side, so it's also the
        // moment to ask everyone (including this tab, via the same
        // broadcast) to resend what they currently are.
        document.addEventListener('visibilitychange', this.handleVisibilityChange);

        this.signaling.announceMediaState(this.alpineStore.micOn, this.alpineStore.camOn);

        media.startSpeakingDetection((speaking) => {
            this.alpineStore.speaking = speaking;
            this.setTileSpeaking(this.participantId, speaking);
            this.signaling.announceSpeaking(speaking);
        });

        this.alpineStore.connected = true;
        this.startHeartbeat();
    }

    /**
     * Tells the server this tab is still genuinely open, so
     * app:prune-stale-participants can tell a closed/crashed tab apart
     * from one that's mid-call — see ParticipantService::heartbeat() for
     * why this (unlike the removed sendBeacon-on-pagehide attempt) can't
     * misfire on a plain refresh: it only ever extends last_seen_at, never
     * marks anyone as left, and simply resumes on the next tick either way.
     */
    startHeartbeat() {
        const send = () => {
            fetch(`/meet/${this.meetingUuid}/heartbeat`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    Accept: 'application/json',
                },
                keepalive: true,
            }).catch(() => {});
        };

        this.heartbeatTimer = setInterval(send, 15_000);
    }

    connectTo(member) {
        if (this.peers.has(member.id) || member.id === this.participantId) {
            return;
        }

        const polite = this.participantId < member.id;

        const peer = new Peer(member.id, {
            polite,
            localStream: this.localStream,
            signaling: this.signaling,
            onTrack: (stream) => this.handleRemoteTrack(member.id, stream),
            onConnectionStateChange: (state) => this.updatePeerState(member.id, state),
        });

        // A screen share already in progress predates this connection —
        // startPresenting() only ever reached peers that existed at the
        // moment sharing began, so anyone connecting afterward (a late
        // joiner, or this app's own reconnect on page reload) never gets
        // the track added to their RTCPeerConnection any other way. Added
        // before the tile/peer bookkeeping below so it rides the same
        // initial negotiation as the camera track rather than triggering a
        // second one.
        if (this.screenStream) {
            const screenTrack = this.screenStream.getVideoTracks()[0];
            peer.addTrack(screenTrack, this.screenStream);
        }

        const tile = this.createTile(member.id, member.name, false);

        this.peers.set(member.id, { peer, name: member.name, camStreamId: null, tile });
        this.alpineStore.peers = {
            ...this.alpineStore.peers,
            [member.id]: {
                name: member.name,
                connectionState: 'connecting',
                joinedAt: member.joined_at ?? null,
                isHost: member.is_host ?? false,
                micOn: true,
                camOn: true,
                handRaised: false,
            },
        };
        this.alpineStore.participantCount = this.peers.size + 1;
    }

    disconnectFrom(peerId) {
        const entry = this.peers.get(peerId);

        if (! entry) {
            return;
        }

        entry.peer.close();
        entry.tile?.remove();
        this.peers.delete(peerId);

        const peers = { ...this.alpineStore.peers };
        delete peers[peerId];
        this.alpineStore.peers = peers;
        this.alpineStore.participantCount = this.peers.size + 1;

        if (this.alpineStore.presenterId === peerId) {
            this.clearStage();
        }
    }

    handleRemoteTrack(peerId, stream) {
        const entry = this.peers.get(peerId);

        if (! entry) {
            return;
        }

        entry.camStreamId ??= stream.id;

        if (stream.id === entry.camStreamId) {
            entry.tile.video.srcObject = stream;
            return;
        }

        // A second, distinct stream from the same peer is their screen
        // share. The video/audio track itself arrives here, but whether the
        // stage is actually shown is driven by the explicit "presentation"
        // whisper (handlePresentationSignal) — the track and the announcement
        // can arrive in either order, so the stream is cached regardless and
        // attached immediately if the announcement already won that race.
        entry.screenStream = stream;

        if (this.alpineStore.presenterId === peerId) {
            this.elements.stageVideo.srcObject = stream;
            this.playStage();
        }
    }

    /**
     * Explicit, instant "who's presenting" control signal from a peer.
     *
     * Google Meet's takeover behavior: only one presenter at a time, and
     * starting a new share always wins over whoever had the stage — but
     * unlike a naive "last announcement wins", the peer who just got
     * displaced has their OWN share torn down here too. Without this,
     * nothing ever stopped the previous presenter's actual
     * getDisplayMedia() stream or its track on every peer connection —
     * only the *visible* stage swapped, while the outgoing screen-share
     * video kept broadcasting invisibly in the background, and their own
     * "Present" button silently desynced from reality (see
     * room-alpine.js's togglePresenting() for the other half: prompting
     * *before* a takeover happens, not just cleaning up after one).
     */
    handlePresentationSignal({ from, presenting, name }) {
        if (presenting) {
            if (this.screenStream && from !== this.participantId) {
                this.stopPresenting();
            }

            this.showPresenter(from, name ?? this.peers.get(from)?.name ?? 'Someone');

            const cachedStream = this.peers.get(from)?.screenStream;

            if (cachedStream) {
                this.elements.stageVideo.srcObject = cachedStream;
                this.playStage();
            }
        } else if (this.alpineStore.presenterId === from) {
            this.clearStage();

            const entry = this.peers.get(from);

            if (entry) {
                entry.screenStream = null;
            }
        }
    }

    /**
     * Re-broadcasts everything about this client that only ever traveled
     * as a whisper (never persisted, never replayed by Reverb) — current
     * mic/cam state, raised-hand state, and, if applicable, that this
     * client is presenting. Used both for a normal late joiner
     * (onJoining) and for a resync request from a peer that suspects it
     * missed something (see signaling.js's requestResync()).
     */
    announceOwnState() {
        this.signaling.announceMediaState(this.alpineStore.micOn, this.alpineStore.camOn);

        if (this.alpineStore.handRaised) {
            this.signaling.announceHandRaised(true);
        }

        if (this.screenStream) {
            this.signaling.announcePresentation(true, this.displayName);
        }
    }

    /** A peer toggled their mic/camera — update the sidebar entry and their tile badges. */
    handleMediaState({ from, micOn, camOn }) {
        if (! this.alpineStore.peers[from]) {
            return;
        }

        this.alpineStore.peers = {
            ...this.alpineStore.peers,
            [from]: { ...this.alpineStore.peers[from], micOn, camOn },
        };

        this.setTileMediaState(from, micOn, camOn);
    }

    handleSpeaking(peerId, speaking) {
        this.setTileSpeaking(peerId, speaking);
    }

    /** A peer raised or lowered their hand — update the sidebar entry and their tile badge. */
    handleHandRaised(peerId, raised) {
        if (! this.alpineStore.peers[peerId]) {
            return;
        }

        this.alpineStore.peers = {
            ...this.alpineStore.peers,
            [peerId]: { ...this.alpineStore.peers[peerId], handRaised: raised },
        };

        this.setTileHandRaised(peerId, raised);
    }

    /** The footer's Raise Hand button — a plain toggle, not tied to mic/cam
     * or speaking state; the participant (or the host, once acknowledged)
     * lowers it explicitly rather than it clearing itself automatically. */
    toggleHand() {
        this.alpineStore.handRaised = ! this.alpineStore.handRaised;
        this.setTileHandRaised(this.participantId, this.alpineStore.handRaised);
        this.signaling.announceHandRaised(this.alpineStore.handRaised);
    }

    /** A persisted chat message arrived via App\Events\ChatMessageSent —
     * delivered to every participant including the sender, since sending
     * one (room-alpine.js's sendChatMessage) has no separate local echo. */
    handleChat({ id, participant_id: from, name, message }) {
        this.alpineStore.chatMessages = [
            ...this.alpineStore.chatMessages,
            { id, from, name, message: String(message ?? '').slice(0, 500), isSelf: from === this.participantId },
        ];
    }

    updatePeerState(peerId, connectionState) {
        if (! this.alpineStore.peers[peerId]) {
            return;
        }

        this.alpineStore.peers = {
            ...this.alpineStore.peers,
            [peerId]: { ...this.alpineStore.peers[peerId], connectionState },
        };
    }

    // ---- Local controls -------------------------------------------------

    toggleMic() {
        this.alpineStore.micOn = media.toggleMic();
        this.setTileMediaState(this.participantId, this.alpineStore.micOn, this.alpineStore.camOn);
        this.signaling.announceMediaState(this.alpineStore.micOn, this.alpineStore.camOn);
    }

    toggleCam() {
        this.alpineStore.camOn = media.toggleCam();
        this.setTileMediaState(this.participantId, this.alpineStore.micOn, this.alpineStore.camOn);
        this.signaling.announceMediaState(this.alpineStore.micOn, this.alpineStore.camOn);
    }

    /**
     * Swaps the local stream for a fresh one — used after "Allow access" on
     * the permission-blocked banner succeeds mid-call. Updates the self
     * tile immediately and pushes the new tracks into every existing peer
     * connection (replacing a same-kind track in place where one already
     * exists, e.g. upgrading from audio-only, or adding fresh if the call
     * started with nothing at all).
     */
    replaceLocalStream(newStream) {
        this.localStream = newStream;

        if (this.selfTile) {
            this.selfTile.video.srcObject = newStream;
        }

        newStream.getTracks().forEach((track) => {
            this.peers.forEach(({ peer }) => peer.replaceOrAddTrack(track, newStream));
        });
    }

    async startPresenting() {
        if (this.screenStream) {
            return;
        }

        // getDisplayMedia doesn't exist at all on several mobile browsers
        // (notably iOS Safari on many versions) — calling it there throws
        // synchronously, which the catch below already swallowed
        // identically to "user cancelled the picker." From the tapper's
        // side those look the same: nothing happens. Checking first means
        // the one case that's a real, permanent capability gap — not a
        // change-your-mind — gets an actual explanation instead of a
        // silently dead button.
        if (! navigator.mediaDevices?.getDisplayMedia) {
            this.alpineStore.selfError = "Screen sharing isn't supported in this browser.";

            return;
        }

        try {
            this.screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: true });
        } catch {
            return; // user cancelled the picker
        }

        const track = this.screenStream.getVideoTracks()[0];

        this.peers.forEach(({ peer }) => peer.addTrack(track, this.screenStream));
        // Fires when the browser's own "Stop sharing" bar is used, not just
        // our in-app button — route both through the same teardown path.
        track.addEventListener('ended', () => this.stopPresenting());

        this.showPresenter(this.participantId, `${this.displayName} (you)`);
        this.elements.stageVideo.srcObject = this.screenStream;
        this.playStage();
        this.signaling.announcePresentation(true, this.displayName);
    }

    stopPresenting() {
        if (! this.screenStream) {
            return;
        }

        const track = this.screenStream.getVideoTracks()[0];
        this.peers.forEach(({ peer }) => peer.removeTrack(track));

        this.screenStream.getTracks().forEach((t) => t.stop());
        this.screenStream = null;

        if (this.alpineStore.presenterId === this.participantId) {
            this.clearStage();
        }

        this.signaling.announcePresentation(false);
    }

    // ---- Stage (presentation) -------------------------------------------

    showPresenter(presenterId, presenterName) {
        this.elements.stageLabel.textContent = `${presenterName} is presenting`;
        this.elements.stage.classList.remove('hidden');
        this.alpineStore.presenterId = presenterId;
        this.alpineStore.presenterName = presenterName;
    }

    clearStage() {
        this.elements.stageVideo.srcObject = null;
        this.elements.stage.classList.add('hidden');
        this.alpineStore.presenterId = null;
        this.alpineStore.presenterName = null;
        this.alpineStore.stageBlocked = false;
    }

    /**
     * The stage video has no `muted` attribute (viewers should hear the
     * presenter's shared audio), and its srcObject is always attached from
     * an async WebSocket event, never a direct click — exactly the
     * combination browsers block autoplay for, especially on mobile. The
     * `autoplay` HTML attribute alone doesn't guarantee playback actually
     * starts; without this, a blocked attempt just leaves the element
     * silently paused on its first (black) frame forever, with nothing in
     * the UI explaining why the "presentation" everyone can see is
     * announced is really being shown.
     */
    playStage() {
        const playPromise = this.elements.stageVideo.play();

        if (! playPromise) {
            return;
        }

        playPromise
            .then(() => {
                this.alpineStore.stageBlocked = false;
            })
            .catch(() => {
                this.alpineStore.stageBlocked = true;
            });
    }

    /** "Tap to play" affordance's click handler — a real user gesture, so
     * the same play() call the browser silently refused in playStage()
     * succeeds here. */
    retryStagePlayback() {
        this.playStage();
    }

    // ---- Tiles ------------------------------------------------------------

    attachSelfTile() {
        this.selfTile = this.createTile(this.participantId, `${this.displayName} (you)`, true);
        this.selfTile.video.muted = true;
        this.selfTile.video.srcObject = this.localStream;
        this.alpineStore.participantCount = 1;
    }

    createTile(id, name, isSelf = false) {
        // Self used to get a colored ring to stand out — dropped in favor
        // of the "(you)" label suffix already on the name, so every tile
        // reads the same and color stays reserved for things that mean
        // something (muted, presenting, disconnected, speaking).
        const wrapper = document.createElement('div');
        // rounded-2xl (16px) was fixed regardless of tile size — fine on
        // the single large solo tile, but the same 16px on an 80px-tall
        // thumbnail (many-participant grid, presenter strip) reads as
        // disproportionately round next to the sharper corners everywhere
        // else. rounded-xl holds up at both ends of that size range.
        // ring-inset: a plain (non-inset) ring's box-shadow paints outside
        // the tile's own border-box — outside overflow-hidden's clip, so it
        // visually bled into the gap between tiles rather than reading as
        // this tile's own border. Inset keeps it, and the speaking ring
        // setTileSpeaking() swaps in, drawn on the inside edge instead.
        wrapper.className = 'relative aspect-video overflow-hidden rounded-xl bg-slate-800 ring-1 ring-inset ring-white/10 transition-all';
        wrapper.dataset.peerId = id;
        // The <video> itself has no accessible content — the tile's
        // container carries the participant's name for screen readers,
        // matching the "list of video feeds" role set on the grid.
        wrapper.setAttribute('role', 'listitem');
        wrapper.setAttribute('aria-label', name);

        const video = document.createElement('video');
        video.autoplay = true;
        video.playsInline = true;
        video.setAttribute('aria-hidden', 'true');
        video.className = 'h-full w-full object-cover' + (isSelf ? ' -scale-x-100' : '');

        const label = document.createElement('span');
        label.className = 'absolute bottom-2 left-2 rounded-full bg-black/50 px-2.5 py-0.5 text-xs text-white';
        label.textContent = name;

        const micBadge = document.createElement('span');
        micBadge.className = 'hidden size-6 items-center justify-center rounded-full bg-red-500 text-white';
        micBadge.innerHTML = badgeSvg('mic-off');

        // No camera-off badge: unlike mic state, a dead camera is already
        // self-evident from the tile itself — there's just no picture — so
        // a second icon saying the same thing was pure redundancy.
        const status = document.createElement('div');
        status.className = 'absolute bottom-2 right-2 flex items-center gap-1';
        status.append(micBadge);

        // Top-left, not grouped with the mic badge: the kick button (below)
        // already claims top-right, and a raised hand is meant to draw the
        // eye rather than blend in with the other status icons — the same
        // reason Zoom/Meet render it in their own idiomatic yellow instead
        // of matching everything else. Sized and animated well past the
        // other (passive-state) badges on purpose: a raised hand is a
        // request waiting on someone, not just background status, so it
        // needs to actually win the eye's attention when scanning a grid
        // of tiles — a same-size static icon the same shade as everything
        // else didn't. The ring gives it a crisp edge against whatever's
        // playing in the video behind it, on any tile.
        const handBadge = document.createElement('span');
        handBadge.className = 'hidden absolute left-2 top-2 size-9 items-center justify-center rounded-full bg-amber-400 text-slate-900 ring-2 ring-slate-900 animate-pulse';
        handBadge.innerHTML = badgeSvg('hand', 'size-5');

        wrapper.append(video, label, status, handBadge);

        if (this.isHost && ! isSelf && this.onKick) {
            const kick = document.createElement('button');
            kick.type = 'button';
            kick.className = 'absolute right-2 top-2 flex size-6 items-center justify-center rounded-full bg-black/50 text-white hover:bg-red-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400';
            kick.title = `Remove ${name}`;
            kick.setAttribute('aria-label', `Remove ${name} from the meeting`);
            kick.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" class="size-3.5"><line x1="6" y1="6" x2="18" y2="18" /><line x1="18" y1="6" x2="6" y2="18" /></svg>';
            kick.addEventListener('click', () => this.onKick(id));
            wrapper.append(kick);
        }

        this.elements.grid.appendChild(wrapper);

        return { wrapper, video, label, micBadge, handBadge, remove: () => wrapper.remove() };
    }

    tileFor(id) {
        if (id === this.participantId) {
            return this.selfTile;
        }

        return this.peers.get(id)?.tile ?? null;
    }

    setTileMediaState(id, micOn, camOn) {
        const tile = this.tileFor(id);

        if (! tile) {
            return;
        }

        tile.micBadge.classList.toggle('hidden', micOn);
        tile.micBadge.classList.toggle('flex', ! micOn);
    }

    setTileHandRaised(id, raised) {
        const tile = this.tileFor(id);

        if (! tile) {
            return;
        }

        tile.handBadge.classList.toggle('hidden', ! raised);
        tile.handBadge.classList.toggle('flex', raised);
    }

    setTileSpeaking(id, speaking) {
        const tile = this.tileFor(id);

        if (! tile) {
            return;
        }

        tile.wrapper.classList.toggle('ring-2', speaking);
        tile.wrapper.classList.toggle('ring-brand-400', speaking);
        tile.wrapper.classList.toggle('ring-1', ! speaking);
        tile.wrapper.classList.toggle('ring-white/10', ! speaking);
    }

    describeMediaError(error) {
        if (error?.name === 'NotAllowedError') {
            return 'Camera/microphone access was blocked. You can still join, but others won’t see or hear you.';
        }

        if (error?.name === 'NotFoundError') {
            return 'No camera or microphone was found on this device.';
        }

        return 'Could not access your camera or microphone.';
    }

    handleRemoved(message) {
        this.alpineStore.removedReason = message;
        this.stop();
    }

    /**
     * The database is always the one already correct here — either
     * ParticipantService::promoteNextHost() (a departing host's successor)
     * or demoteOtherHosts() (the real host reclaiming the role, demoting
     * that temporary successor) ran before this ever arrives, and there is
     * now exactly one active host. This is just telling every open tab.
     *
     * Gaining host needs a reload: it unlocks server-rendered UI
     * (room.blade.php's @if($isHost) blocks — Lock/End Meeting) and
     * this.isHost here, both only ever set at mount. Losing it needs one
     * too, for the same reason in reverse — without it, someone who just
     * got demoted would keep their Lock/End Meeting controls (and their
     * kick button on every tile) live and functional, even though the
     * server would now reject those actions. Everyone else just gets
     * their peers store corrected, clearing the badge off whoever used to
     * be host — there is never more than one now.
     */
    handleHostPromoted(participantId) {
        if (participantId === this.participantId || this.isHost) {
            window.location.reload();

            return;
        }

        const peers = { ...this.alpineStore.peers };

        Object.keys(peers).forEach((id) => {
            peers[id] = { ...peers[id], isHost: id === participantId };
        });

        this.alpineStore.peers = peers;
    }

    stop() {
        clearInterval(this.heartbeatTimer);
        this.heartbeatTimer = null;
        document.removeEventListener('visibilitychange', this.handleVisibilityChange);

        this.peers.forEach(({ peer, tile }) => {
            peer.close();
            tile?.remove();
        });
        this.peers.clear();

        this.stopPresenting();
        this.signaling.leave();
        media.stopAll();

        this.selfTile?.remove();
        this.selfTile = null;

        if (activeController === this) {
            activeController = null;
        }
    }
}
