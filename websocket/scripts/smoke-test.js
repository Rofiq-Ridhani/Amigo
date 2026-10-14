// file: websocket/scripts/smoke-test.js
// Uji otomatis server Socket.IO (tanpa browser). Jalankan SETELAH `npm start` di terminal lain:
//   cd websocket && npm run smoke
const path = require('node:path');
require('dotenv').config({ path: path.resolve(__dirname, '..', '..', '.env') });

const jwt = require('jsonwebtoken');
const { io } = require('socket.io-client');

const PORT = Number(process.env.SOCKET_PORT || 3001);
const URL_ = `http://127.0.0.1:${PORT}`;
const SECRET = process.env.SOCKET_SECRET;
const ORIGIN = (process.env.SOCKET_ALLOWED_ORIGINS || '').split(',')[0].trim();
const GRACE = Number(process.env.PRESENCE_GRACE_MS || 5000);

if (!SECRET || !ORIGIN) {
  console.error('SOCKET_SECRET dan SOCKET_ALLOWED_ORIGINS harus ada di .env root.');
  process.exit(1);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const token = (sub, extra = {}) =>
  jwt.sign({ sub, jti: 'smoke', aud: 'amigo-socket', ...extra }, SECRET, { algorithm: 'HS256', expiresIn: 300 });

let failed = 0;
function check(name, ok, detail = '') {
  console.log(`${ok ? '  PASS' : '  FAIL'}  ${name}${ok || !detail ? '' : ' -> ' + detail}`);
  if (!ok) failed++;
}

function connect(tok, origin = ORIGIN) {
  const socket = io(URL_, {
    auth: { token: tok },
    extraHeaders: { Origin: origin },
    reconnection: false,
    transports: ['websocket'],
  });
  socket.events = [];
  socket.onAny((name, env) => socket.events.push({ name, env }));
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

const rejected = (socket) =>
  new Promise((resolve) => {
    socket.on('connect', () => resolve({ ok: false }));
    socket.on('connect_error', (e) => resolve({ ok: true, message: e.message }));
    setTimeout(() => resolve({ ok: false, timeout: true }), 3000);
  });

(async () => {
  console.log(`Smoke test Socket.IO @ ${URL_} (origin ${ORIGIN}, grace ${GRACE}ms)\n`);

  console.log('[1] Autentikasi');
  let s = connect('token-ngawur');
  let r = await rejected(s);
  check('token ngawur ditolak', r.ok && r.message === 'unauthorized', JSON.stringify(r));

  s = connect(jwt.sign({ sub: 1, aud: 'amigo-socket' }, 'rahasia-salah', { algorithm: 'HS256', expiresIn: 300 }));
  r = await rejected(s);
  check('token dengan secret salah ditolak', r.ok && r.message === 'unauthorized');

  s = connect(jwt.sign({ sub: 1, aud: 'amigo-socket', exp: Math.floor(Date.now() / 1000) - 10 }, SECRET));
  r = await rejected(s);
  check('token kedaluwarsa ditolak', r.ok && r.message === 'unauthorized');

  s = connect(jwt.sign({ sub: 1 }, SECRET, { expiresIn: 300 }));
  r = await rejected(s);
  check('token tanpa audience amigo-socket ditolak', r.ok && r.message === 'unauthorized');

  s = connect(token(1), 'http://situs-jahat.example');
  r = await rejected(s);
  check('Origin yang tidak diizinkan ditolak', r.ok);

  console.log('\n[2] Presence');
  const a = connect(token(1));
  const snapA = await waitFor(a, (e) => e.name === 'presence:snapshot');
  check('A menerima presence:snapshot', !!snapA);
  check('snapshot A berisi dirinya saja', JSON.stringify(snapA?.env.data.online_user_ids) === '[1]', JSON.stringify(snapA?.env));
  check('envelope punya event_id & occurred_at', !!snapA?.env.event_id && /Z$/.test(snapA?.env.occurred_at || ''));

  const b = connect(token(2));
  const snapB = await waitFor(b, (e) => e.name === 'presence:snapshot');
  check('snapshot B berisi user 1 dan 2', JSON.stringify(snapB?.env.data.online_user_ids) === '[1,2]');
  const upd = await waitFor(a, (e) => e.name === 'presence:update' && e.env.data.user_id === 2);
  check('A menerima presence:update online untuk B', upd?.env.data.status === 'online' && upd.env.data.last_seen_at === null);

  console.log('\n[3] Banyak tab');
  const b2 = connect(token(2));
  await waitFor(b2, (e) => e.name === 'presence:snapshot');
  await sleep(300);
  check('tab ke-2 B tidak memicu update online kedua', a.events.filter((e) => e.name === 'presence:update' && e.env.data.user_id === 2).length === 1);
  b.close();
  await sleep(GRACE + 800);
  check('tutup 1 tab: B tetap online', !a.events.some((e) => e.name === 'presence:update' && e.env.data.status === 'offline'));

  console.log('\n[4] Refresh halaman (reconnect dalam masa grace)');
  b2.close();
  await sleep(Math.min(300, GRACE / 2));
  const b3 = connect(token(2));
  await waitFor(b3, (e) => e.name === 'presence:snapshot');
  await sleep(GRACE + 800);
  check('reconnect cepat: B tidak dianggap offline', !a.events.some((e) => e.name === 'presence:update' && e.env.data.status === 'offline'));

  console.log('\n[5] Offline setelah semua tab tutup');
  b3.close();
  const off = await waitFor(a, (e) => e.name === 'presence:update' && e.env.data.status === 'offline', GRACE + 3000);
  check('A menerima presence:update offline untuk B', off?.env.data.user_id === 2);
  check('offline membawa last_seen_at (ISO UTC)', /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(off?.env.data.last_seen_at || ''));

  a.close();
  console.log(failed === 0 ? '\nSEMUA LULUS' : `\n${failed} TES GAGAL`);
  process.exit(failed === 0 ? 0 : 1);
})();
