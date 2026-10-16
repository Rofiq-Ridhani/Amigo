# Amigo — Latihan Keandalan (Fase 7)

Tujuan: membuktikan bahwa aplikasi berperilaku sesuai kontrak (§J) saat ada yang rusak.
Semua perintah dijalankan di **tab Exec workspace Laradock** (user `laradock`, folder `/var/www/Amigo`)
kecuali disebut lain. Perintah `docker compose ...` dijalankan di **terminal Windows, folder `laradock`**.

Persiapan: `npm start` jalan di folder `websocket`, dan satu tab lagi menjalankan
`cd websocket && npm run listen -- 2` (mendengarkan sebagai user 2). Pastikan `php artisan amigo:doctor` hijau.

---

## Latihan 1 — Redis mati (kontrak §J, latihan materi L4 no. 4)

| Langkah | Perintah | Hasil yang diharapkan |
|---|---|---|
| 1. Matikan Redis | `docker compose stop redis` | — |
| 2. Cek Node | buka `http://localhost:3001/health` | `"redis":false`; log Node: `Redis error ... mencoba menyambung kembali` |
| 3. Kirim pesan | `php artisan amigo:send 1 2 "saat redis mati"` | **Berhasil** (`Pesan #.. tersimpan`); tidak ada error ke pengguna |
| 4. Cek log | `tail -n 5 storage/logs/laravel.log` | `Gagal publish event ke Redis` |
| 5. Cek outbox | `php artisan tinker` lalu `App\Models\OutboxEvent::whereNull('published_at')->count()` | `2` (message:new + notification:new) |
| 6. Pesan tetap ada | `App\Models\Message::latest('id')->value('body')` | `saat redis mati` |
| 7. Nyalakan Redis | `docker compose start redis` | log Node: `Redis tersambung` lalu `sync:required dikirim`; tab listen menampilkan `sync:required` |
| 8. Kirim ulang | `php artisan outbox:flush` (otomatis tiap menit bila `schedule:work` jalan) | `terkirim ulang 2`; tab listen menampilkan `message:new` dan `notification:new` |
| 9. Cek lagi | hitung outbox belum terkirim | `0` |

**Kesimpulan yang harus terlihat:** pesan tidak pernah hilang (database = sumber kebenaran); event realtime
tertunda lalu terkirim; client yang menerima event lama membuangnya bila sudah punya datanya (`event_id`, `revision`).

## Latihan 2 — Server Node mati

| Langkah | Hasil yang diharapkan |
|---|---|
| Hentikan `npm start` (Ctrl + C) | `php artisan amigo:doctor` menunjukkan `Server Socket.IO ... GAGAL` |
| `php artisan amigo:send 1 2 "saat node mati"` | Berhasil. Pesan tersimpan. |
| Nyalakan lagi `npm start` | Client reconnect otomatis |
| Client menjalankan resync (§H.6) | `GET .../messages?updated_since=...` memuat pesan yang terlewat |

Catatan jujur: Redis Pub/Sub tidak menyimpan pesan untuk subscriber yang sedang tidak ada. Event yang terbit
saat Node mati **tidak diputar ulang**; pemulihan dilakukan **client** lewat resync REST. Karena itu resync saat
`connect` dan polling unread tiap 60 detik (kontrak §H.6) wajib ada di frontend.

## Latihan 3 — Laravel/Nginx tidak bisa dihubungi Node (status "delivered")

1. `docker compose stop nginx`
2. Kirim pesan dari browser lain / `amigo:send` (butuh Laravel hidup; untuk latihan ini cukup matikan Nginx setelah pesan terkirim).
3. Penerima menerima `message:new` dan membalas ACK, Node mencoba memanggil `/internal/v1/messages/delivered`.
4. Log Node: `internal ... error jaringan` (3 kali percobaan), status pesan **tetap `sent`**.
5. `docker compose start nginx`; saat client resync, ia mengirim `message:delivered` dan status menjadi `delivered`.

## Latihan 4 — Permintaan berulang (idempotensi)

Kirim `POST /api/v1/conversations/{id}/messages` dua kali dengan `client_uuid` yang sama →
respons pertama `201`, kedua `200`, **satu** pesan di database, **satu** event realtime.
(Sudah dijamin tes `test_client_uuid_sama_idempoten...`.)

## Latihan 5 — Batas permintaan

Kirim 31 pesan dalam 1 menit dari satu akun → permintaan ke-31 mendapat `429`, `error.code = RATE_LIMITED`,
header `Retry-After`. Login salah 6 kali dalam 1 menit → `429`.

---

## Batas upload (Nginx / PHP-FPM) — periksa sekali

```bash
# terminal Windows, folder laradock
docker compose exec php-fpm php -r "echo ini_get('upload_max_filesize'), ' ', ini_get('post_max_size'), PHP_EOL;"
docker compose exec nginx nginx -T | findstr client_max_body_size
```
Keduanya harus minimal `60M` (kontrak §G). Cara memperbaiki: lihat catatan Fase 6.

## Daftar periksa sebelum demo / produksi

- [ ] `php artisan test` hijau, `php artisan amigo:doctor` hijau.
- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true` (HTTPS).
- [ ] `APP_KEY`, `SOCKET_SECRET`, `INTERNAL_API_SECRET`, password database/Redis **dibuat baru** (jangan memakai nilai development).
- [ ] `/internal/*` tidak bisa diakses dari internet (blok di Nginx; hanya jaringan Docker).
- [ ] `CHAT_CSP_MODE=enforce` setelah Frontend memastikan tidak ada pelanggaran CSP di Console browser.
- [ ] Penjadwal berjalan: cron `* * * * * php artisan schedule:run` (atau `php artisan schedule:work` saat development).
- [ ] Server Node dijalankan di bawah pengelola proses (mis. pm2) agar menyala ulang otomatis.
