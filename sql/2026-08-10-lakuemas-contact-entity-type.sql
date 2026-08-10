-- Tipe entitas kontak (individual/company), terpisah dari `type`
-- (customer/vendor/both). Dipakai nanti buat nentuin perlakuan pajak
-- (PPh/PPN dll beda antara pribadi vs badan usaha) di transaksi kayak PO.
-- Belum ada logic pajaknya sekarang — cuma nyiapin datanya dulu.
ALTER TABLE contacts
  ADD COLUMN entity_type ENUM('individual','company') NOT NULL DEFAULT 'individual' AFTER type;
