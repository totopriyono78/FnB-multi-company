<?php

namespace App\Filament;

/**
 * Salinan token warna untuk tempat yang tidak bisa membaca CSS variable
 * (konfigurasi palet Filament dan avatar SVG). Sumber utama tetap
 * resources/css/filament/admin/theme.css — ubah keduanya bersamaan.
 */
final class DesignTokens
{
    /** --primary-600 */
    public const PRIMARY = '#5d3a1f';

    /** --primary-100 */
    public const PRIMARY_SOFT = '#f2e8df';

    /** --primary-800 */
    public const PRIMARY_STRONG = '#3a2413';

    /** --gray-300 (pembanding di grafik) */
    public const NEUTRAL_MUTED = '#d6ccbf';

    /** --gray-200 (garis bantu grafik) */
    public const NEUTRAL_LINE = '#e8e1d8';

    /** --gray-500 (teks isi, abu hangat) */
    public const NEUTRAL_TEXT = '#6f6457';

    /** --success-500 / --danger-500 / --warning-500 / --info-500 (rona Vuexy) */
    public const SUCCESS = '#28c76f';

    public const DANGER = '#ea5455';

    public const WARNING = '#ff9f43';

    public const INFO = '#00cfe8';

    /** --fnb-gold-500 (aksen emas brand; jangan untuk teks di latar terang) */
    public const GOLD = '#d4af37';
}
