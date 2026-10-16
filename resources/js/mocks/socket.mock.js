// resources/js/mocks/socket.mock.js — Socket.IO tiruan (envelope §E.2, payload §E.3)
import { uuidv4 } from "../lib/uuid.js";

export const envelope = (data) => ({ event_id: uuidv4(), occurred_at: new Date().toISOString(), data });

let active = null;
// Dipakai api.mock.js untuk mensimulasikan event server (message:new, message:delivery)
export const mockBus = { emit: (name, ...args) => active?.fire(name, ...args) };

export function createMockSocket() {
    const handlers = new Map();
    const timers = [];
    const fire = (name, ...args) => (handlers.get(name) ?? []).forEach((h) => h(...args));
    const at = (ms, fn) => timers.push(setTimeout(fn, ms));

    const socket = {
        connected: false,
        fire,
        connect() {
            at(100, () => {
                socket.connected = true;
                fire("connect");
                fire("presence:snapshot", envelope({ online_user_ids: [2, 3] }));
            });
        },
        on(name, h) {
            handlers.set(name, [...(handlers.get(name) ?? []), h]);
        },
        // client -> server: server tiruan selalu membalas ACK ok
        emit(_name, _payload, cb) {
            if (typeof cb === "function") cb({ ok: true });
        },
        disconnect() {
            timers.forEach(clearTimeout);
            socket.connected = false;
            fire("disconnect", "io client disconnect");
        },
    };
    active = socket;

    at(400, () => {
        socket.connected = true;
        fire("connect");
        fire("presence:snapshot", envelope({ online_user_ids: [2, 3] }));
    });
    at(5000, () => fire("presence:update", envelope({ user_id: 4, status: "online", last_seen_at: null })));
    at(12000, () => fire("presence:update", envelope({ user_id: 2, status: "offline", last_seen_at: new Date().toISOString() })));

    return socket;
}