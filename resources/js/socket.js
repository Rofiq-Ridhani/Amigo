// resources/js/socket.js — klien Socket.IO sesuai kontrak §E.1, §E.2, §H.7
import { io } from "socket.io-client";
import { USE_MOCKS, getMe, getSocketToken } from "./api.js";

const SEEN_MAX = 500; // §E.2: simpan ±500 event_id terakhir

/**
 * Mengembalikan pembungkus:
 *  - on(event, (envelope, ack) => …)  : event bisnis; duplikat (event_id sama) dibuang
 *  - onLifecycle(name, handler)       : connect / disconnect / connect_error (bukan envelope)
 *  - disconnect()
 */
export async function connectSocket() {
    let raw;
    if (USE_MOCKS) {
        // Presence selalu mock sampai server Socket.IO (Fase 1B) ada
        raw = (await import("./mocks/socket.mock.js")).createMockSocket();
    } else {
        raw = io(window.AMIGO.socketUrl, {
            transports: ["websocket", "polling"],
            // Dipanggil pada SETIAP connect/reconnect -> token selalu baru
            auth: async (cb) => {
                try {
                    cb({ token: (await getSocketToken()).token });
                } catch {
                    cb({ token: "" }); // server menolak -> connect_error "unauthorized"
                }
            },
        });
        // §E.1 poin 5: sesi habis -> cek /me; 401 ditangani api.js (redirect /login)
        raw.on("connect_error", (err) => {
            if (err?.message === "unauthorized") getMe().catch(() => {});
        });
    }

    const seen = new Set();
    const order = [];
    const isDuplicate = (id) => {
        if (!id) return false;
        if (seen.has(id)) return true;
        seen.add(id);
        order.push(id);
        if (order.length > SEEN_MAX) seen.delete(order.shift());
        return false;
    };

    return {
        on(event, handler) {
            raw.on(event, (envelope, ack) => {
                if (isDuplicate(envelope?.event_id)) {
                    if (typeof ack === "function") ack({ ok: true });
                    return;
                }
                handler(envelope, ack);
            });
        },
        onLifecycle(name, handler) {
            raw.on(name, handler);
        },
        disconnect() {
            raw.disconnect();
        },
    };
}