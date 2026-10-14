// file: websocket/scripts/listen.js
// Alat bantu development: "mendengarkan" sebagai seorang user dan mencetak semua event.
// Otomatis memberi ACK pada message:new / notification:new (seperti browser).
//   node scripts/listen.js 2        -> mendengarkan sebagai user ID 2
const path = require('node:path');
require('dotenv').config({ path: path.resolve(__dirname, '..', '..', '.env') });

const jwt = require('jsonwebtoken');
const { io } = require('socket.io-client');

const userId = Number(process.argv[2]);
if (!Number.isInteger(userId) || userId < 1) {
  console.error('Pemakaian: node scripts/listen.js <user_id>');
  process.exit(1);
}

const port = Number(process.env.SOCKET_PORT || 3001);
const origin = (process.env.SOCKET_ALLOWED_ORIGINS || '').split(',')[0].trim();
const token = jwt.sign({ sub: userId, aud: 'amigo-socket', jti: 'listen' }, process.env.SOCKET_SECRET, {
  algorithm: 'HS256',
  expiresIn: 3600,
});

const socket = io(`http://127.0.0.1:${port}`, { auth: { token }, extraHeaders: { Origin: origin }, transports: ['websocket'] });
const stamp = () => new Date().toLocaleTimeString();

socket.on('connect', () => console.log(`${stamp()}  tersambung sebagai user ${userId}`));
socket.on('connect_error', (e) => console.log(`${stamp()}  gagal tersambung: ${e.message}`));
socket.on('disconnect', (r) => console.log(`${stamp()}  terputus: ${r}`));

socket.onAny((name, env) => {
  const data = env && env.data ? JSON.stringify(env.data) : '';
  console.log(`${stamp()}  <- ${name}  ${data}`);
});

for (const ev of ['message:new', 'notification:new']) {
  socket.on(ev, (_env, ack) => {
    if (typeof ack === 'function') {
      ack({ ok: true });
      console.log(`${stamp()}  -> ACK ${ev}`);
    }
  });
}
