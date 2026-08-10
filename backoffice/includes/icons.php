<?php
/**
 * Ikon garis minimal (16x16, stroke currentColor) buat sidebar nav — dibikin
 * manual biar gak nambah dependency font/library baru, konsisten sama
 * filosofi app ini (vanilla, no build step). Trusted output, aman di-echo
 * langsung tanpa htmlspecialchars.
 */
function nav_icon(string $key): string
{
    $body = match ($key) {
        'dashboard' => '<rect x="2" y="2" width="6" height="6" rx="1.3"/><rect x="10" y="2" width="6" height="6" rx="1.3"/><rect x="2" y="10" width="6" height="6" rx="1.3"/><rect x="10" y="10" width="6" height="6" rx="1.3"/>',
        'kontak' => '<circle cx="8" cy="5.5" r="2.7"/><path d="M2.5 15c0-3.3 2.5-5.5 5.5-5.5s5.5 2.2 5.5 5.5"/>',
        'product_master' => '<path d="M2 8.6V3a1 1 0 0 1 1-1h5.6a1 1 0 0 1 .7.3l6 6a1 1 0 0 1 0 1.4l-5.6 5.6a1 1 0 0 1-1.4 0l-6-6A1 1 0 0 1 2 8.6Z"/><circle cx="5.3" cy="5.3" r=".9" fill="currentColor" stroke="none"/>',
        'product_categories' => '<path d="M2 5.5 8 2l6 3.5-6 3.5-6-3.5Z"/><path d="M2 10l6 3.5 6-3.5"/>',
        'locations' => '<path d="M8 14.5S13 10 13 6.5A5 5 0 0 0 3 6.5C3 10 8 14.5 8 14.5Z"/><circle cx="8" cy="6.5" r="1.8"/>',
        'stock_types' => '<path d="M8 2 2.5 5 8 8l5.5-3L8 2Z"/><path d="M2.5 8 8 11l5.5-3"/><path d="M2.5 11 8 14l5.5-3"/>',
        'flow_transaksi' => '<circle cx="4" cy="4" r="1.8"/><circle cx="4" cy="12" r="1.8"/><circle cx="12.5" cy="8" r="1.8"/><path d="M5.6 4.7 11 7.2M5.6 11.3 11 8.8"/>',
        'quotation_gold' => '<path d="M4 2h6l3 3v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Z"/><path d="M5.5 7h5M5.5 9.6h5M5.5 12.2h3"/>',
        'po_gold' => '<path d="M2 3h1.6l1 8.4a1.4 1.4 0 0 0 1.4 1.2h6.2a1.4 1.4 0 0 0 1.4-1.1l1-5.5H4.3"/><circle cx="6.6" cy="14" r="1"/><circle cx="11.6" cy="14" r="1"/>',
        'goods_receipt_gold' => '<path d="M2.5 6.5 8 2l5.5 4.5"/><path d="M3 7v6.2a.8.8 0 0 0 .8.8h8.4a.8.8 0 0 0 .8-.8V7"/><path d="M8 6.2v4.3M6 8.6l2 1.9 2-1.9"/>',
        'stock_transfer' => '<path d="M2.5 5.5h8.4M8.6 3l2.3 2.5-2.3 2.5"/><path d="M13.5 10.5H5.1M7.4 13l-2.3-2.5L7.4 8"/>',
        'sale_gold' => '<circle cx="8" cy="8" r="6"/><path d="M8 4.8v6.4M10 6.3c0-.9-.9-1.5-2-1.5-1.2 0-2 .6-2 1.5S6.8 8 8 8s2 .6 2 1.6-.8 1.6-2 1.6c-1.1 0-2-.6-2-1.5"/>',
        'melting' => '<path d="M8 14c2.5 0 4-1.6 4-3.7 0-2-1.4-3-2-4.6-.4 1-1.1 1.5-1.7 1-.7-.6-.4-1.9-1-3.2C6 5 4 6.6 4 9.6 4 12 5.5 14 8 14Z"/>',
        'supplier_return' => '<path d="M4 8.5H12M4 8.5l3-3M4 8.5l3 3"/><path d="M12 3.5v10"/>',
        'stock_report' => '<path d="M2.5 13.5h11"/><rect x="4" y="8.5" width="2.2" height="5" rx=".3"/><rect x="7.4" y="5.5" width="2.2" height="8" rx=".3"/><rect x="10.8" y="9.8" width="2.2" height="3.7" rx=".3"/>',
        'item_lookup' => '<circle cx="7" cy="7" r="4.3"/><path d="M13.5 13.5 10 10"/>',
        'projects' => '<rect x="2" y="5.3" width="12" height="8.2" rx="1.2"/><path d="M5.6 5.3V4a1.3 1.3 0 0 1 1.3-1.3h2.2A1.3 1.3 0 0 1 10.4 4v1.3"/>',
        'project_flow' => '<rect x="2" y="2.5" width="3.6" height="11" rx="1"/><rect x="6.2" y="2.5" width="3.6" height="7" rx="1"/><rect x="10.4" y="2.5" width="3.6" height="9" rx="1"/>',
        'users' => '<circle cx="6" cy="5.3" r="2.3"/><path d="M1.8 14c0-2.8 2-4.6 4.2-4.6s4.2 1.8 4.2 4.6"/><path d="M10.6 4.2a2.1 2.1 0 0 1 0 4.1M12.3 9.6c1.7.3 2.9 1.8 2.9 4"/>',
        'roles' => '<path d="M8 2 3 3.8v3.6c0 3.6 2.2 6 5 6.6 2.8-.6 5-3 5-6.6V3.8L8 2Z"/><path d="M5.8 8l1.6 1.6L10.4 6"/>',
        default => '<circle cx="8" cy="8" r="3"/>',
    };
    return '<svg class="nav-ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}
