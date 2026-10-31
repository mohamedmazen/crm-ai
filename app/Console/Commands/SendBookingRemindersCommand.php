<?php

namespace App\Console\Commands;

use App\Modules\Sales\Jobs\SendBookingReminderJob;
use App\Modules\Sales\Models\Booking;
use Illuminate\Console\Command;

class SendBookingRemindersCommand extends Command
{
    protected $signature = 'bookings:send-reminders {--hours=24 : How far ahead to look for upcoming bookings}';

    protected $description = 'Queue reminder emails for confirmed bookings that start soon';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $bookings = Booking::query()
            ->dueForReminder(now()->addHours($hours))
            ->whereNotNull('guest_email')
            ->with(['meetingType', 'host'])
            ->get();

        $count = $bookings->count();

        foreach ($bookings as $booking) {
            SendBookingReminderJob::dispatch($booking)->onQueue('default');
        }

        $this->info("Dispatched {$count} booking reminder job(s).");

        return self::SUCCESS;
    }
}
