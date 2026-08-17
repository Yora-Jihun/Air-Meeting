// Public STUN only. Behind restrictive/symmetric NATs this mesh will fail
// to connect some pairs — see the deployment notes for adding a TURN
// server (coturn) before relying on this outside trusted networks.
const ICE_SERVERS = [{ urls: 'stun:stun.l.google.com:19302' }];

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
            onConnectionStateChange?.(this.connection.connectionState);
        };
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

            if (description.type === 'offer') {
                await this.connection.setLocalDescription();
                this.signaling.send(this.peerId, { description: this.connection.localDescription });
            }

            return;
        }

        if (candidate) {
            try {
                await this.connection.addIceCandidate(candidate);
            } catch (err) {
                if (! this.ignoreOffer) {
                    throw err;
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
        this.connection.close();
    }
}
