# Wujud ERP — Backoffice Svashta Home — UI Handoff buat Claude Design

Dokumen ini isinya **fungsi & struktur**, bukan visual — tujuannya biar Claude Design bisa
redesign tampilannya tanpa perlu baca kode PHP, dan tanpa kehilangan logic bisnis yang udah
dibangun. Pola ini sama kayak `DOKUMENTASI_ARSITEKTUR.md` di folder yang sama (baca itu dulu
buat konteks model data lengkap) dan `WEB_UI_SAMPLE.md` di project e-sign.ai.

**Status saat ini**: backend PHP+MySQL udah jalan beneran (bukan mockup), tapi tampilannya
masih placeholder — CSS dasar (`backoffice/assets/css/app.css`), belum di-desain. User bilang
("jelek bet") minta di-redesign visualnya doang, struktur/alur/field JANGAN diubah kecuali
emang ada perbaikan UX yang jelas manfaatnya.

**Stack**: PHP vanilla + MySQL (PDO), no framework, no build step, no JS framework (vanilla
JS aja). Redesign harus tetap bisa diimplementasi sebagai HTML+CSS(+vanilla JS opsional) yang
nempel ke markup PHP yang ada — jangan asumsikan React/Vue/dst.

---

## 1. Konsep Produk

Backoffice/ERP internal Svashta Home, rencana **multi-tenant** (bisa dipakai banyak organisasi
berbeda, tiap organisasi data-nya terisolasi penuh) dan **online** (bukan cuma lokal). Fokus:
rantai dokumen dari penawaran ke customer sampai barang terkirim + laporan HPP/COGS, TERMASUK
alur manufaktur/rakit barang lewat vendor pihak ketiga.

Rantai dokumen:
```
Penawaran → Invoice → ┬─ Purchase Order (Bahan Baku / Jasa Produksi / Barang Jadi)
                       └─ SPK (kalau Jasa Produksi = Manufacturing Order beneran)
                              ↓
                     Penerimaan Barang (dari PO ATAU dari SPK selesai produksi)
                              ↓
                     Delivery Order (minus stok, FIFO costing) → Kuitansi & Pelunasan
```
Semua di-payungi opsional oleh **Project** (1 project = banyak Penawaran lintas periode, bisa
di-report total revenue/HPP/profit-nya).

---

## 2. Auth & Multi-Tenant (halaman: `register.php`, `login.php`, `select-org.php`, `logout.php`)

- **Register**: form Nama, Email, Password, Nama Organisasi. Submit = user jadi Owner
  organisasi baru otomatis (akses penuh semua modul).
- **Login**: email + password. Kalau user cuma di 1 organisasi → langsung ke Dashboard. Kalau
  di banyak organisasi → ke halaman **Pilih Organisasi** (list card, tiap card nampilin nama
  organisasi + role user di situ, klik = masuk).
- Layout auth pages saat ini: card putih di tengah layar gelap/krem, form vertikal simpel.
  Ini bagian yang paling "kelihatan" pertama kali — worth polish serius.

## 3. Shell Aplikasi (`includes/header.php` + `includes/footer.php`, dipakai semua halaman)

Struktur sekarang: **sidebar kiri** (fixed, dark) + **konten kanan**.

Sidebar isinya (top ke bottom):
- Brand "WUJUD ERP" + nama organisasi aktif
- Menu 10 modul inti (yang keliatan cuma yang user punya akses `can_view`): Dashboard,
  Penawaran, Invoicing, Purchase Order, SPK/Manufaktur, Penerimaan Barang, Delivery Order,
  Kuitansi, Kontak, Laporan
- Grup "master data": Produk & Tier, Material, Gudang, Syarat & Ketentuan
- Grup terpisah: Project
- Grup admin: Admin User, Roles & Akses

Topbar kanan atas tiap halaman: judul halaman + nama user + role pill + link "Ganti
Organisasi" + "Logout".

Di layar HP: sidebar berubah jadi topbar horizontal-scroll (bukan drawer/hamburger) — kalau
Claude Design mau ganti ke pattern hamburger+drawer yang lebih standar, itu welcome, asal tetap
gampang dipakai satu tangan.

## 4. Pola UI yang berulang di HAMPIR SEMUA modul (penting buat konsistensi desain)

1. **List page**: tabel data + tombol "+ Buat X" di kanan atas yang buka modal form.
2. **Modal form**: overlay gelap + card putih, field vertikal, footer tombol Batal/Simpan.
3. **Status dropdown inline** di tabel: ganti status langsung dari `<select>` di dalam baris
   tabel (auto-submit `onchange`), ditemani `<span class="pill">` versi read-only kalau user
   gak punya izin edit.
4. **Print view** (halaman terpisah, `*-print.php`, dibuka tab baru): layout dokumen bisnis
   simpel (kop organisasi, meta dokumen, tabel item, total, tombol "Print / Save PDF") — ini
   yang dicetak/di-PDF-in buat dikirim ke customer/vendor, JADI TAMPILANNYA HARUS RAPI &
   PROFESIONAL walau sederhana (bukan bagian aplikasi utama, tapi representasi brand ke pihak
   luar).
5. **Empty state**: baris tabel "Belum ada X" kalau kosong.
6. **Flash message**: alert hijau (`ok`)/merah (`error`) di atas konten abis submit form.

## 5. Rincian tiap modul

### Dashboard (`dashboard.php`)
Placeholder: sapaan + nama organisasi + pointer ke Admin User/Roles. Nanti idealnya jadi
ringkasan angka (jumlah Penawaran pending, Invoice belum dibayar, stok menipis, dll) — kalau
Claude Design mau usul layout dashboard KPI, itu selaras sama arah produk.

### Admin User (`users.php`)
Tabel anggota organisasi: Nama, Email, Role (dropdown kalau Owner), Status (pill), tombol
Nonaktifkan/Aktifkan. Form "Tambah Anggota": Email, Nama (kalau user baru), Role.

### Roles & Akses (`roles.php`)
List Role (custom per organisasi) + tombol "Buat Role Baru". Klik "Atur Akses" → tabel matrix
**Modul × (View/Tambah/Edit/Hapus/Print)** checkbox — ini bagian paling "dashboard admin"-nya,
biasanya di produk lain divisualisasikan sebagai grid checkbox rapi dengan grouping visual.

### Kontak (`contacts.php`)
Tabel Customer/Vendor + filter tab (Semua/Customer/Vendor). Modal form: Nama, Tipe
(customer/vendor/both), Telepon, Email, NPWP, Alamat.

### Produk & Tier (`products.php`)
Layout 2 kolom: kiri list produk (sidebar mini di dalam konten), kanan detail produk terpilih
menampilkan **5 card Tier** (Ekonomis/Standard/Premium/Deluxe/Bespoke), tiap card ada harga +
list komponen BOM (tambah baris dinamis: nama komponen, qty, cost/unit). ⚠️ Penting: kalau
tier itu udah pernah dipakai di transaksi, edit-nya otomatis bikin **versi baru** (bukan nimpa)
— UI idealnya kasih sinyal visual "tier ini immutable/sudah terpakai" biar user gak bingung.

### Material (`materials.php`)
Katalog bahan mentah (beda dari Produk — ini yang dipakai buat manufaktur). Tabel: Nama,
Satuan, Stok Tersedia. Tombol "+ Stok Awal" (modal terpisah, buat input saldo awal stok bahan
yang udah dipunya sebelum pakai sistem).

### Gudang (`warehouses.php`)
Tabel simpel: Nama, tandai Default, tombol Hapus.

### Syarat & Ketentuan (`terms.php`)
Master T&C bisa banyak varian (10+). Tabel: Judul + preview teks terpotong. Modal form: Judul,
Isi (textarea panjang). Dipilih pas bikin Penawaran/Invoice, teksnya di-snapshot ke dokumen.

### Penawaran (`quotations.php` + `quotation-print.php`)
Tabel: No. Dokumen, Customer, Project, Sales, Total, Status (draft/sent/approved/rejected),
Tanggal. Modal buat: pilih Customer, pilih Project (opsional), pilih Sales (dropdown anggota
organisasi), **baris item dinamis** (pilih Produk → pilih Tier yang muncul otomatis dengan
harga, qty, catatan opsional), pilih Syarat & Ketentuan (opsional), Catatan.

### Invoicing (`invoices.php` + `invoice-print.php`)
Tabel: No. Dokumen, Customer, Sales, Total, Status (draft/issued/paid/void), Tanggal. Modal:
pilih Customer → muncul checklist item Penawaran approved milik customer itu yang belum
di-invoice (bisa pilih beberapa dari beberapa Penawaran berbeda), pilih Sales, T&C.

### Purchase Order (`purchase-orders.php` + `po-print.php`)
Tabel: No. Dokumen, Vendor, **Tipe** (pill: Bahan Baku / Jasa Produksi / Barang Jadi), Total,
Status, tombol "+ Buat SPK" (khusus tipe Jasa Produksi). Modal: pilih Vendor + Tipe → form
berubah sesuai tipe:
- **Bahan Baku**: baris manual (pilih dari katalog Material ATAU ketik nama bebas, qty, cost)
- **Jasa Produksi / Barang Jadi**: checklist item dari Invoice yang belum di-PO-kan + input cost

### SPK / Manufaktur (`spk.php` + `spk-print.php`) — **modul paling kompleks**
Ini bukan cuma dokumen status, tapi Manufacturing Order beneran. Tabel: No. Dokumen, Produk
Target (+qty hasil), Vendor, **Cost Manufaktur** (breakdown: material + jasa rakit), Estimasi
Selesai, Status, tombol **"Terima Hasil Produksi"**.
Modal buat SPK: Vendor, Produk Target, Qty Hasil, Biaya Jasa Rakit (auto-fill kalau dari PO
Jasa Produksi, atau manual), Gudang sumber material, Estimasi Selesai, **baris Material
dinamis** (pilih material + qty — ini yang minus stok material FIFO pas disimpan).
Modal "Terima Hasil Produksi": nampilin HPP per unit yang udah kehitung otomatis, pilih Gudang
tujuan → nambah stok produk jadi.

### Penerimaan Barang (`goods-receipts.php`)
Tabel: No. Dokumen, PO, Gudang, Tujuan (pill: Gudang/Langsung ke Customer), Tanggal. Modal:
pilih PO yang masih ada sisa qty → muncul baris item dengan input qty diterima per baris
(default = sisa qty), pilih Gudang, pilih Tujuan.

### Delivery Order (`delivery-orders.php` + `do-print.php` — cetakan "Surat Jalan" dengan kolom
tanda tangan Pengirim/Penerima)
Tabel: No. Dokumen, Customer, Invoice, **HPP**, Status (draft/shipped/delivered/void). Modal:
pilih Invoice yang masih ada sisa kirim → checklist item + qty kirim, pilih Gudang asal. Submit
= otomatis minus stok FIFO + hitung HPP per baris.

### Kuitansi (`kuitansi.php` + `kuitansi-print.php`)
Tabel: No. Dokumen, Customer, Invoice, Tipe (DP/Termin/Pelunasan), Nominal, Tanggal. Modal:
pilih Invoice (nampilin total & yang udah dibayar), pilih Delivery Order terkait (opsional),
Nominal, Tipe, Tanggal Bayar. Print view kuitansi beda dari yang lain — ada kotak besar nominal
di tengah (representasi kuitansi fisik).

### Project (`projects.php`)
List page: tabel Nama Project, Customer, Jumlah Penawaran, Revenue. Klik project → halaman
detail: 4 stat card (Revenue, HPP, Gross Profit, Sudah Diterima/Kuitansi) + tabel semua
Penawaran yang tergabung di project itu.

### Laporan HPP/COGS (`laporan.php`)
Filter tanggal (default Month-to-Date, ada tombol "Bulan Ini"/"Semua Periode"). 4 stat card
(Revenue, HPP, Gross Profit, Margin%). Tabel breakdown per Produk. Section kedua: **Performa
Sales** — tabel per sales rep (Revenue, HPP, Gross Profit).

---

## 6. Yang PALING butuh perhatian desain (prioritas kalau waktu terbatas)

1. **Auth pages** (login/register/select-org) — kesan pertama produk.
2. **Sidebar + navigasi** — dipakai di semua halaman, dan menu-nya cukup banyak (14+ item),
   perlu grouping visual yang jelas biar gak berasa "list link doang".
3. **Pola modal form** — dipakai di hampir semua modul, terutama yang formnya panjang/dinamis
   (Penawaran, SPK, PO) butuh layout yang gak berasa numpuk.
4. **Tabel data + status pill + dropdown inline** — konsisten di semua list page.
5. **Print views** — dokumen resmi ke customer/vendor, harus rapi walau tetap simpel (mirip
   invoice generator standar).
6. **Mobile** — user eksplisit minta enak dipakai di HP, karena rencana dipakai harian +
   scaling ke banyak organisasi.

## 7. Referensi kode (kalau Claude Design/dev berikutnya butuh lihat markup asli)

- Shell: `backoffice/includes/header.php`, `backoffice/includes/footer.php`
- CSS saat ini (placeholder, boleh diganti total): `backoffice/assets/css/app.css`
- Semua halaman modul: `backoffice/*.php` (nama file sama kayak nama modul di atas)
- Model data lengkap: `wujud-erp/DOKUMENTASI_ARSITEKTUR.md`
