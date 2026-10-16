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
export async function sendMessage(conversationId, { clientUuid, body, replyToId, attachmentIds }) {
    if (USE_MOCKS) return (await loadMocks()).mockSendMessage(conversationId, { clientUuid, body, replyToId, attachmentIds });
    // §D.5 / CC-006 d: body boleh kosong bila ada attachment_ids
    const payload = { client_uuid: clientUuid };
    if (body) payload.body = body;
    if (attachmentIds?.length) payload.attachment_ids = attachmentIds;
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

// ---- Fase 4: notifikasi (kontrak §D.8) ----

// GET /api/v1/notifications?unread=1&page=&per_page= -> { data: { notifications, unread_count }, meta }
export async function getNotifications({ unread = false, page = 1, perPage = 20 } = {}) {
    if (USE_MOCKS) return (await loadMocks()).mockGetNotifications({ unread, page, perPage });
    const p = await request("GET", "/notifications", { query: { unread: unread ? 1 : undefined, page, per_page: perPage } });
    return { notifications: p.data.notifications, unreadCount: p.data.unread_count, meta: p.meta };
}

// POST /api/v1/notifications/{id}/read -> { data: { notification, unread_count } }
export async function markNotificationRead(id) {
    if (USE_MOCKS) return (await loadMocks()).mockMarkNotificationRead(id);
    return (await request("POST", `/notifications/${encodeURIComponent(id)}/read`)).data;
}

// POST /api/v1/notifications/read-all -> { data: { updated, unread_count } }
export async function markAllNotificationsRead() {
    if (USE_MOCKS) return (await loadMocks()).mockMarkAllNotificationsRead();
    return (await request("POST", "/notifications/read-all")).data;
}

// ---- Fase 5: edit, hapus, reaksi (kontrak §D.5) ----

// PATCH /api/v1/messages/{id} {body} -> { data: { message } }  (403 FORBIDDEN, 409 MESSAGE_DELETED | EDIT_WINDOW_EXPIRED)
export async function editMessage(id, body) {
    if (USE_MOCKS) return (await loadMocks()).mockEditMessage(id, body);
    return (await request("PATCH", `/messages/${encodeURIComponent(id)}`, { body: { body } })).data.message;
}

// DELETE /api/v1/messages/{id} -> { data: { message: <tombstone> } }
export async function deleteMessage(id) {
    if (USE_MOCKS) return (await loadMocks()).mockDeleteMessage(id);
    return (await request("DELETE", `/messages/${encodeURIComponent(id)}`)).data.message;
}

// PUT /api/v1/messages/{id}/reaction {emoji} -> { data: { message_id, revision, reactions } }
export async function setReaction(id, emoji) {
    if (USE_MOCKS) return (await loadMocks()).mockSetReaction(id, emoji);
    return (await request("PUT", `/messages/${encodeURIComponent(id)}/reaction`, { body: { emoji } })).data;
}

// DELETE /api/v1/messages/{id}/reaction -> { data: { message_id, revision, reactions } } (idempoten)
export async function clearReaction(id) {
    if (USE_MOCKS) return (await loadMocks()).mockClearReaction(id);
    return (await request("DELETE", `/messages/${encodeURIComponent(id)}/reaction`)).data;
}

// ---- Fase 6: lampiran (kontrak §D.6, §G) ----

// POST /api/v1/attachments — multipart, field "file", SATU file per request; XMLHttpRequest agar ada progress.
// Mengembalikan { promise -> attachment, abort }. 201 { data: { attachment } } (message_id: null = pending)
export function uploadAttachment(file, { onProgress } = {}) {
    if (USE_MOCKS) {
        let aborted = false;
        let inner = null;
        const promise = loadMocks().then((m) => {
            if (aborted) throw Object.assign(new Error("aborted"), { aborted: true });
            inner = m.mockUploadAttachment(file, { onProgress });
            return inner.promise;
        });
        return { promise, abort: () => { aborted = true; inner?.abort(); } };
    }
    const xhr = new XMLHttpRequest();
    const promise = new Promise((resolve, reject) => {
        const base = window.AMIGO?.apiBase ?? "/api/v1";
        xhr.open("POST", `${base}/attachments`);
        xhr.setRequestHeader("Accept", "application/json");
        xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
        xhr.setRequestHeader("X-CSRF-TOKEN", csrfToken());
        // Content-Type TIDAK di-set: browser mengisi boundary multipart
        xhr.upload.onprogress = (e) => e.lengthComputable && onProgress?.(Math.round((e.loaded / e.total) * 100));
        xhr.onload = () => {
            let payload = null;
            try {
                payload = JSON.parse(xhr.responseText);
            } catch {
                /* 413 dari Nginx bukan format §F */
            }
            if (xhr.status === 401) {
                window.location.assign("/login");
                return reject(new ApiError(401, "UNAUTHENTICATED", "Sesi berakhir. Silakan masuk lagi."));
            }
            if (xhr.status === 419) {
                window.location.reload();
                return reject(new ApiError(419, "CSRF_MISMATCH", "Halaman kedaluwarsa. Memuat ulang…"));
            }
            if (xhr.status >= 200 && xhr.status < 300 && payload?.success !== false && payload?.data?.attachment) return resolve(payload.data.attachment);
            const code = payload?.error?.code ?? (xhr.status === 413 ? "PAYLOAD_TOO_LARGE" : xhr.status === 415 ? "UNSUPPORTED_MEDIA_TYPE" : "UNKNOWN");
            reject(new ApiError(xhr.status, code, payload?.message ?? "Gagal mengunggah. Coba lagi.", payload?.error?.details ?? null));
        };
        xhr.onerror = () => reject(new ApiError(0, "NETWORK_ERROR", "Tidak dapat terhubung ke server."));
        xhr.onabort = () => reject(Object.assign(new Error("aborted"), { aborted: true }));
        const fd = new FormData();
        fd.append("file", file, file.name);
        xhr.send(fd);
    });
    return { promise, abort: () => xhr.abort() };
}

// DELETE /api/v1/attachments/{id} — hanya pending milik sendiri (409 ATTACHMENT_ALREADY_ATTACHED jika sudah terlampir)
export async function deleteAttachment(id) {
    if (USE_MOCKS) return (await loadMocks()).mockDeleteAttachment(id);
    await request("DELETE", `/attachments/${encodeURIComponent(id)}`);
}