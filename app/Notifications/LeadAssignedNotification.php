<?php

namespace App\Notifications;

use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Notifications\Channels\OneSignalChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class LeadAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Lead $lead,
        public readonly ?User $assignedBy = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', OneSignalChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'lead_assigned',
            'lead_id' => $this->lead->id,
            'lead_name' => $this->lead->name,
            'assigned_by' => $this->assignedBy?->name,
            'url' => route('client.leads.pipeline.index'),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toOneSignal(object $notifiable): array
    {
        return [
            'title' => __('Lead assigned to you'),
            'body' => __(':by assigned :lead to you', [
                'by' => $this->assignedBy?->name ?? __('Someone'),
                'lead' => $this->lead->name,
            ]),
            'url' => route('client.leads.pipeline.index'),
        ];
    }
}
