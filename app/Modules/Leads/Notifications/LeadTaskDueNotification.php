<?php

namespace App\Modules\Leads\Notifications;

use App\Modules\Leads\Models\LeadTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class LeadTaskDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly LeadTask $task,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'lead_task_due',
            'task_id' => $this->task->id,
            'title' => $this->task->title,
            'lead_id' => $this->task->lead_id,
            'lead_name' => $this->task->lead?->name,
            'due_at' => $this->task->due_at?->toIso8601String(),
            'url' => route('client.leads.pipeline.index', ['lead' => $this->task->lead_id]),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}

