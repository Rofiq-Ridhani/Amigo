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

export function formatBytes(n) {
    if (n < 1024) return `${n} B`;
    const nf = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 1 });
    if (n < 1024 * 1024) return `${nf.format(n / 1024)} KB`;
    return `${nf.format(n / 1024 / 1024)} MB`;
}