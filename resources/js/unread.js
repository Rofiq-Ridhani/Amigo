// resources/js/unread.js — unread count (§D.7, §H.4, §H.6). Nilai SELALU dari server dan DIGANTI; tidak dijumlah/dihitung di sini.
import { getConversations, getUnreadCounts } from "./api.js";
import { EV } from "./events.js";

const byConv = new Map(); // conversation_id -> unread_count
const peerByConv = new Map(); // conversation_id -> id lawan bicara (untuk badge di daftar user)
let total = 0;
let onChange = () => {};
let baseTitle = document.title;
let pollTimer = null;
let connectedBefore = false;
let loadingConvs = false;

export const totalUnread = () => total;

export function unreadForPeer(userId) {
    for (const [convId, peerId] of peerByConv) if (peerId === userId) return byConv.get(convId) ?? 0;
    return 0;
}

function notify() {
    document.title = total > 0 ? `(${total > 99 ? "99+" : total}) ${baseTitle}` : baseTitle;
    onChange();
}

function replaceCounts(counts) {
    byConv.clear();
    counts.by_conversation.forEach((c) => byConv.set(c.conversation_id, c.unread_count));
    total = counts.total;
}

async function refreshConversations() {
    if (loadingConvs) return;
    loadingConvs = true;
    try {
        const { conversations } = await getConversations({ perPage: 50 });
        conversations.forEach((c) => peerByConv.set(c.id, c.peer.id));
        notify();
    } catch {
        /* dicoba lagi pada event/refresh berikutnya */
    } finally {
        loadingConvs = false;
    }
}

// §H.6 langkah 1–2: GET /unread-counts + GET /conversations
export async function refreshAll() {
    try {
        const [counts, convs] = await Promise.all([getUnreadCounts(), getConversations({ perPage: 50 })]);
        replaceCounts(counts);
        convs.conversations.forEach((c) => peerByConv.set(c.id, c.peer.id));
        notify();
    } catch {
        /* resync berikutnya akan mencoba lagi */
    }
}

// §E.3 unread:update dan respons POST …/read: angka absolut -> GANTI
export function applyUnread({ conversation_id, unread_count, total_unread }) {
    byConv.set(conversation_id, unread_count);
    total = total_unread;
    notify();
    if (!peerByConv.has(conversation_id)) refreshConversations(); // §E.3: percakapan belum dikenal -> GET /conversations
}

export function registerConversation(conv) {
    peerByConv.set(conv.id, conv.peer.id);
    notify();
}

export function initUnread({ onChange: cb }) {
    onChange = cb;
    baseTitle = document.title;
}

async function pollCounts() {
    try {
        replaceCounts(await getUnreadCounts());
        notify();
    } catch {
        /* abaikan */
    }
}

export function bindUnreadSocket(sock) {
    sock.on(EV.UNREAD_UPDATE, ({ data }) => applyUnread(data));
    sock.on(EV.SYNC_REQUIRED, () => refreshAll());
    sock.onLifecycle("connect", () => {
        if (connectedBefore) refreshAll(); // reconnect (§H.6)
        connectedBefore = true;
        clearInterval(pollTimer);
        pollTimer = setInterval(pollCounts, 60_000); // jaring pengaman §H.6
    });
    sock.onLifecycle("disconnect", () => {
        clearInterval(pollTimer);
        pollTimer = null;
    });
}