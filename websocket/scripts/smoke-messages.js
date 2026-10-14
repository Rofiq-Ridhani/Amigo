// file: websocket/scripts/smoke-messages.js
// Uji jalur Redis -> Socket.IO (Fase 2) tanpa browser dan tanpa frontend.
// Jalankan SETELAH `npm start` di terminal lain:   cd websocket && npm run smoke:messages
// Skrip ini berperan sebagai "Laravel": mem-publish envelope ke Redis dengan pesan FIKTIF.
const path = require('node:path');
const http = require('node:http');
require('dotenv').config({ path: path.resolve(__dirname, '..', '..', '.env') });

const jwt = require('jsonwebtoken');
const Redis = require('ioredis');
const { io } = require('socket.io-client');
const crypto = require('node:crypto');

const PORT = Number(process.env.SOCKET_PORT || 3001);
const URL_ = `http://127.0.0.1:${PORT}`;
const SECRET = process.env.SOCKET_SECRET;
const ORIGIN = (process.env.SOCKET_ALLOWED_ORIGINS || '').split(',')[0].trim();
const CHANNEL = process.env.REDIS_CHAT_CHANNEL || 'chat.messages';
// Pesan fiktif tidak ada di database, jadi Laravel asli menjawab "tidak ada yang delivered".
// Set SMOKE_EXPECT_DELIVERY=1 hanya jika Laravel tiruan/pesan asli dipakai.
const EXPECT_DELIVERY = process.env.SMOKE_EXPECT_DELIVERY === '1';

if (!SECRET || !ORIGIN) {
  console.error('SOCKET_SECRET dan SOCKET_ALLOWED_ORIGINS harus ada di .env root.');
  process.exit(1);
}

const redis = new Redis({
  host: process.env.REDIS_HOST || '127.0.0.1',
  port: Number(process.env.REDIS_PORT || 6379),
  password: !process.env.REDIS_PASSWORD || process.env.REDIS_PASSWORD === 'null' ? undefined : process.env.REDIS_PASSWORD,
});

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const token = (sub) => jwt.sign({ sub, aud: 'amigo-socket', jti: 'smoke' }, SECRET, { algorithm: 'HS256', expiresIn: 300 });

let failed = 0;
function check(name, ok, detail = '') {
  console.log(`${ok ? '  PASS' : '  FAIL'}  ${name}${ok || !detail ? '' : ' -> ' + detail}`);
  if (!ok) failed++;
}

function connect(userId, { ack = true } = {}) {
  const socket = io(URL_, {
    auth: { token: token(userId) },
    extraHeaders: { Origin: ORIGIN },
    reconnection: false,
    transports: ['websocket'],
  });
  socket.events = [];
  socket.onAny((name, env) => socket.events.push({ name, env }));
  if (ack) socket.on('message:new', (_env, cb) => typeof cb === 'function' && cb({ ok: true }));
  return socket;
}

const waitFor = (socket, pred, ms = 3000) =>
  new Promise((resolve) => {
    const t0 = Date.now();
    const tick = () => {
      const hit = socket.events.find(pred);
      if (hit) return resolve(hit);
      if (Date.now() - t0 > ms) return resolve(null);
      setTimeout(tick, 50);
    };
    tick();
  });

const health = () =>
  new Promise((resolve) => {
    http
      .get(`${URL_}/health`, (res) => {
        let b = '';
        res.on('data', (c) => (b += c));
        res.on('end', () => resolve(JSON.parse(b)));
      })
      .on('error', () => resolve(null));
  });

function envelopeFor({ to, ackUserId, messageId, body = 'Halo dari smoke test' }) {
  return {
    event_id: crypto.randomUUID(),
    event: 'message:new',
    occurred_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
    to,
    ack_user_id: ackUserId,
    data: {
      message: { id: messageId, conversation_id: 1, sender_id: to[0], recipient_id: to[1], body, status: 'sent', revision: 1 },
    },
  };
}

// Laravel menambahkan prefix pada nama channel; Node memakai pattern "*<channel>"
const publish = (obj, channel = `smoke_database_${CHANNEL}`) =>
  redis.publish(channel, typeof obj === 'string' ? obj : JSON.stringify(obj));

(async () => {
  console.log(`Smoke test Redis -> Socket.IO @ ${URL_}\n`);

  const h = await health();
  check('server hidup dan tersambung ke Redis', !!h && h.redis === true, JSON.stringify(h));
  if (!h) process.exit(1);

  const a = connect(1, { ack: false }); // pengirim
  const b = connect(2); // penerima (otomatis ACK)
  const c = connect(3); // bukan peserta
  await Promise.all([waitFor(a, (e) => e.name === 'presence:snapshot'), waitFor(b, (e) => e.name === 'presence:snapshot'), waitFor(c, (e) => e.name === 'presence:snapshot')]);

  console.log('\n[1] Routing event pesan');
  const env = envelopeFor({ to: [1, 2], ackUserId: 2, messageId: 900001 });
  await publish(env);
  const gotA = await waitFor(a, (e) => e.name === 'message:new');
  const gotB = await waitFor(b, (e) => e.name === 'message:new');
  await sleep(500);
  check('pengirim (user 1) menerima message:new', !!gotA);
  check('penerima (user 2) menerima message:new', !!gotB);
  check('user 3 (bukan peserta) TIDAK menerima apa pun', !c.events.some((e) => e.name === 'message:new'));
  check('envelope diteruskan utuh (event_id sama, data.message.id)', gotB?.env.event_id === env.event_id && gotB?.env.data.message.id === 900001);
  check('field internal (to, ack_user_id) tidak bocor ke client', gotB && !('to' in gotB.env) && !('ack_user_id' in gotB.env) && !('event' in gotB.env));

  console.log('\n[2] Status delivered setelah ACK');
  const delivery = await waitFor(a, (e) => e.name === 'message:delivery', 2500);
  if (delivery) {
    check('pengirim menerima message:delivery', true);
    check('message:delivery memuat id pesan', delivery.env.data.message_ids.includes(900001));
  } else if (EXPECT_DELIVERY) {
    check('pengirim menerima message:delivery', false, 'tidak datang');
  } else {
    console.log('  INFO  message:delivery tidak datang: wajar, pesan fiktif tidak ada di database Laravel');
  }

  console.log('\n[3] Ketahanan terhadap data buruk');
  const before = a.events.length + b.events.length + c.events.length;
  await publish('ini bukan json');
  await publish({ event: 'message:new' }); // tidak lengkap
  await publish({ ...envelopeFor({ to: [1, 2], ackUserId: 2, messageId: 900002 }), event: 'presence:update' }); // event tidak diizinkan
  await publish({ ...envelopeFor({ to: [1, 2], ackUserId: 99, messageId: 900003 }) }); // ack_user_id bukan penerima
  await publish({ ...envelopeFor({ to: ['1', 2], ackUserId: 2, messageId: 900004 }) }); // id bukan integer
  await sleep(800);
  check('envelope rusak/ilegal diabaikan (tidak ada event terkirim)', a.events.length + b.events.length + c.events.length === before);
  const h2 = await health();
  check('server tetap hidup setelah data buruk', !!h2 && h2.ok === true);

  console.log('\n[4] message:delivered dari client');
  const bad = await new Promise((resolve) => b.emit('message:delivered', { message_ids: 'x' }, resolve));
  check('payload tidak valid ditolak', bad.ok === false && bad.error.code === 'VALIDATION_ERROR');
  const tooMany = await new Promise((resolve) => b.emit('message:delivered', { message_ids: Array.from({ length: 101 }, (_, i) => i + 1) }, resolve));
  check('lebih dari 100 id ditolak', tooMany.ok === false);
  const good = await new Promise((resolve) => b.emit('message:delivered', { message_ids: [900001] }, resolve));
  check('payload valid diterima', good.ok === true);

  console.log('\n[5] Penerima offline');
  await publish(envelopeFor({ to: [1, 4], ackUserId: 4, messageId: 900005 }));
  await sleep(700);
  const h3 = await health();
  check('pesan untuk user offline tidak membuat server error', !!h3 && h3.ok === true);

  console.log('\n[6] message:read dan unread:update (Fase 3)');
  const readEnv = {
    event_id: crypto.randomUUID(),
    event: 'message:read',
    occurred_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
    to: [1],
    ack_user_id: null,
    data: { conversation_id: 1, reader_id: 2, up_to_message_id: 900001, read_at: '2026-10-14T02:00:00Z' },
  };
  const unreadEnv = {
    event_id: crypto.randomUUID(),
    event: 'unread:update',
    occurred_at: readEnv.occurred_at,
    to: [2],
    ack_user_id: null,
    data: { conversation_id: 1, unread_count: 0, total_unread: 3 },
  };
  // tab kedua milik user 2 untuk memastikan unread:update sampai ke SEMUA tab
  const b2 = connect(2);
  await waitFor(b2, (e) => e.name === 'presence:snapshot');
  const markA = a.events.length;
  const markB = b.events.length;
  const markC = c.events.length;
  await publish(readEnv);
  await publish(unreadEnv);
  const gotRead = await waitFor(a, (e) => e.name === 'message:read');
  const gotUnreadB = await waitFor(b, (e) => e.name === 'unread:update');
  const gotUnreadB2 = await waitFor(b2, (e) => e.name === 'unread:update');
  await sleep(500);
  check('pengirim (user 1) menerima message:read', !!gotRead && gotRead.env.data.up_to_message_id === 900001 && gotRead.env.data.reader_id === 2);
  check('pembaca menerima unread:update di tab pertama', !!gotUnreadB && gotUnreadB.env.data.total_unread === 3);
  check('pembaca menerima unread:update di tab kedua', !!gotUnreadB2);
  check('pengirim TIDAK menerima unread:update milik pembaca', !a.events.slice(markA).some((e) => e.name === 'unread:update'));
  check('pembaca TIDAK menerima message:read yang untuk pengirim', !b.events.slice(markB).some((e) => e.name === 'message:read'));
  check('user 3 tidak menerima apa pun', c.events.length === markC);
  b2.close();

  [a, b, c].forEach((s) => s.close());
  redis.disconnect();
  console.log(failed === 0 ? '\nSEMUA LULUS' : `\n${failed} TES GAGAL`);
  process.exit(failed === 0 ? 0 : 1);
})();
