<?php

namespace App\Modules\Leads\Jobs;

use App\Modules\Leads\Models\LeadTask;
use App\Modules\Leads\Notifications\LeadTaskDueNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTaskReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly LeadTask $task,
    ) {}

    public function handle(): void
    {
        if ($this->task->isCompleted()) {
            return;
        }

        $user = $this->task->assignedUser ?? $this->task->creator;

        if ($user) {
            $user->notify(new LeadTaskDueNotification($this->task));
            $this->task->update(['reminded_at' => now()]);
        }
    }
}

