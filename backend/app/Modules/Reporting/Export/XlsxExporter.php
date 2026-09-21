<?php

namespace App\Modules\Reporting\Export;

use App\Modules\Reporting\Application\ReportAccess;
use App\Modules\Reporting\Application\ReportTable;
use Brick\Math\BigDecimal;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/** Ekspor ReportTable ke Excel (FR-RPT-08). Angka ditulis sebagai angka agar bisa dihitung ulang di Excel. */
class XlsxExporter
{
    public function write(ReportTable $table, string $path, string $companyName): void
    {
        $options = new Options;
        $columns = $table->columns;
        $i = 1;
        foreach ($columns as $col) {
            $options->setColumnWidth($col['type'] === ReportTable::TEXT ? 28 : 18, $i++);
        }

        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(mb_substr(self::sheetName($table->title), 0, 31));

        $bold = (new Style)->setFontBold();
        $title = (new Style)->setFontBold()->setFontSize(14);
        $muted = (new Style)->setFontColor('57534E');
        $header = (new Style)->setFontBold()->setBackgroundColor('F5F5F4')->setShouldWrapText();
        $money = (new Style)->setFormat('#,##0.00')->setCellAlignment(CellAlignment::RIGHT);
        $number = (new Style)->setFormat('#,##0.###')->setCellAlignment(CellAlignment::RIGHT);
        $percent = (new Style)->setFormat('0.0"%"')->setCellAlignment(CellAlignment::RIGHT);

        $writer->addRow(Row::fromValues([$table->title], $title));
        $writer->addRow(Row::fromValues([$companyName], $bold));
        foreach ($table->filters as $label => $value) {
            $writer->addRow(Row::fromValues([$label, $value], $muted));
        }
        $writer->addRow(Row::fromValues(['Dibuat', now()->setTimezone(ReportAccess::timezone())->format('d/m/Y H.i')], $muted));
        $writer->addRow(Row::fromValues([]));

        if ($table->summary !== []) {
            foreach ($table->summary as $s) {
                $writer->addRow(new Row([
                    Cell::fromValue($s['label'], $bold),
                    self::cell($s['value'], $s['type'], $money, $number, $percent),
                ]));
            }
            $writer->addRow(Row::fromValues([]));
        }

        $writer->addRow(Row::fromValues(array_map(fn (array $c) => $c['label'], array_values($columns)), $header));
        foreach ($table->rows as $row) {
            $cells = [];
            foreach ($columns as $key => $col) {
                $cells[] = self::cell($row[$key] ?? null, $col['type'], $money, $number, $percent);
            }
            $writer->addRow(new Row($cells));
        }
        if ($table->totals !== null) {
            $cells = [];
            $boldMoney = (new Style)->setFontBold()->setFormat('#,##0.00')->setCellAlignment(CellAlignment::RIGHT);
            $boldNumber = (new Style)->setFontBold()->setFormat('#,##0.###')->setCellAlignment(CellAlignment::RIGHT);
            $boldPercent = (new Style)->setFontBold()->setFormat('0.0"%"')->setCellAlignment(CellAlignment::RIGHT);
            foreach ($columns as $key => $col) {
                $value = $table->totals[$key] ?? null;
                $cells[] = $col['type'] === ReportTable::TEXT
                    ? Cell::fromValue($value === null ? '' : (string) $value, $bold)
                    : self::cell($value, $col['type'], $boldMoney, $boldNumber, $boldPercent);
            }
            $writer->addRow(new Row($cells));
        }

        if ($table->notes !== []) {
            $writer->addRow(Row::fromValues([]));
            foreach ($table->notes as $note) {
                $writer->addRow(Row::fromValues([$note], $muted));
            }
        }

        $writer->close();
    }

    private static function cell(string|int|null $value, string $type, Style $money, Style $number, Style $percent): Cell
    {
        if ($value === null || $value === '') {
            return Cell::fromValue('');
        }
        if ($type === ReportTable::TEXT) {
            return Cell::fromValue((string) $value);
        }
        // Nilai desimal disimpan sebagai string; float hanya dipakai di berkas Excel (tampilan 2 desimal).
        $float = BigDecimal::of((string) $value)->toFloat();

        return match ($type) {
            ReportTable::MONEY => Cell::fromValue($float, $money),
            ReportTable::PERCENT => Cell::fromValue($float, $percent),
            default => Cell::fromValue($float, $number),
        };
    }

    private static function sheetName(string $title): string
    {
        $name = trim((string) preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', str_replace('—', '-', $title)));

        return $name !== '' ? $name : 'Laporan';
    }
}
