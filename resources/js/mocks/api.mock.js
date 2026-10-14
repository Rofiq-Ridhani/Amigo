// resources/js/mocks/api.mock.js — pengganti api.js saat VITE_USE_MOCKS === "true"
import { MOCK_USERS } from "./data.js";

const delay = (ms = 150) => new Promise((r) => setTimeout(r, ms));
const meId = () => window.AMIGO?.currentUser?.id;

export async function mockGetMe() {
    await delay();
    const me = MOCK_USERS.find((u) => u.id === meId());
    return { id: meId(), name: window.AMIGO.currentUser.name, email: "mock@example.com", last_seen_at: me?.last_seen_at ?? null };
}

// Mengikuti §D.3: tanpa diri sendiri, urut name asc, q cocok pada name, meta pagination
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