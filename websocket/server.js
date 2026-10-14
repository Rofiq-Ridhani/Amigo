// file: websocket/server.js
// Server Socket.IO Amigo.
//   Fase 1B: autentikasi socket (JWT), room user:{id}, presence.
//   Fase 2 : subscriber Redis -> message:new (+ACK) -> delivered, message:delivered dari client,
//            sync:required saat Redis tersambung kembali.
// Kontrak: AMIGO_TECHNICAL_CONTRACT.md §E, §H, §D.9, §J.
// Akan menyusul: typing (Fase 5), event notifikasi (Fase 4), dll.

const path = require('node:path');
const http = require('node:http');
const https = require('node:https');
const crypto = require('node:crypto');

// .env ada di ROOT project (bukan di folder websocket). Variabel yang sudah ada di
// environment proses TIDAK ditimpa (berguna untuk tes).
require('dotenv').config({ path: path.resolve(__dirname, '..', '.env') });

const jwt = require('jsonwebtoken');
const Redis = require('ioredis');
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
const ACK_TIMEOUT_MS = Number(process.env.ACK_TIMEOUT_MS || 5000);

const REDIS_HOST = process.env.REDIS_HOST || '127.0.0.1';
const REDIS_PORT = Number(process.env.REDIS_PORT || 6379);
// Di .env Laravel, "null" berarti tanpa password
const rawRedisPassword = process.env.REDIS_PASSWORD;
const REDIS_PASSWORD =
  !rawRedisPassword || rawRedisPassword === 'null' || rawRedisPassword === '(null)' ? undefined : rawRedisPassword;
const CHAT_CHANNEL = process.env.REDIS_CHAT_CHANNEL || 'chat.messages';
const NOTIFICATION_CHANNEL = process.env.REDIS_NOTIFICATION_CHANNEL || 'chat.notifications';

// Event yang BOLEH datang dari Redis (daftar putih). Bertambah di fase berikutnya.
const ALLOWED_REDIS_EVENTS = new Set(['message:new']);

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
const isPositiveInt = (n) => Number.isInteger(n) && n > 0;
const isPlainObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

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

    const req = client.request(target, { method: 'POST', headers, timeout: Number(process.env.INTERNAL_TIMEOUT_MS || 15000) }, (res) => {
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

/**
 * Retry 3x (0,5 / 1 / 2 detik). Status 4xx dianggap permanen (tidak diulang).
 * Mengembalikan JSON hasil (object) bila sukses, atau null bila gagal.
 */
async function callInternal(urlPath, payload) {
  const delays = [500, 1000, 2000];
  for (let attempt = 0; attempt <= delays.length; attempt++) {
    try {
      const res = await postInternal(urlPath, payload);
      if (res.status >= 200 && res.status < 300) {
        try {
          return JSON.parse(res.body);
        } catch {
          return {};
        }
      }
      if (res.status >= 400 && res.status < 500) {
        log(`internal ${urlPath} ditolak (${res.status}); tidak diulang`);
        return null;
      }
      log(`internal ${urlPath} gagal (${res.status})`);
    } catch (err) {
      log(`internal ${urlPath} error jaringan: ${err.message}`);
    }
    if (attempt < delays.length) await sleep(delays[attempt]);
  }
  return null;
}

// ---------- Server HTTP + Socket.IO ----------
let redisReady = false;

const httpServer = http.createServer((req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: true, online_users: onlineUsers.size, redis: redisReady }));
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
    if (!isPositiveInt(userId)) return next(new Error('unauthorized'));

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

// ---------- Delivered (kontrak §E.5, §H.2) ----------
/**
 * Tandai pesan sebagai delivered di Laravel lalu beri tahu para pengirim lewat `message:delivery`.
 * Laravel yang menentukan pesan mana yang sah (hanya milik penerima & belum delivered).
 */
async function markDelivered(recipientId, messageIds) {
  const result = await callInternal('/internal/v1/messages/delivered', {
    recipient_id: recipientId,
    message_ids: messageIds,
  });
  if (!result) return;

  const delivered = (result.data && result.data.delivered) || [];

  // Kelompokkan per (pengirim, percakapan)
  const groups = new Map();
  for (const item of delivered) {
    if (!isPositiveInt(item.id) || !isPositiveInt(item.sender_id) || !isPositiveInt(item.conversation_id)) continue;
    const key = `${item.sender_id}:${item.conversation_id}`;
    if (!groups.has(key)) {
      groups.set(key, {
        sender_id: item.sender_id,
        conversation_id: item.conversation_id,
        message_ids: [],
        delivered_at: item.delivered_at,
      });
    }
    groups.get(key).message_ids.push(item.id);
  }

  for (const g of groups.values()) {
    io.to(`user:${g.sender_id}`).emit(
      'message:delivery',
      envelope({ conversation_id: g.conversation_id, message_ids: g.message_ids, delivered_at: g.delivered_at }),
    );
    log(`delivered conversation=${g.conversation_id} ids=${g.message_ids.join(',')} -> pengirim ${g.sender_id}`);
  }
}

// ---------- Subscriber Redis (kontrak §E.2) ----------
/** Validasi bentuk envelope dari Laravel. Mengembalikan null bila tidak valid. */
function parseRedisEnvelope(raw) {
  let env;
  try {
    env = JSON.parse(raw);
  } catch {
    return null;
  }

  if (!isPlainObject(env)) return null;
  if (typeof env.event_id !== 'string' || typeof env.occurred_at !== 'string') return null;
  if (typeof env.event !== 'string' || !ALLOWED_REDIS_EVENTS.has(env.event)) return null;
  if (!Array.isArray(env.to) || env.to.length === 0 || !env.to.every(isPositiveInt)) return null;
  if (!isPlainObject(env.data)) return null;
  if (env.ack_user_id !== null && env.ack_user_id !== undefined) {
    if (!isPositiveInt(env.ack_user_id) || !env.to.includes(env.ack_user_id)) return null;
  }
  return env;
}

function dispatchEnvelope(env) {
  const payload = { event_id: env.event_id, occurred_at: env.occurred_at, data: env.data };

  for (const userId of new Set(env.to)) {
    const room = `user:${userId}`;

    if (env.ack_user_id === userId) {
      // Penerima yang ACK-nya dihitung: delivered bila minimal satu socketnya membalas ok
      io.in(room)
        .timeout(ACK_TIMEOUT_MS)
        .emit(env.event, payload, (err, responses) => {
          const acked = (responses || []).some((r) => r && r.ok === true);
          if (!acked) {
            log(`${env.event} ke user=${userId}: belum ada ACK (status tetap sent)`);
            return;
          }
          if (env.event === 'message:new') {
            const messageId = env.data.message && env.data.message.id;
            if (isPositiveInt(messageId)) markDelivered(userId, [messageId]);
          }
        });
    } else {
      io.to(room).emit(env.event, payload);
    }
  }
}

function handleRedisMessage(raw) {
  const env = parseRedisEnvelope(raw);
  if (!env) {
    log('envelope Redis tidak valid; diabaikan');
    return;
  }
  dispatchEnvelope(env);
}

const subscriber = new Redis({
  host: REDIS_HOST,
  port: REDIS_PORT,
  password: REDIS_PASSWORD,
  retryStrategy: (times) => Math.min(times * 500, 5000),
});

let redisWasDown = false;
let lastRedisErrorLog = 0;

subscriber.on('error', (err) => {
  redisWasDown = true;
  redisReady = false;
  const now = Date.now();
  if (now - lastRedisErrorLog > 10000) {
    lastRedisErrorLog = now;
    log(`Redis error: ${err.message} (mencoba menyambung kembali)`);
  }
});
subscriber.on('close', () => {
  redisWasDown = true;
  redisReady = false;
});
subscriber.on('ready', () => {
  redisReady = true;
  log(`Redis tersambung (${REDIS_HOST}:${REDIS_PORT})`);
  if (redisWasDown) {
    redisWasDown = false;
    // Event selama Redis putus bisa terlewat: minta semua client sinkron ulang (kontrak §J)
    io.emit('sync:required', envelope({ reason: 'redis_reconnected', server_time: isoNow() }));
    log('sync:required dikirim ke semua client');
  }
});

// Pattern diawali "*" agar tetap cocok dengan prefix Redis milik Laravel
subscriber.psubscribe(`*${CHAT_CHANNEL}`, `*${NOTIFICATION_CHANNEL}`, (err) => {
  if (err) log(`psubscribe gagal: ${err.message}`);
  else log(`subscribe pattern: *${CHAT_CHANNEL}, *${NOTIFICATION_CHANNEL}`);
});
subscriber.on('pmessage', (_pattern, _channel, message) => handleRedisMessage(message));

// ---------- Koneksi socket ----------
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

  // ---- message:delivered (client -> server), kontrak §E.4 ----
  // Dipakai setelah resync REST menerima pesan yang belum pernah di-ACK lewat message:new.
  const deliveredCalls = [];
  socket.on('message:delivered', (payload, ack) => {
    const reply = typeof ack === 'function' ? ack : () => {};

    const ids = payload && payload.message_ids;
    if (!Array.isArray(ids) || ids.length < 1 || ids.length > 100 || !ids.every(isPositiveInt)) {
      return reply({ ok: false, error: { code: 'VALIDATION_ERROR', message: 'message_ids tidak valid' } });
    }

    // Batas 20 panggilan / menit / socket (kontrak §I no. 9)
    const now = Date.now();
    while (deliveredCalls.length && now - deliveredCalls[0] > 60000) deliveredCalls.shift();
    if (deliveredCalls.length >= 20) {
      return reply({ ok: false, error: { code: 'RATE_LIMITED', message: 'Terlalu banyak permintaan' } });
    }
    deliveredCalls.push(now);

    // user_id HANYA dari token; Laravel memfilter pesan yang bukan milik user ini
    markDelivered(userId, ids);
    return reply({ ok: true });
  });

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
      callInternal('/internal/v1/presence', { user_id: userId, status: 'offline', at: lastSeenAt });
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
  subscriber.disconnect();
  io.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 2000).unref();
}
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
