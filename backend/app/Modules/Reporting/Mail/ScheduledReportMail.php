<?php

namespace App\Modules\Reporting\Mail;

use App\Modules\Reporting\Application\ReportCatalog;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Reporting\Domain\Models\ReportSchedule;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Email laporan terjadwal dengan lampiran Excel/PDF (FR-RPT-08). */
class ScheduledReportMail extends Mailable
{
    public function __construct(
        public readonly ReportSchedule $schedule,
        public readonly ReportTable $table,
        public readonly string $companyName,
        public readonly string $attachmentPath,
        public readonly string $attachmentName,
        public readonly string $mime,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.$this->companyName.'] '.$this->schedule->name.' — '.($this->table->filters['Periode'] ?? ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.scheduled-report',
            text: 'mail.scheduled-report-text',
            with: [
                'schedule' => $this->schedule,
                'table' => $this->table,
                'company' => $this->companyName,
                'reportLabel' => ReportCatalog::label($this->schedule->report_key),
                'frequency' => ReportSchedule::FREQUENCIES[$this->schedule->frequency] ?? $this->schedule->frequency,
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromPath($this->attachmentPath)->as($this->attachmentName)->withMime($this->mime)];
    }
}
