// resources/js/conversation.js — tampilan percakapan (Fase 2): riwayat, kirim, optimistic UI, ACK, resync
import { createConversation, getConversations, getMessages, markRead, sendMessage } from "./api.js";
import { EV } from "./events.js";
import { formatTime, lastSeenText } from "./format.js";
import { applyUnread, registerConversation } from "./unread.js";

const $ = (id) => document.getElementById(id);
const PAGE = 30;
const MAX_LEN = 2000; // §D.5 — hanya untuk UX; validasi sebenarnya di server
const STATUS_TEXT = { sending: "Mengirim…", failed: "Gagal terkirim" };
const TICK = {
    sent: { t: "✓", label: "Terkirim", c: "text-white/70" },
    delivered: { t: "✓✓", label: "Sampai", c: "text-white/70" },
    read: { t: "✓✓", label: "Dibaca", c: "text-sky-200" },
};
const RANK = { sent: 1, delivered: 2, read: 3 }; // status tidak pernah turun (§H.2)

let sock = null;
let isOnline = () => false;
let onActive = () => {};
let current = null; // { id, peer, token, hasMoreBefore, oldestId, serverTime, loadingOlder }
let tokenSeq = 0;
let connectedBefore = false;
let readTimer = null;
const byUuid = new Map(); // client_uuid -> Message (+ status lokal "sending"/"failed" untuk pesan optimistik)
const acked = new Set(); // id pesan masuk yang sudah di-ACK / dilaporkan delivered
const me = () => window.AMIGO.currentUser.id;

// ---------- state ----------
function sorted() {
    const all = [...byUuid.values()];
    return [...all.filter((m) => m.id != null).sort((a, b) => a.id - b.id), ...all.filter((m) => m.id == null)];
}

// §H.7: terapkan hanya jika revision >= nilai lokal; pesan optimistik (id null) selalu diganti
function upsert(m) {
    const old = byUuid.get(m.client_uuid);
    if (old && old.id != null && (m.revision ?? 0) < (old.revision ?? 0)) return;
    const next = { ...m };
    if (old && (RANK[old.status] ?? 0) > (RANK[next.status] ?? 0)) {
        // data usang tidak boleh menurunkan status (mis. respons POST tiba setelah event delivery)
        next.status = old.status;
        next.delivered_at = old.delivered_at ?? next.delivered_at;
        next.read_at = old.read_at ?? next.read_at;
    }
    byUuid.set(m.client_uuid, next);
}

// ---------- render (hanya textContent / createElement) ----------
function setMsgState(text, retry = null) {
    $("msg-state").textContent = text;
    $("msg-state").hidden = text === "";
    const b = $("msg-retry");
    b.hidden = !retry;
    b.onclick = retry;
}

function buildItem(m) {
    const mine = m.sender_id === me();
    const deleted = m.deleted_at != null;
    const li = document.createElement("li");
    li.className = mine ? "flex justify-end" : "flex justify-start";
    if (m.id != null) li.dataset.id = String(m.id);
    if (!mine && m.id != null && !m.read_at && !deleted) li.dataset.unread = "1"; // kandidat "dibaca"

    const bubble = document.createElement("div");
    bubble.className = (mine ? "bg-brand-600 text-white" : "border border-slate-200 bg-white text-slate-900") + " max-w-[85%] rounded-2xl px-3 py-2 text-sm md:max-w-[70%]";

    if (m.reply_to && !deleted) {
        const q = document.createElement("p");
        q.className = mine ? "mb-1 truncate border-l-2 border-white/60 pl-2 text-xs text-white/80" : "mb-1 truncate border-l-2 border-slate-300 pl-2 text-xs text-slate-500";
        q.textContent = m.reply_to.deleted ? "Pesan dihapus" : (m.reply_to.body_preview ?? "Lampiran");
        bubble.append(q);
    }

    const p = document.createElement("p");
    p.className = "whitespace-pre-wrap break-words";
    if (deleted) {
        p.textContent = "Pesan dihapus";
        p.classList.add("italic", "opacity-70");
    } else {
        p.textContent = m.body ?? (m.attachments?.length ? "Lampiran" : "");
    }
    bubble.append(p);

    const meta = document.createElement("p");
    meta.className = mine ? "mt-1 flex items-center justify-end gap-2 text-[11px] text-white/80" : "mt-1 text-[11px] text-slate-400";
    const time = document.createElement("span");
    time.textContent = formatTime(m.created_at);
    meta.append(time);
    if (mine && !deleted) {
        const st = document.createElement("span");
        const tick = TICK[m.status];
        if (tick) {
            st.className = tick.c;
            st.textContent = tick.t;
            st.title = tick.label;
            st.setAttribute("role", "img");
            st.setAttribute("aria-label", tick.label);
        } else {
            st.textContent = STATUS_TEXT[m.status] ?? "";
        }
        meta.append(st);
        if (m.status === "failed") {
            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = "underline focus:outline-none focus-visible:ring-2 focus-visible:ring-white";
            btn.textContent = "Coba lagi";
            btn.addEventListener("click", () => retry(m.client_uuid));
            meta.append(btn);
        }
    }
    bubble.append(meta);
    li.append(bubble);
    return li;
}

function render({ bottom = false } = {}) {
    const sc = $("message-scroll");
    const nearBottom = sc.scrollHeight - sc.scrollTop - sc.clientHeight < 80;
    $("message-list").replaceChildren(...sorted().map(buildItem));
    setMsgState(byUuid.size === 0 ? "Belum ada pesan. Sapa duluan!" : "");
    if (bottom || nearBottom) sc.scrollTop = sc.scrollHeight;
    scheduleRead();
}

function paintHeader() {
    const p = current.peer;
    $("peer-avatar").textContent = (p.name.trim()[0] ?? "?").toUpperCase();
    $("peer-name").textContent = p.name;
    refreshPeerStatus();
}

export function refreshPeerStatus() {
    if (!current) return;
    $("peer-status").textContent = isOnline(current.peer.id) ? "Online" : lastSeenText(current.peer.last_seen_at);
}

export function onPresence(userId, lastSeenAt) {
    if (current?.peer.id === userId && lastSeenAt) current.peer.last_seen_at = lastSeenAt;
    refreshPeerStatus();
}

const updateOlderButton = () => ($("load-older").hidden = !current?.hasMoreBefore);

function show(open) {
    $("chat-root").dataset.open = open ? "true" : "false";
    $("empty-state").hidden = open;
    $("conversation-view").hidden = !open;
}

function setUrl(id) {
    const u = new URL(window.location.href);
    if (id) u.searchParams.set("conversation", id);
    else u.searchParams.delete("conversation");
    window.history.replaceState(null, "", u);
}

// ---------- delivered (§H.2, §E.4) ----------
function reportDelivered(list) {
    if (!sock) return;
    const ids = list.filter((m) => m.recipient_id === me() && m.id != null && !m.delivered_at && !acked.has(m.id)).map((m) => m.id);
    for (let i = 0; i < ids.length; i += 100) {
        const chunk = ids.slice(i, i + 100);
        sock.emit(EV.MESSAGE_DELIVERED, { message_ids: chunk }, (res) => {
            if (res?.ok) chunk.forEach((id) => acked.add(id));
        });
    }
}

// ---------- memuat data ----------
async function loadLatest() {
    const tk = current.token;
    setMsgState("Memuat pesan…");
    try {
        const { messages, meta } = await getMessages(current.id, { limit: PAGE });
        if (current?.token !== tk) return;
        const pending = [...byUuid.values()].filter((m) => m.id == null);
        byUuid.clear();
        messages.forEach(upsert);
        pending.forEach((m) => byUuid.set(m.client_uuid, m));
        current.serverTime = meta.server_time;
        current.hasMoreBefore = meta.has_more_before;
        current.oldestId = meta.oldest_id;
        updateOlderButton();
        render({ bottom: true });
        reportDelivered(messages);
    } catch (err) {
        if (current?.token !== tk) return;
        setMsgState(err.message || "Gagal memuat pesan.", loadLatest);
    }
}

async function loadOlder() {
    if (!current?.hasMoreBefore || current.loadingOlder) return;
    const tk = current.token;
    current.loadingOlder = true;
    try {
        const { messages, meta } = await getMessages(current.id, { beforeId: current.oldestId, limit: PAGE });
        if (current?.token !== tk) return;
        const sc = $("message-scroll");
        const prevHeight = sc.scrollHeight;
        const prevTop = sc.scrollTop;
        messages.forEach(upsert);
        current.hasMoreBefore = meta.has_more_before;
        current.oldestId = meta.oldest_id ?? current.oldestId;
        render();
        sc.scrollTop = sc.scrollHeight - prevHeight + prevTop; // jaga posisi baca
        reportDelivered(messages);
    } catch {
        /* tombol tetap tampil; pengguna bisa mencoba lagi */
    } finally {
        if (current?.token === tk) {
            current.loadingOlder = false;
            updateOlderButton();
        }
    }
}

// §H.6 langkah 3: resync percakapan yang sedang terbuka
async function resync() {
    if (!current?.id) return;
    const tk = current.token;
    if (!current.serverTime) return loadLatest();
    try {
        const since = new Date(Date.parse(current.serverTime) - 5000).toISOString();
        const { messages, meta } = await getMessages(current.id, { updatedSince: since });
        if (current?.token !== tk) return;
        if (meta.has_more) return loadLatest(); // §D.4: buang cache, muat ulang halaman terbaru
        messages.forEach(upsert);
        current.serverTime = meta.server_time;
        render();
        reportDelivered(messages);
    } catch {
        /* resync berikutnya (connect/sync:required) akan mencoba lagi */
    }
}

// ---------- membuka percakapan ----------
async function openPeer(peer, conv = null) {
    const tk = ++tokenSeq;
    current = { id: conv?.id ?? null, peer: { ...peer }, token: tk, hasMoreBefore: false, oldestId: null, serverTime: null, loadingOlder: false, readUpTo: 0 };
    byUuid.clear();
    $("message-list").replaceChildren();
    show(true);
    paintHeader();
    updateOlderButton();
    onActive(peer.id);
    setMsgState("Memuat pesan…");
    try {
        if (!conv) conv = await createConversation(peer.id);
        if (current?.token !== tk) return;
        current.id = conv.id;
        registerConversation(conv);
        current.peer = { ...(conv.peer ?? peer) };
        paintHeader();
        setUrl(conv.id);
        await loadLatest();
        if (window.matchMedia("(min-width: 768px)").matches) $("composer-input").focus();
    } catch (err) {
        if (current?.token !== tk) return;
        setMsgState(err.message || "Gagal membuka percakapan.", () => openPeer(peer, conv));
    }
}

export const openWithUser = (user) => openPeer(user);

function closeConversation() {
    clearTimeout(readTimer);
    tokenSeq += 1;
    current = null;
    byUuid.clear();
    show(false);
    setUrl(null);
    onActive(null);
}

// Deep-link /chat?conversation={id} (§B.3); memakai GET /conversations (hanya halaman 1)
export async function openFromQuery() {
    const id = Number(new URLSearchParams(window.location.search).get("conversation"));
    if (!id) return;
    try {
        const { conversations } = await getConversations({ perPage: 50 });
        const c = conversations.find((x) => x.id === id);
        if (c) openPeer(c.peer, c);
    } catch {
        /* abaikan: halaman tetap menampilkan daftar pengguna */
    }
}

// ---------- kirim (optimistic UI, §H.7) ----------
async function submit(text) {
    const body = text.trim();
    if (!current?.id || !body || body.length > MAX_LEN) return;
    const m = {
        id: null, client_uuid: crypto.randomUUID(), conversation_id: current.id, sender_id: me(), recipient_id: current.peer.id,
        body, reply_to: null, attachments: [], reactions: [], status: "sending", delivered_at: null, read_at: null,
        edited_at: null, deleted_at: null, revision: 0, created_at: new Date().toISOString(),
    };
    byUuid.set(m.client_uuid, m);
    render({ bottom: true });
    await post(m);
}

async function post(m) {
    const tk = current?.token;
    try {
        const saved = await sendMessage(m.conversation_id, { clientUuid: m.client_uuid, body: m.body });
        if (current?.token !== tk) return; // pindah percakapan; pesan sudah tersimpan di server
        upsert(saved); // ganti pesan optimistik (atau tidak berubah jika event message:new sudah lebih dulu)
    } catch {
        if (current?.token !== tk) return;
        const cur = byUuid.get(m.client_uuid);
        if (cur && cur.id == null) cur.status = "failed";
    }
    render();
}

function retry(uuid) {
    const m = byUuid.get(uuid);
    if (!m || m.id != null) return;
    m.status = "sending"; // client_uuid sama -> aman (idempoten)
    render();
    post(m);
}

// ---------- tandai dibaca (§H.2, §D.7) ----------
// id pesan masuk terbaru yang belum dibaca dan SEDANG terlihat di area gulir
function latestVisibleUnreadId() {
    const box = $("message-scroll").getBoundingClientRect();
    let best = 0;
    $("message-list").querySelectorAll("li[data-unread]").forEach((li) => {
        const r = li.getBoundingClientRect();
        if (r.bottom > box.top && r.top < box.bottom) best = Math.max(best, Number(li.dataset.id));
    });
    return best;
}

function scheduleRead() {
    clearTimeout(readTimer);
    readTimer = setTimeout(markReadNow, 500); // debounce 500 ms
}

async function markReadNow() {
    if (!current?.id || document.visibilityState !== "visible") return;
    const upTo = latestVisibleUnreadId();
    if (!upTo || upTo <= current.readUpTo) return;
    const tk = current.token;
    const prev = current.readUpTo;
    current.readUpTo = upTo; // cegah panggilan ganda selama request berjalan
    try {
        const res = await markRead(current.id, upTo);
        applyUnread(res); // §H.4: GANTI angka unread dengan nilai server
        if (current?.token !== tk) return;
        byUuid.forEach((m) => {
            if (m.recipient_id === me() && m.id != null && m.id <= upTo && !m.read_at) m.read_at = res.read_at;
        });
    } catch {
        if (current?.token === tk) current.readUpTo = prev; // boleh dicoba lagi pada pemicu berikutnya
    }
}

// ---------- inisialisasi ----------
export function initConversation({ online, onActive: cb }) {
    isOnline = (id) => online.has(id);
    onActive = cb ?? (() => {});

    const input = $("composer-input");
    const autosize = () => {
        input.style.height = "auto";
        input.style.height = `${Math.min(input.scrollHeight, 128)}px`;
    };
    input.addEventListener("input", autosize);
    input.addEventListener("keydown", (e) => {
        if (e.key === "Enter" && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            $("composer").requestSubmit();
        }
    });
    $("composer").addEventListener("submit", (e) => {
        e.preventDefault();
        if (!current?.id || !input.value.trim()) return;
        const v = input.value;
        input.value = "";
        autosize();
        submit(v);
    });
    $("load-older").addEventListener("click", loadOlder);
    $("back-button").addEventListener("click", closeConversation);
    $("message-scroll").addEventListener("scroll", scheduleRead, { passive: true });
    document.addEventListener("visibilitychange", () => document.visibilityState === "visible" && scheduleRead());
    window.addEventListener("focus", scheduleRead);
}

export function bindSocket(s) {
    sock = s;

    // §E.3/§E.5: ACK = browser menerima event (bukan dibaca)
    sock.on(EV.MESSAGE_NEW, ({ data }, ack) => {
        const m = data.message;
        if (current && m.conversation_id === current.id) {
            upsert(m);
            render();
        }
        if (m.recipient_id === me()) acked.add(m.id);
        if (typeof ack === "function") ack({ ok: true });
    });

    // §E.3: status -> delivered, tidak pernah menurunkan dari read
    sock.on(EV.MESSAGE_DELIVERY, ({ data }) => {
        if (data.conversation_id !== current?.id) return;
        const ids = new Set(data.message_ids);
        let changed = false;
        byUuid.forEach((m) => {
            if (ids.has(m.id) && m.status !== "read") {
                m.status = "delivered";
                m.delivered_at = data.delivered_at;
                changed = true;
            }
        });
        if (changed) render();
    });

    // §E.3: semua pesan SAYA di percakapan itu dengan id <= up_to menjadi "read"
    sock.on(EV.MESSAGE_READ, ({ data }) => {
        if (data.conversation_id !== current?.id) return;
        let changed = false;
        byUuid.forEach((m) => {
            if (m.sender_id === me() && m.id != null && m.id <= data.up_to_message_id && m.status !== "read") {
                m.status = "read";
                m.read_at = data.read_at;
                m.delivered_at = m.delivered_at ?? data.read_at;
                changed = true;
            }
        });
        if (changed) render();
    });

    sock.on(EV.SYNC_REQUIRED, () => resync());

    sock.onLifecycle("connect", () => {
        if (connectedBefore) resync();
        else reportDelivered([...byUuid.values()]);
        connectedBefore = true;
    });
}