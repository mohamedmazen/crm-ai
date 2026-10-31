<?php

namespace App\Events;

use App\Models\User;
use App\Modules\Leads\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a lead is (re)assigned to a user. The listener turns it into an
 * in-app/email/push notification for the new assignee.
 */
class LeadAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Lead $lead,
        public readonly User $assignedTo,
        public readonly ?User $assignedBy = null,
    ) {}
}
