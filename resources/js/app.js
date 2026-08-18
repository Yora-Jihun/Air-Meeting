import './echo';
import { RoomController } from './webrtc/room';
import { media } from './webrtc/media';
import { meetingRoom } from './webrtc/room-alpine';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('meetingRoom', meetingRoom);

    window.Alpine.store('room', {
        connected: false,
        micOn: true,
        camOn: true,
        speaking: false,
        participantCount: 1,
        presenterId: null,
        presenterName: null,
        selfError: null,
        removedReason: null,
        selfJoinedAt: null,
        peers: {},
        chatMessages: [],
    });
});

window.AirMeet = { RoomController, media };
