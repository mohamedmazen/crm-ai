<?php

namespace App\Mail;

use App\Modules\Reporting\Models\ReportSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ScheduledReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var array<string, string> */
    private const LABELS = [
        'volume' => 'Lead Volume',
        'sources' => 'Source Performance',
        'win-loss' => 'Win/Loss Reasons',
        'agents' => 'Agent Performance',
        'response' => 'Response Time',
        'pipeline' => 'Pipeline Funnel',
        'attribution' => 'Source Attribution',
    ];

    public function __construct(
        public readonly ReportSchedule $schedule,
        public readonly string $period,
        public readonly string $filename,
        public readonly string $csv,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(':report report — :workspace · :period', [
                'report' => self::label($this->schedule->report_type),
                'workspace' => $this->schedule->workspace?->name ?? 'your workspace',
                'period' => $this->period,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.scheduled-report',
            with: [
                'workspaceName' => $this->schedule->workspace?->name,
                'reportLabel' => self::label($this->schedule->report_type),
                'frequency' => $this->schedule->frequency,
                'period' => $this->period,
                'reportsUrl' => url('/app/reports/sales'),
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->csv, $this->filename)->withMime('text/csv'),
        ];
    }

    public static function label(string $reportType): string
    {
        return self::LABELS[$reportType] ?? ucfirst($reportType);
    }
}
