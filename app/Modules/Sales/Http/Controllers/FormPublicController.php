<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Exceptions\FormSubmissionBlockedException;
use App\Modules\Sales\Models\Form;
use App\Modules\Sales\Services\FormSpamVerifier;
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
        private readonly FormSpamVerifier $spamVerifier,
    ) {}

    public function show(Request $request, string $slug): Response
    {
        $form = Form::where('slug', $slug)
            ->where('status', Form::STATUS_PUBLISHED)
            ->with(['fields'])
            ->firstOrFail();

        // Increment view count (avoid counting bots or rapid reloads if desired, but incrementing cleanly)
        $form->increment('views_count');

        $spamProtection = $form->spam_protection ?: 'none';

        return Inertia::render('Forms/PublicForm', [
            'form' => [
                'uuid' => $form->uuid,
                'name' => $form->name,
                'slug' => $form->slug,
                'settings' => $form->settings ?? [],
                'thank_you_type' => $form->thank_you_type,
                'thank_you_value' => $form->thank_you_value,
                'spam_protection' => $spamProtection,
                'spam_site_key' => $spamProtection === 'none'
                    ? null
                    : config("services.{$spamProtection}.site_key"),
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

        $payload = $request->except([
            '_token',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'cf-turnstile-response',
            'g-recaptcha-response',
            'spam_token',
        ]);

        $meta = [
            'ip' => $request->ip(),
            'referrer' => $request->header('referer'),
            'utm_source' => $request->input('utm_source'),
            'utm_medium' => $request->input('utm_medium'),
            'utm_campaign' => $request->input('utm_campaign'),
            'utm_term' => $request->input('utm_term'),
            'utm_content' => $request->input('utm_content'),
        ];

        try {
            $this->spamVerifier->verify($form, $request->all(), $request->ip());
            $result = $this->submissionService->processSubmission($form, $payload, $meta);
        } catch (FormSubmissionBlockedException $exception) {
            return $this->blocked($request, $exception);
        }

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

    /**
     * Answer a refused submission with the status the block carries, using the
     * same shape the public form already reads for JSON and web responses.
     */
    private function blocked(Request $request, FormSubmissionBlockedException $exception): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson() || $request->header('X-Inertia')) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->status);
        }

        return back()->withErrors(['form' => $exception->getMessage()]);
    }
}
