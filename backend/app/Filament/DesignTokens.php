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
    public const PRIMARY = '#1c724f';

    /** --primary-50 */
    public const PRIMARY_SOFT = '#ecf8f2';

    /** --primary-800 */
    public const PRIMARY_STRONG = '#174e39';

    /** --gray-400 (pembanding di grafik) */
    public const NEUTRAL_MUTED = '#b4b7bd';

    /** --gray-200 (garis bantu grafik) */
    public const NEUTRAL_LINE = '#ebe9f1';

    /** --gray-500 (teks isi Vuexy) */
    public const NEUTRAL_TEXT = '#6e6b7b';

    /** --success-500 / --danger-500 / --warning-500 / --info-500 (rona Vuexy) */
    public const SUCCESS = '#28c76f';

    public const DANGER = '#ea5455';

    public const WARNING = '#ff9f43';

    public const INFO = '#00cfe8';
}
