<?php

namespace App\Modules\Sales\Jobs;

use App\Modules\Sales\Exceptions\SequenceRecipientUnavailable;
use App\Modules\Sales\Models\SequenceEnrollment;
use App\Modules\Sales\Services\SequenceEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendSequenceStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $enrollmentId) {}

    public function handle(SequenceEngine $engine): void
    {
        try {
            $engine->sendNextStep($this->enrollmentId);
        } catch (SequenceRecipientUnavailable $e) {
            // Missing address / opt-out will never succeed on retry — stop now.
            $enrollment = SequenceEnrollment::find($this->enrollmentId);

            if ($enrollment && $enrollment->isActive()) {
                $engine->stop($enrollment, $e->reason);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        $enrollment = SequenceEnrollment::find($this->enrollmentId);

        if ($enrollment && $enrollment->isActive()) {
            app(SequenceEngine::class)->stop($enrollment, 'send_failed');
        }
    }
}
