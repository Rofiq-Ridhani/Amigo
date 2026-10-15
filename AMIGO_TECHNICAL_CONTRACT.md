# AMIGO — TECHNICAL CONTRACT

**Contract version:** `0.2.5` · **Status:** siap dipakai Backend + Frontend (semua keputusan terbuka sudah diberi nilai default di §14) · **Bahasa dokumen:** Indonesia, nama teknis tetap Inggris

> Dokumen ini adalah **satu-satunya sumber acuan** untuk AI Backend dan AI Frontend.
> Jika kode dan dokumen bertentangan, **jangan memperbaiki diam-diam di satu sisi** — ikuti prosedur di §0.3.

## 0. Cara Memakai Dokumen Ini

### 0.1 Legenda status

| Tanda | Arti |
|---|---|
| ✅ | Berasal langsung dari materi magang / kebutuhan proyek |
| 🟡 | **Asumsi** — rekomendasi awal, dianggap berlaku sampai ada keputusan lain |
| ❓ | **Terbuka** — saat ini tidak ada; semua sudah diberi default |
| ⏳ | Dijadwalkan fase lanjut / belum masuk MVP |

### 0.1b Status repo (diperiksa 2026-10-12)

Repo `Rofiq-Ridhani/Amigo` adalah **Laravel baru (1 commit)**: PHP `^8.3`, Laravel `^13.17`, PHPUnit `^12.5`, Tailwind `^4` (via `@tailwindcss/vite`, **tanpa** `tailwind.config.js`), Vite `^8`, DB default SQLite, session/queue/cache = `database`, `REDIS_CLIENT=phpredis`, `REDIS_HOST=127.0.0.1`.
Belum ada: auth, controller, model selain `User`, folder `websocket/`, view selain `welcome`, `package.json` tanpa `socket.io-client`. Artinya **semua yang ada di kontrak dibangun baru**; tidak ada kode lama yang harus diadaptasi.
Catatan Docker: `REDIS_HOST` harus diganti ke **nama service Redis** di container (bukan `127.0.0.1`). Jika ekstensi `phpredis` tidak ada (`php -m | grep -i redis` kosong), pakai `composer require predis/predis` dan `REDIS_CLIENT=predis`.

### 0.2 Konvensi global

| Hal | Aturan |
|---|---|
| ID | `id` integer (bigint unsigned) kecuali `notifications.id` = UUID string. Di JSON, integer tetap number. |
| Waktu | Semua timestamp di API/event: **ISO-8601 UTC**, contoh `2026-10-12T02:30:00Z`. Frontend yang mengonversi ke zona lokal. |
| Nama field JSON | `snake_case` |
| Nama event Socket.IO | `domain:aksi` (huruf kecil, titik dua), contoh `message:new` |
| Nama channel Redis | `domain.jamak` dengan titik, contoh `chat.messages` |
| Nama tabel/kolom DB | `snake_case`, tabel jamak |
| Teks pengguna | Selalu dirender sebagai teks (`textContent` / `{{ }}` Blade). **Dilarang** `innerHTML` / `{!! !!}` untuk isi pesan, nama, nama file, notifikasi. |
| Versi API | Prefix `/api/v1` |

### 0.3 Prosedur jika kontrak tidak cocok dengan kebutuhan

1. Identifikasi bagian yang bertentangan (kutip nomor bagian).
2. Jelaskan alasan teknis dan dampak ke backend **dan** frontend.
3. Usulkan perubahan kontrak (diff).
4. Catat di **§14 Change Log** dengan nomor `CC-xxx`.
5. Beri tahu kedua developer; kedua AI menyesuaikan setelah perubahan disetujui.

Selama belum disetujui, AI **tidak boleh** mengganti nama field/event/endpoint sepihak.

---

## A. Technology Stack

| Teknologi | Fungsi di Amigo | Versi | Verifikasi |
|---|---|---|---|
| PHP | Runtime backend | `^8.3` ✅ (dari repo) | — |
| Laravel | Routing, auth session, validasi, API, upload, publish Redis | `^13.17` ✅ (dari repo) | Ikuti versi repo; cek dokumentasi Laravel 13 bila sintaks berbeda dari materi |
| Eloquent ORM | Model & relasi | ikut Laravel | — |
| Database | Sumber data permanen | Default repo: SQLite ✅. MySQL/MariaDB boleh | Pakai yang ada di `.env`. Bila MySQL: **wajib `utf8mb4`** agar emoji tersimpan. |
| Redis | Pub/Sub event + data sementara ber-TTL + rate limit | Redis 6/7 🟡 | `REDIS_HOST` = nama service Redis di Docker, port 6379 |
| Node.js | Runtime server Socket.IO (`websocket/`) | LTS aktif (≥ 20) 🟡 | `node -v` |
| Socket.IO (server & client) | Koneksi realtime, presence, ACK | v4.x 🟡 (**client dan server harus se-mayor**) | `npm i socket.io` di `websocket/`, `npm i socket.io-client` di root |
| Library Redis di Node | Subscribe pattern | `ioredis` 🟡 | — |
| Library JWT di Node & PHP | Token koneksi socket (HS256) | Node: `jsonwebtoken`; PHP: `firebase/php-jwt` 🟡 | — |
| Tailwind CSS, Blade, JS, Vite | UI | Tailwind `^4`, Vite `^8` ✅ | Tailwind 4: konfigurasi lewat `resources/css/app.css` (tanpa `tailwind.config.js`) |
| Laradock / Docker | Lingkungan dev | ✅ | `docker ps`; nama service Redis/Nginx/DB |
| Test framework | Backend test | PHPUnit `^12.5` ✅ | — |

**Langkah verifikasi wajib (Tahap 1 Discovery):** jalankan `php artisan --version`, `node -v`, `npm ls socket.io`, `composer show laravel/framework`, lihat `routes/web.php`, `app/Models`, `database/migrations`, `websocket/server.js`. Jika repo sudah punya implementasi (materi menyebut `AuthController`, `ChatController`, `Message`, `websocket/server.js`), **kontrak ini diadaptasi ke kode yang ada**, bukan membangun dari nol. Bagian yang sudah ada namun berbeda dari kontrak dicatat sebagai `CC-xxx`.

### Variabel environment (kontrak)

```dotenv
# file: .env.example  (root project; websocket/server.js membaca file ini)
APP_URL=http://localhost:8000

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_CHAT_CHANNEL=chat.messages
REDIS_NOTIFICATION_CHANNEL=chat.notifications

SOCKET_URL=http://localhost:3001
SOCKET_PORT=3001
SOCKET_SECRET=            # sama di Laravel & Node; menandatangani token koneksi socket
SOCKET_TOKEN_TTL_SECONDS=300
SOCKET_ALLOWED_ORIGINS=http://localhost:8000

INTERNAL_API_SECRET=      # BEDA dari SOCKET_SECRET; untuk Node -> Laravel /internal/*
LARAVEL_INTERNAL_URL=http://nginx   # sesuaikan nama service web di Docker Anda

CHAT_MESSAGE_MAX_LENGTH=2000
CHAT_EDIT_WINDOW_MINUTES=15
```

---

## B. Role and Ownership

### B.1 Matriks tanggung jawab

| Area | Backend (Anda + AI Backend) | Frontend (teman + AI Frontend) |
|---|---|---|
| Auth, session, CSRF, otorisasi | **Pemilik** | Mengirim form/request sesuai kontrak |
| Validasi & aturan bisnis | **Pemilik** (sumber kebenaran) | Validasi UX saja (tidak menggantikan server) |
| Database, migrasi, model | **Pemilik** | — |
| REST API | **Pemilik** | Konsumen |
| Upload, penyimpanan, akses media | **Pemilik** | UI upload/preview |
| **Server Node.js + Socket.IO** (`websocket/`) | **Pemilik** (auth socket, room, presence, ACK, subscriber Redis) | — |
| **Client Socket.IO** di browser | Menyediakan token & kontrak event | **Pemilik** |
| Redis publish/subscribe | **Pemilik** | — |
| Notifikasi (simpan + publish) | **Pemilik** | Toast, badge, daftar |
| Blade views, Tailwind, JS UI | Menyediakan data bootstrap (§B.3) | **Pemilik** |
| Test | Test backend & server realtime | Test UI/JS; integrasi dikerjakan bersama |

### B.2 Kepemilikan file/folder

| Path | Pemilik | Catatan |
|---|---|---|
| `app/**`, `routes/**`, `database/**`, `config/**`, `tests/**`, `.env.example` | Backend | |
| `websocket/**` | Backend | Termasuk `websocket/README.md` (dokumentasi event harus sinkron dengan §E) |
| `resources/views/**`, `resources/css/**`, `resources/js/**`, `public/**` (aset UI), `vite.config.js` | Frontend | |
| `AMIGO_TECHNICAL_CONTRACT.md`, `docs/contract-fixtures/**` | **Bersama** | Ubah hanya lewat PR berlabel `contract-change`, disetujui kedua developer |
| `package.json` (root), `composer.json` | Backend memegang `composer.json`; Frontend memegang `package.json` root. Penambahan dependency oleh pihak lain → minta lewat PR. | |

**Aturan integrasi**
- Satu file = satu pemilik. Dua AI **tidak boleh** mengedit file yang sama bersamaan.
- Branch: `be/<fitur>` dan `fe/<fitur>`. Merge ke `main` lewat PR; PR yang menyentuh kontrak diberi label `contract-change`.
- Setiap PR backend yang mengubah perilaku API/event wajib menyertakan: contoh request, response, payload, error, dan status "Contract §x: implemented/partial/not yet".
- Frontend yang butuh endpoint/event baru **tidak mengarang**; buat entri di §14 sebagai usulan.
- `docs/contract-fixtures/*.json` berisi contoh payload (salinan contoh di dokumen ini) agar frontend dapat membuat mock tanpa menebak. Mock hanya boleh berada di `resources/js/mocks/` dan harus bisa dimatikan satu flag (`USE_MOCKS`).

### B.3 Titik integrasi Blade ↔ Laravel (data bootstrap)

Backend (`ChatController@index`) menyediakan ke view `chat.index`; Frontend merender:

```html
<!-- file: resources/views/layouts/app.blade.php (Frontend) -->
<meta name="csrf-token" content="{{ csrf_token() }}">
<script>
  window.AMIGO = {
    currentUser: { id: {{ auth()->id() }}, name: @json(auth()->user()->name) },
    apiBase: "/api/v1",
    socketUrl: @json(config('chat.socket_url')),
    reactionEmojis: @json(config('chat.reaction_emojis')),
    limits: @json(config('chat.limits'))
  };
</script>
```

Deep-link halaman chat: `GET /chat?conversation={id}` (Frontend wajib menangani query ini; dipakai notifikasi).

---

## Arsitektur Ringkas

```
Browser A ──HTTP (cookie session + CSRF)──▶ Laravel ──▶ DB (sumber kebenaran)
    ▲  ▲                                       │ setelah commit
    │  └── Socket.IO (token JWT pendek) ──┐    ▼
    │                                     │  Redis Pub/Sub  (chat.messages, chat.notifications)
    └──────────── event ◀── Node.js + Socket.IO ◀── subscribe (pattern)
                              │  ▲
                              │  └─ HTTP internal (HMAC) ─▶ Laravel /internal/v1/* (delivered, presence, participants)
                              └──▶ Browser B (room user:{id})
```

Prinsip: **HTTP = tulis & baca permanen. Socket.IO = pemberitahuan realtime.** Event realtime boleh hilang; database tidak. Setelah reconnect, client **selalu sinkron ulang via REST** (§H.6).

---

## C. Database Contract

Engine/charset: `utf8mb4`, `utf8mb4_unicode_ci` 🟡. Semua FK `ON DELETE` dijelaskan. Penghapusan data pengguna di luar cakupan MVP.

### C.1 `users` (tabel sudah ada dari materi; tambah kolom)

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint unsigned PK | |
| `name` | varchar(100) | |
| `email` | varchar(255) UNIQUE | Tidak pernah dikirim ke user lain (§D) |
| `password` | varchar(255) | cast `hashed` |
| `last_seen_at` | timestamp NULL | **Baru** (latihan materi L2). Diisi Node saat socket terakhir putus. |
| `remember_token`, `created_at`, `updated_at` | standar Laravel | |

### C.2 `conversations` 🟡 (D-02)

Percakapan **1-lawan-1 saja**. Tidak ada grup di v1.

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `user_one_id` | FK → users.id, CASCADE | **Selalu yang lebih kecil** (`user_one_id < user_two_id`) |
| `user_two_id` | FK → users.id, CASCADE | |
| `last_message_id` | bigint unsigned NULL (**tanpa FK database**, lihat CC-002) | Denormalisasi untuk daftar percakapan; dijaga aplikasi |
| `last_message_at` | timestamp NULL | Urutan daftar percakapan |
| `created_at`, `updated_at` | | |

Index/unique: `UNIQUE(user_one_id, user_two_id)`, index `(user_one_id, last_message_at)`, `(user_two_id, last_message_at)`.
Alasan: unread per percakapan, daftar percakapan, dan pengecekan hak akses menjadi satu query sederhana; normalisasi urutan mencegah dua baris untuk pasangan yang sama.

### C.3 `messages`

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | Urutan pesan dalam percakapan = urutan `id` |
| `conversation_id` | FK → conversations.id, CASCADE | |
| `sender_id` | FK → users.id | |
| `recipient_id` | FK → users.id | Denormalisasi (sesuai materi); dipakai query unread |
| `client_uuid` | char(36) | Kunci idempotensi dari client. `UNIQUE(sender_id, client_uuid)` |
| `reply_to_id` | FK → messages.id NULL, `SET NULL` | Pesan yang dibalas |
| `body` | text NULL | NULL jika hanya lampiran. Maks `CHAT_MESSAGE_MAX_LENGTH` (2000) 🟡 D-12 |
| `delivered_at` | timestamp NULL | Lihat §H.2 |
| `read_at` | timestamp NULL | Lihat §H.2 |
| `edited_at` | timestamp NULL | |
| `revision` | unsigned int default 1 | Naik 1 setiap perubahan (edit/hapus/reaksi). Dipakai client untuk membuang event usang. |
| `deleted_at` | timestamp NULL | **Soft delete** → tombstone "Pesan dihapus" |
| `created_at`, `updated_at` | | `updated_at` **wajib** ikut disentuh pada edit/hapus/reaksi/delivered/read (dipakai resync) |

Index: `(conversation_id, id)`, `(recipient_id, read_at, conversation_id)` (unread), `(conversation_id, updated_at)` (resync).
Keputusan 🟡 D-04: status delivered/read disimpan **di baris pesan** — tidak ada tabel receipts karena hanya ada 1 penerima per pesan. Jika grup ditambahkan kelak, migrasi ke tabel `message_receipts` (catat sebagai `CC`).

### C.4 `attachments`

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `message_id` | FK → messages.id NULL, CASCADE | NULL = upload belum dilampirkan (pending) |
| `uploader_id` | FK → users.id | |
| `disk` | varchar(32) | `local` (privat), **bukan** `public` |
| `path` | varchar(255) | Nama acak: `attachments/{Y}/{m}/{uuid}.{ext}` |
| `original_name` | varchar(255) | Hanya untuk tampilan/unduhan, disanitasi |
| `mime_type` | varchar(100) | Hasil deteksi server (bukan klaim client) |
| `kind` | enum(`image`,`video`,`audio`,`file`) | |
| `size_bytes` | bigint unsigned | |
| `width`,`height` | int NULL | Hanya gambar (mencegah layout shift) |
| `created_at`, `updated_at` | | |

Index: `message_id`, `(uploader_id, message_id, created_at)` (pembersihan yatim).

### C.5 `message_reactions` 🟡 (D-08)

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | PK | |
| `message_id` | FK → messages.id, CASCADE | |
| `user_id` | FK → users.id, CASCADE | |
| `emoji` | varchar(16) | Harus ada di allow-list `config('chat.reaction_emojis')` |
| `created_at`, `updated_at` | | |

`UNIQUE(message_id, user_id)` — **satu reaksi per user per pesan**; mengganti emoji = update baris.
Allow-list awal: `👍 ❤️ 😂 😮 😢 🙏`.

### C.6 `notifications`

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | char(36) PK (UUID) | Dipakai client untuk dedup toast |
| `user_id` | FK → users.id, CASCADE | Penerima |
| `type` | varchar(50) | v1: `chat.message`. ⏳ `chat.mention` (D-07) |
| `title` | varchar(255) | |
| `body` | text NULL | Teks polos (preview maks 100 karakter) |
| `data` | json | `{conversation_id, message_id, sender_id, url}` |
| `read_at` | timestamp NULL | |
| `created_at`, `updated_at` | | |

Index: `(user_id, read_at, created_at)`.

### C.7 `outbox_events` ⏳ (opsional, Fase 6 — D-06)

`id`, `channel`, `payload` json, `published_at` NULL, `attempts` int, `created_at`. Ditulis **dalam transaksi yang sama** dengan pesan; lihat §J.

### C.8 Unread count 🟡 (D-05)

**Dihitung lewat query**, bukan counter:
`COUNT(*) FROM messages WHERE recipient_id = :me AND read_at IS NULL AND deleted_at IS NULL [AND conversation_id = :c]`.
Alasan: tidak ada risiko counter melenceng; index `(recipient_id, read_at, conversation_id)` cukup untuk skala magang. Evaluasi ulang jika lambat.

### C.9 Aturan penghapusan

- Hapus pesan = soft delete oleh pengirim; baris tetap ada, `body` tidak lagi dikirim ke client, lampiran dihapus dari storage oleh job (`attachments` row dihapus).
- `notifications` terkait pesan terhapus tetap ada tetapi `data.message_id` bisa tidak valid → client menangani 404 dengan membuka percakapan saja.

---

## D. REST API Contract

### D.0 Aturan umum

- Semua endpoint JSON ada di prefix **`/api/v1`**, didaftarkan di `routes/web.php` dengan middleware `web` + `auth` (autentikasi **session cookie**, bukan token API) 🟡 D-01. Alasan: materi memakai session Laravel; CSRF sudah tersedia.
- **Header wajib dari client** (kecuali dinyatakan lain):

| Header | Nilai |
|---|---|
| `Accept` | `application/json` |
| `X-Requested-With` | `XMLHttpRequest` |
| `X-CSRF-TOKEN` | dari `<meta name="csrf-token">` (semua metode selain GET) |
| `Content-Type` | `application/json` (upload: `multipart/form-data`) |

- Bentuk response sukses/gagal mengikuti **§F**. Tidak ada endpoint yang mengembalikan bentuk lain.
- Pagination: pesan memakai cursor `before_id`; daftar lain memakai `page` & `per_page` (default 20, maks 50).
- **Aturan hak akses resource:** jika user *bukan peserta* percakapan/pesan/lampiran → **404** (bukan 403) agar keberadaan resource tidak bocor (anti-IDOR). 403 dipakai bila resource terlihat tetapi aksi dilarang (mis. mengedit pesan orang lain).
- Rate limit per endpoint di §I.

### D.1 Objek bersama

**User (publik)** — email tidak pernah ikut:
```json
{ "id": 2, "name": "Budi Santoso", "last_seen_at": "2026-10-12T02:10:00Z" }
```

**Attachment**
```json
{
  "id": 7, "message_id": 15, "kind": "image",
  "original_name": "foto.jpg", "mime_type": "image/jpeg", "size_bytes": 234567,
  "width": 1200, "height": 800,
  "url": "/api/v1/attachments/7/content",
  "download_url": "/api/v1/attachments/7/content?download=1",
  "created_at": "2026-10-12T02:30:00Z"
}
```

**Message**
```json
{
  "id": 15,
  "conversation_id": 3,
  "client_uuid": "6f1d1c52-3b0a-4f0c-9a52-0c8a6f3a9a10",
  "sender_id": 1,
  "recipient_id": 2,
  "body": "Halo, apakah sudah selesai?",
  "reply_to": {
    "id": 10, "sender_id": 2, "body_preview": "Besok jam 9 ya", "has_attachments": false, "deleted": false
  },
  "attachments": [],
  "reactions": [ { "user_id": 2, "emoji": "👍" } ],
  "status": "delivered",
  "delivered_at": "2026-10-12T02:30:01Z",
  "read_at": null,
  "edited_at": null,
  "deleted_at": null,
  "revision": 2,
  "created_at": "2026-10-12T02:30:00Z"
}
```
- `reply_to` = `null` jika bukan balasan. `body_preview` maks 100 karakter, teks polos. Jika pesan asal dihapus: `"body_preview": null, "deleted": true`.
- `status` **diturunkan server** dari pengirim: `read` jika `read_at` ada, `delivered` jika `delivered_at` ada, selain itu `sent`. Client tidak menghitung sendiri (kecuali memperbarui dari event `message:delivery`/`message:read`).
- **Tombstone** (pesan dihapus): `body: null`, `attachments: []`, `reactions: []`, `reply_to: null`, `deleted_at` terisi. `id`, `sender_id`, `created_at` tetap ada.

**Conversation**
```json
{
  "id": 3,
  "peer": { "id": 2, "name": "Budi Santoso", "last_seen_at": "2026-10-12T02:10:00Z" },
  "last_message": { "...Message..." },
  "unread_count": 4,
  "last_message_at": "2026-10-12T02:30:00Z",
  "created_at": "2026-10-10T08:00:00Z"
}
```
`last_message` = `null` jika belum ada pesan.

**Notification**
```json
{
  "id": "9f1c2a7e-5d52-4b7e-8a58-1d2f9b8a0c11",
  "type": "chat.message",
  "title": "Pesan baru dari Andi Pratama",
  "body": "Halo, apakah sudah selesai?",
  "data": { "conversation_id": 3, "message_id": 15, "sender_id": 1, "url": "/chat?conversation=3" },
  "read_at": null,
  "created_at": "2026-10-12T02:30:00Z"
}
```
Jika pesan hanya lampiran, `body` = `"Mengirim lampiran"`.

### D.2 Autentikasi

> Form login/register boleh berupa form Blade biasa (redirect). Bila `Accept: application/json`, response mengikuti §F.

| | |
|---|---|
| **POST `/login`** | Auth: tidak perlu · Body: `email` (required,email), `password` (required,string), `remember` (boolean, opsional) |
| Sukses | HTML: regenerasi session + redirect `/chat`. JSON: `200` `{ data: { user: { id, name } } }` |
| Gagal | `422 VALIDATION_ERROR` dengan `details.email: ["Email atau password salah."]` (pesan sama untuk email tak terdaftar & password salah) |
| Efek | `session()->regenerate()`; rate limit 5/menit per email+IP → `429` |
| **POST `/register`** | Body: `name` (required,string,max:100), `email` (required,email,max:255,unique:users), `password` (required,string,min:8,confirmed). Sukses `201` (JSON) / redirect `/chat`. |
| **POST `/logout`** | Auth: ya. Efek: invalidate session + regenerate token. JSON `200` `{ data: null, message: "Logout berhasil" }`; HTML redirect `/login`. Node otomatis memutus socket karena token tak diperbarui; client wajib `socket.disconnect()` saat logout. |
| **GET `/api/v1/me`** | `200` `{ data: { user: { id, name, email, last_seen_at } } }` — satu-satunya tempat `email` dikembalikan (milik sendiri). |
| **GET `/api/v1/socket-token`** | Auth: ya. `200` `{ data: { token: "<jwt>", expires_at: "…Z", socket_url: "http://localhost:3001" } }`. JWT HS256, ditandatangani `SOCKET_SECRET`, klaim: `sub` (user id), `iat`, `exp` (= `SOCKET_TOKEN_TTL_SECONDS`, default 300), `jti`, `aud:"amigo-socket"`. Rate limit 30/menit. Client **harus memanggil ulang** setiap (re)connect (§E.1). |

### D.3 Pengguna

**GET `/api/v1/users`** — daftar pengguna yang bisa diajak chat
- Query: `q` (string, min:2, max:50, opsional; cari pada `name` saja, **bukan email**), `page`, `per_page`.
- Mengecualikan diri sendiri. Urut `name` asc.
- `200`: `{ data: { users: [User…] }, meta: { page, per_page, total, last_page } }`
- Status online **tidak** ada di REST; ambil dari `presence:snapshot`/`presence:update` (§E).

**GET `/api/v1/users/{id}`** — `200 { data: { user: User } }`, `404` jika tidak ada.

### D.4 Percakapan

**GET `/api/v1/conversations`** — percakapan milik saya yang **memiliki ≥ 1 pesan**, urut `last_message_at` desc.
`200`: `{ data: { conversations: [Conversation…] }, meta: { page, per_page, total, last_page } }`

**POST `/api/v1/conversations`** — ambil atau buat percakapan dengan user lain (idempoten)
- Body: `{ "user_id": 2 }` · Rule: `required|integer|exists:users,id|different:<me>`
- `201` bila baru dibuat, `200` bila sudah ada: `{ data: { conversation: Conversation } }` · `422` jika ke diri sendiri.
- Efek DB: insert `conversations` (user id dinormalisasi kecil→besar).

**GET `/api/v1/conversations/{id}/messages`** — riwayat & resync
- Auth: peserta percakapan, selain itu `404`.
- Query (pilih **salah satu** mode):
  - Riwayat: `before_id` (integer, opsional; tanpa ini = halaman terbaru), `limit` (1–100, default 30). Urutan respons: **id naik (lama → baru)**.
  - Resync: `updated_since` (ISO-8601 UTC). Mengembalikan semua pesan (termasuk tombstone) dengan `updated_at >= updated_since`, maks 200.
- `200`: 
```json
{
  "success": true,
  "data": { "messages": [ { "...Message..." } ] },
  "meta": { "has_more_before": true, "oldest_id": 120, "newest_id": 149, "has_more": false, "server_time": "2026-10-12T02:31:00Z" },
  "message": "OK"
}
```
- Resync: bila `has_more: true` client membuang cache percakapan lalu memuat ulang halaman terbaru. Client memakai `meta.server_time` (bukan jam lokal) sebagai `updated_since` berikutnya, dikurangi 5 detik tumpang tindih; penerapan data **idempoten** lewat `revision`.

### D.5 Pesan

**POST `/api/v1/conversations/{id}/messages`** — kirim pesan (juga untuk reply & lampiran)

| | |
|---|---|
| Auth | Peserta percakapan (selain itu `404`) |
| Body | `client_uuid` (required, uuid), `body` (nullable, string, max:2000, di-trim), `reply_to_id` (nullable, integer, harus pesan **tidak terhapus** pada percakapan yang sama), `attachment_ids` (array, max:5, tiap id: `exists`, `uploader_id = me`, `message_id IS NULL`) |
| Aturan | `body` atau `attachment_ids` minimal salah satu terisi (`required_without`); body hanya spasi dianggap kosong |
| Sukses | `201` `{ data: { message: Message }, message: "Pesan terkirim" }` (`status: "sent"`) |
| Idempoten | `client_uuid` yang sudah ada milik pengirim yang sama → `200` dengan pesan yang sama, **tidak** disimpan ulang dan **tidak** dipublish ulang |
| Error | `422` validasi · `404` bukan peserta · `429` rate limit |
| Efek DB (1 transaksi) | insert `messages`; set `attachments.message_id`; update `conversations.last_message_id/last_message_at`; insert `notifications` untuk penerima |
| Realtime (setelah commit) | `message:new` → pengirim + penerima; `notification:new` → penerima; `unread:update` → penerima |

**PATCH `/api/v1/messages/{id}`** — edit
- Body: `{ "body": "teks baru" }` · `required|string|min:1|max:2000`. Hanya **pengirim** (`403` jika bukan, `404` jika bukan peserta), hanya pesan belum dihapus (`409 MESSAGE_DELETED`), maks `CHAT_EDIT_WINDOW_MINUTES` (15) 🟡 D-09 sejak `created_at` (`409 EDIT_WINDOW_EXPIRED`). Lampiran tidak bisa diedit. Teks sama dengan sebelumnya → `200` tanpa event.
- `200 { data: { message } }`. Efek: `body`, `edited_at`, `revision+1`, `updated_at`. Realtime: `message:updated` → kedua peserta.

**DELETE `/api/v1/messages/{id}`** — hapus untuk semua peserta
- Hanya pengirim. Idempoten (sudah terhapus → `200`). `200 { data: { message: <tombstone> } }`.
- Efek: `deleted_at`, `revision+1`; baris `message_reactions` dihapus; lampiran dihapus dari storage via job; unread penerima dihitung ulang.
- Realtime: `message:deleted` → kedua peserta; `unread:update` → penerima.

**PUT `/api/v1/messages/{id}/reaction`** — set/ganti reaksi saya
- Body: `{ "emoji": "👍" }` · `required|in:<allow-list>`. Peserta mana pun, pesan belum terhapus (`409 MESSAGE_DELETED`).
- `200 { data: { message_id: 15, revision: 3, reactions: [ { "user_id": 2, "emoji": "👍" } ] } }`. Realtime: `message:reaction`.

**DELETE `/api/v1/messages/{id}/reaction`** — hapus reaksi saya (idempoten, respons sama bentuknya).

### D.6 Lampiran (lihat juga §G)

**POST `/api/v1/attachments`** — `multipart/form-data`, field **`file`** (satu file per request)
- `201 { data: { attachment: Attachment } }` dengan `message_id: null` (pending). Rate limit §I.
- Error: `413 PAYLOAD_TOO_LARGE`, `415 UNSUPPORTED_MEDIA_TYPE`, `422` (file tidak ada/korup).
- Pending dapat dihapus oleh uploader: **DELETE `/api/v1/attachments/{id}`** (`204`-setara: `200 { data: null }`; `409` jika sudah terlampir).

**GET `/api/v1/attachments/{id}/content`** — stream file (bukan JSON)
- Auth: uploader (selagi pending) **atau** peserta percakapan dari pesan induknya, pesan belum terhapus; selain itu `404`.
- Query `download=1` → `Content-Disposition: attachment`. Mendukung header `Range` (video/audio seeking). Header: `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=3600`.

### D.7 Status baca & unread

**POST `/api/v1/conversations/{id}/read`**
- Body: `{ "up_to_message_id": 149 }` · `required|integer`, harus pesan di percakapan itu.
- Efek: semua pesan `recipient_id = me`, `id <= up_to`, `read_at IS NULL` → `read_at = now`, `delivered_at = COALESCE(delivered_at, now)`, `revision+1`; notifikasi `chat.message` untuk pesan-pesan itu otomatis `read_at = now`. Idempoten.
- `200 { data: { conversation_id: 3, read_count: 4, unread_count: 0, total_unread: 2, read_at: "…Z" } }`
- Realtime: `message:read` → lawan bicara; `unread:update` → semua tab saya; `notification:read` → semua tab saya.

**GET `/api/v1/unread-counts`** — `200 { data: { total: 6, by_conversation: [ { "conversation_id": 3, "unread_count": 4 }, … ] } }`

### D.8 Notifikasi

| Endpoint | Detail |
|---|---|
| **GET `/api/v1/notifications`** | Query: `unread` (boolean), `page`, `per_page`. `200 { data: { notifications: [Notification…], unread_count: 3 }, meta: { pagination } }`. Urut `created_at` desc. Hanya milik saya. |
| **POST `/api/v1/notifications/{id}/read`** | Milik orang lain/tidak ada → `404`. Idempoten. `200 { data: { notification, unread_count } }`. Realtime: `notification:read`. |
| **POST `/api/v1/notifications/read-all`** | `200 { data: { updated: 5, unread_count: 0 } }`. Realtime: `notification:read` (`all: true`). |

### D.9 Endpoint internal (hanya Node → Laravel)

Tidak diekspos publik (blok di Nginx / hanya jaringan Docker). Header wajib:
`X-Internal-Timestamp: <unix>` dan `X-Internal-Signature: hex(HMAC-SHA256(INTERNAL_API_SECRET, timestamp + "." + rawBody))`. Selisih waktu > 60 detik → `401`. Frontend **tidak boleh** memakai endpoint ini.

| Endpoint | Fungsi |
|---|---|
| **POST `/internal/v1/messages/delivered`** | Body `{ recipient_id, message_ids: [15, 16] }`. Laravel hanya memproses pesan yang `recipient_id`-nya cocok & belum delivered; set `delivered_at`, `revision+1`. Respons `{ data: { delivered: [ { id, conversation_id, sender_id, delivered_at } ] } }` — Node memakai ini untuk emit `message:delivery` ke pengirim. Idempoten. |
| **POST `/internal/v1/presence`** | Body `{ user_id, status: "offline", at }` → set `users.last_seen_at`. |
| **GET `/internal/v1/conversations/{id}/participants`** | `{ data: { user_ids: [1, 2] } }` — dipakai Node untuk otorisasi typing (di-cache di Node ≤ 60 detik). |

---

## F. Shared JSON Response Format (format final)

**Sukses**
```json
{ "success": true, "data": { }, "message": "OK", "meta": { } }
```
`data` selalu objek (atau `null`), **tidak pernah array di root** — koleksi berada di dalam kunci bernama (`data.messages`, `data.users`). `meta` hanya ada jika perlu (pagination, `server_time`).

**Gagal**
```json
{
  "success": false,
  "message": "Pesan kesalahan yang aman ditampilkan ke user",
  "error": { "code": "VALIDATION_ERROR", "details": { "body": ["The body field is required."] } }
}
```
`error.details` hanya untuk `VALIDATION_ERROR` (format Laravel `field → [pesan]`); selain itu `null`.

| HTTP | `error.code` | Kapan |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Session tidak ada/kedaluwarsa → client redirect ke `/login` |
| 403 | `FORBIDDEN` | Aksi dilarang pada resource yang terlihat |
| 404 | `NOT_FOUND` | Tidak ada **atau** tidak berhak melihat |
| 409 | `MESSAGE_DELETED`, `EDIT_WINDOW_EXPIRED`, `ATTACHMENT_ALREADY_ATTACHED` | Konflik state |
| 413 | `PAYLOAD_TOO_LARGE` | File melebihi batas |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | Tipe file ditolak |
| 419 | `CSRF_MISMATCH` | Token CSRF salah/kedaluwarsa → client reload halaman |
| 422 | `VALIDATION_ERROR` | Input tidak valid |
| 429 | `RATE_LIMITED` | Header `Retry-After` (detik) disertakan |
| 500 | `SERVER_ERROR` | Tanpa detail internal |
| 503 | `SERVICE_UNAVAILABLE` | Dependensi kritis (mis. DB) tidak tersedia |

Pesan error **tidak pernah** memuat stack trace, query SQL, atau path server. Backend wajib mengganti handler exception default Laravel untuk request `/api/*` dan `/internal/*` agar selalu mengikuti format ini.

Callback ACK Socket.IO memakai bentuk ringkas: `{ "ok": true, ... }` atau `{ "ok": false, "error": { "code": "...", "message": "..." } }` (lihat §E).

---

## E. Socket.IO Event Contract

### E.1 Koneksi & autentikasi

```js
// file: resources/js/socket.js  (Frontend)
import { io } from "socket.io-client";
export const socket = io(window.AMIGO.socketUrl, {
  transports: ["websocket", "polling"],
  // auth dipanggil pada SETIAP connect/reconnect -> token selalu baru
  auth: async (cb) => {
    const r = await fetch("/api/v1/socket-token", { headers: { Accept: "application/json" }, credentials: "same-origin" });
    cb({ token: (await r.json()).data.token });
  },
});
```

Aturan Node (`websocket/server.js`):
1. Middleware koneksi memverifikasi JWT (signature `SOCKET_SECRET`, `exp`, `aud`). Gagal → `connect_error` dengan `message: "unauthorized"`.
2. `userId` **hanya** dari klaim `sub`. Node **mengabaikan** setiap `user_id` yang dikirim client dalam payload.
3. Server otomatis `socket.join("user:{userId}")`. **Client tidak boleh join room apa pun**; tidak ada event `join`.
4. `Origin` harus ada di `SOCKET_ALLOWED_ORIGINS` (CORS Socket.IO + `allowRequest`).
5. Jika `connect_error: unauthorized` (sesi habis) → client memanggil `/api/v1/me`; `401` → redirect `/login`.

### E.2 Envelope (seragam)

**Semua event server → client** memakai envelope:
```json
{ "event_id": "b4d8c0f2-2f4e-4c3e-9d0b-0a8f2b6f0e11", "occurred_at": "2026-10-12T02:30:00Z", "data": { } }
```
- `event_id` (UUID) dibuat Laravel (untuk event dari Redis) atau Node (presence/typing/delivery). Client menyimpan ±500 `event_id` terakhir dan **membuang duplikat**.
- Event yang butuh ACK dipanggil sebagai `socket.on("message:new", (envelope, ack) => { …; ack?.({ ok: true }) })`.

**Envelope Redis (Laravel → Node)**, dipublish ke `chat.messages` atau `chat.notifications`:
```json
{
  "event_id": "b4d8c0f2-…", "event": "message:new", "occurred_at": "2026-10-12T02:30:00Z",
  "to": [1, 2], "ack_user_id": 2,
  "data": { "message": { "...Message..." } }
}
```
- `to` = daftar user id penerima → Node emit ke room `user:{id}` masing-masing. **Laravel yang menentukan siapa berhak**; Node tidak menebak. `ack_user_id` = user yang ACK-nya dihitung (atau `null`).
- `event` sudah berupa nama event Socket.IO (tidak ada tabel pemetaan).
- Karena Laravel menambahkan prefix Redis, Node subscribe dengan **pattern** (`psubscribe("*chat.messages")`, `"*chat.notifications"`) seperti materi, dan harus tahan terhadap JSON rusak (log & abaikan).

### E.3 Event server → client

| Event | Penerima | ACK | Payload `data` | Perilaku client | REST terkait |
|---|---|---|---|---|---|
| `presence:snapshot` | Hanya socket yang baru connect | – | `{ "online_user_ids": [1, 2] }` | Ganti seluruh state online | – |
| `presence:update` | Semua socket terautentikasi | – | `{ "user_id": 2, "status": "online"\|"offline", "last_seen_at": "…Z"\|null }` | Perbarui daftar kontak & header | `users.last_seen_at` |
| `message:new` | Pengirim **dan** penerima (`to:[sender,recipient]`) | **Ya** (dari penerima) | `{ "message": Message }` | Upsert berdasarkan `id` / `client_uuid` (rekonsiliasi pesan optimistik). Jika `conversation_id` belum dikenal → `GET /conversations`. Jangan menambah unread sendiri; tunggu `unread:update`. | `POST …/messages` |
| `message:updated` | Kedua peserta | – | `{ "message": Message }` (edit) | Ganti hanya jika `revision` ≥ yang dimiliki | `PATCH /messages/{id}` |
| `message:deleted` | Kedua peserta | – | `{ "conversation_id", "message_id", "revision", "deleted_at" }` | Ubah menjadi tombstone | `DELETE /messages/{id}` |
| `message:reaction` | Kedua peserta | – | `{ "conversation_id", "message_id", "revision", "reactions": [ {user_id, emoji} ] }` | **Ganti seluruh** array reaksi bila `revision` ≥ | `PUT/DELETE …/reaction` |
| `message:delivery` | Pengirim pesan | – | `{ "conversation_id", "message_ids": [15], "delivered_at": "…Z" }` | Status → `delivered` (tidak pernah menurunkan dari `read`) | `/internal/…/delivered` |
| `message:read` | Pengirim pesan | – | `{ "conversation_id", "reader_id", "up_to_message_id", "read_at" }` | Semua pesan saya di percakapan itu dengan `id ≤ up_to` → `read` | `POST …/read` |
| `typing:update` | Lawan bicara | – | `{ "conversation_id", "user_id", "is_typing": true\|false }` | Tampilkan/sembunyikan; auto-sembunyi 5 dtk tanpa update | – |
| `unread:update` | Semua tab pemilik | – | `{ "conversation_id", "unread_count", "total_unread" }` | **Ganti** angka (bukan increment) | `GET /unread-counts` |
| `notification:new` | Penerima notifikasi | **Ya** | `{ "notification": Notification, "unread_count": 3 }` | Dedup berdasarkan `notification.id`; toast **kecuali** percakapan itu sedang terbuka & tab terlihat; set badge = `unread_count`; ACK **setelah** toast/badge diproses | `GET /notifications` |
| `notification:read` | Semua tab pemilik | – | `{ "ids": ["…"] \| null, "all": false, "unread_count": 2 }` | Tandai terbaca di semua tab; set badge | `POST /notifications/*/read` |
| `sync:required` | Semua socket | – | `{ "reason": "redis_reconnected"\|"server_restart", "server_time": "…Z" }` | Jalankan prosedur resync (§H.6) | semua GET |

**Nama `notification:delivery` pada materi tidak dipakai** 🟡: ACK notifikasi hanya dikonsumsi Node (log/metrik); tidak ada event ke user lain. Alasannya: tidak ada kebutuhan produk bagi pengirim untuk tahu notifikasinya tampil. Catat bila supervisor mengharapkannya (`CC`).
**Status `queued` pada `message:delivery` materi tidak dipakai** 🟡: keadaan "tersimpan tetapi belum diterima" sudah dinyatakan oleh `status: "sent"`.

### E.4 Event client → server

Semua memakai ACK callback `(res) => …` dengan `{ ok, error? }`. Payload **tanpa** `user_id`.

| Event | Payload | Validasi Node | Tujuan |
|---|---|---|---|
| `typing:start` | `{ "conversation_id": 3 }` | Peserta (via `/internal/…/participants`, cache ≤ 60 dtk); maks 1 event / 2 dtk / socket, lebihnya diabaikan | Node emit `typing:update {is_typing:true}` ke lawan bicara; Node membuat timer 5 dtk → emit `is_typing:false` bila tak ada `typing:start` baru |
| `typing:stop` | `{ "conversation_id": 3 }` | Sama | Emit `is_typing:false` & batalkan timer |
| `message:delivered` | `{ "message_ids": [15, 16] }` (maks 100) | Dikirim ke Laravel `/internal/…/delivered`; Laravel memfilter yang bukan milik penerima | Dipakai client setelah **resync REST** menerima pesan yang belum pernah di-ACK lewat `message:new` |

Handshake (`handshake:*`): lihat §E.6 — **tidak termasuk MVP**.

### E.5 Alur ACK `message:new` (Node)

1. Terima envelope dari Redis → emit ke `user:{sender}` (tanpa ACK) dan `io.to("user:{recipient}").timeout(5000).emit("message:new", envelope, cb)`.
2. Jika **minimal satu** socket penerima membalas `{ok:true}` → panggil `/internal/v1/messages/delivered` → emit `message:delivery` ke `user:{sender}`.
3. Tidak ada ACK (offline/timeout) → tidak ada event; status tetap `sent`. Pesan sampai lewat REST saat penerima membuka percakapan/reconnect, lalu client mengirim `message:delivered`.
4. ACK = "browser menerima event", **bukan** "pesan dibaca" (§H.2).

### E.6 Handshake antar-user ⏳ (D-03)

Materi mengajarkan `handshake:start|request|accept|complete` (status di Redis dengan TTL, retry idempoten). **Rekomendasi: tidak diimplementasikan di MVP.** Alasan: database + presence snapshot sudah menjamin pesan tidak hilang dan menentukan siapa online; handshake menambah state Redis, event, dan titik gagal tanpa mengubah hasil pesan. Namun handshake ada di **kriteria selesai materi**, jadi:
- Keputusan harus dikonfirmasi pembimbing sebelum Fase 2 selesai.
- Jika diwajibkan, tambahkan sebagai **Fase 7** dengan kontrak terpisah (`CC-xxx`); jangan menebak nama field. Nama event yang dicadangkan: `handshake:start`, `handshake:request`, `handshake:accept`, `handshake:complete` (arah sesuai materi §10).

### E.7 Daftar event ringkas (cek silang)

Client→Server: `typing:start`, `typing:stop`, `message:delivered`.
Server→Client: `presence:snapshot`, `presence:update`, `message:new`, `message:updated`, `message:deleted`, `message:reaction`, `message:delivery`, `message:read`, `typing:update`, `unread:update`, `notification:new`, `notification:read`, `sync:required`.
Internal Redis: channel `chat.messages` (event `message:*`, `unread:update`), channel `chat.notifications` (event `notification:*`).

Contoh konstanta (Frontend, menyalin tabel ini):
```js
// file: resources/js/contract/events.js
export const EV = {
  PRESENCE_SNAPSHOT: "presence:snapshot", PRESENCE_UPDATE: "presence:update",
  MESSAGE_NEW: "message:new", MESSAGE_UPDATED: "message:updated", MESSAGE_DELETED: "message:deleted",
  MESSAGE_REACTION: "message:reaction", MESSAGE_DELIVERY: "message:delivery", MESSAGE_READ: "message:read",
  TYPING_UPDATE: "typing:update", UNREAD_UPDATE: "unread:update",
  NOTIFICATION_NEW: "notification:new", NOTIFICATION_READ: "notification:read", SYNC_REQUIRED: "sync:required",
  // client -> server
  TYPING_START: "typing:start", TYPING_STOP: "typing:stop", MESSAGE_DELIVERED: "message:delivered",
};
```

---

## G. Media Contract

**Alur (D-10 🟡): upload HTTP dua langkah — file tidak pernah lewat Socket.IO.**
1. Client `POST /api/v1/attachments` (multipart, satu file per request, dengan `XMLHttpRequest`/`fetch` + progress) → `attachment_id` (pending).
2. Client `POST /api/v1/conversations/{id}/messages` dengan `attachment_ids`.
3. Realtime `message:new` membawa objek `attachments` lengkap (metadata + `url`). **Byte file tidak pernah ada di event.**

| Kategori | MIME yang diizinkan (hasil deteksi server `finfo`) | Maks |
|---|---|---|
| `image` | `image/jpeg`, `image/png`, `image/gif`, `image/webp` | 10 MB |
| `video` | `video/mp4`, `video/webm`, `video/quicktime` | 50 MB |
| `audio` | `audio/mpeg`, `audio/ogg`, `audio/wav`, `audio/mp4`, `audio/aac`, `audio/webm` | 20 MB |
| `file` | `application/pdf`, `text/plain`, `text/csv`, Office (`doc/docx/xls/xlsx/ppt/pptx`), `application/zip` | 20 MB |

- Maks **5 lampiran per pesan**. Angka batas bersumber dari `config/chat.php` dan dikirim ke frontend lewat `window.AMIGO.limits`.
- **Ditolak:** `svg`, `html`, `js`, `php`, `exe`, `sh`, `bat`, arsip bertingkat/tak dikenal. Ekstensi dan `Content-Type` dari client **tidak dipercaya**; server memeriksa isi file dan menyimpan dengan nama acak.
- **Penyimpanan:** disk privat `local` (`storage/app/private/attachments/…`), **bukan** `public/`, tanpa symlink publik. Akses hanya lewat `GET /api/v1/attachments/{id}/content` (otorisasi per request, §D.6).
- **Konfigurasi server (verifikasi!):** `upload_max_filesize` & `post_max_size` PHP ≥ 55M, `client_max_body_size` Nginx ≥ 55M — kalau tidak, upload besar gagal sebelum sampai Laravel (`413` dari Nginx bukan format §F; frontend tetap menampilkan error generik).
- **Upload gagal/file yatim:** attachment pending > 24 jam dihapus (record + file) oleh scheduled command `attachments:prune` tiap jam. Pesan gagal dikirim setelah upload berhasil → attachment tetap pending dan bisa dipakai ulang pada retry (dengan `client_uuid` yang sama).
- **Preview (Frontend):** `image` → `<img loading="lazy">` dengan `width/height` dari metadata; `video` → `<video controls preload="metadata">`; `audio` → `<audio controls>`; `file` → kartu ikon + `original_name` (teks) + ukuran + tombol unduh (`download_url`). Nama file dirender sebagai teks.
- Metadata thumbnail/durasi: **tidak ada di v1** (tidak dibuat tanpa kebutuhan).

---

## H. Realtime and State Contract

### H.1 Presence (online/offline)
- **Online** = pengguna memiliki ≥ 1 socket terautentikasi yang terhubung. **Bukan** bukti user sedang menatap layar.
- Node menyimpan hitungan socket per `user_id` (in-memory, **satu instance Node** 🟡 D-11; skala-out butuh Redis set — di luar MVP).
- Socket pertama → `presence:update online`. Tab ke-2 / menutup 1 dari 2 tab → tidak ada perubahan.
- Socket terakhir putus → tunggu **grace 5 detik**; jika tidak ada socket baru (mis. refresh halaman) → `presence:update offline` + `last_seen_at` + panggil `/internal/v1/presence`.
- Socket baru menerima `presence:snapshot`. Siapa pun yang terautentikasi melihat presence semua user (direktori user bersifat global) 🟡 D-15.
- Node restart: semua socket putus → client reconnect otomatis → snapshot baru.

### H.2 Sent / Delivered / Read
| Status | Definisi | Sumber kebenaran | Pemicu |
|---|---|---|---|
| **sent** | Backend sudah menyimpan pesan | `messages` row ada | Response `201` dari `POST …/messages` |
| **delivered** | Minimal satu client penerima menerima event `message:new` / data via REST dan **ACK** | `messages.delivered_at` | ACK `message:new` (Node) atau `message:delivered` dari client setelah resync |
| **read** | Penerima **melihat** pesan di percakapan terbuka | `messages.read_at` | `POST …/read` |

- **Definisi "dibaca" (🟡):** percakapan itu sedang terbuka di tab yang `document.visibilityState === "visible"` dan jendela fokus, dan pesan sudah dirender dalam viewport/area bawah daftar. Client memanggil `POST …/read` (debounce 500 ms) dengan id pesan terbaru yang terlihat.
- Status monoton: `sent → delivered → read`; tidak pernah turun. `read` otomatis mengisi `delivered_at` bila kosong.
- ACK **tidak boleh** dianggap read; presence **tidak boleh** dianggap read.

### H.3 Typing indicator
Client mengirim `typing:start` saat mengetik (ulangi tiap 3 detik selama masih mengetik, maks 1 per 2 detik diterima), `typing:stop` setelah 3 detik tak mengetik, saat kirim pesan, atau saat berganti percakapan. Penerima menyembunyikan indikator bila **5 detik** tanpa `typing:update`. Node juga menghentikan typing otomatis saat socket pengirim putus. Typing **tidak disimpan**.

### H.4 Unread count
Rumus §C.8. Server menerbitkan `unread:update` (angka absolut, `total_unread` ikut) ke semua tab penerima saat: pesan baru, pesan dihapus, `POST …/read`. Client **mengganti** nilai, tidak menjumlah. Tab yang sedang menampilkan percakapan aktif tetap menerima update; read otomatis menurunkannya ke 0.

### H.5 Notifikasi vs pesan chat
| | Pesan chat | Notifikasi |
|---|---|---|
| Tabel | `messages` | `notifications` |
| Tampil | Di dalam percakapan | Toast, badge global, halaman daftar |
| Dibuat | Saat pesan dikirim | Saat pesan tersimpan (1 baris per pesan, `chat.message`) 🟡 D-16 |
| Dibaca | `POST …/read` | `POST /notifications/*/read`; **otomatis** terbaca saat pesan terkait terbaca |
| Toast | – | Tidak ditampilkan bila percakapan terkait sedang aktif & tab terlihat |
Notifikasi untuk user offline tetap tersimpan dan muncul via `GET /notifications` setelah login.

### H.6 Reconnect & sinkronisasi ulang
Pada setiap event `connect` (termasuk reconnect) **dan** pada `sync:required`, client menjalankan berurutan:
1. `GET /api/v1/unread-counts` dan `GET /api/v1/notifications?unread=1` (badge).
2. `GET /api/v1/conversations` (daftar & `last_message`).
3. Untuk percakapan yang **sedang terbuka**: `GET …/messages?updated_since=<server_time terakhir − 5s>`; terapkan per `revision`; kirim `message:delivered` untuk pesan masuk yang belum pernah di-ACK.
4. Presence otomatis dari `presence:snapshot`.
Jaring pengaman: saat socket tersambung, client memanggil `GET /unread-counts` tiap 60 detik (menutup kemungkinan event hilang saat Redis bermasalah).
Socket.IO **Connection State Recovery tidak diandalkan** (tidak ada jaminan lintas deploy).

### H.7 Deduplikasi & urutan
- Pesan: kunci `id` (sinkronisasi pesan optimistik via `client_uuid`). Urutan tampilan: `id` naik.
- Event: `event_id`. Pembaruan pesan: `revision` — terapkan hanya bila `revision` ≥ nilai lokal.
- Notifikasi: `notification.id`.
- Pengiriman ulang request (retry/jaringan lambat): aman karena `client_uuid` (pesan) dan idempotensi endpoint lain (§D). Tombol kirim di-disable hingga response, namun retry otomatis memakai `client_uuid` yang sama.
- **Tidak ada jaminan exactly-once.** Yang dijamin: *at-least-once* untuk event realtime (dengan dedup di client) dan *source of truth* di database.

---

## I. Security Contract

| # | Aturan | Pemilik |
|---|---|---|
| 1 | Semua otorisasi dihitung di server (peserta percakapan, kepemilikan pesan/lampiran/notifikasi). Frontend tidak menjadi satu-satunya penjaga. | BE |
| 2 | Validasi semua input via Form Request; batas panjang & tipe sesuai §D. | BE |
| 3 | CSRF: seluruh request non-GET membawa `X-CSRF-TOKEN`; session cookie `HttpOnly`, `SameSite=Lax`, `Secure` di produksi. | BE + FE |
| 4 | Token socket: JWT HS256, TTL 5 menit, dipakai hanya saat handshake koneksi; secret hanya di `.env`. `SOCKET_SECRET` ≠ `INTERNAL_API_SECRET`. | BE |
| 5 | Origin/CORS: Socket.IO hanya menerima `SOCKET_ALLOWED_ORIGINS`; API tidak membuka CORS lintas-origin (same-origin session). | BE |
| 6 | Node hanya memercayai `socket.data.userId` dari JWT; payload client tidak pernah membawa `user_id`. Room hanya `user:{id}` yang dibuat server. | BE |
| 7 | Anti-IDOR: bukan peserta → `404`. Query selalu di-scope ke `auth()->id()`. Lampiran melewati otorisasi di controller (tanpa URL publik). | BE |
| 8 | Anti-XSS: render teks aman (§0.2); header `X-Content-Type-Options: nosniff`; direkomendasikan CSP `default-src 'self'; connect-src 'self' <SOCKET_URL>; img-src 'self' data:; media-src 'self'`. | BE (header) + FE (render) |
| 9 | Rate limiting (limiter Laravel, per user kecuali dinyatakan): login 5/menit per email+IP · kirim pesan 30/menit · edit/hapus 30/menit · reaksi 60/menit · upload 10/menit · user search 30/menit · socket-token 30/menit · notifikasi read 60/menit. Respons `429` + `Retry-After`. Node membatasi `typing:*` dan `message:delivered` (maks 20/menit/socket). | BE |
| 10 | Upload: deteksi MIME server, allow-list, batas ukuran, nama acak, disk privat (§G). | BE |
| 11 | Secrets hanya di `.env` (tidak di-commit; `.env.example` berisi placeholder). | BE |
| 12 | Endpoint `/internal/*`: HMAC + timestamp ≤ 60 dtk, tidak diekspos publik. | BE |
| 13 | Password di-hash (cast `hashed`), pesan login generik, `session()->regenerate()` setelah login. | BE |
| 14 | Log: jangan mencatat isi pesan/token/secret. | BE |

---

## J. Error Handling and Reliability

| Kejadian | Perilaku yang dikontrak |
|---|---|
| **Redis mati saat pesan dikirim** | Pesan **tetap tersimpan** dan REST mengembalikan `201` (sumber kebenaran = DB). Publish gagal dicatat (`Log::error`). Penerima menerima pesan lewat resync/polling 60 dtk/pembukaan percakapan. Dengan **outbox** (⏳ D-06, direkomendasikan Fase 6): event ditulis ke `outbox_events` dalam transaksi yang sama, dipublish setelah commit, dan command `chat:outbox:flush` (tiap menit) mengulang yang belum `published_at`. |
| **Redis down di Node (subscriber putus)** | Node reconnect otomatis; setelah subscribe kembali emit `sync:required {reason:"redis_reconnected"}` ke semua socket. |
| **Node/Socket.IO mati / client putus** | Client memakai reconnect bawaan Socket.IO (backoff), UI menampilkan status "Menyambung ulang…", fitur REST tetap berfungsi. Setelah `connect` → prosedur §H.6. |
| **Laravel `/internal/*` gagal saat Node menandai delivered** | Node retry 3× (backoff 0,5/1/2 dtk); jika tetap gagal status tetap `sent` dan client penerima akan mengirim `message:delivered` pada resync berikutnya. Tidak ada kerugian data. |
| **ACK terlambat/tidak ada** | Timeout 5 dtk → status `sent`; ACK yang datang terlambat diabaikan atau diproses idempoten (`delivered_at` hanya diisi sekali). |
| **Event diterima 2×** | Client dedup `event_id`/`id`/`revision` (§H.7). |
| **Event datang tak berurutan** | `revision` mencegah data lama menimpa data baru. |
| **Upload gagal** | Client menampilkan error per file, bisa retry file itu saja; pesan tidak dikirim sampai semua lampiran sukses atau dihapus dari draft. File yatim dibersihkan (§G). |
| **Request berulang** | `client_uuid` (kirim pesan), idempotensi PUT/DELETE/POST read. |
| **Session habis (401) / CSRF (419)** | Client: 401 → redirect `/login`; 419 → reload halaman (token baru). |
| **Pengiriman gagal jaringan** | Pesan optimistik ditandai "gagal — coba lagi" dengan `client_uuid` sama. |
| **DB tidak tersedia** | `503 SERVICE_UNAVAILABLE`; tidak ada event dipublish. |

---

## K. Testing and Acceptance Criteria

### K.1 Backend (Feature/Unit — PHPUnit 12)
- Login berhasil/gagal, regenerasi session, logout; `register` validasi.
- Kirim pesan: tersimpan, tidak bisa ke diri sendiri (`POST /conversations`), validasi panjang, `client_uuid` idempoten, rate limit.
- **Otorisasi:** user C tidak bisa `GET/POST/PATCH/DELETE` apa pun pada percakapan A–B (semua `404`); tidak bisa mengunduh lampiran; tidak bisa membaca notifikasi orang lain.
- Edit: hanya pengirim, jendela 15 menit, tidak bisa pada pesan terhapus. Hapus: tombstone + lampiran terhapus. Reply: id harus dalam percakapan sama. Reaksi: allow-list, satu per user.
- Upload: tipe/ukuran ditolak (svg/exe/spoof ekstensi), lampiran milik orang lain tidak bisa dilampirkan, `Range` & `nosniff` pada unduhan.
- Read: `read_at` + `delivered_at`, unread count, notifikasi ikut terbaca, idempoten.
- Redis gagal (mock/stop): `POST messages` tetap `201` + log error.
- Endpoint internal menolak signature salah / timestamp basi.
- Format response §F pada setiap error (401/403/404/419/422/429/500).

### K.2 Server realtime (Node)
- JWT salah/kedaluwarsa ditolak; `user_id` palsu di payload diabaikan.
- Event hanya masuk ke room di `to`; user lain tidak menerima apa pun.
- Presence multi-tab (2 tab → tutup 1 → tetap online; tutup semua → offline setelah grace); snapshot benar.
- ACK → `message:delivery` ke pengirim; tanpa ACK → tidak ada event.
- Typing: kedaluwarsa 5 dtk, throttle, ditolak untuk non-peserta.
- Envelope Redis rusak tidak membuat server crash.

### K.3 Frontend
- Render pesan/nama/lampiran/notifikasi sebagai teks (uji dengan `<img src=x onerror=alert(1)>`).
- Optimistic UI → rekonsiliasi `client_uuid`; dedup `event_id`; `revision` mencegah data lama menimpa.
- State loading/empty/error/reconnect; badge & toast tidak ganda.
- Tidak ada endpoint/event/field di luar kontrak (grep `fetch(`/`socket.on(`).

### K.4 Integrasi (dua browser: normal + incognito) — Kriteria diterima
- [ ] Andi → Budi: pesan tersimpan di DB dan muncul di kedua browser **tanpa reload**.
- [ ] Budi offline → pesan tersimpan → Budi login → riwayat & unread benar; status Andi `sent` → `delivered` setelah Budi menerima.
- [ ] User ketiga tidak bisa membaca/mengubah pesan A–B (API **dan** socket).
- [ ] Edit, hapus, reply, reaksi konsisten di kedua sisi dan di semua tab pengirim.
- [ ] Upload gambar/video/audio/file sesuai batas; preview & unduh hanya untuk peserta.
- [ ] Presence benar dengan banyak tab; offline setelah semua tab tutup.
- [ ] Unread & read receipt sinkron antar tab dan antar user; notifikasi terbaca otomatis saat pesan dibaca.
- [ ] Notifikasi hanya ke penerima; toast tidak muncul pada percakapan aktif.
- [ ] Matikan Node lalu hidupkan: client reconnect, tidak ada pesan ganda, state kembali sinkron.
- [ ] Matikan Redis: kirim pesan tetap berhasil (`201`); setelah Redis hidup & resync, penerima mendapat pesan; dokumentasikan perilakunya.

---

## 12. Implementation Roadmap

| Fase | Isi (MVP dulu) | Backend | Frontend | Bergantung pada |
|---|---|---|---|---|
| **0 Discovery** | Verifikasi repo/versi (§A), bekukan kontrak `0.2.0` | ✔ | ✔ (review) | – |
| **1 Fondasi** | Auth, `/me`, user list/search, bootstrap Blade, `socket-token`, Node auth+room, presence | login/register, `users`, `last_seen_at`, Node auth, presence | halaman login/register, layout, daftar user, klien socket, indikator online | 0 |
| **2 Pesan teks realtime** | `conversations`, `POST messages`, riwayat, Redis→`message:new`, ACK delivered, resync | tabel, endpoint, publisher, subscriber, internal API | tampilan percakapan, form kirim, optimistic UI, dedup, reconnect | 1 |
| **3 Unread & read** | `read`, `unread-counts`, `unread:update`, `message:read`, tick sent/delivered/read | ✔ | badge unread, tick status, kebijakan "dibaca" (§H.2) | 2 |
| **4 Notifikasi** | tabel+endpoint+`notification:*`, toast, badge, daftar | ✔ | toast, badge, halaman daftar, mark read | 2 (3 untuk auto-read) |
| **5 Interaksi pesan** | edit, hapus, reply, reaksi, typing | ✔ | UI interaksi, typing | 2 |
| **6 Media** | upload, akses, preview | ✔ | UI upload, progress, preview | 2 |
| **7 Hardening** | rate limit, outbox, uji Redis/Node down, review keamanan, CSP | ✔ | uji reconnect/XSS | 2–6 |
| **8 Opsional** | handshake (D-03), mention (D-07) | bila diminta | bila diminta | keputusan pembimbing |

**Paralel:** dari Fase 1, Frontend memakai `docs/contract-fixtures` + mock (flag `USE_MOCKS`) sehingga UI Fase 2–6 dapat dikerjakan sebelum backend selesai; Backend mengerjakan fase berikutnya sambil Frontend mengintegrasikan fase sebelumnya. **Titik integrasi bersama (wajib uji bareng):** akhir Fase 1, 2, 3, 4, dan 7. Fase 4, 5, 6 independen satu sama lain setelah Fase 2.

---

## 13. Risiko Integrasi

| Risiko | Pencegahan |
|---|---|
| Nama field/event berbeda (`recipient_id` vs `receiver_id`, `message:new` vs `new-message`) | Satu sumber (dokumen ini + fixtures + `events.js`); PR yang mengubah nama = `contract-change` |
| Frontend mengasumsikan field yang tidak ada (mis. `email` peer, `is_online` di REST) | §D.1 mendefinisikan objek lengkap; online hanya dari socket |
| Frontend menghitung status/unread sendiri | Server mengirim `status`, `unread_count` absolut; client mengganti, bukan menjumlah |
| Duplikasi pesan (optimistic + REST + event) | `client_uuid`, `event_id`, `revision` |
| Token socket kedaluwarsa saat reconnect | `auth` sebagai fungsi yang mengambil token baru tiap connect |
| Cookie/CSRF gagal pada `fetch` | Header §D.0 + `credentials: "same-origin"`; 419 → reload |
| Redis prefix Laravel membuat Node tak menerima event | `psubscribe("*chat.messages")` seperti materi; uji pertama di Fase 2 |
| Emoji rusak (`???`) di DB | `utf8mb4` sejak migrasi pertama |
| Upload besar gagal tanpa pesan jelas | Samakan batas PHP/Nginx; frontend error generik untuk non-JSON `413` |
| Dua AI mengedit file yang sama | Kepemilikan path §B.2; branch terpisah |
| Presence salah pada refresh | Grace 5 detik (§H.1) |
| Node dan Laravel beda zona waktu/format | Semua UTC `Z`; Laravel menghasilkan, Node meneruskan apa adanya |
| Kontrak kebanyakan sehingga tidak terbaca | Fase 0: review bersama 30 menit; hanya fitur fase aktif yang wajib dipahami |

---

## 14. Decision Register & Change Log

### 14.1 Keputusan

| ID | Keputusan | Status | Catatan |
|---|---|---|---|
| D-01 | Endpoint JSON `/api/v1` di `web.php`, auth session+CSRF | 🟡 | Berbeda dari materi (`POST /messages`) untuk versioning & cakupan percakapan |
| D-02 | Tabel `conversations` 1:1 (user id dinormalisasi) | 🟡 | Tidak ada grup |
| D-03 | Handshake tidak masuk MVP (dikerjakan di Fase 8 bila pembimbing meminta) | 🟡 default | |
| D-04 | `delivered_at`/`read_at` di `messages`; read lewat REST | 🟡 | Tanpa tabel receipts |
| D-05 | Unread = query | 🟡 | |
| D-06 | Outbox transaksional di Fase 7 | 🟡 default | MVP: try/catch + log + resync + polling |
| D-07 | Mention ditunda | 🟡 default | Hanya bermakna di grup; tentukan format token jika diaktifkan |
| D-08 | Satu reaksi per user per pesan, allow-list emoji | 🟡 | |
| D-09 | Edit ≤ 15 menit; hapus = soft delete untuk semua, hanya pengirim | 🟡 | |
| D-10 | Upload dua langkah, disk privat, batas §G | 🟡 | |
| D-11 | Satu instance Node, presence in-memory | 🟡 | |
| D-12 | Panjang pesan maks 2000 (materi latihan menyebut 500) | 🟡 | Sesuaikan jika diminta |
| D-13 | Pakai DB yang ada di `.env` (default SQLite); bila MySQL wajib utf8mb4 | ✅ |
| D-14 | PHPUnit 12 | ✅ | Dari `composer.json` |
| D-15 | Presence terlihat oleh semua user | 🟡 | |
| D-16 | Satu notifikasi per pesan | 🟡 | |
| D-17 | Secret internal terpisah dari `SOCKET_SECRET` | 🟡 | |

### 14.2 Change Log

| ID | Tanggal | Perubahan | Disetujui oleh |
|---|---|---|---|
| CC-000 | 2026-10-12 | Draft awal `0.1.0` | – |
| CC-001 | 2026-10-12 | `0.2.0`: sesuaikan ke repo nyata (Laravel 13, Tailwind 4, PHPUnit), semua ❓ diberi default | Backend |
| CC-002 | 2026-10-14 | `0.2.1` (Fase 2): (a) `conversations.last_message_id` tanpa FK database karena referensi melingkar dengan `messages`; (b) pada Fase 2 `POST …/messages` hanya menerima teks — `body` wajib dan `attachment_ids` ditolak `422` sampai Fase 6; (c) efek samping `notifications` dan event `unread:update` pada `POST …/messages` ditunda ke Fase 3/4; (d) `unread_count` pada Conversation sudah dihitung lewat query; (e) tidak ada perubahan nama field/event/endpoint | Backend (beri tahu Frontend) |
| CC-003 | 2026-10-14 | `0.2.2` (Fase 3): (a) `POST …/read` mengembalikan `read_at: null` bila `read_count` = 0 (panggilan ulang), dan tidak memublikasikan event apa pun; (b) `GET /unread-counts` → `by_conversation` hanya memuat percakapan dengan `unread_count` > 0; (c) `unread:update` kini juga dikirim ke penerima setiap ada pesan baru (melengkapi CC-002 butir c); (d) pembaruan otomatis `notifications` saat pesan dibaca menyusul di Fase 4 bersama tabel notifikasi; (e) tidak ada perubahan nama field/event/endpoint | Backend (beri tahu Frontend) |
| CC-004 | 2026-10-15 | `0.2.3` (Fase 4): (a) satu notifikasi `chat.message` dibuat per pesan baru untuk penerima, dalam transaksi yang sama dengan pesan; `body` = 100 karakter pertama teks; (b) notifikasi terkait otomatis `read_at` saat pesannya dibaca via `POST …/read`, lalu `notification:read` dikirim ke semua tab pembaca; (c) `notification:new` dipublish ke channel `chat.notifications` dan ACK-nya hanya dicatat Node (tanpa event ke user lain, sesuai §E.3); (d) `POST /notifications/read-all` hanya menandai notifikasi, bukan pesan; (e) id notifikasi harus UUID — selain itu `404`; (f) tidak ada perubahan nama field/event/endpoint | Backend (beri tahu Frontend) |
| CC-005 | 2026-10-16 | `0.2.4` (Fase 5): (a) urutan pemeriksaan aksi pesan: bukan peserta `404` → bukan pengirim `403` → pesan terhapus `409 MESSAGE_DELETED` → lewat batas edit `409 EDIT_WINDOW_EXPIRED` → input `422`; (b) edit dengan teks sama → `200` tanpa event dan tanpa kenaikan `revision`; (c) hapus: reaksi pesan itu dihapus, `unread:update` dikirim ke penerima, notifikasi terkait TIDAK diubah (sesuai §C.9); (d) reaksi: emoji harus persis sama dengan allow-list (termasuk ❤️ yang memuat U+FE0F), `message:reaction` selalu membawa SELURUH daftar `reactions`, reaksi/hapus-reaksi yang tidak mengubah apa pun tidak menaikkan `revision` dan tidak mengirim event; `DELETE …/reaction` pada pesan terhapus tetap `200`; (e) typing: setiap `typing:start` yang diterima (maks 1 / 2 dtk / socket) diteruskan sebagai `typing:update` `is_typing:true` ke lawan bicara saja; `is_typing:false` dikirim saat `typing:stop`, 5 dtk tanpa `typing:start`, atau socket pengirim putus; kode error ACK: `VALIDATION_ERROR`, `FORBIDDEN`, `RATE_LIMITED`, `UNAVAILABLE`; (f) `GET /internal/v1/conversations/{id}/participants` menandatangani body kosong; (g) tidak ada perubahan nama field/event/endpoint | Backend (beri tahu Frontend) |
| CC-006 | 2026-10-17 | `0.2.5` (Fase 6): (a) jenis file ditentukan dari ISI file (finfo); ekstensi/Content-Type client tidak dipercaya, dan file disimpan dengan ekstensi hasil deteksi; `original_name` hanya untuk tampilan (path dibuang, karakter kontrol dihapus, maks 255); (b) rekaman `audio/webm` terdeteksi sebagai `video/webm` oleh finfo, maka dianggap audio bila client mengirim Content-Type `audio/webm` atau ekstensi `.weba` (klasifikasi saja, bukan keamanan); (c) kode error upload: `413 PAYLOAD_TOO_LARGE` (melebihi batas jenis/ server), `415 UNSUPPORTED_MEDIA_TYPE` (jenis tidak diizinkan/gambar rusak), `422 VALIDATION_ERROR` (tanpa file, file kosong, dimensi gambar > 100 juta piksel); (d) `POST …/messages`: `body` boleh kosong bila ada `attachment_ids` dan sebaliknya; lampiran harus milik pengirim, belum terlampir, tanpa duplikat, maks 5, jika tidak `422` pada `attachment_ids`; kirim ulang dengan `client_uuid` yang sama tetap `200` walau lampiran sudah terpakai; (e) hapus pesan menghapus baris `attachments` dan file SECARA SINKRON (bukan lewat job); (f) lampiran pending > 24 jam dihapus oleh `php artisan attachments:prune` (dijadwalkan tiap jam; butuh `php artisan schedule:work` atau cron); (g) `…/content`: `Content-Type` dari hasil deteksi, `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=3600`, mendukung `Range`; gambar/video/audio tampil `inline`, dokumen selalu `attachment`, `?download=1` memaksa unduh; pesan terhapus = `404`; (h) tidak ada perubahan nama field/event/endpoint, dan Node tidak berubah (media tidak lewat Socket.IO) | Backend (beri tahu Frontend) |

## 15. Status Implementasi (diperbarui Backend tiap fase)

| Bagian kontrak | Status |
|---|---|
| §D.2 `POST /login`, `/register`, `/logout`, `GET /api/v1/me`, `GET /api/v1/socket-token` | ✅ Fase 1 |
| §D.3 `GET /api/v1/users`, `GET /api/v1/users/{id}` | ✅ Fase 1 |
| §E presence: `presence:snapshot`, `presence:update` | ✅ Fase 1B |
| §D.4 `GET/POST /api/v1/conversations`, `GET …/{id}/messages` (riwayat `before_id`, resync `updated_since`) | ✅ Fase 2 |
| §D.5 `POST /api/v1/conversations/{id}/messages` (teks, reply, idempoten `client_uuid`) | ✅ Fase 2 |
| §E `message:new` (+ACK), `message:delivery`, `message:delivered` (client→server), `sync:required` | ✅ Fase 2 |
| §D.9 `/internal/v1/presence`, `/internal/v1/messages/delivered`, `/internal/v1/conversations/{id}/participants` | ✅ (internal, bukan untuk Frontend) |
| §D.7 `POST /api/v1/conversations/{id}/read`, `GET /api/v1/unread-counts`; §E `message:read`, `unread:update` (juga dikirim saat ada pesan baru) | ✅ Fase 3 |
| §D.8 `GET /api/v1/notifications`, `POST …/{id}/read`, `POST …/read-all`; §E `notification:new` (+ACK), `notification:read` | ✅ Fase 4 |
| §D.5 `PATCH /api/v1/messages/{id}`, `DELETE /api/v1/messages/{id}`, `PUT/DELETE /api/v1/messages/{id}/reaction`; §E `message:updated`, `message:deleted`, `message:reaction`, `typing:start/stop` (client→server), `typing:update` | ✅ Fase 5 |
| §D.6, §G `POST /api/v1/attachments`, `DELETE /api/v1/attachments/{id}`, `GET /api/v1/attachments/{id}/content`; `attachment_ids` pada `POST …/messages`; `attachments` pada Message | ✅ Fase 6 |
