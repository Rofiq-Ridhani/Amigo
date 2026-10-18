// file: websocket/scripts/smoke-group.js
// Uji event & typing GRUP (Fase 8). Server harus jalan (npm start).
//   Buat grup nyata:  php artisan amigo:group 1 "Tim Uji" 2 3      (mis. menghasilkan "Grup #5")
//   Jalankan:         cd websocket && npm run smoke:group -- 5
// Argumen: <group_id>. Tiga anggota diambil OTOMATIS dari Laravel (grup harus punya minimal 3 anggota aktif).
// Argumen <user A> <user B> <user C> bersifat opsional dan hanya dipakai bila Laravel tidak bisa dihubungi.
const path = require('node:path');
require('dotenv').config({ path: path.resolve(__dirname, '..', '..', '.env') });

const crypto = require('node:crypto');
const jwt = require('jsonwebtoken');
const Redis = require('ioredis');
const { io } = require('socket.io-client');

const cli = process.argv.slice(2).map(Number);
const groupId = cli[0];
if (!Number.isInteger(groupId) || groupId < 1) {
  console.error('Pemakaian: npm run smoke:group -- <group_id>');
  process.exit(1);
}
let [A, B, C] = cli.slice(1);

const PORT = Number(process.env.SOCKET_PORT || 3001);
const SECRET = process.env.SOCKET_SECRET;
const ORIGIN = (process.env.SOCKET_ALLOWED_ORIGINS || '').split(',')[0].trim();
const CHANNEL = process.env.REDIS_CHAT_CHANNEL || 'chat.messages';
const OUTSIDER = 9001; // bukan anggota

if (!SECRET || !ORIGIN) {
  console.error('SOCKET_SECRET dan SOCKET_ALLOWED_ORIGINS harus ada di .env root.');
  process.exit(1);
}

const INTERNAL_SECRET = process.env.INTERNAL_API_SECRET || '';
const LARAVEL_URL = process.env.LARAVEL_INTERNAL_URL || 'http://127.0.0.1:8000';
const LARAVEL_HOST = process.env.LARAVEL_INTERNAL_HOST || '';

/** Tanya Laravel siapa anggota aktif percakapan ini (endpoint internal bertanda tangan HMAC). */
function fetchParticipants(id) {
  return new Promise((resolve) => {
    if (!INTERNAL_SECRET) return resolve({ error: 'INTERNAL_API_SECRET kosong di .env root' });
    const timestamp = String(Math.floor(Date.now() / 1000));
    const signature = crypto.createHmac('sha256', INTERNAL_SECRET).update(`${timestamp}.`).digest('hex');
    const url = new URL(`/internal/v1/conversations/${id}/participants`, LARAVEL_URL);
    const headers = { Accept: 'application/json', 'X-Internal-Timestamp': timestamp, 'X-Internal-Signature': signature };
    if (LARAVEL_HOST) headers.Host = LARAVEL_HOST;
    const req = (url.protocol === 'https:' ? require('node:https') : require('node:http')).request(url, { method: 'GET', headers, timeout: 15000 }, (res) => {
      let body = '';
      res.on('data', (c) => (body += c));
      res.on('end', () => {
        try {
          resolve({ status: res.statusCode, json: JSON.parse(body) });
        } catch {
          resolve({ status: res.statusCode, json: null });
        }
      });
    });
    req.on('timeout', () => req.destroy(new Error('waktu habis (15 detik) menunggu jawaban Laravel')));
    req.on('error', (err) => resolve({ error: err.code ? `${err.code} ${err.message}` : err.message }));
    req.end();
  });
}

const redis = new Redis({
  host: process.env.REDIS_HOST || '127.0.0.1',
  port: Number(process.env.REDIS_PORT || 6379),
  password: !process.env.REDIS_PASSWORD || process.env.REDIS_PASSWORD === 'null' ? undefined : process.env.REDIS_PASSWORD,
});

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const token = (sub) => jwt.sign({ sub, aud: 'amigo-socket', jti: 'smoke' }, SECRET, { algorithm: 'HS256', expiresIn: 300 });
const iso = () => new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');

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
  socket.events = [];
  socket.onAny((name, env) => socket.events.push({ name, env }));
  return socket;
}

const waitFor = async (socket, pred, ms = 3000) => {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    const hit = socket.events.find(pred);
    if (hit) return hit;
    await sleep(50);
  }
  return null;
};
const emit = (socket, event, payload) => new Promise((resolve) => socket.emit(event, payload, resolve));
const connected = (socket) => new Promise((resolve) => socket.on('connect', resolve));

const publish = (event, to, data, ackUserId = null) =>
  redis.publish(
    `smoke_database_${CHANNEL}`,
    JSON.stringify({ event_id: crypto.randomUUID(), event, occurred_at: iso(), to, ack_user_id: ackUserId, data }),
  );

(async () => {
  // Anggota yang SEBENARNYA menurut database (bukan tebakan dari argumen)
  const res = await fetchParticipants(groupId);

  // Jelaskan penyebab sebenarnya bila Laravel tidak memberi jawaban yang bisa dipakai
  if (res && res.error) {
    console.error(`Gagal menghubungi Laravel di ${LARAVEL_URL}${LARAVEL_HOST ? ` (Host: ${LARAVEL_HOST})` : ''}: ${res.error}`);
  } else if (res && ![200, 404].includes(res.status)) {
    const hint = {
      401: 'tanda tangan ditolak: INTERNAL_API_SECRET di .env tidak sama dengan yang dipakai Laravel (jalankan php artisan config:clear)',
      503: 'Laravel belum membaca INTERNAL_API_SECRET (jalankan php artisan config:clear)',
      500: 'error di Laravel: lihat storage/logs/laravel.log',
    }[res.status] || 'jawaban tak terduga';
    console.error(`Laravel menjawab HTTP ${res.status} — ${hint}`);
    console.error(`Isi jawaban: ${JSON.stringify(res.json)}`);
  }

  if (res && res.status === 404) {
    console.error(`Percakapan #${groupId} tidak ada di database. Buat grup dulu: php artisan amigo:group 1 "Tim Uji" 2 3`);
    process.exit(1);
  }

  if (res && res.status === 200 && res.json && res.json.data) {
    const { type, user_ids: ids } = res.json.data;
    if (type !== 'group') {
      console.error(`Percakapan #${groupId} adalah percakapan ${type}, bukan GRUP. Pakai nomor grup (lihat keluaran "amigo:group").`);
      process.exit(1);
    }
    if (ids.length < 3) {
      console.error(`Grup #${groupId} hanya punya ${ids.length} anggota aktif; uji ini butuh minimal 3.`);
      process.exit(1);
    }
    if ([A, B, C].some((n, i) => n !== ids[i])) {
      console.log(`Anggota aktif grup #${groupId} menurut Laravel: [${ids.join(', ')}] -> memakai ${ids[0]}, ${ids[1]}, ${ids[2]}\n`);
    }
    [A, B, C] = ids;
  } else if (![A, B, C].every((n) => Number.isInteger(n) && n > 0)) {
    console.error('Anggota grup tidak bisa diambil dari Laravel dan tidak disebut di argumen.');
    console.error('Perbaiki masalah di atas, atau jalankan: npm run smoke:group -- <group_id> <user A> <user B> <user C>');
    process.exit(1);
  } else {
    console.log('PERINGATAN: anggota tidak bisa diverifikasi ke Laravel; memakai anggota dari argumen apa adanya.\n');
  }

  console.log(`Smoke test grup #${groupId}: anggota ${A}, ${B}, ${C}\n`);

  const a = connect(A);
  const b = connect(B);
  const c = connect(C);
  const out = connect(OUTSIDER);
  await Promise.all([connected(a), connected(b), connected(c), connected(out)]);
  await sleep(300);

  console.log('[1] Routing event grup (via Redis)');
  await publish('message:new', [A, B, C], { message: { id: 910001, conversation_id: groupId, sender_id: A, recipient_id: null, body: 'Halo grup', status: 'sent', read_by_count: 0, mentions: [] } });
  const got = await Promise.all([a, b, c].map((s) => waitFor(s, (e) => e.name === 'message:new')));
  check('semua anggota (A, B, C) menerima message:new', got.every(Boolean));
  check('pesan grup tidak dikirim ke non-anggota', !out.events.some((e) => e.name === 'message:new'));
  check('payload pesan grup utuh (recipient_id null, read_by_count)', got[1]?.env.data.message.recipient_id === null && got[1].env.data.message.read_by_count === 0);

  await publish('conversation:updated', [A, B, C], { conversation_id: groupId, reason: 'renamed' });
  const upd = await Promise.all([a, b, c].map((s) => waitFor(s, (e) => e.name === 'conversation:updated')));
  check('conversation:updated sampai ke semua anggota', upd.every((e) => e && e.env.data.reason === 'renamed'));

  await publish('conversation:removed', [C], { conversation_id: groupId });
  const removed = await waitFor(c, (e) => e.name === 'conversation:removed');
  await sleep(300);
  check('conversation:removed hanya untuk yang dikeluarkan', !!removed && !a.events.some((e) => e.name === 'conversation:removed') && !b.events.some((e) => e.name === 'conversation:removed'));

  await publish('group:read', [A, C], { conversation_id: groupId, reader_id: B, from_message_id: 0, up_to_message_id: 910001, read_at: iso() });
  const gr = await waitFor(a, (e) => e.name === 'group:read');
  await waitFor(c, (e) => e.name === 'group:read');
  await sleep(300);
  check('group:read sampai ke anggota lain', !!gr && gr.env.data.reader_id === B && gr.env.data.from_message_id === 0);
  check('group:read TIDAK dikirim ke pembaca sendiri', !b.events.some((e) => e.name === 'group:read'));

  console.log('\n[2] Typing di grup');
  const ack = await emit(a, 'typing:start', { conversation_id: groupId });
  check('typing:start anggota diterima', ack && ack.ok === true, JSON.stringify(ack) + (ack && ack.ok === false ? '  (periksa LARAVEL_INTERNAL_URL dan keanggotaan di database)' : ''));
  const tb = await waitFor(b, (e) => e.name === 'typing:update' && e.env.data.is_typing === true);
  const tc = await waitFor(c, (e) => e.name === 'typing:update' && e.env.data.is_typing === true);
  check('SEMUA anggota lain menerima typing:update', !!tb && !!tc && tb.env.data.user_id === A && tb.env.data.conversation_id === groupId);
  check('pengetik tidak menerima typing:update miliknya', !a.events.some((e) => e.name === 'typing:update'));
  check('non-anggota tidak menerima typing:update', !out.events.some((e) => e.name === 'typing:update'));

  const bad = await emit(out, 'typing:start', { conversation_id: groupId });
  check('non-anggota ditolak mengetik di grup (FORBIDDEN)', bad && bad.ok === false && bad.error.code === 'FORBIDDEN', JSON.stringify(bad));

  const stop = await emit(a, 'typing:stop', { conversation_id: groupId });
  const fb = await waitFor(b, (e) => e.name === 'typing:update' && e.env.data.is_typing === false);
  const fc = await waitFor(c, (e) => e.name === 'typing:update' && e.env.data.is_typing === false);
  check('typing:stop diteruskan ke semua anggota lain', stop.ok === true && !!fb && !!fc);

  [a, b, c, out].forEach((s) => s.close());
  redis.disconnect();
  console.log(failed === 0 ? '\nSEMUA LULUS' : `\n${failed} TES GAGAL`);
  process.exit(failed === 0 ? 0 : 1);
})();
