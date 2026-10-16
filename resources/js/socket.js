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
let pending = null;
// Satu koneksi per halaman (maks 10 per akun, §I): pemanggilan berulang memakai koneksi yang sama
export function connectSocket() {
    pending ??= createSocket();
    return pending;
}

async function createSocket() {
    let raw;
    let tokenErr = null;
    let retryDelay = 2000;
    let retryTimer = null;
    if (USE_MOCKS) {
        // Presence selalu mock sampai server Socket.IO (Fase 1B) ada
        raw = (await import("./mocks/socket.mock.js")).createMockSocket();
    } else {
        raw = io(window.AMIGO.socketUrl, {
            transports: ["websocket", "polling"],
            closeOnBeforeunload: true, // tutup koneksi saat halaman ditinggalkan (hemat jatah 10 koneksi)
            // Dipanggil pada SETIAP connect/reconnect -> token selalu baru
            auth: async (cb) => {
                try {
                    cb({ token: (await getSocketToken()).token });
                } catch (err) {
                    tokenErr = err; // 429 / jaringan / 5xx: dicatat agar percobaan ulang menghormati Retry-After
                    cb({ token: "" }); // server menolak -> connect_error "unauthorized"
                }
            },
        });
    }
    // Socket.IO TIDAK menyambung ulang sendiri bila ditolak middleware ("unauthorized"). Jika token gagal diambil sementara
    // (429/jaringan) tetapi sesi masih valid, coba lagi dengan backoff (2→30 dtk) dan hormati Retry-After.
    const scheduleRetry = () => {
        if (retryTimer || raw.connected) return;
        const wait = Math.max(retryDelay, (tokenErr?.retryAfter ?? 0) * 1000);
        retryDelay = Math.min(retryDelay * 2, 30_000);
        retryTimer = setTimeout(() => {
            retryTimer = null;
            if (!raw.connected) raw.connect();
        }, wait);
    };
    raw.on("connect", () => {
        retryDelay = 2000;
        tokenErr = null;
    });
    raw.on("connect_error", (err) => {
        // §E.1 poin 5: sesi habis -> cek /me; 401 ditangani api.js (redirect /login). Sesi valid -> coba lagi.
        if (err?.message === "unauthorized") getMe().then(scheduleRetry).catch(() => {});
        // maks 10 koneksi per akun: berhenti, jangan retry terus-menerus (pengguna mencoba lagi secara manual)
        if (err?.message === "too_many_connections") raw.disconnect();
    });

    const listeners = new Map(); // event -> Set(handler)
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
        // Satu listener mentah per event: dedup SEKALI, lalu diteruskan ke semua handler terdaftar
        on(event, handler) {
            if (!listeners.has(event)) {
                listeners.set(event, new Set());
                raw.on(event, (envelope, ack) => {
                    if (isDuplicate(envelope?.event_id)) {
                        if (typeof ack === "function") ack({ ok: true });
                        return;
                    }
                    listeners.get(event).forEach((h) => h(envelope, ack));
                });
            }
            listeners.get(event).add(handler);
        },
        onLifecycle(name, handler) {
            raw.on(name, handler);
        },
        // client -> server (§E.4), mis. message:delivered; payload tanpa user_id
        emit(event, payload, cb) {
            raw.emit(event, payload, cb);
        },
        disconnect() {
            raw.disconnect();
        },
        reconnect() {
            raw.connect(); // percobaan manual setelah too_many_connections
        },
    };
}