<?php
/**
 * Daftar modul dipakai buat matrix hak akses (role_module_access) & menu
 * sidebar. Modul furniture lama (Invoicing/PO/SPK/Penerimaan/DO/Kuitansi/
 * Laporan) udah di-retire bareng tabelnya — vertikal emas cuma gerbang lewat
 * 'kontak', dan 'penawaran' sekarang gerbang Project + Project Flow.
 */
const MODULES = [
    'dashboard' => 'Dashboard',
    'penawaran' => 'Project & Transaksi',
    'kontak' => 'Kontak',
];
