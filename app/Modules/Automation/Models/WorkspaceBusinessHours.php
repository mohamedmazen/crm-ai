<?php

namespace App\Modules\Automation\Models;

use App\Models\Workspace;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A workspace's weekly opening hours, expressed in a single timezone.
 *
 * The schedule is a map of ISO weekday keys to either a {start, end} window or
 * null for a closed day:
 *
 *     {"mon":{"start":"09:00","end":"17:00"}, ..., "sat":null, "sun":null}
 *
 * Phase 4's email sequences are the first consumer of {@see isOpenAt()}; nothing
 * in the automation engine enforces these hours yet.
 */
class WorkspaceBusinessHours extends Model
{
    protected $table = 'workspace_business_hours';

    /** Weekday keys, ordered as the settings UI renders them (Monday first). */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected $fillable = ['workspace_id', 'timezone', 'schedule'];

    protected function casts(): array
    {
        return [
            'schedule' => 'array',
        ];
    }

    /**
     * Default week: Monday–Friday 09:00–17:00, weekends closed.
     *
     * @return array<string, array{start: string, end: string}|null>
     */
    public static function defaultSchedule(): array
    {
        $weekday = ['start' => '09:00', 'end' => '17:00'];

        return [
            'mon' => $weekday,
            'tue' => $weekday,
            'wed' => $weekday,
            'thu' => $weekday,
            'fri' => $weekday,
            'sat' => null,
            'sun' => null,
        ];
    }

    /** The hours row for a workspace, creating the default week on first access. */
    public static function forWorkspace(int $workspaceId): self
    {
        return self::firstOrCreate(
            ['workspace_id' => $workspaceId],
            ['timezone' => 'UTC', 'schedule' => self::defaultSchedule()],
        );
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Whether the workspace is open at the given instant (defaults to now).
     *
     * Overnight windows (end earlier than start, e.g. 22:00–02:00) count as open
     * from start until midnight and from midnight until end.
     */
    public function isOpenAt(?DateTimeInterface $moment = null): bool
    {
        $local = $this->localMoment($moment);
        $window = $this->schedule[strtolower($local->format('D'))] ?? null;

        if (! is_array($window) || empty($window['start']) || empty($window['end'])) {
            return false; // closed day
        }

        $start = $this->minutesFromClock((string) $window['start']);
        $end = $this->minutesFromClock((string) $window['end']);

        if ($start === null || $end === null) {
            return false;
        }

        $now = ((int) $local->format('G') * 60) + (int) $local->format('i');

        // Overnight window spans midnight.
        if ($end <= $start) {
            return $now >= $start || $now < $end;
        }

        return $now >= $start && $now < $end;
    }

    private function localMoment(?DateTimeInterface $moment): CarbonInterface
    {
        $at = $moment ? Carbon::instance($moment) : Carbon::now();

        return $at->setTimezone($this->timezone ?: 'UTC');
    }

    private function minutesFromClock(string $clock): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($clock), $parts)) {
            return null;
        }

        $hours = (int) $parts[1];
        $minutes = (int) $parts[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }
}
