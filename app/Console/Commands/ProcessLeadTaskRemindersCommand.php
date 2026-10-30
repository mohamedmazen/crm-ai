<?php

namespace App\Console\Commands;

use App\Modules\Leads\Jobs\SendTaskReminderJob;
use App\Modules\Leads\Models\LeadTask;
use Illuminate\Console\Command;

class ProcessLeadTaskRemindersCommand extends Command
{
    protected $signature = 'leads:process-task-reminders';

    protected $description = 'Process upcoming lead tasks and queue notification reminders';

    public function handle(): int
    {
        $upcomingTasks = LeadTask::query()
            ->pending()
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addMinutes(15))
            ->whereNull('reminded_at')
            ->with(['lead', 'assignedUser', 'creator'])
            ->get();

        $count = $upcomingTasks->count();

        foreach ($upcomingTasks as $task) {
            SendTaskReminderJob::dispatch($task)->onQueue('leads');
        }

        $this->info("Dispatched {$count} task reminder job(s).");

        return self::SUCCESS;
    }
}

