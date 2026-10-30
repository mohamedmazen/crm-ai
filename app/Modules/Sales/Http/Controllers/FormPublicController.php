<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Form;
use App\Modules\Sales\Services\FormSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FormPublicController extends Controller
{
    public function __construct(
        private readonly FormSubmissionService $submissionService,
    ) {}

    public function show(Request $request, string $slug): Response
    {
        $form = Form::where('slug', $slug)
            ->where('status', Form::STATUS_PUBLISHED)
            ->with(['fields'])
            ->firstOrFail();

        // Increment view count (avoid counting bots or rapid reloads if desired, but incrementing cleanly)
        $form->increment('views_count');

        return Inertia::render('Forms/PublicForm', [
            'form' => [
                'uuid' => $form->uuid,
                'name' => $form->name,
                'slug' => $form->slug,
                'settings' => $form->settings ?? [],
                'thank_you_type' => $form->thank_you_type,
                'thank_you_value' => $form->thank_you_value,
            ],
            'fields' => $form->fields,
        ]);
    }

    public function submit(Request $request, string $slug): JsonResponse|RedirectResponse
    {
        $form = Form::where('slug', $slug)
            ->where('status', Form::STATUS_PUBLISHED)
            ->with(['fields'])
            ->firstOrFail();

        $payload = $request->except(['_token', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']);

        $meta = [
            'ip' => $request->ip(),
            'referrer' => $request->header('referer'),
            'utm_source' => $request->input('utm_source'),
            'utm_medium' => $request->input('utm_medium'),
            'utm_campaign' => $request->input('utm_campaign'),
            'utm_term' => $request->input('utm_term'),
            'utm_content' => $request->input('utm_content'),
        ];

        $result = $this->submissionService->processSubmission($form, $payload, $meta);

        if ($request->wantsJson() || $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'thank_you_type' => $result['thank_you_type'],
                'thank_you_value' => $result['thank_you_value'],
            ]);
        }

        if ($result['thank_you_type'] === 'redirect' && ! empty($result['thank_you_value'])) {
            return redirect()->away($result['thank_you_value']);
        }

        return back()->with('success', $result['thank_you_value']);
    }
}

