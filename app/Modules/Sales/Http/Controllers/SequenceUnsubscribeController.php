<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\SequenceEnrollment;
use App\Modules\Sales\Models\SequenceEvent;
use App\Modules\Sales\Services\SequenceEngine;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One-click unsubscribe target for sequence emails (RFC 8058).
 *
 * Reachable without auth, so the route is signed. It only ever touches the one
 * enrollment named in the signed URL.
 */
class SequenceUnsubscribeController extends Controller
{
    public function unsubscribe(SequenceEnrollment $enrollment, SequenceEngine $engine): Response
    {
        $enrollment->load('sequence');

        if ($enrollment->isActive()) {
            $engine->stop($enrollment, 'unsubscribed');
        }

        SequenceEvent::create([
            'enrollment_id' => $enrollment->id,
            'step_position' => (int) $enrollment->current_step,
            'type' => SequenceEvent::TYPE_UNSUBSCRIBE,
            'created_at' => now(),
        ]);

        return Inertia::render('Sequences/Unsubscribed', [
            'sequenceName' => $enrollment->sequence?->name,
        ]);
    }
}
