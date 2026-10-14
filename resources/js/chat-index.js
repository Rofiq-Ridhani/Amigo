// resources/js/chat-index.js — sidebar: daftar user, pencarian, indikator online (Fase 1)
import { USE_MOCKS, getUsers } from "./api.js";
import { connectSocket } from "./socket.js";
import { EV } from "./events.js";

const PER_PAGE = 20;
const online = new Set(); // hanya dari presence:snapshot / presence:update (bukan REST)
const rows = new Map(); // user.id -> { li, user }
const state = { q: "", nextPage: 1, reqId: 0, loading: false };

const $ = (id) => document.getElementById(id);

function lastSeenText(iso) {
    if (!iso) return "Offline";
    const t = new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(iso));
    return `Terakhir dilihat ${t}`;
}

function paintRow(id) {
    const row = rows.get(id);
    if (!row) return;
    const isOnline = online.has(id);
    const dot = row.li.querySelector("[data-dot]");
    dot.classList.toggle("bg-emerald-500", isOnline);
    dot.classList.toggle("bg-slate-300", !isOnline);
    row.li.querySelector("[data-status]").textContent = isOnline ? "Online" : lastSeenText(row.user.last_seen_at);
}

function addRow(user) {
    const li = $("user-item-template").content.firstElementChild.cloneNode(true);
    li.querySelector("[data-avatar]").textContent = (user.name.trim()[0] ?? "?").toUpperCase();
    li.querySelector("[data-name]").textContent = user.name;
    $("user-list").appendChild(li);
    rows.set(user.id, { li, user });
    paintRow(user.id);
}

function setState(text, { button = null } = {}) {
    $("list-state").textContent = text;
    $("list-state").hidden = text === "";
    const btn = $("load-more");
    btn.hidden = button === null;
    if (button) btn.textContent = button;
}

async function loadUsers(reset) {
    if (reset) {
        state.nextPage = 1;
        state.reqId += 1;
        rows.clear();
        $("user-list").replaceChildren();
    }
    const myReq = state.reqId;
    state.loading = true;
    setState("Memuat pengguna…");
    try {
        const { users, meta } = await getUsers({ q: state.q, page: state.nextPage, perPage: PER_PAGE });
        if (myReq !== state.reqId) return; // respons usang (query sudah berganti)
        users.forEach(addRow);
        state.nextPage = meta.page + 1;
        const hasMore = meta.page < meta.last_page;
        if (rows.size === 0) {
            setState(state.q ? "Tidak ada pengguna yang cocok." : "Belum ada pengguna lain.");
        } else {
            setState("", { button: hasMore ? "Muat lebih banyak" : null });
        }
    } catch (err) {
        if (myReq !== state.reqId) return;
        setState(err.message || "Gagal memuat pengguna.", { button: "Coba lagi" });
    } finally {
        if (myReq === state.reqId) state.loading = false;
    }
}

function setConnection(label, ok) {
    $("conn-status").textContent = label;
    $("conn-dot").classList.toggle("bg-emerald-500", ok);
    $("conn-dot").classList.toggle("bg-slate-300", !ok);
}

async function startRealtime() {
    const sock = await connectSocket();
    sock.onLifecycle("connect", () => setConnection("Terhubung", true));
    sock.onLifecycle("disconnect", () => setConnection("Menyambung ulang…", false));
    sock.onLifecycle("connect_error", () => setConnection("Menyambung ulang…", false));

    // §E.3: ganti seluruh state online
    sock.on(EV.PRESENCE_SNAPSHOT, ({ data }) => {
        online.clear();
        data.online_user_ids.forEach((id) => online.add(id));
        rows.forEach((_, id) => paintRow(id));
    });
    // §E.3: perbarui satu user
    sock.on(EV.PRESENCE_UPDATE, ({ data }) => {
        if (data.status === "online") online.add(data.user_id);
        else online.delete(data.user_id);
        const row = rows.get(data.user_id);
        if (row && data.last_seen_at) row.user.last_seen_at = data.last_seen_at;
        paintRow(data.user_id);
    });

    // §D.2: client wajib memutus socket saat logout (form tetap submit normal)
    $("logout-form").addEventListener("submit", () => sock.disconnect());
}

export function initChatIndex() {
    if (USE_MOCKS) $("mock-badge").classList.remove("hidden");

    let timer;
    $("user-search").addEventListener("input", (e) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            const v = e.target.value.trim();
            const next = v.length >= 2 ? v : ""; // server: q min 2 karakter
            if (next === state.q) return;
            state.q = next;
            loadUsers(true);
        }, 300);
    });
    $("load-more").addEventListener("click", () => !state.loading && loadUsers(false));

    loadUsers(true);
    startRealtime().catch(() => setConnection("Tidak terhubung", false));
}