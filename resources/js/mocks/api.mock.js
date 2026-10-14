// resources/js/mocks/api.mock.js — pengganti api.js saat VITE_USE_MOCKS === "true"
// Tips uji: kirim pesan berisi "#gagal" -> gagal sekali (tombol "Coba lagi"), retry berhasil dengan client_uuid yang sama.
import { MOCK_USERS } from "./data.js";
import { envelope, mockBus } from "./socket.mock.js";

const delay = (ms = 150) => new Promise((r) => setTimeout(r, ms));
const meId = () => window.AMIGO?.currentUser?.id;
const now = () => new Date().toISOString();

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
    id: nextMsgId++, conversation_id: convId, client_uuid: crypto.randomUUID(),
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
        store.get(conv.id).push({ m: makeMsg(conv.id, a.id, meId(), body, t, "delivered"), u: t });
    });
    setTimeout(() => {
        const c = convs.get(b.id) ?? newConv(b);
        const t = now();
        const r = { m: makeMsg(c.id, b.id, meId(), "Hai, ada waktu sebentar?", t), u: t };
        store.get(c.id).push(r);
        mockBus.emit("message:new", envelope({ message: structuredClone(r.m) }), () => {});
        emitUnread(c.id);
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

export async function mockSendMessage(convId, { clientUuid, body }) {
    await delay(250);
    const rows = store.get(convId);
    const dup = rows.find((r) => r.m.client_uuid === clientUuid);
    if (dup) return structuredClone(dup.m); // idempoten (§D.5)
    if (body.includes("#gagal") && !failedOnce.has(clientUuid)) {
        failedOnce.add(clientUuid);
        throw Object.assign(new Error("Gagal mengirim (simulasi)."), { status: 500 });
    }
    const conv = [...convs.values()].find((c) => c.id === convId);
    const t = now();
    const r = { m: makeMsg(convId, meId(), conv.peer.id, body, t), u: t };
    r.m.client_uuid = clientUuid;
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
    setTimeout(() => {
        const rt = now();
        const rr = { m: makeMsg(convId, conv.peer.id, meId(), "Halo! Ini balasan tiruan.", rt), u: rt };
        rows.push(rr);
        mockBus.emit("message:new", envelope({ message: structuredClone(rr.m) }), () => {});
        emitUnread(convId);
    }, 2500);
    return structuredClone(r.m);
}

// ---- Fase 3 ----
// §D.7: tandai pesan masuk (recipient = saya) sampai up_to sebagai dibaca; balas angka unread absolut
export async function mockMarkRead(convId, upTo) {
    await delay(120);
    const t = now();
    let n = 0;
    (store.get(convId) ?? []).forEach((r) => {
        const m = r.m;
        if (m.recipient_id === meId() && m.id <= upTo && !m.read_at) {
            Object.assign(m, { read_at: t, delivered_at: m.delivered_at ?? t, status: "read", revision: m.revision + 1 });
            r.u = t;
            n += 1;
        }
    });
    setTimeout(() => emitUnread(convId), 50); // unread:update ke semua tab
    return { conversation_id: convId, read_count: n, unread_count: unreadFor(convId), total_unread: totalUnread(), read_at: t };
}

export async function mockGetUnreadCounts() {
    await delay(80);
    ensureSeed();
    const by = [...store.keys()].map((id) => ({ conversation_id: id, unread_count: unreadFor(id) })).filter((c) => c.unread_count > 0);
    return { total: totalUnread(), by_conversation: by };
}