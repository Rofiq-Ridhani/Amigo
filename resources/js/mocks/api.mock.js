// resources/js/mocks/api.mock.js — pengganti api.js saat VITE_USE_MOCKS === "true"
// Tips uji: kirim pesan berisi "#gagal" -> gagal sekali (tombol "Coba lagi"), retry berhasil dengan client_uuid yang sama.
import { MOCK_REACTION_EMOJIS, MOCK_USERS } from "./data.js";
import { uuidv4 } from "../lib/uuid.js";
import { envelope, mockBus } from "./socket.mock.js";

const delay = (ms = 150) => new Promise((r) => setTimeout(r, ms));
const meId = () => window.AMIGO?.currentUser?.id;
const now = () => new Date().toISOString();
if (window.AMIGO && !window.AMIGO.reactionEmojis) window.AMIGO.reactionEmojis = MOCK_REACTION_EMOJIS;

export async function mockGetMe() {
    await delay();
    const me = MOCK_USERS.find((u) => u.id === meId());
    return { id: meId(), name: window.AMIGO.currentUser.name, email: "mock@example.com", last_seen_at: me?.last_seen_at ?? null };
}

// §D.3: tanpa diri sendiri, urut name asc, q cocok pada name, meta pagination
export async function mockGetUsers({ q = "", page = 1, perPage = 20 }) {
    await delay();
    const needle = q.trim().toLowerCase();
    const all = MOCK_USERS.filter((u) => u.id !== meId())
        .filter((u) => !needle || u.name.toLowerCase().includes(needle))
        .sort((a, b) => a.name.localeCompare(b.name));
    const start = (page - 1) * perPage;
    return {
        users: all.slice(start, start + perPage),
        meta: { page, per_page: perPage, total: all.length, last_page: Math.max(1, Math.ceil(all.length / perPage)) },
    };
}

export async function mockGetUser(id) {
    await delay();
    const u = MOCK_USERS.find((x) => x.id === Number(id));
    if (!u) throw Object.assign(new Error("Pengguna tidak ditemukan."), { status: 404, code: "NOT_FOUND" });
    return u;
}

export async function mockGetSocketToken() {
    await delay(50);
    return { token: "mock-token", expires_at: new Date(Date.now() + 300_000).toISOString(), socket_url: "mock" };
}

// ---- Fase 2 (bentuk Message §D.1, Conversation §D.1) ----
let nextConvId = 1;
let nextMsgId = 1;
const convs = new Map(); // peerId -> Conversation
const store = new Map(); // convId -> [{ m: Message, u: updated_at }]
const failedOnce = new Set();

const makeMsg = (convId, from, to, body, t, status = "sent") => ({
    id: nextMsgId++, conversation_id: convId, client_uuid: uuidv4(),
    sender_id: from, recipient_id: to, body, reply_to: null, attachments: [], reactions: [],
    status, delivered_at: status === "sent" ? null : t, read_at: status === "read" ? t : null,
    edited_at: null, deleted_at: null, revision: 1, created_at: t,
});

function seed(conv) {
    const rows = store.get(conv.id);
    for (let i = 0; i < 40; i++) {
        const mine = i % 3 !== 0;
        const t = new Date(Date.now() - (40 - i) * 3_600_000).toISOString();
        rows.push({ m: makeMsg(conv.id, mine ? meId() : conv.peer.id, mine ? conv.peer.id : meId(), `Pesan contoh nomor ${i + 1}`, t, "read"), u: t });
    }
}

// Notifikasi (§D.8): satu per pesan masuk; otomatis terbaca saat pesannya dibaca lewat /read (§D.7)
const notifs = [];
const notifUnread = () => notifs.filter((n) => !n.read_at).length;
function addNotif(m, fromName) {
    const n = {
        id: uuidv4(), type: "chat.message", title: `Pesan baru dari ${fromName}`, body: m.body,
        data: { conversation_id: m.conversation_id, message_id: m.id, sender_id: m.sender_id, url: `/chat?conversation=${m.conversation_id}` },
        read_at: null, created_at: m.created_at,
    };
    notifs.push(n);
    return n;
}

function newConv(u) {
    const conv = { id: nextConvId++, peer: { ...u }, last_message: null, unread_count: 0, last_message_at: null, created_at: now() };
    convs.set(u.id, conv);
    store.set(conv.id, []);
    return conv;
}
// Unread dihitung seperti server (§C.8): pesan masuk, belum dibaca, belum dihapus
const unreadFor = (convId) => (store.get(convId) ?? []).filter((r) => r.m.recipient_id === meId() && !r.m.read_at && !r.m.deleted_at).length;
const totalUnread = () => [...store.keys()].reduce((s, id) => s + unreadFor(id), 0);
const emitUnread = (convId) => mockBus.emit("unread:update", envelope({ conversation_id: convId, unread_count: unreadFor(convId), total_unread: totalUnread() }));

// Data awal: satu percakapan dengan 3 pesan belum dibaca; 8 detik kemudian pengguna lain mengirim pesan (badge bertambah)
let seeded = false;
function ensureSeed() {
    if (seeded) return;
    seeded = true;
    const peers = MOCK_USERS.filter((u) => u.id !== meId());
    const pick = (...ids) => peers.find((u) => ids.includes(u.id));
    const a = pick(3, 5);
    const b = pick(4, 6);
    const conv = newConv(a);
    ["Halo!", "Sudah lihat dokumennya?", "Kabari aku ya."].forEach((body, i) => {
        const t = new Date(Date.now() - (3 - i) * 60_000).toISOString();
        const m = makeMsg(conv.id, a.id, meId(), body, t, "delivered");
        store.get(conv.id).push({ m, u: t });
        addNotif(m, a.name);
    });
    setTimeout(() => {
        const c = convs.get(b.id) ?? newConv(b);
        const t = now();
        const r = { m: makeMsg(c.id, b.id, meId(), "Hai, ada waktu sebentar?", t), u: t };
        store.get(c.id).push(r);
        const n = addNotif(r.m, b.name);
        mockBus.emit("message:new", envelope({ message: structuredClone(r.m) }), () => {});
        emitUnread(c.id);
        mockBus.emit("notification:new", envelope({ notification: structuredClone(n), unread_count: notifUnread() }), () => {});
    }, 8000);
}

export async function mockCreateConversation(userId) {
    await delay();
    let conv = convs.get(Number(userId));
    if (!conv) {
        conv = newConv(MOCK_USERS.find((x) => x.id === Number(userId)));
        if (conv.peer.id === 2) seed(conv); // percakapan dengan Andi berisi 40 pesan untuk uji "muat sebelumnya"
    }
    return structuredClone({ ...conv, unread_count: unreadFor(conv.id) });
}

export async function mockGetConversations() {
    await delay();
    ensureSeed();
    const list = [...convs.values()].filter((c) => store.get(c.id).length > 0).map((c) => ({ ...c, unread_count: unreadFor(c.id), last_message: structuredClone(store.get(c.id).at(-1).m) }));
    return { conversations: list, meta: { page: 1, per_page: 50, total: list.length, last_page: 1 } };
}

export async function mockGetMessages(convId, { beforeId, limit = 30, updatedSince } = {}) {
    await delay();
    const rows = store.get(convId) ?? [];
    const server_time = now();
    if (updatedSince) {
        const messages = rows.filter((r) => r.u >= updatedSince).map((r) => structuredClone(r.m));
        return { messages, meta: { has_more: false, server_time } };
    }
    const all = rows.map((r) => r.m).sort((a, b) => a.id - b.id);
    const pool = beforeId ? all.filter((m) => m.id < beforeId) : all;
    const page = pool.slice(-limit);
    return {
        messages: structuredClone(page),
        meta: { has_more_before: pool.length > page.length, oldest_id: page[0]?.id ?? null, newest_id: page.at(-1)?.id ?? null, has_more: false, server_time },
    };
}

export async function mockSendMessage(convId, { clientUuid, body, replyToId, attachmentIds }) {
    await delay(250);
    const rows = store.get(convId);
    const dup = rows.find((r) => r.m.client_uuid === clientUuid);
    if (dup) return structuredClone(dup.m); // idempoten (§D.5)
    const ids = attachmentIds ?? [];
    if (!(body ?? "").trim() && ids.length === 0) throw fail(422, "VALIDATION_ERROR", "Pesan kosong.");
    // CC-006 d: lampiran harus belum terlampir, tanpa duplikat, maks 5
    if (new Set(ids).size !== ids.length || ids.length > 5 || ids.some((id) => attachmentsById.get(id)?.message_id !== null)) {
        throw fail(422, "VALIDATION_ERROR", "Lampiran tidak valid.");
    }
    if ((body ?? "").includes("#gagal") && !failedOnce.has(clientUuid)) {
        failedOnce.add(clientUuid);
        throw Object.assign(new Error("Gagal mengirim (simulasi)."), { status: 500 });
    }
    const conv = [...convs.values()].find((c) => c.id === convId);
    const t = now();
    const r = { m: makeMsg(convId, meId(), conv.peer.id, body?.trim() || null, t), u: t };
    r.m.client_uuid = clientUuid;
    r.m.attachments = ids.map((id) => {
        const a = attachmentsById.get(id);
        a.message_id = r.m.id;
        return structuredClone(a);
    });
    const orig = replyToId ? rows.find((x) => x.m.id === replyToId) : null;
    if (orig && !orig.m.deleted_at) {
        r.m.reply_to = { id: orig.m.id, sender_id: orig.m.sender_id, body_preview: (orig.m.body ?? "").slice(0, 100), has_attachments: false, deleted: false };
    }
    rows.push(r);

    // Simulasi realtime server
    setTimeout(() => mockBus.emit("message:new", envelope({ message: structuredClone(r.m) })), 100);
    setTimeout(() => {
        const d = now();
        Object.assign(r.m, { delivered_at: d, status: "delivered", revision: 2 });
        r.u = d;
        mockBus.emit("message:delivery", envelope({ conversation_id: convId, message_ids: [r.m.id], delivered_at: d }));
    }, 700);
    setTimeout(() => {
        const d = now(); // lawan bicara "membaca" pesan saya -> centang biru
        Object.assign(r.m, { read_at: d, status: "read", revision: 3 });
        r.u = d;
        mockBus.emit("message:read", envelope({ conversation_id: convId, reader_id: conv.peer.id, up_to_message_id: r.m.id, read_at: d }));
    }, 1600);
    setTimeout(() => mockBus.emit("typing:update", envelope({ conversation_id: convId, user_id: conv.peer.id, is_typing: true })), 1200);
    setTimeout(() => {
        const rt = now();
        mockBus.emit("typing:update", envelope({ conversation_id: convId, user_id: conv.peer.id, is_typing: false }));
        const rr = { m: makeMsg(convId, conv.peer.id, meId(), "Halo! Ini balasan tiruan.", rt), u: rt };
        rows.push(rr);
        const rn = addNotif(rr.m, conv.peer.name);
        mockBus.emit("message:new", envelope({ message: structuredClone(rr.m) }), () => {});
        emitUnread(convId);
        mockBus.emit("notification:new", envelope({ notification: structuredClone(rn), unread_count: notifUnread() }), () => {});
    }, 2500);
    return structuredClone(r.m);
}

// ---- Fase 3 ----
// §D.7: tandai pesan masuk (recipient = saya) sampai up_to sebagai dibaca; balas angka unread absolut
export async function mockMarkRead(convId, upTo) {
    await delay(120);
    const t = now();
    let n = 0;
    const marked = new Set();
    (store.get(convId) ?? []).forEach((r) => {
        const m = r.m;
        if (m.recipient_id === meId() && m.id <= upTo && !m.read_at) {
            Object.assign(m, { read_at: t, delivered_at: m.delivered_at ?? t, status: "read", revision: m.revision + 1 });
            r.u = t;
            n += 1;
            marked.add(m.id);
        }
    });
    // §D.7: notifikasi untuk pesan-pesan itu otomatis terbaca + notification:read ke semua tab
    const readIds = notifs.filter((x) => marked.has(x.data.message_id) && !x.read_at).map((x) => {
        x.read_at = t;
        return x.id;
    });
    if (readIds.length) setTimeout(() => mockBus.emit("notification:read", envelope({ ids: readIds, all: false, unread_count: notifUnread() })), 50);
    setTimeout(() => emitUnread(convId), 50); // unread:update ke semua tab
    return { conversation_id: convId, read_count: n, unread_count: unreadFor(convId), total_unread: totalUnread(), read_at: t };
}

export async function mockGetUnreadCounts() {
    await delay(80);
    ensureSeed();
    const by = [...store.keys()].map((id) => ({ conversation_id: id, unread_count: unreadFor(id) })).filter((c) => c.unread_count > 0);
    return { total: totalUnread(), by_conversation: by };
}

// ---- Fase 4: notifikasi (§D.8) ----
export async function mockGetNotifications({ unread = false, page = 1, perPage = 20 }) {
    await delay(100);
    ensureSeed();
    const all = notifs.filter((n) => !unread || !n.read_at).sort((a, b) => b.created_at.localeCompare(a.created_at));
    const start = (page - 1) * perPage;
    return {
        notifications: structuredClone(all.slice(start, start + perPage)),
        unreadCount: notifUnread(),
        meta: { page, per_page: perPage, total: all.length, last_page: Math.max(1, Math.ceil(all.length / perPage)) },
    };
}

export async function mockMarkNotificationRead(id) {
    await delay(100);
    const n = notifs.find((x) => x.id === id);
    if (!n) throw Object.assign(new Error("Notifikasi tidak ditemukan."), { status: 404, code: "NOT_FOUND" });
    n.read_at = n.read_at ?? now();
    setTimeout(() => mockBus.emit("notification:read", envelope({ ids: [id], all: false, unread_count: notifUnread() })), 50);
    return { notification: structuredClone(n), unread_count: notifUnread() };
}

export async function mockMarkAllNotificationsRead() {
    await delay(100);
    const t = now();
    let updated = 0;
    notifs.forEach((n) => {
        if (!n.read_at) {
            n.read_at = t;
            updated += 1;
        }
    });
    setTimeout(() => mockBus.emit("notification:read", envelope({ ids: null, all: true, unread_count: 0 })), 50);
    return { updated, unread_count: 0 };
}

// ---- Fase 5: edit, hapus, reaksi (§D.5) ----
const fail = (status, code, message) => Object.assign(new Error(message), { status, code });
function findRec(id) {
    for (const rows of store.values()) {
        const r = rows.find((x) => x.m.id === Number(id));
        if (r) return r;
    }
    throw fail(404, "NOT_FOUND", "Pesan tidak ditemukan.");
}
const ownRec = (id) => {
    const r = findRec(id);
    if (r.m.sender_id !== meId()) throw fail(403, "FORBIDDEN", "Hanya pengirim yang dapat melakukan ini.");
    return r;
};

export async function mockEditMessage(id, body) {
    await delay(150);
    const r = ownRec(id);
    const m = r.m;
    if (m.deleted_at) throw fail(409, "MESSAGE_DELETED", "Pesan sudah dihapus.");
    if (Date.now() - Date.parse(m.created_at) > 15 * 60_000) throw fail(409, "EDIT_WINDOW_EXPIRED", "Waktu edit sudah lewat.");
    if (body === m.body) return structuredClone(m); // teks sama: tanpa event
    const t = now();
    Object.assign(m, { body, edited_at: t, revision: m.revision + 1 });
    r.u = t;
    mockBus.emit("message:updated", envelope({ message: structuredClone(m) }));
    return structuredClone(m);
}

export async function mockDeleteMessage(id) {
    await delay(150);
    const r = ownRec(id);
    const m = r.m;
    if (!m.deleted_at) {
        const t = now();
        Object.assign(m, { body: null, attachments: [], reactions: [], reply_to: null, deleted_at: t, revision: m.revision + 1 });
        r.u = t;
        mockBus.emit("message:deleted", envelope({ conversation_id: m.conversation_id, message_id: m.id, revision: m.revision, deleted_at: t }));
    }
    return structuredClone(m); // tombstone
}

const reactionResult = (m) => ({ message_id: m.id, revision: m.revision, reactions: structuredClone(m.reactions) });
function emitReaction(r) {
    const m = r.m;
    mockBus.emit("message:reaction", envelope({ conversation_id: m.conversation_id, message_id: m.id, revision: m.revision, reactions: structuredClone(m.reactions) }));
}

export async function mockSetReaction(id, emoji) {
    await delay(120);
    const r = findRec(id);
    const m = r.m;
    if (m.deleted_at) throw fail(409, "MESSAGE_DELETED", "Pesan sudah dihapus.");
    if (!(window.AMIGO.reactionEmojis ?? []).includes(emoji)) throw fail(422, "VALIDATION_ERROR", "Emoji tidak diizinkan.");
    m.reactions = [...m.reactions.filter((x) => x.user_id !== meId()), { user_id: meId(), emoji }]; // satu reaksi per user
    m.revision += 1;
    r.u = now();
    emitReaction(r);
    return reactionResult(m);
}

export async function mockClearReaction(id) {
    await delay(120);
    const r = findRec(id);
    const m = r.m;
    if (m.reactions.some((x) => x.user_id === meId())) {
        m.reactions = m.reactions.filter((x) => x.user_id !== meId());
        m.revision += 1;
        r.u = now();
        emitReaction(r);
    }
    return reactionResult(m); // idempoten
}

// ---- Fase 6: lampiran (§D.6, §G, CC-006) ----
const attachmentsById = new Map();
let nextAttId = 1;
const failedUploads = new Set();
const MOCK_MAX_MB = { image: 10, video: 50, audio: 20, file: 20 };

function readDataUrl(file) {
    return new Promise((resolve) => {
        const fr = new FileReader();
        fr.onload = () => resolve(fr.result);
        fr.onerror = () => resolve("");
        fr.readAsDataURL(file);
    });
}

function imageSize(url) {
    return new Promise((resolve) => {
        const img = new Image();
        img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
        img.onerror = () => resolve({ width: null, height: null });
        img.src = url;
    });
}

async function finishUpload(file) {
    const t = file.type || "";
    const kind = t.startsWith("audio/") || /\.weba$/i.test(file.name) ? "audio" : t.startsWith("image/") ? "image" : t.startsWith("video/") ? "video" : "file";
    // §G: svg/html/js/php/exe/sh/bat ditolak (server memeriksa isi file, bukan ekstensi)
    if (/svg|html|javascript|x-msdownload|x-sh/.test(t) || /\.(exe|sh|bat|php|js|html|svg)$/i.test(file.name)) throw fail(415, "UNSUPPORTED_MEDIA_TYPE", "Jenis file tidak diizinkan.");
    if (file.size === 0) throw fail(422, "VALIDATION_ERROR", "File kosong.");
    if (file.size > MOCK_MAX_MB[kind] * 1024 * 1024) throw fail(413, "PAYLOAD_TOO_LARGE", "File terlalu besar.");
    if (/gagal/i.test(file.name) && !failedUploads.has(file.name)) {
        failedUploads.add(file.name);
        throw fail(500, "SERVER_ERROR", "Gagal menyimpan file (simulasi).");
    }
    // hanya mock; di mode asli selalu /api/v1/attachments/{id}/content. Gambar memakai data: (CSP img-src 'self' data:)
    const url = kind === "image" ? await readDataUrl(file) : URL.createObjectURL(file);
    const dims = kind === "image" ? await imageSize(url) : { width: null, height: null };
    const a = {
        id: nextAttId++, message_id: null, kind, original_name: file.name, mime_type: t || "application/octet-stream",
        size_bytes: file.size, width: dims.width, height: dims.height, url, download_url: url, created_at: now(),
    };
    attachmentsById.set(a.id, a);
    return structuredClone(a);
}

export function mockUploadAttachment(file, { onProgress } = {}) {
    let timer = null;
    let rejectFn = null;
    const promise = new Promise((resolve, reject) => {
        rejectFn = reject;
        let p = 0;
        timer = setInterval(async () => {
            p = Math.min(100, p + 25);
            onProgress?.(p);
            if (p < 100) return;
            clearInterval(timer);
            try {
                resolve(await finishUpload(file));
            } catch (e) {
                reject(e);
            }
        }, 120);
    });
    return {
        promise,
        abort: () => {
            clearInterval(timer);
            rejectFn(Object.assign(new Error("aborted"), { aborted: true }));
        },
    };
}

export async function mockDeleteAttachment(id) {
    await delay(100);
    const a = attachmentsById.get(Number(id));
    if (!a) throw fail(404, "NOT_FOUND", "Lampiran tidak ditemukan.");
    if (a.message_id !== null) throw fail(409, "ATTACHMENT_ALREADY_ATTACHED", "Lampiran sudah terlampir pada pesan.");
    attachmentsById.delete(a.id);
}