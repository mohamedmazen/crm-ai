import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Bot, Clock, Play, ShieldCheck, Sparkles } from 'lucide-react';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';

const fieldClass = 'mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-100';

/**
 * The AI SDR control panel.
 *
 * The page is the guard rails made visible: the switch that turns the sender
 * on, the caps it respects, and the counters that show it staying inside them.
 */
export default function AiSdrIndex({ settings, channels = [], usage = { today: 0, total: 0 }, limits = {} }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};

    const [running, setRunning] = useState(false);

    const { data, setData, put, processing, errors } = useForm({
        enabled: settings.enabled,
        channel: settings.channel,
        daily_cap: settings.daily_cap,
        per_lead_cap: settings.per_lead_cap,
        min_hours_between: settings.min_hours_between,
    });

    const submit = (event) => {
        event.preventDefault();
        put(route('client.sales.ai-sdr.update'), { preserveScroll: true });
    };

    const runNow = () => {
        setRunning(true);
        router.post(
            route('client.sales.ai-sdr.run'),
            {},
            { preserveScroll: true, onFinish: () => setRunning(false) },
        );
    };

    const numberField = (key, hintKey) => (
        <div>
            <label className="text-xs font-medium text-neutral-600 dark:text-neutral-300">
                {t(`ai_sdr.${key}`)}
            </label>
            <input
                type="number"
                min={limits[key]?.min ?? 1}
                max={limits[key]?.max ?? 999}
                value={data[key]}
                onChange={(event) => setData(key, event.target.value)}
                className={fieldClass}
            />
            <p className="mt-1 text-[11px] text-neutral-400">{t(hintKey)}</p>
            {errors[key] && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors[key]}</p>}
        </div>
    );

    return (
        <ClientLayout title={t('ai_sdr.title')}>
            <Head title={t('ai_sdr.title')} />

            <div className="mx-auto max-w-4xl space-y-5">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                        <Bot className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                        {t('ai_sdr.title')}
                    </h2>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{t('ai_sdr.subtitle')}</p>
                </div>

                {flash.success && (
                    <div className="rounded-lg bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-200">{flash.success}</div>
                )}
                {flash.error && (
                    <div className="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200">{flash.error}</div>
                )}

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                        <p className="text-[11px] font-semibold uppercase tracking-wider text-neutral-400">{t('ai_sdr.usage_today')}</p>
                        <p className="mt-1 text-2xl font-bold text-neutral-900 dark:text-neutral-100">
                            {usage.today}
                            <span className="text-sm font-medium text-neutral-400"> / {data.daily_cap}</span>
                        </p>
                    </div>
                    <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                        <p className="text-[11px] font-semibold uppercase tracking-wider text-neutral-400">{t('ai_sdr.usage_total')}</p>
                        <p className="mt-1 text-2xl font-bold text-neutral-900 dark:text-neutral-100">{usage.total}</p>
                    </div>
                    <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                        <p className="text-[11px] font-semibold uppercase tracking-wider text-neutral-400">{t('ai_sdr.last_run')}</p>
                        <p className="mt-1 text-sm font-medium text-neutral-700 dark:text-neutral-200">
                            {settings.last_run_at
                                ? new Date(settings.last_run_at).toLocaleString()
                                : t('ai_sdr.never')}
                        </p>
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-5 rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('ai_sdr.kill_switch')}</p>
                            <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{t('ai_sdr.kill_switch_hint')}</p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={data.enabled}
                            onClick={() => setData('enabled', !data.enabled)}
                            className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition ${
                                data.enabled ? 'bg-brand-600' : 'bg-neutral-300 dark:bg-neutral-600'
                            }`}
                        >
                            <span className={`inline-block h-4 w-4 rounded-full bg-white shadow transition ${data.enabled ? 'translate-x-6' : 'translate-x-1'}`} />
                        </button>
                    </div>

                    <div className="rounded-lg bg-neutral-50 px-3 py-2 text-xs text-neutral-600 dark:bg-neutral-800/60 dark:text-neutral-300">
                        <span className="inline-flex items-center gap-1.5 font-medium">
                            {data.enabled ? <Sparkles className="h-3.5 w-3.5 text-brand-600" /> : <Clock className="h-3.5 w-3.5" />}
                            {data.enabled ? t('ai_sdr.enabled') : t('ai_sdr.disabled')}
                        </span>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="text-xs font-medium text-neutral-600 dark:text-neutral-300">{t('ai_sdr.channel')}</label>
                            <select
                                value={data.channel}
                                onChange={(event) => setData('channel', event.target.value)}
                                className={fieldClass}
                            >
                                {channels.map((channel) => (
                                    <option key={channel} value={channel}>{t(`ai_sdr.channel_${channel}`)}</option>
                                ))}
                            </select>
                            {errors.channel && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.channel}</p>}
                        </div>

                        {numberField('daily_cap', 'ai_sdr.daily_cap_hint')}
                        {numberField('per_lead_cap', 'ai_sdr.per_lead_cap_hint')}
                        {numberField('min_hours_between', 'ai_sdr.min_hours_between_hint')}
                    </div>

                    <div className="flex flex-wrap items-center justify-end gap-2 border-t border-neutral-100 pt-4 dark:border-neutral-800">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={runNow}
                            disabled={running || !settings.enabled}
                            title={!settings.enabled ? t('ai_sdr.run_disabled_hint') : undefined}
                        >
                            <Play className="me-1.5 h-4 w-4" aria-hidden="true" />
                            {running ? t('ai_sdr.run_queued') : t('ai_sdr.run_now')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? t('ai_sdr.saving') : t('ai_sdr.save')}
                        </Button>
                    </div>
                </form>

                <div className="flex gap-3 rounded-xl border border-brand-200/60 bg-brand-50/50 p-4 dark:border-brand-900 dark:bg-brand-900/20">
                    <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                    <div>
                        <p className="text-sm font-semibold text-brand-800 dark:text-brand-200">{t('ai_sdr.guard_title')}</p>
                        <p className="mt-1 text-xs leading-relaxed text-brand-700/90 dark:text-brand-300/90">{t('ai_sdr.guard_body')}</p>
                    </div>
                </div>
            </div>
        </ClientLayout>
    );
}