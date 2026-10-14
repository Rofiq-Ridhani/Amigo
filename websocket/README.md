# websocket — Server Socket.IO Amigo

Pemilik: **Backend**. Acuan: `AMIGO_TECHNICAL_CONTRACT.md` (§E, §H.1, §D.9). Jika README ini
berbeda dengan kontrak, **kontrak yang benar**.

## Menjalankan
```bash
# jalankan di: dalam container workspace, folder websocket
npm install
npm start          # atau: npm run dev  (restart otomatis)
```
Baca `.env` di ROOT project. Variabel wajib: `SOCKET_SECRET`, `INTERNAL_API_SECRET`,
`SOCKET_ALLOWED_ORIGINS`. Opsional: `SOCKET_PORT` (3001), `LARAVEL_INTERNAL_URL`,
`LARAVEL_INTERNAL_HOST`, `PRESENCE_GRACE_MS` (5000).

Cek hidup: `http://localhost:3001/health`. Uji otomatis: `npm run smoke`.

## Event yang sudah ada (Fase 1B)
| Event | Arah | Payload `data` |
|---|---|---|
| `presence:snapshot` | server → socket yang baru connect | `{ "online_user_ids": [1, 2] }` |
| `presence:update` | server → semua | `{ "user_id": 2, "status": "online"\|"offline", "last_seen_at": null\|"…Z" }` |

Semua event memakai envelope `{ event_id, occurred_at, data }`.
Event pesan, typing, dan notifikasi menyusul di Fase 2 dst.
