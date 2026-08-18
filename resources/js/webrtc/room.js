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
};

function badgeSvg(name) {
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="size-3">${BADGE_ICONS[name]}</svg>`;
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
                this.signaling.announceMediaState(this.alpineStore.micOn, this.alpineStore.camOn);
            },
            onLeaving: (member) => this.disconnectFrom(member.id),
            onSignal: (payload) => this.peers.get(payload.from)?.peer.handleSignal(payload),
            onPresentation: (payload) => this.handlePresentationSignal(payload),
            onMediaState: (payload) => this.handleMediaState(payload),
            onSpeaking: (payload) => this.handleSpeaking(payload.from, payload.speaking),
            onChat: (payload) => this.handleChat(payload),
            onKicked: () => this.handleRemoved('You were removed from the meeting by the host.'),
            onMeetingEnded: () => this.handleRemoved('The host ended this meeting.'),
        });

        this.signaling.announceMediaState(this.alpineStore.micOn, this.alpineStore.camOn);

        media.startSpeakingDetection((speaking) => {
            this.alpineStore.speaking = speaking;
            this.setTileSpeaking(this.participantId, speaking);
            this.signaling.announceSpeaking(speaking);
        });

        this.alpineStore.connected = true;
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
        wrapper.className = 'relative aspect-video overflow-hidden rounded-xl bg-slate-800 ring-1 ring-white/10 transition-all';
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

        const camBadge = document.createElement('span');
        camBadge.className = 'hidden size-6 items-center justify-center rounded-full bg-red-500 text-white';
        camBadge.innerHTML = badgeSvg('video-off');

        const status = document.createElement('div');
        status.className = 'absolute bottom-2 right-2 flex items-center gap-1';
        status.append(micBadge, camBadge);

        wrapper.append(video, label, status);

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

        return { wrapper, video, label, micBadge, camBadge, remove: () => wrapper.remove() };
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
        tile.camBadge.classList.toggle('hidden', camOn);
        tile.camBadge.classList.toggle('flex', ! camOn);
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

    stop() {
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
