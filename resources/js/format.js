// resources/js/format.js — format waktu (server mengirim UTC ISO-8601; konversi ke zona lokal di sini, §0.2)
export function lastSeenText(iso) {
    if (!iso) return "Offline";
    const t = new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(iso));
    return `Terakhir dilihat ${t}`;
}

export function formatTime(iso) {
    const d = new Date(iso);
    const sameDay = d.toDateString() === new Date().toDateString();
    const opts = sameDay ? { hour: "2-digit", minute: "2-digit" } : { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" };
    return new Intl.DateTimeFormat("id-ID", opts).format(d);
}