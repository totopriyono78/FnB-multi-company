<?php

namespace App\Modules\Reporting\Export;

use App\Modules\Reporting\Application\ReportTable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Membuat berkas ekspor sementara dari ReportTable. Pemanggil wajib menghapus berkas setelah dipakai. */
class ReportExporter
{
    public const FORMATS = ['xlsx' => 'Excel', 'pdf' => 'PDF'];

    public function __construct(
        private readonly XlsxExporter $xlsx,
        private readonly PdfExporter $pdf,
    ) {}

    /** @return array{path: string, filename: string, mime: string} */
    public function export(ReportTable $table, string $format, string $companyName, string $periodSlug): array
    {
        if (! array_key_exists($format, self::FORMATS)) {
            throw new InvalidArgumentException("Format ekspor tidak dikenal: {$format}");
        }
        $dir = storage_path('app/private/report-exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir.'/'.Str::uuid7().'.'.$format;

        if ($format === 'xlsx') {
            $this->xlsx->write($table, $path, $companyName);
        } else {
            $this->pdf->write($table, $path, $companyName);
        }

        return [
            'path' => $path,
            'filename' => self::filename($table, $periodSlug, $format),
            'mime' => $format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'application/pdf',
        ];
    }

    public static function filename(ReportTable $table, string $periodSlug, string $format): string
    {
        $slug = Str::slug(str_replace('—', ' ', $table->title));

        return (str_starts_with($slug, 'laporan') ? $slug : 'laporan-'.$slug).'-'.$periodSlug.'.'.$format;
    }
}
