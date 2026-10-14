# websocket — Server Socket.IO Amigo

Pemilik: **Backend**. Acuan: `AMIGO_TECHNICAL_CONTRACT.md` (§E, §H, §D.9). Jika README ini
berbeda dengan kontrak, **kontrak yang benar**.

## Menjalankan
```bash
# jalankan di: dalam container workspace, folder websocket
npm install
npm start          # atau: npm run dev  (restart otomatis)
```
Membaca `.env` di ROOT project. Variabel wajib: `SOCKET_SECRET`, `INTERNAL_API_SECRET`,
`SOCKET_ALLOWED_ORIGINS`. Untuk Redis: `REDIS_HOST` (nama service, mis. `redis`), `REDIS_PORT`,
`REDIS_PASSWORD`, `REDIS_CHAT_CHANNEL`, `REDIS_NOTIFICATION_CHANNEL`.
Opsional: `SOCKET_PORT` (3001), `LARAVEL_INTERNAL_URL`, `LARAVEL_INTERNAL_HOST`,
`PRESENCE_GRACE_MS` (5000), `ACK_TIMEOUT_MS` (5000), `INTERNAL_TIMEOUT_MS` (15000).

Cek hidup: `http://localhost:3001/health` (memuat status Redis).

## Skrip uji / bantu
| Perintah | Fungsi |
|---|---|
| `npm run smoke` | Uji autentikasi & presence |
| `npm run smoke:messages` | Uji jalur Redis → Socket.IO (pesan fiktif) |
| `npm run listen -- 2` | Mendengarkan sebagai user 2 dan mencetak semua event (otomatis ACK) |

## Event
Server → client (semua memakai envelope `{ event_id, occurred_at, data }`):

| Event | Penerima | ACK | `data` |
|---|---|---|---|
| `presence:snapshot` | socket yang baru connect | – | `{ online_user_ids: [1, 2] }` |
| `presence:update` | semua | – | `{ user_id, status: "online"\|"offline", last_seen_at }` |
| `message:new` | pengirim + penerima | **ya** (penerima) | `{ message: Message }` |
| `message:delivery` | pengirim pesan | – | `{ conversation_id, message_ids: [..], delivered_at }` |
| `message:read` | pengirim pesan | – | `{ conversation_id, reader_id, up_to_message_id, read_at }` |
| `unread:update` | semua tab satu user | – | `{ conversation_id, unread_count, total_unread }` (angka absolut) |
| `notification:new` | penerima notifikasi | **ya** (hanya dicatat) | `{ notification: Notification, unread_count }` |
| `notification:read` | semua tab satu user | – | `{ ids: [..] \| null, all: false, unread_count }` |
| `sync:required` | semua | – | `{ reason: "redis_reconnected", server_time }` |

Client → server:

| Event | Payload | Balasan ACK |
|---|---|---|
| `message:delivered` | `{ message_ids: [15, 16] }` (1–100 id) | `{ ok: true }` atau `{ ok: false, error: { code, message } }` |

Alur `message:new`: Laravel menyimpan pesan → publish ke Redis → Node meneruskan ke room
`user:{id}` → client penerima membalas ACK `{ ok: true }` → Node memanggil
`/internal/v1/messages/delivered` → Node mengirim `message:delivery` ke pengirim.
Tanpa ACK, status tetap `sent`.
