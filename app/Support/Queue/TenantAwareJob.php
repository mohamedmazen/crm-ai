<?php

namespace App\Support\Queue;

/**
 * Implemented by queued jobs that belong to a workspace.
 *
 * Per-tenant queue middleware resolves the workspace from this contract rather
 * than trusting ambient state: the workspace id travels with the payload, so a
 * worker picking up the job always knows which tenant it is spending time on.
 */
interface TenantAwareJob
{
    /**
     * Workspace that owns the work, or null when the record is gone.
     */
    public function tenantWorkspaceId(): ?int;
}
