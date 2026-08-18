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
        // True when the browser blocked the stage video's autoplay (common
        // for mobile/unengaged origins on unmuted video attached outside a
        // click) — see RoomController.playStage(). Drives a "Tap to play"
        // affordance instead of leaving viewers looking at a silently-black
        // stage with no explanation.
        stageBlocked: false,
        selfError: null,
        removedReason: null,
        selfJoinedAt: null,
        peers: {},
        chatMessages: [],
    });
});

window.AirMeet = { RoomController, media };
