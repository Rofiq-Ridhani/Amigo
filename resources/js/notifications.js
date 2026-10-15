// resources/js/notifications.js — notifikasi: badge, toast, panel daftar (Fase 4; kontrak §D.8, §E.3, §H.5)
import { getNotifications, markAllNotificationsRead, markNotificationRead } from "./api.js";
import { currentConversationId, openConversationById } from "./conversation.js";
import { EV } from "./events.js";
import { formatTime } from "./format.js";

const $ = (id) => document.getElementById(id);
const TOAST_MS = 6000;
const TOAST_MAX = 3;
const SEEN_MAX = 500;

const state = { items: [], unreadCount: 0, page: 1, hasMore: false, unreadOnly: false, open: false, loading: false, reqId: 0 };
const seen = new Set(); // notification.id yang sudah diproses (dedup toast, §H.7)
const toasts = new Map(); // notification.id -> { el, timer }
let connectedBefore = false;

const badgeText = (n) => (n > 99 ? "99+" : String(n));

// §E.3: badge = unread_count dari server (angka absolut)
function setBadge(n) {
    state.unreadCount = n;
    const badge = $("notif-badge");
    badge.hidden = n === 0;
    badge.textContent = badgeText(n);
    $("notif-toggle").setAttribute("aria-label", n > 0 ? `Notifikasi, ${n} belum dibaca` : "Notifikasi");
    $("notif-read-all").disabled = n === 0;
}

// ---------- toast (title/body hanya lewat textContent) ----------
function dismissToast(id) {
    const t = toasts.get(id);
    if (!t) return;
    clearTimeout(t.timer);
    t.el.remove();
    toasts.delete(id);
}

function showToast(n) {
    if (toasts.has(n.id)) return;
    const el = document.createElement("div");
    el.className = "pointer-events-auto flex w-full max-w-sm items-start gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-lg";
    el.setAttribute("role", "status");

    const main = document.createElement("button");
    main.type = "button";
    main.className = "min-w-0 flex-1 rounded-lg px-3 py-2 text-left hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600";
    const title = document.createElement("span");
    title.className = "block truncate text-sm font-semibold text-slate-900";
    title.textContent = n.title;
    const body = document.createElement("span");
    body.className = "block line-clamp-2 text-sm text-slate-600";
    body.textContent = n.body ?? "";
    main.append(title, body);
    main.addEventListener("click", () => activate(n));

    const close = document.createElement("button");
    close.type = "button";
    close.setAttribute("aria-label", "Tutup notifikasi");
    close.className = "rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600";
    close.textContent = "×";
    close.addEventListener("click", () => dismissToast(n.id));

    el.append(main, close);
    $("toast-root").append(el);
    toasts.set(n.id, { el, timer: setTimeout(() => dismissToast(n.id), TOAST_MS) });
    while (toasts.size > TOAST_MAX) dismissToast(toasts.keys().next().value);
}

// ---------- klik notifikasi: arahkan ke data.url, lalu tandai dibaca ----------
function go(n) {
    let url;
    try {
        url = new URL(n.data?.url ?? "", window.location.origin);
    } catch {
        return;
    }
    if (url.origin !== window.location.origin) return; // hanya URL same-origin
    const convId = Number(url.searchParams.get("conversation"));
    if (url.pathname === "/chat" && convId) openConversationById(convId, n.data?.sender_id ?? null);
    else window.location.assign(url.href);
}

async function markOne(id) {
    try {
        const res = await markNotificationRead(id); // idempoten
        setBadge(res.unread_count);
        const it = state.items.find((x) => x.id === id);
        if (it && res.notification) Object.assign(it, res.notification);
        if (state.open) renderList();
    } catch {
        /* badge akan disinkronkan oleh notification:read / refresh berikutnya */
    }
}

function activate(n) {
    dismissToast(n.id);
    closePanel(false);
    go(n);
    if (!n.read_at) markOne(n.id);
}

// ---------- panel daftar ----------
function setListState(text, button = null) {
    $("notif-state").textContent = text;
    $("notif-state").hidden = text === "";
    $("notif-more").hidden = button === null;
    if (button) $("notif-more").textContent = button;
}

function buildItem(n) {
    const li = $("notif-item-template").content.firstElementChild.cloneNode(true);
    const unread = !n.read_at;
    li.classList.toggle("bg-brand-50", unread);
    li.querySelector("[data-dot]").classList.toggle("bg-brand-600", unread);
    const title = li.querySelector("[data-title]");
    title.textContent = n.title;
    title.classList.toggle("font-semibold", unread);
    title.classList.toggle("font-normal", !unread);
    li.querySelector("[data-body]").textContent = n.body ?? "";
    li.querySelector("[data-time]").textContent = formatTime(n.created_at);
    li.querySelector("[data-open]").addEventListener("click", () => activate(n));
    return li;
}

function renderList() {
    const items = state.unreadOnly ? state.items.filter((n) => !n.read_at) : state.items;
    $("notif-list").replaceChildren(...items.map(buildItem));
    const empty = items.length === 0 && !state.loading;
    setListState(empty ? (state.unreadOnly ? "Tidak ada notifikasi yang belum dibaca." : "Belum ada notifikasi.") : "", state.hasMore ? "Muat lebih banyak" : null);
}

async function loadList(reset) {
    if (reset) {
        state.page = 1;
        state.items = [];
        state.hasMore = false;
        state.reqId += 1;
        $("notif-list").replaceChildren();
    }
    const my = state.reqId;
    state.loading = true;
    setListState("Memuat notifikasi…");
    try {
        const { notifications, unreadCount, meta } = await getNotifications({ unread: state.unreadOnly, page: state.page });
        if (my !== state.reqId) return;
        notifications.forEach((n) => {
            if (!state.items.some((x) => x.id === n.id)) state.items.push(n);
        });
        const pg = meta?.pagination ?? meta; // bentuk meta belum dirinci di §D.8
        state.page = (pg?.page ?? state.page) + 1;
        state.hasMore = pg?.page != null && pg?.last_page != null ? pg.page < pg.last_page : false;
        setBadge(unreadCount);
        state.loading = false;
        renderList();
    } catch (err) {
        if (my !== state.reqId) return;
        state.loading = false;
        setListState(err.message || "Gagal memuat notifikasi.", "Coba lagi");
    }
}

function openPanel() {
    state.open = true;
    $("notif-panel").hidden = false;
    $("notif-toggle").setAttribute("aria-expanded", "true");
    $("notif-close").focus();
    loadList(true);
}

function closePanel(restoreFocus) {
    if (!state.open) return;
    state.open = false;
    $("notif-panel").hidden = true;
    $("notif-toggle").setAttribute("aria-expanded", "false");
    if (restoreFocus) $("notif-toggle").focus();
}

async function markAll() {
    try {
        const res = await markAllNotificationsRead();
        setBadge(res.unread_count);
        [...toasts.keys()].forEach(dismissToast);
        if (state.open) loadList(true); // muat ulang agar nilai read_at berasal dari server
    } catch (err) {
        if (state.open) setListState(err.message || "Gagal menandai semua dibaca.", "Coba lagi");
    }
}

// ---------- event realtime ----------
// §E.3: dedup by notification.id; tanpa toast jika percakapannya terbuka & tab terlihat; ACK SETELAH toast & badge
function onNew({ data }, ack) {
    const n = data.notification;
    const dup = seen.has(n.id);
    if (!dup) {
        seen.add(n.id);
        if (seen.size > SEEN_MAX) seen.delete(seen.values().next().value);
    }
    setBadge(data.unread_count);
    if (!dup) {
        if (!state.items.some((x) => x.id === n.id)) {
            state.items.unshift(n);
            if (state.open) renderList();
        }
        const convId = n.data?.conversation_id;
        const viewing = document.visibilityState === "visible" && convId != null && convId === currentConversationId();
        if (!viewing) showToast(n);
    }
    if (typeof ack === "function") ack({ ok: true });
}

// §E.3: ids null + all true = semua terbaca; berlaku untuk semua tab
function onRead({ data }) {
    const all = data.all === true;
    const ids = new Set(data.ids ?? []);
    const t = new Date().toISOString();
    state.items.forEach((it) => {
        if ((all || ids.has(it.id)) && !it.read_at) it.read_at = t;
    });
    [...toasts.keys()].forEach((id) => {
        if (all || ids.has(id)) dismissToast(id);
    });
    setBadge(data.unread_count);
    if (state.open) renderList();
}

// §H.6 langkah 1: GET /notifications?unread=1 untuk badge
export async function refreshNotifications() {
    try {
        const { unreadCount } = await getNotifications({ unread: true, perPage: 1 });
        setBadge(unreadCount);
        if (state.open) loadList(true);
    } catch {
        /* dicoba lagi pada refresh berikutnya */
    }
}

export function bindNotificationSocket(sock) {
    sock.on(EV.NOTIFICATION_NEW, onNew);
    sock.on(EV.NOTIFICATION_READ, onRead);
    sock.on(EV.SYNC_REQUIRED, () => refreshNotifications());
    sock.onLifecycle("connect", () => {
        if (connectedBefore) refreshNotifications();
        connectedBefore = true;
    });
}

export function initNotifications() {
    $("notif-toggle").addEventListener("click", () => (state.open ? closePanel(true) : openPanel()));
    $("notif-close").addEventListener("click", () => closePanel(true));
    $("notif-read-all").addEventListener("click", markAll);
    $("notif-more").addEventListener("click", () => !state.loading && loadList(false));
    $("notif-unread-only").addEventListener("change", (e) => {
        state.unreadOnly = e.target.checked;
        loadList(true);
    });
    document.addEventListener("keydown", (e) => e.key === "Escape" && state.open && closePanel(true));
    setBadge(0);
    refreshNotifications();
}