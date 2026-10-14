// resources/js/mocks/socket.mock.js — Socket.IO tiruan: presence:snapshot & presence:update (envelope §E.2, payload §E.3)
const envelope = (data) => ({ event_id: crypto.randomUUID(), occurred_at: new Date().toISOString(), data });

export function createMockSocket() {
    const handlers = new Map();
    const timers = [];
    const emit = (name, ...args) => (handlers.get(name) ?? []).forEach((h) => h(...args));
    const at = (ms, fn) => timers.push(setTimeout(fn, ms));

    const socket = {
        connected: false,
        on(name, h) {
            handlers.set(name, [...(handlers.get(name) ?? []), h]);
        },
        disconnect() {
            timers.forEach(clearTimeout);
            socket.connected = false;
            emit("disconnect", "io client disconnect");
        },
    };

    at(400, () => {
        socket.connected = true;
        emit("connect");
        emit("presence:snapshot", envelope({ online_user_ids: [2, 3] }));
    });
    at(5000, () => emit("presence:update", envelope({ user_id: 4, status: "online", last_seen_at: null })));
    at(12000, () => emit("presence:update", envelope({ user_id: 2, status: "offline", last_seen_at: new Date().toISOString() })));

    return socket;
}