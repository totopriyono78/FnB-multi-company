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
    public const NEUTRAL_MUTED = '#a8a29e';

    /** --gray-200 (garis bantu grafik) */
    public const NEUTRAL_LINE = '#e7e5e4';
}
