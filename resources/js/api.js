// resources/js/api.js — helper fetch sesuai kontrak §D.0 dan §F
export const USE_MOCKS = import.meta.env.VITE_USE_MOCKS === "true";

export class ApiError extends Error {
    constructor(status, code, message, details = null) {
        super(message);
        this.status = status;
        this.code = code;
        this.details = details;
    }
}

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? "";

async function request(method, path, { query, body } = {}) {
    const base = window.AMIGO?.apiBase ?? "/api/v1";
    const params = new URLSearchParams();
    Object.entries(query ?? {}).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== "") params.set(k, String(v));
    });
    const qs = params.toString();

    const headers = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };
    if (method !== "GET") headers["X-CSRF-TOKEN"] = csrfToken();
    if (body !== undefined) headers["Content-Type"] = "application/json";

    let res;
    try {
        res = await fetch(`${base}${path}${qs ? `?${qs}` : ""}`, {
            method,
            headers,
            credentials: "same-origin",
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });
    } catch {
        throw new ApiError(0, "NETWORK_ERROR", "Tidak dapat terhubung ke server. Periksa koneksi Anda.");
    }

    // Bisa bukan JSON (mis. 413 dari Nginx) -> payload null
    const payload = await res.json().catch(() => null);

    if (res.status === 401) {
        window.location.assign("/login");
        throw new ApiError(401, "UNAUTHENTICATED", "Sesi berakhir. Silakan masuk lagi.");
    }
    if (res.status === 419) {
        window.location.reload();
        throw new ApiError(419, "CSRF_MISMATCH", "Halaman kedaluwarsa. Memuat ulang…");
    }
    if (!res.ok || payload?.success === false) {
        throw new ApiError(
            res.status,
            payload?.error?.code ?? "UNKNOWN",
            payload?.message ?? "Terjadi kesalahan. Coba lagi.",
            payload?.error?.details ?? null,
        );
    }
    return payload;
}

const loadMocks = () => import("./mocks/api.mock.js");

// GET /api/v1/me -> { data: { user: { id, name, email, last_seen_at } } }
export async function getMe() {
    if (USE_MOCKS) return (await loadMocks()).mockGetMe();
    return (await request("GET", "/me")).data.user;
}

// GET /api/v1/users?q=&page=&per_page= -> { data: { users }, meta: { page, per_page, total, last_page } }
export async function getUsers({ q = "", page = 1, perPage = 20 } = {}) {
    if (USE_MOCKS) return (await loadMocks()).mockGetUsers({ q, page, perPage });
    const p = await request("GET", "/users", { query: { q, page, per_page: perPage } });
    return { users: p.data.users, meta: p.meta };
}

// GET /api/v1/users/{id} -> { data: { user } }
export async function getUser(id) {
    if (USE_MOCKS) return (await loadMocks()).mockGetUser(id);
    return (await request("GET", `/users/${encodeURIComponent(id)}`)).data.user;
}

// GET /api/v1/socket-token -> { data: { token, expires_at, socket_url } }
export async function getSocketToken() {
    if (USE_MOCKS) return (await loadMocks()).mockGetSocketToken();
    return (await request("GET", "/socket-token")).data;
}

// ---- Fase 2: percakapan & pesan (kontrak §D.4, §D.5) ----

// POST /api/v1/conversations {user_id} -> { data: { conversation } } (201 baru / 200 sudah ada)
export async function createConversation(userId) {
    if (USE_MOCKS) return (await loadMocks()).mockCreateConversation(userId);
    return (await request("POST", "/conversations", { body: { user_id: userId } })).data.conversation;
}

// GET /api/v1/conversations?page=&per_page= -> { data: { conversations }, meta }
export async function getConversations({ page = 1, perPage = 20 } = {}) {
    if (USE_MOCKS) return (await loadMocks()).mockGetConversations();
    const p = await request("GET", "/conversations", { query: { page, per_page: perPage } });
    return { conversations: p.data.conversations, meta: p.meta };
}

// GET /api/v1/conversations/{id}/messages — mode riwayat (before_id, limit) ATAU resync (updated_since)
export async function getMessages(conversationId, { beforeId, limit, updatedSince } = {}) {
    if (USE_MOCKS) return (await loadMocks()).mockGetMessages(conversationId, { beforeId, limit, updatedSince });
    const query = updatedSince ? { updated_since: updatedSince } : { before_id: beforeId, limit };
    const p = await request("GET", `/conversations/${encodeURIComponent(conversationId)}/messages`, { query });
    return { messages: p.data.messages, meta: p.meta };
}

// POST /api/v1/conversations/{id}/messages {client_uuid, body, reply_to_id?} -> { data: { message } }
export async function sendMessage(conversationId, { clientUuid, body, replyToId }) {
    if (USE_MOCKS) return (await loadMocks()).mockSendMessage(conversationId, { clientUuid, body });
    const payload = { client_uuid: clientUuid, body };
    if (replyToId) payload.reply_to_id = replyToId;
    return (await request("POST", `/conversations/${encodeURIComponent(conversationId)}/messages`, { body: payload })).data.message;
}

// ---- Fase 3: baca & unread (kontrak §D.7) ----

// POST /api/v1/conversations/{id}/read {up_to_message_id} -> { data: { conversation_id, read_count, unread_count, total_unread, read_at } }
export async function markRead(conversationId, upToMessageId) {
    if (USE_MOCKS) return (await loadMocks()).mockMarkRead(conversationId, upToMessageId);
    const path = `/conversations/${encodeURIComponent(conversationId)}/read`;
    return (await request("POST", path, { body: { up_to_message_id: upToMessageId } })).data;
}

// GET /api/v1/unread-counts -> { data: { total, by_conversation: [{ conversation_id, unread_count }] } }
export async function getUnreadCounts() {
    if (USE_MOCKS) return (await loadMocks()).mockGetUnreadCounts();
    return (await request("GET", "/unread-counts")).data;
}