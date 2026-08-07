# Wujud ERP — Dokumentasi Arsitektur

Rekap seluruh keputusan desain & arsitektur yang sudah disepakati sepanjang sesi diskusi. Ditulis dengan format yang sama seperti `WEB_UI_SAMPLE.md` di project e-sign.ai — dokumen ini jadi source of truth untuk *keputusan*, bukan untuk kode.

## Dua Fase Proyek — Jangan Dicampur

Sejak awal diskusi, proyek ini sengaja dipecah 2 fase yang terpisah. Semua kerja sampai dokumen ini ditulis masih di **Fase 1**.

| | **Fase 1 — Prototipe (sekarang)** | **Fase 2 — Project Asli (nanti)** |
|---|---|---|
| Tujuan | Validasi konsep, alur, dan tampilan untuk presentasi internal. Dibawa ke Claude Design buat dirapihin visualnya. | Implementasi sungguhan: dipakai operasional harian, bisa dijual ke perusahaan lain. |
| Data | Dummy, hidup di `state` JS di browser, hilang tiap refresh. | Database sungguhan (skema di bawah), persist, backup, migrasi. |
| Auth & akses | Simulasi (klik kartu organisasi langsung masuk, tanpa password beneran). | Auth sungguhan (password hash, session/token, OTP kalau perlu), enforced di server bukan cuma di UI. |
| Isolasi multi-tenant | Disimulasikan lewat `state.quotationsByOrg` di satu file JS — **tidak ada isolasi keamanan sungguhan**, cuma nunjukin konsepnya. | Wajib enforced di level query/API — `organization_id` jadi filter wajib di setiap akses data, tidak bisa diakali dari client. |
| File terkait | `erp-konstruksi-mockup.html`, `erp-functional-prototype.html` | Belum dimulai — menunggu keputusan pending di bagian 6 selesai dulu. |

Bagian 1–4 di bawah ini isinya **keputusan bisnis & model data** yang berlaku untuk kedua fase (ini yang harus dibawa utuh ke Fase 2). Bagian 5 khusus soal status Fase 1. Bagian 6 adalah hal yang belum diputuskan dan wajib selesai sebelum Fase 2 mulai.

## Konteks Produk

ERP untuk perusahaan **konstruksi & mebel**, dirancang jadi **produk SaaS multi-tenant** — satu instalasi bisa dijual ke banyak perusahaan dengan model bisnis serupa. Benchmark awal: Odoo — functional-nya bagus, tapi kelemahannya data harus diinput ulang tiap pindah modul/transaksi. Prinsip desain utama sistem ini: **data mengalir sepanjang rantai dokumen, tidak pernah diketik ulang.**

## 1. Rantai Dokumen (Document Chain)

```
Penawaran → Invoice → ┬─ PO (Bahan Baku / Jasa Produksi / Barang Jadi)
                       └─ SPK Produksi
                              ↓
                     Penerimaan Barang (gudang ATAU langsung ke customer)
                              ↓
                     Delivery Order → Kuitansi & Pelunasan
```

- **Penawaran**: transaksi penawaran ke customer berdasarkan barang yang mereka minati.
- **Invoicing**: lebih mengikat dari penawaran. Bisa narik dari **1 atau beberapa Penawaran sekaligus** (1 customer bisa punya banyak Penawaran yang digabung jadi 1 Invoice, atau sebaliknya).
- **Purchase Order**: fleksibel — bisa PO bahan baku mentah, PO jasa produksi (bahan dari kita, vendor cuma ngerjain), atau PO barang jadi langsung.
- **SPK Produksi**: mendampingi PO jasa — jadi dasar tagihan ke vendor & estimasi kapan barang produksi selesai.
- **Penerimaan Barang**: pindah tanggung jawab dari vendor ke gudang internal, ATAU langsung ke customer (skip gudang) — keduanya tetap terlacak balik ke Penawaran/Invoice sumbernya.
- **Kuitansi**: pelunasan berdasarkan status Delivery Order (sudah terkirim atau belum menentukan termin apa yang bisa ditagih).

**Prinsip kunci — Line-Item Provenance**: bukan cuma header dokumen yang saling nyambung, tapi **tiap baris item** bawa referensi ke baris sumbernya. Invoice tahu tiap barisnya berasal dari Penawaran & baris mana; PO/SPK mewarisi qty & spesifikasi dari baris Invoice, bukan diketik manual ulang. Ini yang membedakan dari Odoo yang lebih modul-sentris.

## 2. Model Tier / BOM (5 Tingkat Spesifikasi)

Tiap produk (mis. "Kitchen Set HPL", "Sofa Adhum") punya **5 Tier**: Ekonomis, Standard, Premium, Deluxe, Bespoke.

**Keputusan penting**: Tier adalah **resep/kombinasi terkunci** (satu `tier_id` = satu paket material+harga), **bukan kombinasi atribut bebas** (seperti matrix attribute × value ala Odoo yang berisiko menghasilkan kombinasi tidak valid, mis. material premium ketempel hardware ekonomis).

Aturan keamanan data yang wajib dipegang saat implementasi:

1. **Snapshot, bukan referensi hidup** — begitu tier dipakai di suatu baris transaksi (Penawaran disetujui), BOM & harga tier itu **dibekukan**. Perubahan katalog tier di masa depan (harga naik, ganti supplier) TIDAK boleh mengubah dokumen yang sudah terbit.
2. Tiap baris turunan (PO/SPK) bawa 3 referensi: `product_id + tier_id + tier_version + source_line_id`.
3. **Tier 5 (Bespoke)** adalah pengecualian yang disengaja — begitu dipilih, sistem clone BOM dari tier terdekat lalu jadi baris independen yang bisa diedit bebas, ditandai `custom: true` supaya tidak mencemari katalog tier resmi.
4. Qty & harga per komponen dikunci di tingkat tier (bukan cuma di tingkat produk jadi), supaya PO bahan baku bisa agregasi total kebutuhan material lintas transaksi (mis. total m² HPL import yang dibutuhkan bulan ini dari semua Tier 3 & 4).

**Master data mentah** (dari referensi Excel `ODOO_BAYU_SULUH_16 JULI 2026.r1_MIRA.xlsx`, sheet 1 — struktur: Collection / Item / Material / Ukuran / Finishing) berfungsi sebagai **katalog atribut**, sumber referensi buat menyusun Tier Profile — bukan tempat kombinasi dipilih bebas oleh user transaksi.

> ⚠️ **Belum diselesaikan**: isi lengkap file Excel tersebut belum benar-benar dibaca/dipetakan ke skema. Perlu sesi lanjutan untuk konfirmasi: apakah tiap kombinasi Material/Ukuran/Finishing punya harga sendiri atau harga baru muncul di level Tier; apa peran "Collection" (grouping desain vs tag marketing); dan ada berapa sheet lain (harga, BOM, vendor) di file itu.

## 3. Multi-Tenant: Organisasi & User

Keputusan eksplisit yang sudah dikonfirmasi:

- **Isolasi data penuh (strict multi-tenant)**: tiap Organisasi = badan usaha terpisah. Customer, vendor, produk, transaksi PT A tidak pernah bocor ke PT B. **Setiap tabel** (master maupun transaksi) wajib punya `organization_id`.
- **Role independen per organisasi**: satu User (satu identitas login/email) bisa ikut banyak Organisasi, dengan Role yang berbeda-beda di tiap organisasi (mis. Owner di PT A, Staff di PT B).

### Skema Entitas

```
User (identitas global, lintas organisasi)
├─ id, nama, email, no_hp, password_hash, status, foto

Organization (badan usaha, data terisolasi penuh)
├─ id, nama_legal, NPWP, alamat, logo, mata_uang, prefix_dokumen

Role (bawaan sistem ATAU custom per organisasi)
├─ id, organization_id (nullable), nama_role
└─ contoh: Owner, Admin Utama, Manager Operasional, SPV Purchasing, Staff Purchasing, Finance

RoleModuleAccess (matrix hak akses granular)
└─ role_id, module_key, can_view, can_create, can_edit, can_delete, can_print

UserOrganizationRole (simpul penghubung — 1 baris = 1 keanggotaan)
├─ user_id, organization_id, role_id, status, joined_at
```

### Alur Login

1. Login pakai 1 kredensial global (email+password), org-agnostic.
2. Sistem cek semua `UserOrganizationRole` aktif milik user.
3. 1 organisasi → langsung masuk. **Lebih dari 1 organisasi → tampil layar Pilih Organisasi** (workspace picker) sebelum masuk dashboard.
4. Session/token simpan `{user_id, organization_id, role_id}` aktif. Switch organisasi = reload konteks penuh (bukan sekadar ganti filter, karena data memang tenant terpisah).

### Alur Owner & Hierarki

- **Owner** membuat Organisasi baru, otomatis jadi Admin pertama.
- Owner/Admin Utama menyusun hierarki di bawahnya secara bebas: contoh pola yang dipakai di prototipe — **Manager → SPV → Staff**.
- Tiap Role diberi hak akses granular per menu: dari ~10 menu (Dashboard, Penawaran, Invoicing, Purchase Order, SPK Produksi, Penerimaan Barang, Delivery Order, Kuitansi, Kontak, Laporan), tiap menu punya kombinasi **View / Tambah / Edit / Hapus / Print** yang bisa diatur independen.
- Contoh pola akses yang divalidasi di prototipe: makin ke bawah hierarki, makin sempit akses (Staff Purchasing cuma punya View+Tambah di PO/SPK/Penerimaan, tidak punya akses sama sekali ke Penawaran/Invoicing/Kuitansi).

> ⚠️ **Belum diputuskan**: alur user baru bergabung ke organisasi — apakah **invite-only** (Admin organisasi invite via email, user accept), atau ada juga jalur **self-signup + create-org** (user daftar sendiri dan otomatis jadi Owner organisasi baru). Kemungkinan besar dua-duanya dibutuhkan, tinggal dikonfirmasi prioritas mana yang dibangun duluan.

## 4. Dashboard (Landing Page)

Dashboard adalah halaman pertama setelah organisasi dipilih. **Kontennya beda per organisasi DAN per role** — bukan kosmetik, tapi betulan hasil filter dari data organisasi + hak akses role yang login. Contoh yang divalidasi di prototipe: KPI & tile modul yang muncul di dashboard Staff Purchasing otomatis lebih sedikit dibanding Owner, konsisten dengan matrix akses di poin 3.

## 5. Status Prototipe Saat Ini

Dua file HTML di folder ini, keduanya **vanilla JS, tanpa framework/build step** (pola yang sama dengan `e-sign.ai` — lihat referensi arsitektur mereka di `WEB_UI_SAMPLE.md`):

| File | Sifat | Isi |
|---|---|---|
| `erp-konstruksi-mockup.html` | Mockup visual (sebagian interaktif ringan) | Dashboard, Penawaran (tier picker), Invoicing (rantai dokumen + sumber/turunan), PO Baru (toggle jenis pengadaan), Struktur & Akses (tree hierarki + permission matrix), Pilih Organisasi |
| `erp-functional-prototype.html` | **Prototipe fungsional** — state + `render()` penuh | Pilih Organisasi (data ter-isolasi per org beneran lewat `state.quotationsByOrg`), CRUD Penawaran penuh (tambah/hapus item, pilih tier beneran ganti BOM & harga, validasi, simpan ke state). Modul lain (Invoicing, PO, SPK, dst.) masih placeholder — sengaja belum diimplementasikan supaya polanya tervalidasi dulu di 1 modul sebelum diperluas. |

Pola arsitektur front-end prototipe (`erp-functional-prototype.html`): satu object `state` global (mutable) + satu fungsi `render()` yang me-replace innerHTML container `#app` tiap ada perubahan (full re-render, bukan virtual DOM). Fokus & posisi kursor input dijaga manual sebelum/sesudah re-render (fix yang sama seperti yang sudah dipecahkan di e-sign.ai — lihat `WEB_UI_SAMPLE.md` poin arsitektur teknis).

## 6. Yang Perlu Diputuskan Sebelum Masuk Fase Backend Real

1. Struktur lengkap master data dari Excel (belum dibaca detail — lihat poin 2).
2. Alur onboarding user baru: invite-only vs self-signup+create-org (poin 3).
3. Desain detail modul SPK Produksi & Penerimaan Barang (baru ada di level konsep/mockup visual, belum di prototipe fungsional).
4. Desain modul Kuitansi & Pelunasan — termin, cicilan, relasi ke Delivery Order.
5. Numbering/format dokumen per organisasi (prefix invoice/PO dst. — disebut sekilas di skema Organization tapi belum didetailkan).
