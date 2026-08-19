// Public STUN always; TURN only if VITE_TURN_URLS is configured (see
// .env.example). Without a TURN relay, this mesh cannot connect any pair
// where at least one side is behind a symmetric NAT or restrictive
// firewall — STUN alone isn't enough there, no matter how the rest of the
// signaling is implemented.
//
// VITE_TURN_URLS is a comma-separated list, not a single URL: a TURN
// provider is typically reached over several transports (UDP, TCP, and
// TLS-over-TCP on port 443, which is what gets through a firewall that
// blocks everything else because it looks like ordinary HTTPS) sharing one
// username/credential — offering all of them as one ICE server's `urls`
// array lets the browser pick whichever one actually gets through on a
// given network, instead of only ever trying one and giving up.
const ICE_SERVERS = [
    { urls: 'stun:stun.l.google.com:19302' },
    ...(import.meta.env.VITE_TURN_URLS
        ? [{
            urls: import.meta.env.VITE_TURN_URLS.split(',').map((url) => url.trim()),
            username: import.meta.env.VITE_TURN_USERNAME,
            credential: import.meta.env.VITE_TURN_CREDENTIAL,
        }]
        : []),
];

/**
 * One RTCPeerConnection per remote participant, implementing the "perfect
 * negotiation" pattern (https://developer.mozilla.org/en-US/docs/Web/API/WebRTC_API/Perfect_negotiation).
 * This single code path transparently handles both the initial offer/answer
 * and every later renegotiation (e.g. adding a screen-share track) without
 * the two peers needing an explicit "who calls whom" protocol beyond the
 * `polite` flag, which both sides derive independently and identically
 * from comparing participant ids.
 */
export class Peer {
    constructor(peerId, { polite, localStream, signaling, onTrack, onConnectionStateChange }) {
        this.peerId = peerId;
        this.polite = polite;
        this.signaling = signaling;
        this.onTrack = onTrack;
        this.makingOffer = false;
        this.ignoreOffer = false;
        // ICE candidates can — and with several peers negotiating at once,
        // routinely do — arrive over the whisper channel before the
        // corresponding setRemoteDescription() has resolved (they're
        // independent async messages with no ordering guarantee beyond
        // "sent after" the description). addIceCandidate() throws if
        // there's no remote description yet, so anything that arrives too
        // early is buffered here and flushed once one is set, instead of
        // being dropped — the standard fix for this in every perfect
        // negotiation reference implementation.
        this.pendingCandidates = [];
        this.disconnectTimer = null;

        this.connection = new RTCPeerConnection({ iceServers: ICE_SERVERS });
        this.senders = new Map(); // track.kind/id -> RTCRtpSender, for later replaceTrack/removeTrack

        localStream.getTracks().forEach((track) => {
            this.senders.set(track.id, this.connection.addTrack(track, localStream));
        });

        this.connection.onnegotiationneeded = async () => {
            try {
                this.makingOffer = true;
                await this.connection.setLocalDescription();
                this.signaling.send(this.peerId, { description: this.connection.localDescription });
            } catch (err) {
                console.error('[webrtc] negotiation failed', err);
            } finally {
                this.makingOffer = false;
            }
        };

        this.connection.onicecandidate = ({ candidate }) => {
            if (candidate) {
                this.signaling.send(this.peerId, { candidate });
            }
        };

        this.connection.ontrack = (event) => {
            this.onTrack(event.streams[0] ?? new MediaStream([event.track]), event.track);
        };

        this.connection.onconnectionstatechange = () => {
            const state = this.connection.connectionState;
            onConnectionStateChange?.(state);

            // 'disconnected' is often a transient blip (brief packet loss, a
            // wifi handoff) that ICE resolves on its own within a couple of
            // seconds — restarting immediately on it would fire far more
            // renegotiations than necessary. 'failed' is ICE giving up for
            // good, so that one restarts right away; 'disconnected' only
            // escalates to a restart if it's still stuck after a grace
            // period, same threshold either way.
            if (state === 'failed') {
                clearTimeout(this.disconnectTimer);
                this.restartIce();
            } else if (state === 'disconnected') {
                clearTimeout(this.disconnectTimer);
                this.disconnectTimer = setTimeout(() => {
                    if (this.connection.connectionState === 'disconnected') {
                        this.restartIce();
                    }
                }, 3000);
            } else {
                clearTimeout(this.disconnectTimer);
            }
        };
    }

    /**
     * Renegotiates with fresh ICE credentials without tearing down the
     * connection — the standard recovery for a connection that dropped due
     * to a network change rather than either side actually leaving.
     * restartIce() fires `negotiationneeded` per spec, which the handler
     * above already turns into an offer sent down the same signaling path
     * as any other renegotiation (e.g. adding a screen-share track), so no
     * separate offer/answer handling is needed here.
     */
    restartIce() {
        if (this.connection.connectionState === 'closed') {
            return;
        }

        this.connection.restartIce();
    }

    async handleSignal({ description, candidate }) {
        if (description) {
            const offerCollision = description.type === 'offer'
                && (this.makingOffer || this.connection.signalingState !== 'stable');

            this.ignoreOffer = ! this.polite && offerCollision;

            if (this.ignoreOffer) {
                return;
            }

            await this.connection.setRemoteDescription(description);
            await this.flushPendingCandidates();

            if (description.type === 'offer') {
                await this.connection.setLocalDescription();
                this.signaling.send(this.peerId, { description: this.connection.localDescription });
            }

            return;
        }

        if (candidate) {
            if (! this.connection.remoteDescription) {
                this.pendingCandidates.push(candidate);

                return;
            }

            try {
                await this.connection.addIceCandidate(candidate);
            } catch (err) {
                if (! this.ignoreOffer) {
                    throw err;
                }
            }
        }
    }

    async flushPendingCandidates() {
        const candidates = this.pendingCandidates;
        this.pendingCandidates = [];

        for (const candidate of candidates) {
            try {
                await this.connection.addIceCandidate(candidate);
            } catch (err) {
                if (! this.ignoreOffer) {
                    console.error('[webrtc] buffered ICE candidate failed', err);
                }
            }
        }
    }

    /** Add a fresh track (e.g. a screen-share track) — triggers renegotiation. */
    addTrack(track, stream) {
        this.senders.set(track.id, this.connection.addTrack(track, stream));
    }

    /**
     * Swap in a track of the same kind (e.g. after retrying camera/mic
     * permission mid-call), or add it fresh if this connection never had
     * one (e.g. it started completely denied). replaceTrack() re-uses the
     * existing sender/transceiver — no renegotiation needed; addTrack()
     * does trigger one, exactly like any other new track.
     */
    replaceOrAddTrack(track, stream) {
        for (const [key, sender] of this.senders) {
            if (sender.track && sender.track.kind === track.kind) {
                sender.replaceTrack(track);
                this.senders.delete(key);
                this.senders.set(track.id, sender);

                return;
            }
        }

        this.addTrack(track, stream);
    }

    /** Remove a previously added track (e.g. when screen sharing stops). */
    removeTrack(track) {
        const sender = this.senders.get(track.id);

        if (sender) {
            this.connection.removeTrack(sender);
            this.senders.delete(track.id);
        }
    }

    close() {
        clearTimeout(this.disconnectTimer);
        this.connection.close();
    }
}
