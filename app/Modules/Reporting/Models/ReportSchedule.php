<?php

namespace App\Modules\Reporting\Models;

use App\Models\Workspace;
use Carbon\CarbonInterface;
use Database\Factories\ReportScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recurring email delivery of one sales report.
 *
 * The report period is not stored: it is derived from the frequency at send
 * time (daily = yesterday, weekly = last week, monthly = last month), so a
 * schedule keeps producing fresh windows without anyone editing it.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $report_type
 * @property array{pipeline_id?: int|null, agent_id?: int|null, source?: string|null}|null $filters
 * @property string $frequency
 * @property string $format
 * @property list<string> $recipients
 * @property Carbon|null $next_run_at
 */
class ReportSchedule extends Model
{
    use HasFactory;

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /** Hour of day, in the app timezone, that scheduled reports are delivered at. */
    public const DELIVERY_HOUR = 8;

    protected $fillable = [
        'workspace_id',
        'report_type',
        'filters',
        'frequency',
        'format',
        'recipients',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'recipients' => 'array',
            'next_run_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ReportScheduleFactory
    {
        return ReportScheduleFactory::new();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The next delivery slot at or after the given moment.
     */
    public static function nextRunAt(string $frequency, CarbonInterface $from): Carbon
    {
        $moment = Carbon::parse($from);

        $candidate = match ($frequency) {
            'weekly' => $moment->copy()->startOfWeek()->setTime(self::DELIVERY_HOUR, 0),
            'monthly' => $moment->copy()->startOfMonth()->setTime(self::DELIVERY_HOUR, 0),
            default => $moment->copy()->setTime(self::DELIVERY_HOUR, 0),
        };

        if ($candidate->isAfter($moment)) {
            return $candidate;
        }

        return match ($frequency) {
            'weekly' => $candidate->addWeek(),
            'monthly' => $candidate->addMonthNoOverflow(),
            default => $candidate->addDay(),
        };
    }

    /**
     * The reporting window the next send covers, relative to the run time.
     *
     * @return array{from: Carbon, to: Carbon}
     */
    public static function periodFor(string $frequency, CarbonInterface $at): array
    {
        $moment = Carbon::parse($at);

        return match ($frequency) {
            'weekly' => [
                'from' => $moment->copy()->subWeek()->startOfWeek(),
                'to' => $moment->copy()->subWeek()->endOfWeek(),
            ],
            'monthly' => [
                'from' => $moment->copy()->subMonthNoOverflow()->startOfMonth(),
                'to' => $moment->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            default => [
                'from' => $moment->copy()->subDay()->startOfDay(),
                'to' => $moment->copy()->subDay()->endOfDay(),
            ],
        };
    }

    /**
     * Move the schedule on to its next slot, based on the current time.
     */
    public function advance(): void
    {
        $this->update(['next_run_at' => self::nextRunAt($this->frequency, now())]);
    }
}
