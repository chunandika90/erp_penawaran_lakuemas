# Setup Lokal — Lakuemas ERP

Panduan ini buat pas pindah laptop / install ulang. Ngikutin ini bakal balikin
lingkungan dev ke kondisi yang sama kayak sebelumnya, tanpa perlu re-derive
langkah-langkah dari awal.

## 1. Prasyarat

- **Laragon** (PHP + MySQL, no build step, no composer/npm dibutuhin sama sekali).
  Install lewat winget: `winget install --id LeNgocKhoa.Laragon -e`
  Yang dipakai sekarang: PHP 8.3.30, MySQL 8.4.3 — keduanya ke-install otomatis
  sama Laragon, ada di `C:\laragon\bin\php\...` dan `C:\laragon\bin\mysql\...`.
- **Git**.

## 2. Clone repo

```bash
git clone https://github.com/chunandika90/erp_penawaran_lakuemas.git
```

## 3. Setup database

Nyalain MySQL dulu (dari GUI Laragon, atau manual lewat command line):

```bash
"C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe" --defaults-file="C:\laragon\bin\mysql\mysql-8.4.3-winx64\my.ini" --standalone
```

**Ada 2 cara isi datanya, pilih salah satu:**

### Cara A — Restore dari snapshot (kalau ada `local-db-snapshot.sql`)
File ini gak ke-commit ke git (personal backup, ada di `.gitignore`) — kalau
lo bawa filenya pas pindah laptop, tinggal:
```bash
mysql -h 127.0.0.1 -u root -e "CREATE DATABASE wujud_erp_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -h 127.0.0.1 -u root wujud_erp_local < local-db-snapshot.sql
```
Ini balikin SEMUA data yang udah ada (47 produk incl. harga real dari
lakuemas.com, kontak, transaksi demo split/merge/partial, dst) — gak perlu
input ulang apa-apa.

### Cara B — Import dari schema kosong (fresh install)
Kalau gak ada snapshot-nya, import file-file di `sql/` **urut sesuai nama
(tanggal)** — urutan ini penting karena ada dependency FK antar migrasi:
```bash
mysql -h 127.0.0.1 -u root -e "CREATE DATABASE wujud_erp_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for f in sql/*.sql; do mysql -h 127.0.0.1 -u root wujud_erp_local < "$f"; done
```
(Pastiin urutannya: `wujud-erp-schema-DEPLOY-READY.sql` → migrasi
`2026-08-07-*` → `2026-08-08-*` → `2026-08-10-*`, alfabetis udah bener.)

Fresh install = database kosong, belum ada organisasi/user — lanjut daftar
manual lewat `register.php` (jadi Owner organisasi baru otomatis).

## 4. Config

```bash
cp backoffice-shared/config.example.php backoffice-shared/config.php
cp backoffice-shared/config.local.example.php backoffice-shared/config.local.php
```
Dua file ini sengaja gak ke-commit (isinya kredensial, treat kayak password).
Default `config.local.php` udah pas buat dev lokal (`127.0.0.1` / `root` /
tanpa password) — gak perlu diubah kalau MySQL-nya default Laragon.

## 5. Jalanin server

```bash
cd backoffice
php -S 0.0.0.0:8000
```
**Penting:** bind ke `0.0.0.0`, JANGAN `localhost` doang — `php -S
localhost:8000` kadang cuma bind ke `[::1]` (IPv6 loopback) dan browser yang
resolve `localhost`/`127.0.0.1` ke IPv4 duluan bakal dapet "connection
refused". Ini kejadian berkali-kali pas dev di laptop lama.

Buka `http://localhost:8000`.

## 6. Login (kalau restore dari snapshot / Cara A)

- Email: `admin@lakuemas.local`
- Password: `lakuemas123`
- Organisasi: "Lakuemas Group"

## Struktur project

- `backoffice/` — aplikasi PHP (vanilla, no framework, no build step)
- `backoffice-shared/` — auth, koneksi DB, helper nomor dokumen, dll — dipakai bareng semua halaman
- `sql/` — schema + migrasi, urut berdasarkan nama file (tanggal)
- `wujud-erp/` — dokumen desain arsitektur dari fase awal (sebelum pivot ke vertikal emas) — historical reference doang

## Catatan lain

- Repo ini basisnya "Wujud ERP" (ERP generik konstruksi/mebel) yang di-retire
  total dan diganti alur emas — lihat commit history buat kronologinya kalau
  perlu konteks.
- `erp_harmony` (starter copy buat project ERP sekolah terpisah) sengaja
  gak ada di repo ini (`.gitignore`) — itu project lain, database sendiri
  (`harmony_local`), gak ada hubungannya sama data Lakuemas.
