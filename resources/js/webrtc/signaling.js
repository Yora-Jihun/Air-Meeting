/**
 * Thin wrapper around a Laravel Echo presence channel.
 *
 * Two kinds of traffic travel over the same channel:
 *  - Presence membership (who's here / joining / leaving) is native to
 *    presence channels and authorized server-side in routes/channels.php.
 *  - SDP offers/answers and ICE candidates are sent as "whispers" —
 *    client-to-client messages relayed by Reverb that never touch a
 *    Laravel controller/event. This keeps signaling latency to a single
 *    WebSocket hop and avoids writing a PHP class per SDP message.
 *
 * Meeting-level control events (kicked, meeting ended) and chat messages
 * are true Laravel broadcast events (see App\Events). Kicked/ended
 * originate from server state changes; chat is here too, despite
 * originating from a peer's browser, because it's persisted (unlike the
 * whispered signals above) so it survives a refresh and reaches anyone
 * who joins mid-call — sending one is a Livewire call
 * ($wire.sendChat in room-alpine.js), not a whisper.
 */
export class SignalingClient {
    constructor(meetingUuid, participantId) {
        this.meetingUuid = meetingUuid;
        this.participantId = participantId;
        this.channel = null;
    }

    join({
        onHere, onJoining, onLeaving, onSignal, onPresentation,
        onMediaState, onSpeaking, onChat, onKicked, onMeetingEnded, onHostPromoted,
        onResyncRequest, onHandRaised,
    }) {
        this.channel = window.Echo.join(`meeting.${this.meetingUuid}`)
            .here((members) => onHere(members.filter((m) => m.id !== this.participantId)))
            .joining((member) => onJoining(member))
            .leaving((member) => onLeaving(member))
            .listenForWhisper('signal', (payload) => {
                if (payload.to === this.participantId) {
                    onSignal(payload);
                }
            })
            // Explicit "who's presenting" control signal — broadcast to
            // everyone (not directed), independent of the WebRTC track
            // itself. Deliberately not inferred from track mute/ended
            // events: stopping a screen-share track via removeTrack()
            // fires `mute` on the receiving end, not `ended`, which made
            // "stop presenting" never clear other participants' stage
            // without a manual refresh.
            .listenForWhisper('presentation', (payload) => {
                if (payload.from !== this.participantId) {
                    onPresentation(payload);
                }
            })
            // Mic/camera on-off state for the sidebar list and video tile
            // badges. Not derivable from the WebRTC track itself the same
            // way presence is — a disabled track still exists, it just
            // stops producing frames/audio — so this has to be its own
            // explicit signal too.
            .listenForWhisper('media-state', (payload) => {
                if (payload.from !== this.participantId) {
                    onMediaState(payload);
                }
            })
            .listenForWhisper('speaking', (payload) => {
                if (payload.from !== this.participantId) {
                    onSpeaking(payload);
                }
            })
            // Raise Hand — an explicit request-to-speak signal, same
            // ephemeral whisper treatment as mic/cam/speaking: nobody
            // needs to know a hand was raised an hour ago after they
            // refresh, only that it's raised right now.
            .listenForWhisper('hand-raised', (payload) => {
                if (payload.from !== this.participantId) {
                    onHandRaised(payload);
                }
            })
            // Chat: a real broadcast (see App\Events\ChatMessageSent), not a
            // whisper, and NOT filtered by participant id — it's meant to
            // reach the sender's own other tabs too, since there's no
            // separate optimistic local echo for a message you just sent.
            .listen('.chat.message.sent', (payload) => onChat(payload))
            .listen('.participant.kicked', (event) => {
                if (event.participant_id === this.participantId) {
                    onKicked();
                }
            })
            .listen('.meeting.ended', () => onMeetingEnded())
            .listen('.host.promoted', (event) => onHostPromoted(event))
            // Whispers are never replayed and are trivially lost — a mobile
            // browser that throttled/suspended this tab's WebSocket while
            // backgrounded (screen locked, app-switched away) can sit
            // "connected" without ever having actually received a mic/cam
            // or presentation change announced during that window, with no
            // error to react to. requestResync() below asks everyone to
            // just re-announce their current state from scratch, rather
            // than trying to detect and recover from that gap precisely.
            .listenForWhisper('resync-request', (payload) => {
                if (payload.from !== this.participantId) {
                    onResyncRequest();
                }
            });

        return this.channel;
    }

    /** Send an SDP description or ICE candidate directly to one peer. */
    send(to, data) {
        this.channel?.whisper('signal', { from: this.participantId, to, ...data });
    }

    /** Broadcast presentation start/stop to every other participant instantly. */
    announcePresentation(presenting, name) {
        this.channel?.whisper('presentation', { from: this.participantId, presenting, name });
    }

    /** Broadcast current mic/camera on-off state to every other participant. */
    announceMediaState(micOn, camOn) {
        this.channel?.whisper('media-state', { from: this.participantId, micOn, camOn });
    }

    /** Broadcast a speaking/not-speaking transition (called on change only, not per-sample). */
    announceSpeaking(speaking) {
        this.channel?.whisper('speaking', { from: this.participantId, speaking });
    }

    /** Broadcast a raised/lowered hand to every other participant instantly. */
    announceHandRaised(raised) {
        this.channel?.whisper('hand-raised', { from: this.participantId, raised });
    }

    /** Ask every other participant to re-announce their current media/presentation state. */
    requestResync() {
        this.channel?.whisper('resync-request', { from: this.participantId });
    }

    leave() {
        window.Echo.leave(`meeting.${this.meetingUuid}`);
        this.channel = null;
    }
}
