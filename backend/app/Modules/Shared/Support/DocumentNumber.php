<?php

namespace App\Modules\Shared\Support;

use App\Modules\Tenancy\Application\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Nomor dokumen berurutan per company: {PREFIX}-{KODE_OUTLET}-{YYMM}-{URUT}, mis. PO-KMG-2609-0001.
 * Urutan dijaga baris `document_sequences` (UPSERT atomik) sehingga aman dipakai bersamaan.
 */
final class DocumentNumber
{
    public static function next(string $prefix, string $outletCode, CarbonInterface $date): string
    {
        return self::sequence(strtoupper($prefix).'-'.strtoupper($outletCode).'-'.$date->format('ym'));
    }

    /**
     * Nomor tanpa cakupan outlet: {PREFIX}-{YYMM}-{URUT}, mis. JU-2610-0001.
     * Dipakai dokumen tingkat entitas seperti jurnal, yang memang tidak milik outlet mana pun.
     */
    public static function nextForCompany(string $prefix, CarbonInterface $date): string
    {
        return self::sequence(strtoupper($prefix).'-'.$date->format('ym'));
    }

    private static function sequence(string $key): string
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $row = DB::selectOne(
            'INSERT INTO document_sequences (company_id, key, last_value) VALUES (?, ?, 1)
             ON CONFLICT (company_id, key) DO UPDATE SET last_value = document_sequences.last_value + 1
             RETURNING last_value',
            [$companyId, $key],
        );

        return sprintf('%s-%04d', $key, (int) $row->last_value);
    }
}
