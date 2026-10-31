import ClientLayout from '@/Layouts/ClientLayout';
import { Button, Input, Select } from '@/Components/ui';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, CalendarClock, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const REPORT_KEYS = {
    volume: 'reports.sales_tab_volume',
    sources: 'reports.sales_tab_sources',
    'win-loss': 'reports.sales_tab_win_loss',
    agents: 'reports.sales_tab_agents',
    response: 'reports.sales_tab_response',
    pipeline: 'reports.sales_tab_pipeline',
    attribution: 'reports.sales_tab_attribution',
};

const FREQUENCY_KEYS = {
    daily: 'reports.sales_frequency_daily',
    weekly: 'reports.sales_frequency_weekly',
    monthly: 'reports.sales_frequency_monthly',
};

export default function Schedules({
    schedules = [],
    reportTypes = [],
    frequencies = [],
    pipelines = [],
    agents = [],
    sources = [],
    defaultRecipients = '',
}) {
    const { t } = useTranslation();
    const [editingId, setEditingId] = useState(null);

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        report_type: reportTypes[0] ?? 'volume',
        frequency: 'weekly',
        recipients: defaultRecipients,
        pipeline_id: '',
        agent_id: '',
        source: '',
    });

    const startCreate = () => {
        setEditingId(null);
        clearErrors();
        reset();
    };

    const startEdit = (schedule) => {
        setEditingId(schedule.id);
        clearErrors();
        setData({
            report_type: schedule.report_type,
            frequency: schedule.frequency,
            recipients: (schedule.recipients ?? []).join(', '),
            pipeline_id: schedule.filters?.pipeline_id ? String(schedule.filters.pipeline_id) : '',
            agent_id: schedule.filters?.agent_id ? String(schedule.filters.agent_id) : '',
            source: schedule.filters?.source ?? '',
        });
    };

    const submit = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => startCreate() };

        if (editingId) {
            put(route('client.reports.sales.schedules.update', editingId), options);
        } else {
            post(route('client.reports.sales.schedules.store'), options);
        }
    };

    const remove = (schedule) => {
        if (!window.confirm(t('reports.sales_schedule_delete_confirm'))) {
            return;
        }

        router.delete(route('client.reports.sales.schedules.destroy', schedule.id), {
            preserveScroll: true,
            onSuccess: () => {
                if (editingId === schedule.id) {
                    startCreate();
                }
            },
        });
    };

    const reportLabel = (type) => t(REPORT_KEYS[type] ?? 'reports.sales_schedules_title');
    const frequencyLabel = (frequency) => t(FREQUENCY_KEYS[frequency] ?? 'reports.sales_frequency_weekly');

    const filterSummary = (schedule) => {
        const parts = [];

        if (schedule.filters?.pipeline_id) {
            parts.push(pipelines.find((pipeline) => pipeline.id === schedule.filters.pipeline_id)?.name);
        }
        if (schedule.filters?.agent_id) {
            parts.push(agents.find((agent) => agent.id === schedule.filters.agent_id)?.name);
        }
        if (schedule.filters?.source) {
            parts.push(schedule.filters.source);
        }

        return parts.filter(Boolean).join(' · ') || t('reports.sales_schedule_all_filters');
    };

    const formatNextRun = (value) => {
        if (!value) {
            return '—';
        }

        return new Date(value).toLocaleString();
    };

    return (
        <ClientLayout title={t('reports.sales_schedules_title')}>
            <Head title={t('reports.sales_schedules_title')} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <Link
                            href={route('client.reports.sales.volume')}
                            className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-neutral-500 hover:text-brand-600 dark:text-neutral-400"
                        >
                            <ArrowLeft className="h-3.5 w-3.5" />
                            {t('reports.sales_back_to_reports')}
                        </Link>
                        <h1 className="text-xl font-bold text-neutral-900 dark:text-white">{t('reports.sales_schedules_title')}</h1>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('reports.sales_schedules_subtitle')}</p>
                    </div>
                </div>

                <form
                    onSubmit={submit}
                    className="space-y-4 rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div className="flex items-center gap-2">
                        <CalendarClock className="h-4 w-4 text-brand-600" />
                        <h2 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">
                            {editingId ? t('reports.sales_schedules_edit') : t('reports.sales_schedules_new')}
                        </h2>
                    </div>

                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <Select
                            label={t('reports.sales_schedule_report')}
                            value={data.report_type}
                            onChange={(event) => setData('report_type', event.target.value)}
                            options={reportTypes.map((type) => ({ value: type, label: reportLabel(type) }))}
                            placeholder={null}
                            error={errors.report_type}
                        />
                        <Select
                            label={t('reports.sales_schedule_frequency')}
                            value={data.frequency}
                            onChange={(event) => setData('frequency', event.target.value)}
                            options={frequencies.map((frequency) => ({ value: frequency, label: frequencyLabel(frequency) }))}
                            placeholder={null}
                            error={errors.frequency}
                        />
                        <Input
                            label={t('reports.sales_schedule_recipients')}
                            value={data.recipients}
                            onChange={(event) => setData('recipients', event.target.value)}
                            hint={t('reports.sales_schedule_recipients_hint')}
                            error={errors.recipients ?? Object.values(errors).find((message) => String(message).includes('recipients'))}
                        />
                        <div className="flex flex-col justify-end text-xs text-neutral-500 dark:text-neutral-400">
                            {t('reports.sales_schedule_period_note')}
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <Select
                            label={t('reports.sales_schedule_filters')}
                            value={data.pipeline_id}
                            onChange={(event) => setData('pipeline_id', event.target.value)}
                            options={pipelines.map((pipeline) => ({ value: String(pipeline.id), label: pipeline.name }))}
                            placeholder={t('reports.sales_all_pipelines')}
                            error={errors.pipeline_id}
                        />
                        <Select
                            label={t('reports.sales_agent')}
                            value={data.agent_id}
                            onChange={(event) => setData('agent_id', event.target.value)}
                            options={agents.map((agent) => ({ value: String(agent.id), label: agent.name }))}
                            placeholder={t('reports.sales_all_agents')}
                            error={errors.agent_id}
                        />
                        <Select
                            label={t('reports.sales_source')}
                            value={data.source}
                            onChange={(event) => setData('source', event.target.value)}
                            options={sources.map((source) => ({ value: source, label: source }))}
                            placeholder={t('reports.sales_all_sources')}
                            error={errors.source}
                        />
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="submit" disabled={processing}>
                            {editingId ? t('reports.sales_schedule_update') : t('reports.sales_schedule_save')}
                        </Button>
                        {editingId && (
                            <Button type="button" variant="ghost" onClick={startCreate}>
                                {t('reports.sales_schedule_cancel')}
                            </Button>
                        )}
                    </div>
                </form>

                <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                        {t('reports.sales_schedules_title')}
                    </h2>

                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-neutral-200 dark:border-neutral-700">
                                    <th className="pb-2 text-left text-xs text-neutral-500">{t('reports.sales_schedule_report')}</th>
                                    <th className="pb-2 text-left text-xs text-neutral-500">{t('reports.sales_schedule_frequency')}</th>
                                    <th className="pb-2 text-left text-xs text-neutral-500">{t('reports.sales_schedule_recipients')}</th>
                                    <th className="pb-2 text-left text-xs text-neutral-500">{t('reports.sales_schedule_filters')}</th>
                                    <th className="pb-2 text-left text-xs text-neutral-500">{t('reports.sales_schedule_next_run')}</th>
                                    <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_schedule_actions')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700/50">
                                {schedules.map((schedule) => (
                                    <tr key={schedule.id}>
                                        <td className="py-2 font-medium text-neutral-800 dark:text-neutral-200">{reportLabel(schedule.report_type)}</td>
                                        <td className="py-2 text-neutral-600 dark:text-neutral-400">{frequencyLabel(schedule.frequency)}</td>
                                        <td className="py-2 text-neutral-600 dark:text-neutral-400">{(schedule.recipients ?? []).join(', ')}</td>
                                        <td className="py-2 text-neutral-600 dark:text-neutral-400">{filterSummary(schedule)}</td>
                                        <td className="py-2 text-neutral-600 dark:text-neutral-400">{formatNextRun(schedule.next_run_at)}</td>
                                        <td className="py-2">
                                            <div className="flex justify-end gap-1">
                                                <button
                                                    type="button"
                                                    onClick={() => startEdit(schedule)}
                                                    className="rounded-lg p-1.5 text-neutral-500 transition hover:bg-neutral-100 hover:text-brand-600 dark:hover:bg-neutral-800"
                                                    aria-label={t('reports.sales_schedules_edit')}
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => remove(schedule)}
                                                    className="rounded-lg p-1.5 text-neutral-500 transition hover:bg-coral-50 hover:text-coral-600 dark:hover:bg-neutral-800"
                                                    aria-label={t('reports.sales_schedule_delete')}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {schedules.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="py-6 text-center text-neutral-400">
                                            {t('reports.sales_schedule_none')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </ClientLayout>
    );
}
