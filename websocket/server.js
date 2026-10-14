// file: websocket/server.js
// Server Socket.IO Amigo — Fase 1B: autentikasi socket (JWT), room user:{id}, presence.
// Kontrak: AMIGO_TECHNICAL_CONTRACT.md §E.1, §E.2, §E.3 (presence:*), §H.1, §D.9.
// Fase 2 akan menambah: subscriber Redis, message:new + ACK, typing, message:delivered.

const path = require('node:path');
const http = require('node:http');
const https = require('node:https');
const crypto = require('node:crypto');

// .env ada di ROOT project (bukan di folder websocket). Variabel yang sudah ada di
// environment proses TIDAK ditimpa (berguna untuk tes).
require('dotenv').config({ path: path.resolve(__dirname, '..', '.env') });

const jwt = require('jsonwebtoken');
const { Server } = require('socket.io');

// ---------- Konfigurasi ----------
const PORT = Number(process.env.SOCKET_PORT || 3001);
const SOCKET_SECRET = process.env.SOCKET_SECRET || '';
const INTERNAL_SECRET = process.env.INTERNAL_API_SECRET || '';
const ALLOWED_ORIGINS = (process.env.SOCKET_ALLOWED_ORIGINS || '')
  .split(',')
  .map((s) => s.trim())
  .filter(Boolean);
const LARAVEL_URL = process.env.LARAVEL_INTERNAL_URL || 'http://127.0.0.1:8000';
// Jika Nginx memilih site berdasarkan nama host (mis. amigo.test), isi ini.
const LARAVEL_HOST_HEADER = process.env.LARAVEL_INTERNAL_HOST || '';
const PRESENCE_GRACE_MS = Number(process.env.PRESENCE_GRACE_MS || 5000);

function fatal(msg) {
  console.error(`[amigo-ws] FATAL: ${msg}`);
  process.exit(1);
}
if (!SOCKET_SECRET) fatal('SOCKET_SECRET belum diisi di .env root.');
if (!INTERNAL_SECRET) fatal('INTERNAL_API_SECRET belum diisi di .env root.');
if (ALLOWED_ORIGINS.length === 0) fatal('SOCKET_ALLOWED_ORIGINS belum diisi di .env root.');

const log = (...args) => console.log(new Date().toISOString(), '[amigo-ws]', ...args);

// ---------- Util ----------
const isoNow = () => new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');

/** Envelope seragam server -> client (kontrak §E.2). */
function envelope(data) {
  return { event_id: crypto.randomUUID(), occurred_at: isoNow(), data };
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * POST JSON ke endpoint internal Laravel dengan tanda tangan HMAC (kontrak §D.9).
 * Mengembalikan { status, body }. Melempar error bila jaringan gagal.
 */
function postInternal(urlPath, payload) {
  return new Promise((resolve, reject) => {
    const raw = JSON.stringify(payload);
    const timestamp = String(Math.floor(Date.now() / 1000));
    const signature = crypto
      .createHmac('sha256', INTERNAL_SECRET)
      .update(`${timestamp}.${raw}`)
      .digest('hex');

    const target = new URL(urlPath, LARAVEL_URL);
    const client = target.protocol === 'https:' ? https : http;

    const headers = {
      'Content-Type': 'application/json',
      'Content-Length': Buffer.byteLength(raw),
      Accept: 'application/json',
      'X-Internal-Timestamp': timestamp,
      'X-Internal-Signature': signature,
    };
    if (LARAVEL_HOST_HEADER) headers.Host = LARAVEL_HOST_HEADER;

    const req = client.request(target, { method: 'POST', headers, timeout: 5000 }, (res) => {
      let body = '';
      res.on('data', (c) => (body += c));
      res.on('end', () => resolve({ status: res.statusCode, body }));
    });
    req.on('timeout', () => req.destroy(new Error('timeout')));
    req.on('error', reject);
    req.write(raw);
    req.end();
  });
}

/** Retry 3x (0,5 / 1 / 2 detik). Status 4xx dianggap permanen (tidak diulang). */
async function postInternalWithRetry(urlPath, payload) {
  const delays = [500, 1000, 2000];
  for (let attempt = 0; attempt <= delays.length; attempt++) {
    try {
      const res = await postInternal(urlPath, payload);
      if (res.status >= 200 && res.status < 300) return true;
      if (res.status >= 400 && res.status < 500) {
        log(`internal ${urlPath} ditolak (${res.status}); tidak diulang`);
        return false;
      }
      log(`internal ${urlPath} gagal (${res.status})`);
    } catch (err) {
      log(`internal ${urlPath} error jaringan: ${err.message}`);
    }
    if (attempt < delays.length) await sleep(delays[attempt]);
  }
  return false;
}

// ---------- Server HTTP + Socket.IO ----------
const httpServer = http.createServer((req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: true, online_users: onlineUsers.size }));
    return;
  }
  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ ok: false }));
});

const io = new Server(httpServer, {
  cors: { origin: ALLOWED_ORIGINS, methods: ['GET', 'POST'] },
  // Kontrak §E.1 no. 4: Origin harus ada di SOCKET_ALLOWED_ORIGINS
  allowRequest: (req, callback) => {
    const origin = req.headers.origin;
    callback(null, Boolean(origin) && ALLOWED_ORIGINS.includes(origin));
  },
});

// ---------- Autentikasi koneksi (kontrak §E.1) ----------
io.use((socket, next) => {
  const token = socket.handshake.auth && socket.handshake.auth.token;
  if (typeof token !== 'string' || token === '') return next(new Error('unauthorized'));

  jwt.verify(token, SOCKET_SECRET, { algorithms: ['HS256'], audience: 'amigo-socket' }, (err, payload) => {
    if (err) return next(new Error('unauthorized'));

    const userId = Number(payload.sub);
    if (!Number.isInteger(userId) || userId <= 0) return next(new Error('unauthorized'));

    // Identitas HANYA dari token. Payload apa pun dari client tidak dipercaya.
    socket.data.userId = userId;
    next();
  });
});

// ---------- Presence (kontrak §H.1) ----------
/** userId -> Set<socketId> (satu instance Node; D-11) */
const socketsByUser = new Map();
/** User yang saat ini dianggap online (sudah di-broadcast) */
const onlineUsers = new Set();
/** userId -> timer grace offline */
const offlineTimers = new Map();

io.on('connection', (socket) => {
  const userId = socket.data.userId;

  socket.join(`user:${userId}`); // satu-satunya room; dibuat server

  // Ada yang kembali sebelum grace habis (mis. refresh halaman) -> batalkan offline
  const pending = offlineTimers.get(userId);
  if (pending) {
    clearTimeout(pending);
    offlineTimers.delete(userId);
  }

  if (!socketsByUser.has(userId)) socketsByUser.set(userId, new Set());
  socketsByUser.get(userId).add(socket.id);

  if (!onlineUsers.has(userId)) {
    onlineUsers.add(userId);
    socket.broadcast.emit(
      'presence:update',
      envelope({ user_id: userId, status: 'online', last_seen_at: null }),
    );
  }

  // Socket baru menerima snapshot seluruh user online (termasuk dirinya)
  socket.emit('presence:snapshot', envelope({ online_user_ids: [...onlineUsers].sort((a, b) => a - b) }));

  log(`connect user=${userId} sockets=${socketsByUser.get(userId).size}`);

  socket.on('disconnect', () => {
    const set = socketsByUser.get(userId);
    if (set) {
      set.delete(socket.id);
      if (set.size > 0) {
        log(`disconnect user=${userId} (masih ada ${set.size} socket)`);
        return;
      }
      socketsByUser.delete(userId);
    }

    log(`disconnect user=${userId} (socket terakhir; grace ${PRESENCE_GRACE_MS}ms)`);

    const timer = setTimeout(() => {
      offlineTimers.delete(userId);
      if (socketsByUser.has(userId)) return; // sudah tersambung lagi

      onlineUsers.delete(userId);
      const lastSeenAt = isoNow();
      io.emit('presence:update', envelope({ user_id: userId, status: 'offline', last_seen_at: lastSeenAt }));
      log(`offline user=${userId}`);

      // Simpan last_seen_at di database lewat Laravel (kontrak §D.9)
      postInternalWithRetry('/internal/v1/presence', {
        user_id: userId,
        status: 'offline',
        at: lastSeenAt,
      });
    }, PRESENCE_GRACE_MS);

    offlineTimers.set(userId, timer);
  });
});

httpServer.listen(PORT, '0.0.0.0', () => {
  log(`siap di port ${PORT}`);
  log(`origin diizinkan: ${ALLOWED_ORIGINS.join(', ')}`);
  log(`Laravel internal: ${LARAVEL_URL}${LARAVEL_HOST_HEADER ? ` (Host: ${LARAVEL_HOST_HEADER})` : ''}`);
});

function shutdown() {
  log('berhenti...');
  io.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 2000).unref();
}
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
