<?php
/**
 * Upload + resize foto produk. File asli TIDAK disimpan — langsung di-resize
 * ke max dimensi tertentu terus disave sebagai JPEG, biar hemat storage &
 * konsisten dipakai di layar cetak.
 */

function save_resized_product_photo(array $file, int $maxDim = 1000): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload foto gagal.');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info) throw new RuntimeException('File bukan gambar yang valid.');
    [$width, $height, $type] = $info;

    $src = match ($type) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_PNG => imagecreatefrompng($file['tmp_name']),
        IMAGETYPE_WEBP => imagecreatefromwebp($file['tmp_name']),
        default => throw new RuntimeException('Format gambar harus JPEG, PNG, atau WEBP.'),
    };

    $scale = min(1, $maxDim / max($width, $height));
    $newW = max(1, (int) round($width * $scale));
    $newH = max(1, (int) round($height * $scale));

    $dst = imagecreatetruecolor($newW, $newH);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);

    $dir = __DIR__ . '/../backoffice/uploads/products';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = bin2hex(random_bytes(8)) . '.jpg';
    imagejpeg($dst, $dir . '/' . $filename, 82);

    return 'uploads/products/' . $filename;
}

function delete_product_photo(?string $relativePath): void
{
    if (!$relativePath) return;
    $full = __DIR__ . '/../backoffice/' . $relativePath;
    if (is_file($full)) @unlink($full);
}
