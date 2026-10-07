# 📋 Amigo — Backend × Frontend Contract

> **Repository:** https://github.com/Rofiq-Ridhani/Amigo.git  
> **Versi:** 1.0.0  
> **Nama Project:** Amigo  
> **Tanggal:** 2026-09-16  
> **Status:** Active  
> 
> File ini adalah **sumber kebenaran tunggal** (single source of truth) antara backend dan frontend.  
> Setiap perubahan kontrak HARUS di-update di file ini dulu, baru diimplementasi kode.  
> AI frontend WAJIB membaca file ini sebelum menulis kode apapun.

---

## 📖 DAFTAR ISI

1. [Arsitektur & Tech Stack](#1--arsitektur--tech-stack)
2. [Pembagian File Owner (Anti-Konflik)](#2--pembagian-file-owner-anti-konflik)
3. [Konvensi Umum](#3--konvensi-umum)
4. [Auth Contract](#4--auth-contract)
5. [REST API Endpoints](#5--rest-api-endpoints)
6. [Socket.IO Event Contract](#6--socketio-event-contract)
7. [Data Models & Schema](#7--data-models--schema)
8. [Media Upload Contract](#8--media-upload-contract)
9. [Notification Contract](#9--notification-contract)
10. [Error Format](#10--error-format)
11. [Branch & Git Strategy](#11--branch--git-strategy)
12. [Environment Config](#12--environment-config)
13. [Deferred / TODO](#13-deferred--todo)

---

## 1. 🏗️ Arsitektur & Tech Stack

```
┌─────────────┐     HTTP (REST)      ┌──────────────┐
│   Browser   │ ──────────────────▶  │   Laravel    │
│   (Frontend)│ ◀──────────────────  │  (Backend)   │
└──────┬──────┘     JSON Response     └──────┬───────┘
       │                                    │
       │  WebSocket (Socket.IO)             │  Redis Pub/Sub
       │                                    │
       ▼                                    ▼
┌──────────────┐                    ┌──────────────┐
│  Node.js     │ ◀───────────────── │    Redis     │
│  Socket.IO   │   Subscribe        │  Pub/Sub     │
│  :3001       │                    │  + Cache     │
└──────────────┘                    └──────────────┘
```

| Teknologi | Versi | Tanggung Jawab |
|-----------|-------|----------------|
| PHP | 8.2+ | Runtime Laravel |
| Laravel | 11.x | Routing, Auth, Validation, Session, DB |
| MySQL / MariaDB | 8.0+ | Persistent storage |
| Redis | 7.x | Pub/Sub event distribution, handshake cache |
| Node.js | 18+ | Socket.IO server runtime |
| Socket.IO | 4.x | Realtime connection, rooms, presence |
| Vite | 5.x | Frontend asset build |
| Tailwind CSS | 3.x | UI styling |
| Laradock | - | Docker development environment |

### Dual-Path Principle (WAJIB PAHAM)

> **HTTP = penyimpanan permanen. WebSocket = distribusi realtime.**

- Setiap pesan **HARUS** disimpan ke database via HTTP POST terlebih dahulu.
- Setelah tersimpan, Laravel publish event ke Redis.
- Node.js subscribe Redis lalu emit ke browser via Socket.IO.
- Jika penerima offline → pesan tetap ada di DB → saat online, ambil dari DB.
- **TIDAK BOLEH** mengandalkan memory Node.js untuk data penting.

---

## 2. 📁 Pembagian File Owner (Anti-Konflik)

> **ATURAN EMAS:** Satu file = satu owner. Tidak ada file yang diedit oleh backend dan frontend bersamaan.

### 🔒 Backend Owner (Hanya backend yang edit)

```
app/
├── Http/
│   └── Controllers/
│       ├── AuthController.php      # Login, Register, Logout
│       └── ChatController.php      # Pesan, Media, Reaction, Reply
├── Models/
│   ├── User.php                    # Model user + relasi
│   ├── Message.php                 # Model pesan
│   ├── Notification.php            # Model notifikasi
│   └── MessageReaction.php         # Model reaction emoji
├── Services/                       # Business logic (jika perlu)
│   ├── MessageService.php
│   ├── NotificationService.php
│   └── MediaService.php
└── ...

database/
├── migrations/
│   ├── *_create_users_table.php
│   ├── *_create_messages_table.php
│   ├── *_create_notifications_table.php
│   ├── *_create_message_reactions_table.php
│   └── *_create_media_attachments_table.php
└── seeders/

routes/
└── web.php                         # Route definitions (BACKEND ONLY)

config/                             # Laravel config
.env                                # JANGAN COMMIT (ada .env.example)
bootstrap/
composer.json
phpunit.xml
```

### 🎨 Frontend Owner (Hanya frontend yang edit)

```
resources/
├── views/
│   ├── auth/
│   │   ├── login.blade.php
│   │   └── register.blade.php
│   ├── chat/
│   │   ├── index.blade.php         # Halaman utama chat
│   │   └── components/             # Komponen Blade
│   │       ├── message-item.blade.php
│   │       ├── message-input.blade.php
│   │       ├── contact-list.blade.php
│   │       ├── online-status.blade.php
│   │       ├── notification-toast.blade.php
│   │       ├── notification-badge.blade.php
│   │       ├── media-preview.blade.php
│   │       ├── voice-recorder.blade.php
│   │       ├── emoji-reaction.blade.php
│   │       ├── reply-preview.blade.php
│   │       └── typing-indicator.blade.php
│   └── layouts/
│       └── app.blade.php           # Layout utama
├── css/
│   └── app.css                     # Tailwind + custom CSS
└── js/
    └── app.js                      # Client Socket.IO + UI logic
```

### 🔀 Shared Files (KOORDINASI WAJIB)

File-file ini berpotensi dikeduai. **Komunikasi dulu sebelum edit:**

| File | Owner Utama | Kapan frontend boleh usul |
|------|-------------|--------------------------|
| `routes/web.php` | Backend | Minta tambah route baru |
| `websocket/server.js` | Backend (Node) | Minta emit event baru |
| `.env.example` | Backend | Tambah key baru |
| `CONTRACT.md` | Bersama | PR review bareng |

---

## 3. 📐 Konvensi Umum

### 3.1 Response Format Standar

```json
// === SUKSES ===
{
    "success": true,
    "data": { ... }
}

// === SUKSES dengan pesan ===
{
    "success": true,
    "message": "Pesan berhasil dikirim",
    "data": { ... }
}

// === LIST/PAGINATION ===
{
    "success": true,
    "data": [...],
    "pagination": {
        "current_page": 1,
        "per_page": 50,
        "total": 120,
        "total_pages": 3
    }
}

// === ERROR VALIDASI (422) ===
{
    "success": false,
    "message": "Validasi gagal",
    "errors": {
        "body": ["Pesan wajib diisi"],
        "recipient_id": ["Penerima tidak valid"]
    }
}

// === ERROR SERVER (500) ===
{
    "success": false,
    "message": "Terjadi kesalahan pada server"
}

// === ERROR AUTH (401/403) ===
{
    "success": false,
    "message": "Belum login atau session expired"
}
```

### 3.2 Timestamp Format

Semua timestamp menggunakan format **ISO 8601**:
```
2026-09-16T10:30:00+08:00
```
Frontend bertanggung jawab formatting tampilan (relative time: "5 menit yang lalu").

### 3.3 ID Format

- `user_id`: integer (auto-increment)
- `message_id`: integer (UUID opsional, tapi untuk v1 pakai integer dulu)
- `notification_id`: string prefix `"notification-{integer}"`
- `media_id`: integer

### 3.4 Keamanan Frontend

⚠️ **WAJIB:**
- Gunakan `textContent` BUKAN `innerHTML` saat render pesan/user input → cegah XSS
- CSRF token wajib dikirim di header setiap POST/PUT/DELETE
- Jangan simpan secret apa pun di JavaScript
- Token WebSocket memiliki expiry time, refresh jika expired

---

## 4. 🔐 Auth Contract

### 4.1 Register

```
POST /register
Content-Type: application/x-www-form-urlencoded
X-CSRF-TOKEN: {{ csrf_token() }}

Request Body:
  name       string  required  min:3, max:50
  email      string  required  email, unique
  password   string  required  min:8, confirmed

Response 201:
{
    "success": true,
    "message": "Registrasi berhasil",
    "data": {
        "user": {
            "id": 1,
            "name": "Andi Pratama",
            "email": "andi@example.com"
        }
    }
}

Response 422:
{
    "success": false,
    "message": "Validasi gagal",
    "errors": { "email": ["Email sudah terdaftar"] }
}
```

### 4.2 Login

```
POST /login
Content-Type: application/x-www-form-urlencoded
X-CSRF-TOKEN: {{ csrf_token() }}

Request Body:
  email      string  required  email
  password   string  required

Response 200:
{
    "success": true,
    "message": "Login berhasil",
    "data": {
        "user": {
            "id": 1,
            "name": "Andi Pratama",
            "email": "andi@example.com"
        }
    }
}
→ Server auto-set session cookie (laravel_session)
→ Session regenerated (anti session fixation)

Response 401:
{
    "success": false,
    "message": "Email atau password salah"
}
```

### 4.3 Logout

```
POST /logout
X-CSRF-TOKEN: {{ csrf_token() }}

Response 200:
{
    "success": true,
    "message": "Logout berhasil"
}
→ Session invalidated
```

### 4.4 Get Current User

```
GET /api/me
Authorization: Laravel Session Cookie

Response 200:
{
    "success": true,
    "data": {
        "id": 1,
        "name": "Andi Pratama",
        "email": "andi@example.com",
        "avatar": null
    }
}

Response 401:
{
    "success": false,
    "message": "Unauthorized"
}
```

### 4.5 Get Socket Token

```
GET /socket-token
Authorization: Laravel Session Cookie

Response 200:
{
    "success": true,
    "data": {
        "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
        "expires_in": 3600
    }
}
→ Token digunakan untuk autentikasi koneksi Socket.IO
→ Token berlaku 1 jam, frontend harus refresh jika expired
```

---

## 5. 🌐 REST API Endpoints

### 5.1 Users — Daftar Kontak

```
GET /api/users

Query Params (opsional):
  search    string    Filter nama/email
  online_only bool    Hanya user online (true/false)

Response 200:
{
    "success": true,
    "data": [
        {
            "id": 2,
            "name": "Budi Santoso",
            "email": "budi@example.com",
            "avatar": null,
            "status": "online",          // "online" | "offline" | "away"
            "last_seen_at": "2026-09-16T09:00:00+08:00"
        }
    ]
}
```

### 5.2 Messages — Kirim Pesan

```
POST /messages
Content-Type: application/json
X-CSRF-TOKEN: {{ csrf_token() }}

Request Body:
{
    "recipient_id": 2,              // required, integer, != current user
    "body": "Halo, apa kabar?",     // required if no media, string, max:1000
    "reply_to_id": 45               // optional, integer (message id yang direply)
}

Rules:
  - body WAJIB isi jika tidak ada attachment
  - recipient_id tidak boleh diri sendiri
  - reply_to_id harus valid & milik conversation yang sama

Response 201:
{
    "success": true,
    "message": "Pesan terkirim",
    "data": {
        "id": 67,
        "sender_id": 1,
        "recipient_id": 2,
        "body": "Halo, apa kabar?",
        "reply_to_id": 45,
        "reply_to_preview": {        // hanya ada jika reply_to_id tidak null
            "id": 45,
            "body": "Bagaimana proyeknya?",
            "sender_name": "Budi Santoso"
        },
        "attachments": [],           // array of media objects (lihat bagian Media)
        "reactions": [],
        "is_edited": false,
        "is_deleted": false,
        "created_at": "2026-09-16T10:30:00+08:00",
        "updated_at": "2026-09-16T10:30:00+08:00"
    }
}
→ Backend akan otomatis publish event ke Redis setelah save
```

### 5.3 Messages — Ambil Riwayat Percakapan

```
GET /api/conversations/{user_id}/messages

Query Params:
  page          integer   default:1
  per_page      integer   default:50, max:100
  before_id     integer   Ambil pesan sebelum ID ini (infinite scroll)

Response 200:
{
    "success": true,
    "data": [
        {
            "id": 67,
            "sender_id": 1,
            "sender_name": "Andi Pratama",
            "recipient_id": 2,
            "body": "Halo!",
            "reply_to_id": null,
            "reply_to_preview": null,
            "attachments": [],
            "reactions": [
                {
                    "emoji": "❤️",
                    "count": 2,
                    "users": [1, 2]       // user_ids yang react
                }
            ],
            "is_edited": false,
            "is_deleted": false,
            "delivered_at": "2026-09-16T10:30:05+08:00",
            "read_at": "2026-09-16T10:31:00+08:00",
            "created_at": "2026-09-16T10:30:00+08:00"
        }
    ],
    "pagination": {
        "current_page": 1,
        "per_page": 50,
        "total": 120,
        "total_pages": 3
    }
}
```

### 5.4 Messages — Edit Pesan

```
PUT /messages/{id}
Content-Type: application/json
X-CSRF-TOKEN: {{ csrf_token() }}

Request Body:
{
    "body": "Pesan yang sudah di-edit"    // required, string, max:1000
}

Rules:
  - Hanya sender yang bisa edit
  - Hanya pesan yang belum di-delete
  - Batas waktu edit: 15 menit setelah kirim (configurable)
  - Jika pesan punya media-only (tanpa body), body bisa di-set

Response 200:
{
    "success": true,
    "message": "Pesan berhasil diedit",
    "data": {
        "id": 67,
        "body": "Pesan yang sudah di-edit",
        "is_edited": true,
        "edited_at": "2026-09-16T10:35:00+08:00",
        "updated_at": "2026-09-16T10:35:00+08:00"
    }
}

Response 403:
{ "success": false, "message": "Tidak bisa edit pesan orang lain" }

Response 422:
{ "success": false, "message": "Batas waktu edit telah lewat (15 menit)" }
```

### 5.5 Messages — Hapus Pesan

```
DELETE /messages/{id}
X-CSRF-TOKEN: {{ csrf_token() }}

Types (query param):
  type    string    "soft" (default) | "hard"

Rules:
  - Soft delete: body berubah menjadi "[Pesan dihapus]", tetap tampil di riwayat
  - Hard delete: hapus total dari DB (hanya untuk pesan sendiri, dalam 5 menit)
  - Hanya sender yang bisa hapus

Response 200 (soft):
{
    "success": true,
    "message": "Pesan dihapus",
    "data": {
        "id": 67,
        "is_deleted": true,
        "deleted_at": "2026-09-16T10:40:00+08:00"
    }
}

Response 200 (hard):
{
    "success": true,
    "message": "Pesan dihapus permanen"
}
```

### 5.6 Reactions — Tambah/Hapus Emoji React

```
POST /messages/{id}/react
Content-Type: application/json
X-CSRF-TOKEN: {{ csrf_token() }}

Request Body:
{
    "emoji": "❤️"          // required, string, emoji single character
}

Rules:
  - Satu user = satu emoji per pesan (toggle)
  - Jika user sudah react emoji yang sama → hapus reaction (un-react)
  - Jika user react emoji beda → ganti emoji lama dengan baru
  - Emoji divalidasi dari daftar yang diizinkan (configurable)

Response 200:
{
    "success": true,
    "message": "Reaction updated",
    "data": {
        "message_id": 67,
        "emoji": "❤️",
        "reactions": [
            { "emoji": "❤️", "count": 3, "users": [1, 2, 3] },
            { "emoji": "👍", "count": 1, "users": [4] }
        ]
    }
}
→ Backend publish event reaction:{message_id} ke Redis (room conversation)
```

### 5.7 Messages — Tandai Sudah Dibaca

```
POST /api/conversations/{user_id}/mark-read
Content-Type: application/json
X-CSRF-TOKEN: {{ csrf_token() }}

Request Body:
{
    "up_to_message_id": 67        // optional, tandai sampai ID ini
}

Response 200:
{
    "success": true,
    "message": "Pesan ditandai dibaca",
    "data": {
        "unread_count": 0
    }
}
→ Emit event message:read ke pengirim pesan
```

### 5.8 Unread Count

```
GET /api/unread-count

Response 200:
{
    "success": true,
    "data": {
        "total_unread": 5,
        "conversations": {
            "2": 3,       // user_id => unread count
            "5": 2
        }
    }
}
```

---

## 6. ⚡ Socket.IO Event Contract

### 6.1 Koneksi & Autentikasi

```javascript
// FRONTEND: Cara koneksi (wajib ikuti pattern ini)
import { io } from 'socket.io-client';

const socket = io(SOCKET_URL, {
    auth: {
        token: socketToken,   // dari GET /socket-token
        user_id: currentUser.id
    },
    transports: ['websocket'],
    withCredentials: true
});
```

**Event: Connection**
```
Client → Server: connect (dengan auth token)
Server → Client:
  - Jika token valid: 'connected' { user_id, socket_id }
  - Jika token invalid: 'error' { message: 'Authentication failed' } → disconnect
```

### 6.2 Presence Events (Online/Offline)

| Event | Arah | Trigger | Payload |
|-------|------|---------|---------|
| `presence:snapshot` | Server → Client | Saat client pertama connect | `{ users: [{ user_id, status }] }` |
| `presence:update` | Server → All | User online/offline berubah | `{ user_id, status: "online"\|"offline" }` |

**Frontend wajib:**
- Update badge/status di contact list saat terima `presence:update`
- Update header conversation saat status berubah
- Tampilkan indikator hijau (online) / abu-abu (offline)

### 6.3 Handshake Events

| Event | Arah | Payload |
|-------|------|---------|
| `handshake:start` | Client → Server | `{ target_user_id }` |
| `handshake:request` | Server → Target Client | `{ from_user_id, from_user_name }` |
| `handshake:accept` | Target Client → Server | `{ from_user_id }` |
| `handshake:complete` | Server → Both Clients | `{ with_user_id, status: "ready" }` |

**Alur:**
1. User A buka conversation dengan B → emit `handshake:start({target_user_id: B})`
2. Server cek: B online?
   - ❌ Offline → retry otomatis (frontend show "menunggu...")
   - ✅ Online → server emit `handshake:request` ke B
3. B terima request → (bisa show dialog atau auto-accept) → emit `handshake:accept`
4. Server emit `handshake:complete` ke A dan B
5. Conversation siap untuk kirim pesan realtime

**Catatan:** Handshake disimpan di Redis dengan TTL. Idempotent — handshake ganda tidak overwrite status yang sudah aktif.

### 6.4 Message Events

| Event | Arah | Payload |
|-------|------|---------|
| `message:new` | Server → Client (room) | Lihat payload di bawah |
| `message:edited` | Server → Client (room) | `{ id, body, edited_at, is_edited }` |
| `message:deleted` | Server → Client (room) | `{ id, is_deleted, deleted_at }` |
| `message:delivery` | Server → Sender | `{ message_id, recipient_id, delivered_at }` |
| `message:read` | Server → Sender | `{ reader_id, up_to_message_id, read_at }` |
| `acknowledge` | Client → Server (ACK) | `{ ok: boolean, message_id }` |

**Payload `message:new`:**
```json
{
    "id": 67,
    "sender_id": 1,
    "sender_name": "Andi Pratama",
    "recipient_id": 2,
    "body": "Halo!",
    "reply_to_id": null,
    "reply_to_preview": null,
    "attachments": [
        {
            "id": 10,
            "type": "image",
            "url": "/storage/media/images/abc123.jpg",
            "thumbnail_url": "/storage/media/thumbs/abc123.jpg",
            "file_name": "photo.jpg",
            "file_size": 245000,
            "mime_type": "image/jpeg"
        }
    ],
    "reactions": [],
    "is_edited": false,
    "created_at": "2026-09-16T10:30:00+08:00"
}
```

**Wajib:** Setelah terima `message:new`, client HARUS emit `acknowledge`:
```javascript
socket.emit('acknowledge', { ok: true, message_id: message.id });
```

### 6.5 Typing Indicator

| Event | Arah | Payload |
|-------|------|---------|
| `typing:start` | Client → Server | `{ conversation_user_id }` |
| `typing:stop` | Client → Server | `{ conversation_user_id }` |
| `typing:indicator` | Server → Room | `{ user_id, user_name, is_typing: true\|false }` |

**Frontend rules:**
- Emit `typing:start` saat user mulak ketik di input
- Emit `typing:stop` setelah 3 detik tidak ada ketikan (debounce)
- Tampilkan "{User} sedang mengetik..." maksimal 5 detik lalu hide otomatis
- Server akan broadcast `typing:stop` jika client disconnect tanpa stop

### 6.6 Reaction Events (Realtime)

| Event | Arah | Payload |
|-------|------|---------|
| `reaction:updated` | Server → Room | `{ message_id, reactions: [{ emoji, count, users }] }` |

### 6.7 Notification Events

Lihat [Section 9](#9--notification-contract).

---

## 7. 🗄️ Data Models & Schema

### 7.1 users

| Kolom | Tipe | Keterangan |
|-------|------|------------|
| id | BIGINT UNSIGNED, AUTO INCREMENT | Primary Key |
| name | VARCHAR(100) | Nama tampilan |
| email | VARCHAR(255), UNIQUE | Email login |
| password | VARCHAR(255), hashed | Bcrypt hash |
| avatar | VARCHAR(255), NULLABLE | Path foto profil |
| last_seen_at | TIMESTAMP, NULLABLE | Terakhir online |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

### 7.2 messages

| Kolom | Tipe | Keterangan |
|-------|------|------------|
| id | BIGINT UNSIGNED, AUTO INCREMENT | Primary Key |
| sender_id | BIGINT UNSIGNED, FK → users.id | Pengirim |
| recipient_id | BIGINT UNSIGNED, FK → users.id | Penerima (DM) |
| body | TEXT, NULLABLE | Isi teks pesan |
| reply_to_id | BIGINT UNSIGNED, NULLABLE, FK → messages.id | ID pesan yang direply |
| is_edited | BOOLEAN, DEFAULT FALSE | Flag edit |
| is_deleted | BOOLEAN, DEFAULT FALSE | Flag soft delete |
| deleted_at | TIMESTAMP, NULLABLE | Waktu hapus |
| delivered_at | TIMESTAMP, NULLABLE | Waktu terkirim ke penerima (via ACK) |
| read_at | TIMESTAMP, NULLABLE | Waktu dibaca penerima |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

### 7.3 media_attachments

| Kolom | Tipe | Keterangan |
|-------|------|------------|
| id | BIGINT UNSIGNED, AUTO INCREMENT | Primary Key |
| message_id | BIGINT UNSIGNED, FK → messages.id | Pesan terkait |
| type | ENUM('image','video','audio','file') | Jenis media |
| file_path | VARCHAR(500) | Path storage |
| thumbnail_path | VARCHAR(500), NULLABLE | Thumbnail (untuk image/video) |
| original_name | VARCHAR(255) | Nama file asli |
| mime_type | VARCHAR(100) | MIME type |
| file_size | INTEGER | Ukuran byte |
| created_at | TIMESTAMP | |

### 7.4 message_reactions

| Kolom | Tipe | Keterangan |
|-------|------|------------|
| id | BIGINT UNSIGNED, AUTO INCREMENT | Primary Key |
| message_id | BIGINT UNSIGNED, FK → messages.id | Pesan |
| user_id | BIGINT UNSIGNED, FK → users.id | User yang react |
| emoji | VARCHAR(50) | Emoji (e.g. ❤️, 👍, 😂) |
| created_at | TIMESTAMP | |
| UNIQUE: (message_id, user_id) — satu user satu emoji per pesan |

### 7.5 notifications

| Kolom | Tipe | Keterangan |
|-------|------|------------|
| id | BIGINT UNSIGNED, AUTO INCREMENT | Primary Key |
| user_id | BIGINT UNSIGNED, FK → users.id | Penerima notif |
| type | VARCHAR(50) | e.g. "chat.message" |
| title | VARCHAR(255) | Judul singkat |
| body | TEXT, NULLABLE | Isi notif |
| data | JSON, NULLABLE | Data tambahan (message_id, url, dll) |
| read_at | TIMESTAMP, NULLABLE | Waktu dibaca |
| created_at | TIMESTAMP | |

---

## 8. 📎 Media Upload Contract

### 8.1 Upload Endpoint

```
POST /media/upload
Content-Type: multipart/form-data
X-CSRF-TOKEN: {{ csrf_token() }}

Form Data:
  file        required   file    File yang diupload
  type        optional   string  Auto-detect dari mime, bisa override: "image"|"video"|"audio"|"file"

Rules:
  - Max file size: 25MB (configurable via env)
  - Allowed types:
    - Image: jpg, jpeg, png, gif, webp (max 10MB)
    - Video: mp4, webm, mov (max 25MB)
    - Audio: mp3, m4a, ogg, wav (max 10MB) — untuk voice note
    - File: pdf, doc, docx, xls, xlsx, zip, rar (max 25MB)
  - Image > 2MB auto-compress & generate thumbnail
  - Video generate thumbnail (frame pertama)
  - File disimpan di `storage/app/public/media/{type}/`
  - Accessible via `/storage/media/{type}/{filename}`

Response 201:
{
    "success": true,
    "data": {
        "id": 10,
        "type": "image",
        "url": "/storage/media/images/abc123.jpg",
        "thumbnail_url": "/storage/media/thumbs/abc123.jpg",
        "original_name": "photo.jpg",
        "file_size": 245000,
        "mime_type": "image/jpeg"
    }
}
```

### 8.2 Upload + Kirim Pesan (Combined Flow)

Frontend bisa choose salah satu flow:

**Flow A: Upload dulu, kirim pesan setelah dapat URL**
1. `POST /media/upload` → dapat `media_id` + `url`
2. Simpan `media_id` di state
3. `POST /messages` dengan body + referensi media (atau backend attach via message_id setelah create)

**Flow B: Upload bersamaan pesan (recommended UX)**
1. Frontend upload ke `POST /media/upload` (background)
2. Setelah dapat response, kirim `POST /messages` dengan `attachment_ids: [10, 11]`
3. Backend attach media ke message

> **CATATAN:** Flow exact (A vs B) akan dikonfirmasi setelah backend implementasi. Untuk sini, frontend anggap **Flow A** dulu.

### 8.3 Voice Note Khusus

Voice note adalah audio dengan treatment khusus:

```
Spesifikasi Voice Note:
- Format: WebM (opus codec) atau MP3
- Recording: gunakan MediaRecorder API browser
- Max duration: 300 detik (5 menit)
- Auto-stop setelah batas
- Waveform preview opsional (frontend kerjaan)
- Player custom dengan progress bar
- Type di backend: "audio"
- Tampilkan icon 🎤 di chat bubble
```

### 8.4 Image Preview

```
- Sebelum upload: tampilkan preview lokal (URL.createObjectURL)
- Setelah upload: ganti URL dengan URL dari server
- Thumbnail: gunakan thumbnail_url untuk tampilan di chat bubble
- Fullsize: buka di modal/lightbox saat klik
```

---

## 9. 🔔 Notification Contract

### 9.1 Endpoint: Ambil Notifikasi

```
GET /api/notifications

Query Params:
  page        integer   default:1
  per_page    integer   default:20
  only_unread bool      default:false

Response 200:
{
    "success": true,
    "data": [
        {
            "id": 15,
            "type": "chat.message",
            "title": "Pesan baru dari Andi Pratama",
            "body": "Halo, apa kabar?",
            "data": {
                "message_id": 67,
                "sender_id": 1,
                "url": "/chat?user=1"
            },
            "read_at": null,
            "created_at": "2026-09-16T10:30:00+08:00"
        }
    ],
    "unread_count": 5
}
```

### 9.2 Endpoint: Tandai Dibaca

```
// Tandai 1 notifikasi
POST /api/notifications/{id}/read
→ Response 200: { "success": true }

// Tandai semua sudah dibaca
POST /api/notifications/read-all
→ Response 200: { "success": true, "message": "Semua notifikasi ditandai dibaca" }
```

### 9.3 Socket.IO Notification Events

| Event | Arah | Payload |
|-------|------|---------|
| `notification:new` | Server → User Room | `{ id, type, title, body, data: {}, created_at }` |
| `notification:delivery` | Server → Sender ACK | `{ notification_id, delivered_at }` |

**Payload `notification:new` (lengkap):**
```json
{
    "id": "notification-15",
    "type": "chat.message",
    "recipient_id": 2,
    "title": "Pesan baru dari Andi Pratama",
    "body": "Halo, apa kabar?",
    "data": {
        "message_id": 67,
        "sender_id": 1,
        "url": "/chat?user=1"
    },
    "created_at": "2026-09-16T10:30:00+08:00"
}
```

**Frontend wajib:**
1. Terima `notification:new` → show toast (auto-hide 5 detik)
2. Increment notification badge counter
3. Simpan ID notifikasi yang sudah ditampilkan (cegah duplikat toast)
4. Emit ACK: `socket.emit('acknowledge', { ok: true, notification_id: notif.id })`
5. Klik toast → redirect ke URL di `data.url`
6. Klik badge → buka halaman daftar notifikasi

---

## 10. ❌ Error Format

### HTTP Status Code Convention

| Code | Makna | Contoh Penggunaan |
|------|-------|-------------------|
| 200 | OK | Sukses operasi |
| 201 | Created | Create berhasil |
| 400 | Bad Request | Request body tidak valid |
| 401 | Unauthorized | Belum login / session expired |
| 403 | Forbidden | Tidak punya akses |
| 404 | Not Found | Resource tidak ada |
| 413 | Payload Too Large | File terlalu besar |
| 422 | Unprocessable Entity | Validasi gagal |
| 429 | Too Many Requests | Rate limit |
| 500 | Internal Server Error | Error tak terduga |

### Error Response Structure (selalu konsisten)

```json
{
    "success": false,
    "message": "Human-readable error message (Indonesia)",
    "errors": {
        "field_name": ["Error detail 1", "Error detail 2"]
    },
    "error_code": "VALIDATION_ERROR"    // optional, machine-readable
}
```

---

## 11. 🌿 Branch & Git Strategy

### Struktur Branch

```
main                    ← Production-ready code
  ├─ develop            ← Integration branch
  │   ├─ backend        ← Backend features
  │   ├─ frontend       ← Frontend features
  │   └─ fix/xxx        ← Bugfix
```

### Aturan Kerja

1. **Backend dan frontend kerja di branch TERPISAH** dari `develop`
   - Backend: `backend` branch
   - Frontend: `frontend` branch
2. **Setiap fitur di sub-branch** jika diperlukan
   - Contoh: `backend/auth`, `backend/messages`, `frontend/chat-ui`
3. **Pull Request WAJIB** ke `develop`, tidak langs push ke main
4. **Conflict prevention:**
   - Backend edit file di `app/`, `database/`, `routes/`, `websocket/`
   - Frontend edit file di `resources/views/`, `resources/css/`, `resources/js/`
   - Shared files (`CONTRACT.md`) → koordinasi di chat/issue
5. **Commit message convention:**
   ```
   feat: tambah endpoint edit pesan
   fix: bug handshake timeout
   style: format CSS chat bubble
   docs: update CONTRACT.md media upload
   refactor: pisah ChatController jadi Service
   ```
6. **Jangan commit:** `.env`, `node_modules/`, `vendor/`, `.DS_Store`, `storage/app/public/media/*`

### .gitignore (wajib ada)

```
.env
.env.backup
vendor/
node_modules/
.DS_Store
Thumbs.db
*.log
storage/debugbar/
storage/framework/cache/data/*
storage/framework/sessions/*
storage/framework/views/*
storage/logs/*.log
storage/app/public/media/*
storage/app/public/thumbs/*
.phpunit.result.cache
hotfix
.idea/
.vscode/
```

---

## 12. ⚙️ Environment Config

### .env.example (ini yang di-commit)

```env
# === APP ===
APP_NAME=Amigo
APP_ENV=local
APP_KEY=base64:generate-with-php-artisan-key-generate
APP_DEBUG=true
APP_URL=http://localhost:8000

# === DATABASE ===
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=amigo_chat
DB_USERNAME=root
DB_PASSWORD=

# === REDIS ===
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_CHAT_CHANNEL=chat.messages
REDIS_NOTIFICATION_CHANNEL=chat.notifications

# === SOCKET.IO (WEBSOCKET) ===
SOCKET_URL=http://localhost:3001
SOCKET_PORT=3001
SOCKET_SECRET=sama-di-laravel-dan-node
SOCKET_ALLOWED_ORIGINS=http://localhost:8000

# === MEDIA UPLOAD ===
MEDIA_MAX_FILE_SIZE=25600          # in KB (25MB)
MEDIA_MAX_IMAGE_SIZE=10240         # in KB (10MB)
MEDIA_ALLOWED_IMAGE_TYPES=jpg,jpeg,png,gif,webp
MEDIA_ALLOWED_VIDEO_TYPES=mp4,webm,mov
MEDIA_ALLOWED_AUDIO_TYPES=mp3,m4a,ogg,wav
MEDIA_ALLOWED_FILE_TYPES=pdf,doc,docx,xls,xlsx,zip,rar

# === SESSION ===
SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_DOMAIN=localhost

# === EDIT MESSAGE ===
MESSAGE_EDIT_WINDOW_MINUTES=15
MESSAGE_HARD_DELETE_MINUTES=5
```

---

## 13. ⏳ Deferred / TODO (Belum Implementasi Sekarang)

| # | Fitur | Status | Catatan |
|---|-------|--------|---------|
| D1 | **Reply-in-Reply** | ⏸️ Deferred | Masih didiskusikan. Kalau jadi, perlu kolom `thread_root_id` di tabel messages |
| D2 | Group Chat | 📋 Future | Perlu tabel `conversations` + `conversation_users` (many-to-many) |
| D3 | Message Search | 📋 Future | Fulltext search di body pesan |
| D4 | Block User | 📋 Future | Tabel `blocked_users` |
| D5 | End-to-End Encryption | 📋 Future | Complex, perlu crypto library |
| D6 | Message Scheduling | 📋 Future | Kolom `scheduled_at` |
| D7 | Voice/Video Call | 📋 Future | Butuh WebRTC |
| D8 | Internationalization (i18n) | 📋 Future | Multi-bahasa |

---

## 📌 Checklist Sebelum Mulai Coding

### Untuk Frontend (Teman)
- [ ] Baca CONTRACT.md keseluruhan
- [ ] Setup environment: Node.js 18+, npm
- [ ] Jalankan `npm install` dan `npm run dev` (Vite)
- [ ] Pastikan akses ke `http://localhost:8000` (Laravel)
- [ ] Pastikan Socket.IO server jalan di `http://localhost:3001`
- [ ] Test koneksi Socket.IO dengan token dari `/socket-token`
- [ ] Gunakan `textContent` untuk semua render user input
- [ ] Jangan edit file di `app/`, `database/`, `routes/` (kecuali koordinasi)

### Untuk Backend (Anda)
- [ ] Setup Laravel project
- [ ] Jalankan migration dan seeder
- [ ] Pastikan Redis berjalan
- [ ] Pastikan Node.js + Socket.IO server berjalan
- [ ] Implementasi endpoint sesuai contract
- [ ] Test setiap endpoint dengan Postman/curl sebelum notify frontend
- [ ] Update CONTRACT.md jika ada perubahan signature

---

## 🔄 Changelog

| Versi | Tanggal | Perubahan | Author |
|-------|---------|-----------|--------|
| 1.0.0 | 2026-09-16 | Initial contract: Auth, Messages (CRUD), Media, Reactions, Presence, Handshake, Notifications, Socket.IO events | Backend Lead |

---

> **Document End**  
> Repository: https://github.com/Rofiq-Ridhani/Amigo.git  
> Jika ada yang tidak jelas atau perlu clarifikasi → buka issue di repo atau diskusi langsung.  
> **Jangan pernah assume — selalu cross-check ke CONTRACT.md dulu.** 🚀
