import { useState, useEffect, useRef } from 'react';
import { Head } from '@inertiajs/react';
import { CheckCircle2, AlertCircle, Loader2 } from 'lucide-react';

export default function PublicForm({ form, fields = [] }) {
    const settings = form.settings || {};
    const primaryColor = settings.primary_color || '#6366f1';
    const buttonText = settings.button_text || 'Submit';
    const showTitle = settings.show_title ?? true;
    const spamProtection = form.spam_protection || 'none';
    const spamSiteKey = form.spam_site_key;

    const [formData, setFormData] = useState({});
    const [utmParams, setUtmParams] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [submitted, setSubmitted] = useState(false);
    const [errorMessage, setErrorMessage] = useState('');
    const [fieldErrors, setFieldErrors] = useState({});
    const [successMessage, setSuccessMessage] = useState('');
    const [spamToken, setSpamToken] = useState('');
    const spamContainerRef = useRef(null);
    const spamWidgetIdRef = useRef(null);

    useEffect(() => {
        // Extract UTM parameters from URL query string
        if (typeof window !== 'undefined') {
            const urlParams = new URLSearchParams(window.location.search);
            const utm = {};
            ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach((param) => {
                const val = urlParams.get(param);
                if (val) utm[param] = val;
            });
            setUtmParams(utm);
        }
    }, []);

    const spamScriptSrc = spamProtection === 'turnstile'
        ? 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
        : spamProtection === 'recaptcha'
            ? 'https://www.google.com/recaptcha/api.js?render=explicit'
            : null;

    useEffect(() => {
        if (!spamScriptSrc || !spamSiteKey || !spamContainerRef.current) {
            return;
        }

        let cancelled = false;

        const renderWidget = () => {
            if (cancelled || !spamContainerRef.current || spamWidgetIdRef.current !== null) {
                return;
            }

            if (spamProtection === 'turnstile' && window.turnstile) {
                spamWidgetIdRef.current = window.turnstile.render(spamContainerRef.current, {
                    sitekey: spamSiteKey,
                    callback: (token) => setSpamToken(token),
                    'expired-callback': () => setSpamToken(''),
                    'error-callback': () => setSpamToken(''),
                });
            } else if (spamProtection === 'recaptcha' && window.grecaptcha) {
                spamWidgetIdRef.current = window.grecaptcha.render(spamContainerRef.current, {
                    sitekey: spamSiteKey,
                    callback: (token) => setSpamToken(token),
                    'expired-callback': () => setSpamToken(''),
                });
            }
        };

        const scriptId = `spam-${spamProtection}-script`;
        const existing = document.getElementById(scriptId);

        if (existing) {
            if (spamProtection === 'turnstile' ? window.turnstile : window.grecaptcha) {
                renderWidget();
            } else {
                existing.addEventListener('load', renderWidget);
            }

            return () => {
                cancelled = true;
            };
        }

        const script = document.createElement('script');
        script.id = scriptId;
        script.src = spamScriptSrc;
        script.async = true;
        script.defer = true;
        script.onload = renderWidget;
        document.body.appendChild(script);

        return () => {
            cancelled = true;
        };
    }, [spamProtection, spamSiteKey, spamScriptSrc]);

    // CAPTCHA tokens are single use, so an error means the visitor needs a
    // fresh one before they can retry.
    const resetSpam = () => {
        setSpamToken('');

        const widgetId = spamWidgetIdRef.current;
        if (widgetId === null) {
            return;
        }

        if (spamProtection === 'turnstile' && window.turnstile) {
            window.turnstile.reset(widgetId);
        } else if (spamProtection === 'recaptcha' && window.grecaptcha) {
            window.grecaptcha.reset(widgetId);
        }
    };

    const handleChange = (key, value) => {
        setFormData((prev) => ({ ...prev, [key]: value }));
        if (fieldErrors[key]) {
            setFieldErrors((prev) => {
                const next = { ...prev };
                delete next[key];
                return next;
            });
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setErrorMessage('');
        setFieldErrors({});

        if (spamProtection !== 'none' && !spamToken) {
            setErrorMessage('Please complete the spam verification and try again.');
            return;
        }

        setSubmitting(true);

        try {
            const payload = {
                ...formData,
                ...utmParams,
            };

            if (spamProtection === 'turnstile') {
                payload['cf-turnstile-response'] = spamToken;
            } else if (spamProtection === 'recaptcha') {
                payload['g-recaptcha-response'] = spamToken;
            }

            const response = await fetch(route('forms.public.submit', form.slug), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            });

            const data = await response.json();

            if (!response.ok) {
                if (response.status === 422 && data.errors) {
                    setFieldErrors(data.errors);
                } else {
                    setErrorMessage(data.message || 'Something went wrong. Please check your inputs.');
                }
                resetSpam();
                setSubmitting(false);
                return;
            }

            if (data.thank_you_type === 'redirect' && data.thank_you_value) {
                window.location.href = data.thank_you_value;
                return;
            }

            setSuccessMessage(data.thank_you_value || 'Thank you! Your submission has been received.');
            setSubmitted(true);
        } catch (err) {
            setErrorMessage('Network error. Please try again.');
            resetSpam();
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="min-h-screen bg-neutral-50/70 p-4 sm:p-8 flex items-center justify-center font-sans antialiased text-neutral-800">
            <Head title={form.name} />

            <div className="w-full max-w-lg rounded-2xl border border-neutral-200/80 bg-white p-6 sm:p-8 shadow-sm">
                {submitted ? (
                    <div className="py-12 text-center space-y-4">
                        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                            <CheckCircle2 className="h-10 w-10" />
                        </div>
                        <h2 className="text-2xl font-bold text-neutral-900">
                            {successMessage}
                        </h2>
                    </div>
                ) : (
                    <form onSubmit={handleSubmit} className="space-y-5">
                        {showTitle && (
                            <div className="border-b border-neutral-100 pb-4">
                                <h1 className="text-xl font-bold text-neutral-900">
                                    {form.name}
                                </h1>
                            </div>
                        )}

                        {errorMessage && (
                            <div className="flex items-center gap-2 rounded-xl bg-red-50 p-3 text-xs text-red-700 border border-red-200">
                                <AlertCircle className="h-4 w-4 shrink-0" />
                                <span>{errorMessage}</span>
                            </div>
                        )}

                        <div className="space-y-4">
                            {fields.map((field) => (
                                <div key={field.id || field.field_key} className="space-y-1">
                                    <label className="block text-xs font-semibold text-neutral-700">
                                        {field.label}
                                        {field.required && <span className="text-red-500 ml-1">*</span>}
                                    </label>

                                    {field.type === 'textarea' ? (
                                        <textarea
                                            rows="3"
                                            required={Boolean(field.required)}
                                            placeholder={field.placeholder || ''}
                                            value={formData[field.field_key] || ''}
                                            onChange={(e) => handleChange(field.field_key, e.target.value)}
                                            className="w-full rounded-xl border border-neutral-300 p-2.5 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                    ) : field.type === 'select' ? (
                                        <select
                                            required={Boolean(field.required)}
                                            value={formData[field.field_key] || ''}
                                            onChange={(e) => handleChange(field.field_key, e.target.value)}
                                            className="w-full rounded-xl border border-neutral-300 p-2.5 text-sm text-neutral-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 bg-white"
                                        >
                                            <option value="">{field.placeholder || 'Select an option...'}</option>
                                            {(field.options || []).map((opt, oi) => (
                                                <option key={oi} value={opt}>{opt}</option>
                                            ))}
                                        </select>
                                    ) : field.type === 'checkbox' ? (
                                        <div className="flex items-center gap-2 pt-1">
                                            <input
                                                type="checkbox"
                                                id={`field_${field.field_key}`}
                                                required={Boolean(field.required)}
                                                checked={Boolean(formData[field.field_key])}
                                                onChange={(e) => handleChange(field.field_key, e.target.checked)}
                                                className="rounded text-indigo-600 focus:ring-indigo-500 h-4 w-4"
                                            />
                                            <label htmlFor={`field_${field.field_key}`} className="text-xs text-neutral-600">
                                                {field.placeholder || field.label}
                                            </label>
                                        </div>
                                    ) : (
                                        <input
                                            type={field.type === 'email' ? 'email' : field.type === 'phone' ? 'tel' : field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
                                            required={Boolean(field.required)}
                                            placeholder={field.placeholder || ''}
                                            value={formData[field.field_key] || ''}
                                            onChange={(e) => handleChange(field.field_key, e.target.value)}
                                            className="w-full rounded-xl border border-neutral-300 p-2.5 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                    )}

                                    {fieldErrors[field.field_key] && (
                                        <p className="text-[11px] text-red-500 mt-1">
                                            {fieldErrors[field.field_key]}
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>

                        {spamProtection !== 'none' && spamSiteKey && (
                            <div className="pt-1">
                                <div ref={spamContainerRef} />
                            </div>
                        )}

                        <div className="pt-3">
                            <button
                                type="submit"
                                disabled={submitting}
                                style={{ backgroundColor: primaryColor }}
                                className="w-full rounded-xl py-3 text-sm font-bold text-white shadow-sm transition-all hover:opacity-90 disabled:opacity-50 flex items-center justify-center gap-2"
                            >
                                {submitting ? (
                                    <>
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                        <span>Submitting...</span>
                                    </>
                                ) : (
                                    <span>{buttonText}</span>
                                )}
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}

