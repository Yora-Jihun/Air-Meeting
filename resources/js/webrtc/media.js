/**
 * Owns the local camera/microphone MediaStream. Lives outside of any
 * Alpine/Livewire component so the stream survives Livewire's DOM
 * morphing when the join screen is swapped for the in-call layout.
 */
class MediaStore {
    stream = null;
    micOn = true;
    camOn = true;
    error = null;
    speaking = false;

    _audioContext = null;
    _analyser = null;
    _speakingFrame = null;

    async acquire() {
        if (this.stream) {
            return this.stream;
        }

        try {
            this.stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });

            return this.stream;
        } catch (err) {
            // Camera denied/unavailable is common (busy device, no camera,
            // permission blocked) — fall back to audio-only rather than
            // failing the whole join, then surface the reason in the UI.
            this.error = err;
            this.camOn = false;

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });

                return this.stream;
            } catch (audioErr) {
                this.error = audioErr;
                this.micOn = false;
                this.stream = new MediaStream();

                return this.stream;
            }
        }
    }

    /**
     * Re-attempts getUserMedia from scratch — used by the "Allow access"
     * button on the permission-blocked banner. A browser that already
     * denied access usually won't re-prompt on its own; this is meant for
     * the case where the participant went and granted the permission in
     * their browser's site settings and came back to retry.
     */
    async retry() {
        this.stopAll();
        this.error = null;
        this.micOn = true;
        this.camOn = true;

        return this.acquire();
    }

    toggleMic() {
        this.micOn = ! this.micOn;
        this.stream?.getAudioTracks().forEach((track) => (track.enabled = this.micOn));

        return this.micOn;
    }

    toggleCam() {
        this.camOn = ! this.camOn;
        this.stream?.getVideoTracks().forEach((track) => (track.enabled = this.camOn));

        return this.camOn;
    }

    /**
     * Watches the local mic's volume and calls back only when the
     * speaking/not-speaking state actually changes (not on every sample),
     * so callers can cheaply broadcast it without flooding the channel.
     * Self-contained — each participant detects their own speech and
     * announces it, rather than every client analyzing every incoming
     * stream, which is both cheaper and more accurate per-person.
     */
    startSpeakingDetection(onChange) {
        const [audioTrack] = this.stream?.getAudioTracks() ?? [];

        if (! audioTrack || this._audioContext) {
            return;
        }

        this._audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const source = this._audioContext.createMediaStreamSource(new MediaStream([audioTrack]));
        this._analyser = this._audioContext.createAnalyser();
        this._analyser.fftSize = 512;
        this._analyser.smoothingTimeConstant = 0.6;
        source.connect(this._analyser);

        const data = new Uint8Array(this._analyser.frequencyBinCount);
        const threshold = 16;
        let speaking = false;

        const tick = () => {
            this._analyser.getByteFrequencyData(data);
            const average = data.reduce((sum, value) => sum + value, 0) / data.length;
            const isSpeaking = this.micOn && average > threshold;

            if (isSpeaking !== speaking) {
                speaking = isSpeaking;
                this.speaking = speaking;
                onChange(speaking);
            }

            this._speakingFrame = requestAnimationFrame(tick);
        };

        tick();
    }

    stopSpeakingDetection() {
        if (this._speakingFrame) {
            cancelAnimationFrame(this._speakingFrame);
        }

        this._audioContext?.close();
        this._audioContext = null;
        this._analyser = null;
        this._speakingFrame = null;
        this.speaking = false;
    }

    stopAll() {
        this.stopSpeakingDetection();
        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;
    }
}

export const media = new MediaStore();
