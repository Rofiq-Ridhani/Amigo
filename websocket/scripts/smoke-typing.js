// file: websocket/scripts/smoke-typing.js
// Uji typing indicator (Fase 5). Butuh percakapan NYATA di database (Node bertanya ke Laravel
// siapa pesertanya). Contoh, untuk percakapan id 1 antara user 1 dan 2:
//   cd websocket && npm run smoke:typing -- 1 1 2
// Argumen: <conversation_id> <user A> <user B>. Server harus sedang jalan (npm start).
const path = require('node:path');
require('dotenv').config({ path: path.resolve(__dirname, '..', '..', '.env') });

const jwt = require('jsonwebtoken');
const { io } = require('socket.io-client');

const [conversationId, userA, userB] = process.argv.slice(2).map(Number);
if (![conversationId, userA, userB].every((n) => Number.isInteger(n) && n > 0)) {
  console.error('Pemakaian: npm run smoke:typing -- <conversation_id> <user A> <user B>');
  process.exit(1);
}

const PORT = Number(process.env.SOCKET_PORT || 3001);
const SECRET = process.env.SOCKET_SECRET;
const ORIGIN = (process.env.SOCKET_ALLOWED_ORIGINS || '').split(',')[0].trim();
const EXPIRE = Number(process.env.TYPING_EXPIRE_MS || 5000);
// user ketiga yang BUKAN peserta (dipilih otomatis)
const outsider = [userA, userB].includes(9001) ? 9002 : 9001;

if (!SECRET || !ORIGIN) {
  console.error('SOCKET_SECRET dan SOCKET_ALLOWED_ORIGINS harus ada di .env root.');
  process.exit(1);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const token = (sub) => jwt.sign({ sub, aud: 'amigo-socket', jti: 'smoke' }, SECRET, { algorithm: 'HS256', expiresIn: 300 });

let failed = 0;
function check(name, ok, detail = '') {
  console.log(`${ok ? '  PASS' : '  FAIL'}  ${name}${ok || !detail ? '' : ' -> ' + detail}`);
  if (!ok) failed++;
}

function connect(userId) {
  const socket = io(`http://127.0.0.1:${PORT}`, {
    auth: { token: token(userId) },
    extraHeaders: { Origin: ORIGIN },
    reconnection: false,
    transports: ['websocket'],
  });
  socket.typing = [];
  socket.on('typing:update', (env) => socket.typing.push(env));
  return socket;
}

const emit = (socket, event, payload) => new Promise((resolve) => socket.emit(event, payload, resolve));
const connected = (socket) => new Promise((resolve) => socket.on('connect', resolve));
const waitTyping = async (socket, pred, ms = 3000) => {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    const hit = socket.typing.find(pred);
    if (hit) return hit;
    await sleep(50);
  }
  return null;
};

(async () => {
  console.log(`Smoke test typing: percakapan ${conversationId}, user ${userA} <-> ${userB}\n`);

  const a = connect(userA);
  const b = connect(userB);
  const c = connect(outsider);
  await Promise.all([connected(a), connected(b), connected(c)]);

  console.log('[1] typing:start');
  const ack1 = await emit(a, 'typing:start', { conversation_id: conversationId });
  check('typing:start diterima (ack ok)', ack1 && ack1.ok === true, JSON.stringify(ack1));
  const t1 = await waitTyping(b, (e) => e.data.is_typing === true);
  check('lawan bicara menerima typing:update is_typing=true', !!t1 && t1.data.user_id === userA && t1.data.conversation_id === conversationId);
  check('envelope punya event_id & occurred_at', !!t1?.event_id && /Z$/.test(t1?.occurred_at || ''));
  check('pengirim TIDAK menerima typing:update miliknya', a.typing.length === 0);

  console.log('\n[2] Throttle 1 start / 2 detik');
  await sleep(300);
  await emit(a, 'typing:start', { conversation_id: conversationId });
  await sleep(300);
  check('start kedua dalam 2 detik diabaikan', b.typing.filter((e) => e.data.is_typing).length === 1);
  await sleep(1700);
  await emit(a, 'typing:start', { conversation_id: conversationId });
  await sleep(300);
  check('start setelah > 2 detik diteruskan (menyegarkan timer penerima)', b.typing.filter((e) => e.data.is_typing).length === 2);

  console.log('\n[3] typing:stop');
  const stopAck = await emit(a, 'typing:stop', { conversation_id: conversationId });
  check('typing:stop diterima', stopAck && stopAck.ok === true);
  const f1 = await waitTyping(b, (e) => e.data.is_typing === false);
  check('lawan bicara menerima is_typing=false segera', !!f1);

  console.log(`\n[4] Kedaluwarsa otomatis setelah ${EXPIRE} ms tanpa typing:start baru`);
  await sleep(2100);
  const before = b.typing.length;
  await emit(a, 'typing:start', { conversation_id: conversationId });
  const falsesBefore = b.typing.filter((e) => e.data.is_typing === false).length;
  await sleep(EXPIRE + 1500);
  const falsesAfter = b.typing.filter((e) => e.data.is_typing === false).length;
  check('server menghentikan typing sendiri (is_typing=false)', b.typing.length > before && falsesAfter === falsesBefore + 1);

  console.log('\n[5] Socket pengirim putus saat mengetik');
  await sleep(2100);
  await emit(a, 'typing:start', { conversation_id: conversationId });
  await waitTyping(b, (e) => e.data.is_typing === true && b.typing.length > 0, 500);
  const falsesBeforeClose = b.typing.filter((e) => e.data.is_typing === false).length;
  a.close();
  await sleep(800);
  check('typing berhenti otomatis saat socket pengirim putus', b.typing.filter((e) => e.data.is_typing === false).length === falsesBeforeClose + 1);

  console.log('\n[6] Otorisasi & validasi');
  const bad = await emit(c, 'typing:start', { conversation_id: conversationId });
  check('bukan peserta ditolak (FORBIDDEN)', bad && bad.ok === false && bad.error.code === 'FORBIDDEN', JSON.stringify(bad));
  await sleep(300);
  const bTypingBefore = b.typing.length;
  check('lawan bicara tidak menerima apa pun dari non-peserta', b.typing.length === bTypingBefore);
  const inv = await emit(b, 'typing:start', { conversation_id: 'abc' });
  check('payload tidak valid ditolak (VALIDATION_ERROR)', inv && inv.ok === false && inv.error.code === 'VALIDATION_ERROR');
  const unknown = await emit(b, 'typing:start', { conversation_id: 987654 });
  check('percakapan yang tidak ada ditolak', unknown && unknown.ok === false);

  [b, c].forEach((s) => s.close());
  console.log(failed === 0 ? '\nSEMUA LULUS' : `\n${failed} TES GAGAL`);
  process.exit(failed === 0 ? 0 : 1);
})();
