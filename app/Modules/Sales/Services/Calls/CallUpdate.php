<?php

namespace App\Modules\Sales\Services\Calls;

/**
 * A provider-agnostic snapshot of one call lifecycle event.
 *
 * `status` is null for events that only carry a recording (Twilio's recording
 * callback, Telnyx's call.recording.saved) so a late recording cannot roll a
 * completed call back to "answered".
 */
final class CallUpdate
{
    public function __construct(
        public readonly string $providerSid,
        public readonly ?string $status = null,
        public readonly ?int $durationSecs = null,
        public readonly ?string $recordingUrl = null,
        public readonly ?string $fromNumber = null,
        public readonly ?string $toNumber = null,
    ) {}
}
