<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Form;
use Illuminate\Http\Response;

class FormEmbedController extends Controller
{
    public function script(string $slug): Response
    {
        $form = Form::where('slug', $slug)
            ->where('status', Form::STATUS_PUBLISHED)
            ->firstOrFail();

        $formUrl = url('/f/'.$form->slug);

        $js = <<<JS
(function() {
    var container = document.currentScript ? document.currentScript.parentNode : document.body;
    var iframe = document.createElement('iframe');
    iframe.src = "{$formUrl}";
    iframe.width = "100%";
    iframe.height = "550px";
    iframe.frameBorder = "0";
    iframe.scrolling = "auto";
    iframe.style.border = "none";
    iframe.style.borderRadius = "12px";
    iframe.style.overflow = "hidden";
    iframe.style.boxShadow = "0 4px 12px rgba(0,0,0,0.05)";
    container.appendChild(iframe);
})();
JS;

        return response($js, 200, [
            'Content-Type' => 'application/javascript',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
