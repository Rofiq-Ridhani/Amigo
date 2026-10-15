// resources/js/conversation.js — tampilan percakapan (Fase 2): riwayat, kirim, optimistic UI, ACK, resync
import {
    clearReaction, createConversation, deleteMessage, editMessage, getConversations, getMessages, getUser, markRead, sendMessage, setReaction, deleteAttachment, uploadAttachment,
} from "./api.js";
import { EV } from "./events.js";
import { formatBytes, formatTime, lastSeenText } from "./format.js";
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

const EDIT_WINDOW_MS = 15 * 60_000; // D-09: hanya petunjuk UI; server yang memutuskan (409 EDIT_WINDOW_EXPIRED)
const TYPING_REPEAT_MS = 3000; // §H.3: ulangi typing:start tiap 3 dtk selama mengetik
const TYPING_IDLE_MS = 3000; // typing:stop setelah 3 dtk tidak mengetik
const TYPING_HIDE_MS = 5000; // sembunyikan indikator lawan bicara bila 5 dtk tanpa update
const ERR_TEXT = {
    EDIT_WINDOW_EXPIRED: "Waktu edit (15 menit) sudah lewat.",
    MESSAGE_DELETED: "Pesan ini sudah dihapus.",
    FORBIDDEN: "Hanya pengirim yang dapat melakukan ini.",
    NOT_FOUND: "Pesan tidak ditemukan.",
    VALIDATION_ERROR: "Isi pesan tidak valid.",
    RATE_LIMITED: "Terlalu banyak permintaan. Coba lagi sebentar.",
    SERVICE_UNAVAILABLE: "Layanan sedang tidak tersedia. Coba lagi nanti.",
};
// ui.compose = { mode: "reply" | "edit", uuid } (target = pesan di byUuid)
const ui = { activeUuid: null, focusUuid: null, compose: null, peerTyping: false, peerTypingTimer: null, errorTimer: null };
const typing = { active: false, convId: null, lastSent: 0, stopTimer: null, blockedConv: null, pauseUntil: 0 };
const TYPING_BACKOFF_MS = 15_000; // setelah RATE_LIMITED (Node maks 20 typing:*/menit/socket, §I.9)

// ---- lampiran (§D.6, §G) ----
const DEFAULT_MAX_MB = { image: 10, video: 50, audio: 20, file: 20 }; // default §G bila window.AMIGO.limits kosong
const maxMB = (kind) => window.AMIGO.limits?.max_size_mb?.[kind] ?? DEFAULT_MAX_MB[kind];
const maxPerMessage = () => window.AMIGO.limits?.attachments_per_message ?? 5;
const KIND_LABEL = { image: "Gambar", video: "Video", audio: "Audio", file: "Berkas" };
const UPLOAD_ERR = {
    PAYLOAD_TOO_LARGE: "File terlalu besar.",
    UNSUPPORTED_MEDIA_TYPE: "Jenis file tidak diizinkan atau file rusak.",
    VALIDATION_ERROR: "File kosong, rusak, atau dimensi gambar terlalu besar.",
    RATE_LIMITED: "Terlalu banyak unggahan. Coba lagi sebentar.",
    NETWORK_ERROR: "Tidak dapat terhubung ke server.",
    SERVER_ERROR: "Server bermasalah. Coba lagi.",
    SERVICE_UNAVAILABLE: "Layanan sedang tidak tersedia.",
};
// { key, file, name, size, kind, status: queued|uploading|ready|error, progress, attachment, error, clientRejected, abort, objUrl, barEl, statusEl }
const tray = [];
// Klasifikasi hanya untuk UX (CC-006 b: audio/webm / .weba = audio); server menentukan jenis dari ISI file
function classify(file) {
    const t = file.type || "";
    if (t.startsWith("audio/") || /\.weba$/i.test(file.name)) return "audio";
    if (t.startsWith("image/")) return "image";
    if (t.startsWith("video/")) return "video";
    return "file";
}
const allowList = () => (Array.isArray(window.AMIGO.reactionEmojis) ? window.AMIGO.reactionEmojis : []); // emoji WAJIB dari sini

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

const canAct = (m) => m.id != null && m.deleted_at == null;
const mineReaction = (m) => m.reactions?.find((r) => r.user_id === me())?.emoji ?? null;

function showActionError(err) {
    const el = $("compose-error");
    el.textContent = ERR_TEXT[err?.code] ?? err?.message ?? "Terjadi kesalahan. Coba lagi.";
    el.hidden = false;
    clearTimeout(ui.errorTimer);
    ui.errorTimer = setTimeout(() => (el.hidden = true), 6000);
}

function smallBtn(label, onClick, cls = "text-slate-700") {
    const b = document.createElement("button");
    b.type = "button";
    b.className = `rounded-lg px-2 py-1 text-xs hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 ${cls}`;
    b.textContent = label;
    b.addEventListener("click", onClick);
    return b;
}

// URL lampiran WAJIB same-origin (attachment.url / download_url, butuh cookie login); jangan pakai URL storage langsung
function safeUrl(u) {
    try {
        const x = new URL(u, window.location.origin);
        return x.origin === window.location.origin ? x.href : null;
    } catch {
        return null;
    }
}

function docIcon() {
    const ns = "http://www.w3.org/2000/svg";
    const svg = document.createElementNS(ns, "svg");
    svg.setAttribute("viewBox", "0 0 20 20");
    svg.setAttribute("class", "h-5 w-5");
    svg.setAttribute("fill", "none");
    svg.setAttribute("stroke", "currentColor");
    svg.setAttribute("stroke-width", "1.6");
    svg.setAttribute("stroke-linejoin", "round");
    svg.setAttribute("aria-hidden", "true");
    const path = document.createElementNS(ns, "path");
    path.setAttribute("d", "M5 2.5h6.5L15 6v11.5H5zM11.5 2.5V6H15");
    svg.append(path);
    return svg;
}

function buildFileCard(a, mine, dl) {
    const card = document.createElement("div");
    card.className = (mine ? "bg-white/15" : "bg-slate-50") + " flex w-64 max-w-full items-center gap-3 rounded-lg px-3 py-2";
    const icon = document.createElement("span");
    icon.className = (mine ? "bg-white/20" : "bg-slate-200 text-slate-600") + " flex h-9 w-9 shrink-0 items-center justify-center rounded-lg";
    icon.append(docIcon());
    const info = document.createElement("div");
    info.className = "min-w-0 flex-1";
    const name = document.createElement("p");
    name.className = "truncate text-sm font-medium";
    name.textContent = a.original_name; // teks polos
    name.title = a.original_name;
    const size = document.createElement("p");
    size.className = "text-xs opacity-80";
    size.textContent = formatBytes(a.size_bytes);
    info.append(name, size);
    card.append(icon, info);
    if (dl) {
        const link = document.createElement("a");
        link.href = dl;
        link.setAttribute("download", "");
        link.rel = "noopener";
        link.className = "shrink-0 rounded-lg px-2 py-1 text-xs underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600";
        link.textContent = "Unduh";
        link.setAttribute("aria-label", `Unduh ${a.original_name}`);
        card.append(link);
    }
    return card;
}

// §G Preview: image -> <img loading=lazy width height>, video -> <video controls preload=metadata>, audio -> <audio controls>, lainnya -> kartu file
function buildAttachments(m, mine) {
    const wrap = document.createElement("div");
    wrap.className = "mt-2 flex flex-col gap-2 first:mt-0";
    m.attachments.forEach((a) => {
        const url = safeUrl(a.url);
        const dl = safeUrl(a.download_url);
        let el;
        if (a.kind === "image" && url) {
            el = document.createElement("a");
            el.href = url;
            el.target = "_blank";
            el.rel = "noopener";
            const img = document.createElement("img");
            img.loading = "lazy";
            img.src = url;
            img.alt = a.original_name;
            if (a.width && a.height) {
                img.width = a.width; // dari metadata agar tata letak tidak loncat
                img.height = a.height;
            }
            img.className = "h-auto w-full max-w-xs rounded-lg bg-black/5";
            el.append(img);
        } else if (a.kind === "video" && url) {
            el = document.createElement("video");
            el.controls = true;
            el.preload = "metadata";
            el.playsInline = true;
            el.src = url;
            el.className = "max-h-72 w-full max-w-xs rounded-lg bg-black";
        } else if (a.kind === "audio" && url) {
            el = document.createElement("audio");
            el.controls = true;
            el.preload = "metadata";
            el.src = url;
            el.className = "w-64 max-w-full";
        } else {
            el = buildFileCard(a, mine, dl);
        }
        wrap.append(el);
    });
    return wrap;
}

function buildReactions(m) {
    const counts = new Map();
    m.reactions.forEach((r) => counts.set(r.emoji, (counts.get(r.emoji) ?? 0) + 1));
    const mineEmoji = mineReaction(m);
    const wrap = document.createElement("div");
    wrap.className = "flex flex-wrap gap-1";
    counts.forEach((n, emoji) => {
        const isMine = emoji === mineEmoji;
        const b = document.createElement("button");
        b.type = "button";
        b.className = (isMine ? "border-brand-600 bg-brand-50" : "border-slate-200 bg-white") + " rounded-full border px-2 py-0.5 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600";
        b.textContent = n > 1 ? `${emoji} ${n}` : emoji;
        b.setAttribute("aria-label", `Reaksi ${emoji}, ${n} orang${isMine ? ", termasuk Anda" : ""}`);
        b.setAttribute("aria-pressed", String(isMine));
        b.addEventListener("click", () => toggleReaction(m.client_uuid, emoji));
        wrap.append(b);
    });
    return wrap;
}

function buildActions(m, mine) {
    const bar = document.createElement("div");
    bar.className = "flex flex-wrap items-center gap-0.5 rounded-xl border border-slate-200 bg-white p-1 shadow-sm";
    const mineEmoji = mineReaction(m);
    allowList().forEach((emoji) => {
        const b = document.createElement("button");
        b.type = "button";
        b.className = (emoji === mineEmoji ? "bg-brand-100" : "hover:bg-slate-100") + " rounded-lg px-1.5 py-1 text-base focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600";
        b.textContent = emoji;
        b.setAttribute("aria-label", `Reaksi ${emoji}`);
        b.setAttribute("aria-pressed", String(emoji === mineEmoji));
        b.addEventListener("click", () => toggleReaction(m.client_uuid, emoji));
        bar.append(b);
    });
    bar.append(smallBtn("Balas", () => startReply(m.client_uuid)));
    if (mine && m.body && Date.now() - Date.parse(m.created_at) < EDIT_WINDOW_MS) bar.append(smallBtn("Edit", () => startEdit(m.client_uuid)));
    if (mine) bar.append(smallBtn("Hapus", () => removeMessage(m.client_uuid), "text-red-700"));
    return bar;
}

function buildItem(m) {
    const mine = m.sender_id === me();
    const deleted = m.deleted_at != null;
    const li = document.createElement("li");
    li.className = mine ? "flex justify-end" : "flex justify-start";
    li.dataset.uuid = m.client_uuid;
    if (m.id != null) li.dataset.id = String(m.id);
    if (!mine && m.id != null && !m.read_at && !deleted) li.dataset.unread = "1"; // kandidat "dibaca"

    const col = document.createElement("div");
    col.className = (mine ? "items-end" : "items-start") + " flex min-w-0 max-w-[85%] flex-col gap-1 md:max-w-[70%]";
    const row = document.createElement("div");
    row.className = (mine ? "flex-row-reverse" : "flex-row") + " flex max-w-full items-center gap-1";

    const bubble = document.createElement("div");
    bubble.className = (mine ? "bg-brand-600 text-white" : "border border-slate-200 bg-white text-slate-900") + " min-w-0 rounded-2xl px-3 py-2 text-sm";

    if (m.reply_to && !deleted) {
        const q = document.createElement("p");
        q.className = mine ? "mb-1 truncate border-l-2 border-white/60 pl-2 text-xs text-white/80" : "mb-1 truncate border-l-2 border-slate-300 pl-2 text-xs text-slate-500";
        q.textContent = m.reply_to.deleted ? "Pesan dihapus" : (m.reply_to.body_preview ?? "Lampiran");
        bubble.append(q);
    }

    const hasAtt = !deleted && m.attachments?.length > 0;
    if (deleted || m.body || !hasAtt) {
        const p = document.createElement("p");
        p.className = "whitespace-pre-wrap break-words";
        if (deleted) {
            p.textContent = "Pesan dihapus";
            p.classList.add("italic", "opacity-70");
        } else {
            p.textContent = m.body ?? "";
        }
        bubble.append(p);
    }
    if (hasAtt) bubble.append(buildAttachments(m, mine));

    const meta = document.createElement("p");
    meta.className = mine ? "mt-1 flex items-center justify-end gap-2 text-[11px] text-white/80" : "mt-1 flex items-center gap-2 text-[11px] text-slate-400";
    const time = document.createElement("span");
    time.textContent = formatTime(m.created_at);
    meta.append(time);
    if (m.edited_at && !deleted) {
        const ed = document.createElement("span");
        ed.textContent = "diedit";
        meta.append(ed);
    }
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
            const drop = document.createElement("button");
            drop.type = "button";
            drop.className = "underline focus:outline-none focus-visible:ring-2 focus-visible:ring-white";
            drop.textContent = "Buang";
            drop.addEventListener("click", () => {
                byUuid.delete(m.client_uuid);
                render();
            });
            meta.append(drop);
        }
    }
    bubble.append(meta);
    row.append(bubble);

    if (canAct(m)) {
        const open = ui.activeUuid === m.client_uuid;
        const more = document.createElement("button");
        more.type = "button";
        more.dataset.more = "1";
        more.className = "shrink-0 rounded-full px-2 py-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600";
        more.textContent = "⋯";
        more.setAttribute("aria-label", "Aksi pesan");
        more.setAttribute("aria-expanded", String(open));
        more.addEventListener("click", () => {
            ui.activeUuid = open ? null : m.client_uuid;
            ui.focusUuid = m.client_uuid;
            render();
        });
        row.append(more);
    }
    col.append(row);
    if (!deleted && m.reactions?.length) col.append(buildReactions(m));
    if (canAct(m) && ui.activeUuid === m.client_uuid) col.append(buildActions(m, mine));
    li.append(col);
    return li;
}

function render({ bottom = false } = {}) {
    const sc = $("message-scroll");
    const nearBottom = sc.scrollHeight - sc.scrollTop - sc.clientHeight < 80;
    const list = $("message-list");
    list.replaceChildren(...sorted().map(buildItem));
    setMsgState(byUuid.size === 0 ? "Belum ada pesan. Sapa duluan!" : "");
    if (ui.focusUuid) {
        [...list.children].find((li) => li.dataset.uuid === ui.focusUuid)?.querySelector("[data-more]")?.focus();
        ui.focusUuid = null;
    }
    if (bottom || nearBottom) sc.scrollTop = sc.scrollHeight;
    updateComposeBar();
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
    $("peer-status").textContent = ui.peerTyping ? "sedang mengetik…" : isOnline(current.peer.id) ? "Online" : lastSeenText(current.peer.last_seen_at);
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
    resetTransient();
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
    resetTransient();
    clearTimeout(readTimer);
    tokenSeq += 1;
    current = null;
    byUuid.clear();
    show(false);
    setUrl(null);
    onActive(null);
}

// Deep-link /chat?conversation={id} (§B.3); memakai GET /conversations (hanya halaman 1)
export const currentConversationId = () => current?.id ?? null;

// Dipakai deep-link dan klik notifikasi. Jika percakapan tidak ada di 50 teratas tetapi id pengirim diketahui,
// ambil user (GET /users/{id}) lalu POST /conversations (idempoten) untuk membukanya.
export async function openConversationById(id, peerId = null) {
    if (current?.id === id) {
        show(true);
        return;
    }
    try {
        const { conversations } = await getConversations({ perPage: 50 });
        const c = conversations.find((x) => x.id === id);
        if (c) return openPeer(c.peer, c);
        if (peerId) return openPeer(await getUser(peerId));
    } catch {
        /* abaikan: halaman tetap menampilkan daftar pengguna */
    }
}

export function openFromQuery() {
    const id = Number(new URLSearchParams(window.location.search).get("conversation"));
    if (id) openConversationById(id);
}

// ---------- kirim (optimistic UI, §H.7) ----------
async function submit(text, replyTo = null, atts = []) {
    const body = text.trim();
    if (!current?.id || (!body && atts.length === 0) || body.length > MAX_LEN) return;
    const m = {
        id: null, client_uuid: crypto.randomUUID(), conversation_id: current.id, sender_id: me(), recipient_id: current.peer.id,
        body: body || null,
        reply_to: replyTo
            ? {
                id: replyTo.id, sender_id: replyTo.sender_id, body_preview: replyTo.body ? replyTo.body.slice(0, 100) : null,
                has_attachments: (replyTo.attachments?.length ?? 0) > 0, deleted: false,
            }
            : null,
        attachments: atts.map((a) => ({ ...a })), reactions: [], status: "sending", delivered_at: null, read_at: null,
        edited_at: null, deleted_at: null, revision: 0, created_at: new Date().toISOString(),
    };
    byUuid.set(m.client_uuid, m);
    render({ bottom: true });
    await post(m);
}

async function post(m) {
    const tk = current?.token;
    try {
        const saved = await sendMessage(m.conversation_id, { clientUuid: m.client_uuid, body: m.body, replyToId: m.reply_to?.id, attachmentIds: m.attachments.map((a) => a.id) });
        if (current?.token !== tk) return; // pindah percakapan; pesan sudah tersimpan di server
        upsert(saved); // ganti pesan optimistik (atau tidak berubah jika event message:new sudah lebih dulu)
    } catch {
        if (current?.token !== tk) return;
        const cur = byUuid.get(m.client_uuid);
        if (cur && cur.id == null) cur.status = "failed";
    }
    render();
}

// ---------- lampiran: unggah dua langkah (§G) ----------
function statusText(i) {
    const size = formatBytes(i.size);
    if (i.status === "queued") return `${size} · Menunggu…`;
    if (i.status === "uploading") return `${size} · Mengunggah ${i.progress}%`;
    if (i.status === "ready") return `${size} · Siap dikirim`;
    return i.error ?? "Gagal mengunggah.";
}

function updateProgress(i) {
    if (i.barEl) i.barEl.style.width = `${i.progress}%`;
    if (i.statusEl) i.statusEl.textContent = statusText(i);
}

function buildTrayItem(i) {
    const li = document.createElement("li");
    li.className = (i.status === "error" ? "border-red-300" : "border-slate-200") + " flex w-full items-center gap-2 rounded-lg border bg-white px-2 py-1.5 text-xs sm:w-64";
    if (i.objUrl) {
        const img = document.createElement("img");
        img.src = i.objUrl;
        img.alt = "";
        img.className = "h-9 w-9 shrink-0 rounded object-cover";
        li.append(img);
    } else {
        const ic = document.createElement("span");
        ic.className = "flex h-9 w-9 shrink-0 items-center justify-center rounded bg-slate-100 text-[10px] font-semibold text-slate-600";
        ic.textContent = KIND_LABEL[i.kind];
        li.append(ic);
    }
    const info = document.createElement("div");
    info.className = "min-w-0 flex-1";
    const name = document.createElement("p");
    name.className = "truncate font-medium text-slate-900";
    name.textContent = i.name; // teks polos
    const st = document.createElement("p");
    st.className = i.status === "error" ? "text-red-700" : "text-slate-500";
    st.textContent = statusText(i);
    if (i.status === "error") st.setAttribute("role", "alert");
    i.statusEl = st;
    info.append(name, st);
    i.barEl = null;
    if (i.status === "uploading") {
        const track = document.createElement("div");
        track.className = "mt-1 h-1 overflow-hidden rounded bg-slate-200";
        const fill = document.createElement("div");
        fill.className = "h-full bg-brand-600";
        fill.style.width = `${i.progress}%`;
        track.append(fill);
        i.barEl = fill;
        info.append(track);
    }
    li.append(info);
    if (i.status === "error" && !i.clientRejected) {
        const retryBtn = smallBtn("Coba lagi", () => retryItem(i));
        retryBtn.setAttribute("aria-label", `Coba lagi mengunggah ${i.name}`);
        li.append(retryBtn);
    }
    const del = smallBtn("Hapus", () => removeItem(i), "text-slate-600");
    del.setAttribute("aria-label", `Hapus lampiran ${i.name}`);
    li.append(del);
    return li;
}

function renderTray() {
    const ul = $("attach-tray");
    ul.replaceChildren(...tray.map(buildTrayItem));
    ul.hidden = tray.length === 0;
}

function addFiles(files) {
    if (!current?.id || ui.compose?.mode === "edit") return;
    [...files].forEach((file) => {
        const kind = classify(file);
        const item = {
            key: crypto.randomUUID(), file, name: file.name, size: file.size, kind, status: "queued", progress: 0,
            attachment: null, error: null, clientRejected: false, abort: null, barEl: null, statusEl: null,
            objUrl: kind === "image" ? URL.createObjectURL(file) : null,
        };
        // Cek client hanya untuk UX; server tetap menolak dengan 413 / 415 / 422
        const reject = (msg) => {
            item.status = "error";
            item.clientRejected = true;
            item.error = msg;
        };
        if (tray.filter((x) => !x.clientRejected).length >= maxPerMessage()) reject(`Maksimal ${maxPerMessage()} lampiran per pesan.`);
        else if (file.size === 0) reject("File kosong.");
        else if (file.size > maxMB(kind) * 1024 * 1024) reject(`File terlalu besar (maks ${maxMB(kind)} MB untuk ${KIND_LABEL[kind].toLowerCase()}).`);
        tray.push(item);
    });
    renderTray();
    pumpUploads();
}

// Unggah satu per satu (batas upload 10/menit, §I.9)
function pumpUploads() {
    if (tray.some((i) => i.status === "uploading")) return;
    const next = tray.find((i) => i.status === "queued");
    if (next) startUpload(next);
}

function startUpload(item) {
    item.status = "uploading";
    item.progress = 0;
    item.error = null;
    renderTray();
    const up = uploadAttachment(item.file, {
        onProgress: (p) => {
            item.progress = p;
            updateProgress(item);
        },
    });
    item.abort = up.abort;
    up.promise
        .then(
            (att) => {
                if (!tray.includes(item)) {
                    deleteAttachment(att.id).catch(() => {}); // dihapus pengguna saat mengunggah
                    return;
                }
                item.attachment = att;
                item.status = "ready";
            },
            (err) => {
                if (err?.aborted || !tray.includes(item)) return;
                item.status = "error";
                item.error = UPLOAD_ERR[err.code] ?? err.message ?? "Gagal mengunggah.";
            },
        )
        .finally(() => {
            item.abort = null;
            renderTray();
            pumpUploads();
        });
}

function retryItem(item) {
    item.status = "queued"; // hanya file ini
    item.error = null;
    renderTray();
    pumpUploads();
}

function removeItem(item) {
    const i = tray.indexOf(item);
    if (i === -1) return;
    tray.splice(i, 1);
    if (item.objUrl) URL.revokeObjectURL(item.objUrl);
    item.abort?.();
    if (item.status === "ready" && item.attachment) deleteAttachment(item.attachment.id).catch(() => {}); // DELETE /attachments/{id}
    renderTray();
    pumpUploads();
}

// cleanup=true: batalkan unggahan & hapus lampiran pending di server; false: lampiran sudah dipakai pesan
function clearTray(cleanup) {
    tray.splice(0).forEach((item) => {
        if (item.objUrl) URL.revokeObjectURL(item.objUrl);
        if (!cleanup) return;
        item.abort?.();
        if (item.status === "ready" && item.attachment) deleteAttachment(item.attachment.id).catch(() => {});
    });
    renderTray();
}

// ---------- reply / edit lewat composer ----------
function autosizeInput() {
    const i = $("composer-input");
    i.style.height = "auto";
    i.style.height = `${Math.min(i.scrollHeight, 128)}px`;
}

function updateComposeBar() {
    $("attach-btn").disabled = ui.compose?.mode === "edit"; // lampiran tidak bisa diubah saat edit
    const c = ui.compose;
    const bar = $("compose-context");
    if (!c) {
        bar.hidden = true;
        return;
    }
    const t = byUuid.get(c.uuid);
    if (!t || t.deleted_at != null) {
        cancelCompose(c.mode === "edit"); // target hilang/terhapus
        return;
    }
    bar.hidden = false;
    $("compose-label").textContent = c.mode === "edit" ? "Mengedit pesan" : `Membalas ${t.sender_id === me() ? "diri sendiri" : (current?.peer.name ?? "")}`;
    $("compose-preview").textContent = (t.body ?? (t.attachments?.length ? "Lampiran" : "")).slice(0, 100);
}

function cancelCompose(clearInput) {
    ui.compose = null;
    if (clearInput) {
        $("composer-input").value = "";
        autosizeInput();
    }
    updateComposeBar();
}

function startReply(uuid) {
    if (ui.compose?.mode === "edit") cancelCompose(true);
    ui.activeUuid = null;
    ui.compose = { mode: "reply", uuid };
    render();
    $("composer-input").focus();
}

function startEdit(uuid) {
    const m = byUuid.get(uuid);
    if (!m || m.id == null || m.deleted_at != null) return;
    ui.activeUuid = null;
    ui.compose = { mode: "edit", uuid };
    const i = $("composer-input");
    i.value = m.body ?? "";
    autosizeInput();
    render();
    i.focus();
    i.setSelectionRange(i.value.length, i.value.length);
}

async function submitEdit(c, text) {
    const m = byUuid.get(c.uuid);
    const body = text.trim();
    if (!m || !body || body.length > MAX_LEN) return;
    if (body === m.body) {
        cancelCompose(true);
        return;
    }
    try {
        upsert(await editMessage(m.id, body)); // §D.5: terapkan hanya jika revision >= lokal (upsert)
        cancelCompose(true);
    } catch (err) {
        showActionError(err);
        if (err.code === "EDIT_WINDOW_EXPIRED" || err.code === "MESSAGE_DELETED") cancelCompose(true);
    }
    render();
}

async function removeMessage(uuid) {
    const m = byUuid.get(uuid);
    if (!m || m.id == null || !window.confirm("Hapus pesan ini untuk semua orang?")) return;
    ui.activeUuid = null;
    try {
        upsert(await deleteMessage(m.id)); // tombstone dari server (revision lebih tinggi)
    } catch (err) {
        showActionError(err);
    }
    render();
}

// ---------- reaksi ----------
// §E.3: ganti SELURUH array reaksi bila revision >= nilai lokal
function applyReaction({ message_id, revision, reactions }) {
    for (const m of byUuid.values()) {
        if (m.id === message_id) {
            if (revision >= (m.revision ?? 0)) {
                m.reactions = reactions;
                m.revision = revision;
            }
            return;
        }
    }
}

async function toggleReaction(uuid, emoji) {
    const m = byUuid.get(uuid);
    if (!m || m.id == null || m.deleted_at != null) return;
    if (!allowList().includes(emoji)) return; // emoji WAJIB persis dari window.AMIGO.reactionEmojis
    ui.activeUuid = null;
    try {
        const res = mineReaction(m) === emoji ? await clearReaction(m.id) : await setReaction(m.id, emoji);
        applyReaction({ ...res, message_id: m.id });
    } catch (err) {
        showActionError(err);
    }
    render();
}

// ---------- typing (§E.4, §H.3) ----------
// ACK {ok:true} | {ok:false,error:{code}} diabaikan: typing hanya petunjuk UI, tidak disimpan
function emitTyping(event, convId) {
    sock?.emit(event, { conversation_id: convId }, (res) => {
        if (res?.ok !== false) return;
        const code = res.error?.code; // VALIDATION_ERROR | FORBIDDEN | RATE_LIMITED | UNAVAILABLE (CC-005 e)
        if (code === "FORBIDDEN" || code === "VALIDATION_ERROR") typing.blockedConv = convId; // bukan peserta/payload salah: berhenti untuk percakapan ini
        else if (code === "RATE_LIMITED") typing.pauseUntil = Date.now() + TYPING_BACKOFF_MS; // kurangi pengiriman sementara
        // UNAVAILABLE: abaikan; typing hanya petunjuk UI
    });
}

function onTypingInput() {
    if (!current?.id || ui.compose?.mode === "edit" || typing.blockedConv === current.id) return;
    if ($("composer-input").value.trim() === "") {
        stopTyping();
        return;
    }
    const now = Date.now();
    if (typing.active && typing.convId !== current.id) stopTyping();
    if (now >= typing.pauseUntil && (!typing.active || now - typing.lastSent >= TYPING_REPEAT_MS)) {
        emitTyping(EV.TYPING_START, current.id);
        typing.active = true;
        typing.convId = current.id;
        typing.lastSent = now;
    }
    clearTimeout(typing.stopTimer);
    typing.stopTimer = setTimeout(stopTyping, TYPING_IDLE_MS);
}

function stopTyping() {
    clearTimeout(typing.stopTimer);
    if (typing.active) emitTyping(EV.TYPING_STOP, typing.convId);
    typing.active = false;
}

function hidePeerTyping() {
    clearTimeout(ui.peerTypingTimer);
    if (ui.peerTyping) {
        ui.peerTyping = false;
        refreshPeerStatus();
    }
}

function onTypingUpdate({ data }) {
    if (!current || data.conversation_id !== current.id || data.user_id !== current.peer.id) return;
    if (!data.is_typing) {
        hidePeerTyping();
        return;
    }
    ui.peerTyping = true;
    refreshPeerStatus();
    clearTimeout(ui.peerTypingTimer);
    ui.peerTypingTimer = setTimeout(hidePeerTyping, TYPING_HIDE_MS); // §H.3: auto-sembunyi 5 dtk tanpa update
}

// berganti/menutup percakapan: typing:stop, buang menu aksi & mode reply/edit
function resetTransient() {
    stopTyping();
    typing.blockedConv = null;
    clearTray(true);
    hidePeerTyping();
    ui.activeUuid = null;
    cancelCompose(ui.compose?.mode === "edit");
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
    if (!current?.id || document.visibilityState !== "visible" || !document.hasFocus()) return; // §H.2: tab terlihat + jendela fokus
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
            if (m.recipient_id === me() && m.id != null && m.id <= upTo && !m.read_at) m.read_at = res.read_at ?? new Date().toISOString(); // CC-003(a): read_at null jika sudah terbaca di tab lain
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
    input.addEventListener("input", () => {
        autosizeInput();
        onTypingInput();
    });
    input.addEventListener("keydown", (e) => {
        if (e.key === "Enter" && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            $("composer").requestSubmit();
        } else if (e.key === "Escape" && ui.compose) {
            cancelCompose(ui.compose.mode === "edit");
        }
    });
    $("composer").addEventListener("submit", (e) => {
        e.preventDefault();
        if (!current?.id) return;
        const v = input.value;
        const c = ui.compose;
        if (c?.mode === "edit") {
            if (v.trim()) submitEdit(c, v);
            return;
        }
        if (tray.some((i) => i.status === "queued" || i.status === "uploading")) {
            showActionError({ message: "Tunggu sampai unggahan selesai." });
            return;
        }
        if (tray.some((i) => i.status === "error")) {
            showActionError({ message: "Ada lampiran yang gagal. Coba lagi atau hapus dulu." });
            return;
        }
        const ready = tray.filter((i) => i.status === "ready").map((i) => i.attachment);
        if (!v.trim() && ready.length === 0) return;
        const replyTo = c?.mode === "reply" ? (byUuid.get(c.uuid) ?? null) : null;
        input.value = "";
        autosizeInput();
        stopTyping(); // §H.3: typing:stop saat kirim pesan
        cancelCompose(false);
        clearTray(false); // lampiran kini milik pesan (tetap pending di server sampai pesan tersimpan)
        submit(v, replyTo, ready);
    });
    $("compose-cancel").addEventListener("click", () => cancelCompose(ui.compose?.mode === "edit"));

    // lampiran: tombol, tempel (paste), dan seret-lepas (drag & drop)
    $("attach-btn").addEventListener("click", () => $("attach-input").click());
    $("attach-input").addEventListener("change", (e) => {
        addFiles(e.target.files);
        e.target.value = "";
    });
    input.addEventListener("paste", (e) => {
        if (e.clipboardData?.files?.length) {
            e.preventDefault();
            addFiles(e.clipboardData.files);
        }
    });
    const view = $("conversation-view");
    view.addEventListener("dragover", (e) => {
        if (e.dataTransfer?.types?.includes("Files")) e.preventDefault();
    });
    view.addEventListener("drop", (e) => {
        if (e.dataTransfer?.files?.length) {
            e.preventDefault();
            addFiles(e.dataTransfer.files);
        }
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
            if (m.sender_id === current.peer.id) hidePeerTyping(); // pesan baru menggantikan indikator
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

    // §E.3: edit — ganti hanya jika revision >= nilai lokal (ditangani upsert)
    sock.on(EV.MESSAGE_UPDATED, ({ data }) => {
        if (data.message.conversation_id !== current?.id) return;
        upsert(data.message);
        render();
    });

    // §E.3: hapus -> tombstone "Pesan dihapus"
    sock.on(EV.MESSAGE_DELETED, ({ data }) => {
        if (data.conversation_id !== current?.id) return;
        for (const m of byUuid.values()) {
            if (m.id === data.message_id && data.revision >= (m.revision ?? 0)) {
                Object.assign(m, { body: null, attachments: [], reactions: [], reply_to: null, deleted_at: data.deleted_at, revision: data.revision });
            }
            if (m.reply_to?.id === data.message_id) m.reply_to = { ...m.reply_to, body_preview: null, deleted: true };
        }
        render();
    });

    // §E.3: SELURUH daftar reaksi diganti bila revision >= nilai lokal (bukan ditambah)
    sock.on(EV.MESSAGE_REACTION, ({ data }) => {
        if (data.conversation_id !== current?.id) return;
        applyReaction(data);
        render();
    });

    sock.on(EV.TYPING_UPDATE, onTypingUpdate);

    sock.on(EV.SYNC_REQUIRED, () => resync());

    sock.onLifecycle("disconnect", () => {
        typing.active = false;
        clearTimeout(typing.stopTimer);
        hidePeerTyping();
    });

    sock.onLifecycle("connect", () => {
        if (connectedBefore) resync();
        else reportDelivered([...byUuid.values()]);
        connectedBefore = true;
    });
}