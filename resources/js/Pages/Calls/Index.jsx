import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import Modal from '@/Components/ui/Modal';
import {
    Phone, PhoneCall, PhoneIncoming, PhoneOutgoing, Plus, Settings, Trash2,
    Clock, Headphones, Search, CheckCircle2, XCircle, Loader2,
} from 'lucide-react';

const statusStyles = {
    completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
    answered: 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300',
    initiated: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
    ringing: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300',
    no_answer: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
};

function durationLabel(seconds) {
    const value = Math.max(0, Number(seconds) || 0);
    if (!value) return '—';
    const minutes = Math.floor(value / 60);
    const rest = value % 60;
    return minutes > 0 ? `${minutes}m ${String(rest).padStart(2, '0')}s` : `${rest}s`;
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString();
}

export default function CallsIndex({
    calls = { data: [], links: [], total: 0, last_page: 1 },
    leads = [],
    filters = { q: '', status: '', direction: '', lead_id: null },
    stats = { total: 0, completed: 0, today: 0, avg_duration: 0 },
    provider = { connected: false, name: null, caller_id: null },
    providerFields = {},
    providers = [],
}) {
    const { t } = useTranslation();
    const { flash, errors = {} } = usePage().props;

    const [query, setQuery] = useState(filters.q || '');
    const [status, setStatus] = useState(filters.status || '');
    const [direction, setDirection] = useState(filters.direction || '');

    const [showLog, setShowLog] = useState(false);
    const [showCall, setShowCall] = useState(false);
    const [showSettings, setShowSettings] = useState(false);
    const [deleting, setDeleting] = useState(null);

    const [callLeadId, setCallLeadId] = useState(filters.lead_id || '');
    const [calling, setCalling] = useState(false);

    const [logForm, setLogForm] = useState({
        lead_id: filters.lead_id || '',
        direction: 'outbound',
        status: 'completed',
        minutes: '',
        notes: '',
    });

    const settingsForm = useForm({
        provider: provider.name || 'twilio',
        caller_id: provider.caller_id || '',
        credentials: {},
    });

    const applyFilters = (event) => {
        event?.preventDefault();
        router.get(route('client.sales.calls.index'), {
            q: query || undefined,
            status: status || undefined,
            direction: direction || undefined,
            lead_id: filters.lead_id || undefined,
        }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const openSettings = () => {
        settingsForm.setData({
            provider: provider.name || 'twilio',
            caller_id: provider.caller_id || '',
            credentials: {},
        });
        settingsForm.clearErrors();
        setShowSettings(true);
    };

    const saveSettings = (event) => {
        event.preventDefault();
        settingsForm.post(route('client.sales.calls.settings.update'), {
            preserveScroll: true,
            onSuccess: () => setShowSettings(false),
        });
    };

    const placeCall = () => {
        if (!callLeadId) return;

        setCalling(true);
        router.post(route('client.sales.calls.initiate'), { lead_id: callLeadId }, {
            preserveScroll: true,
            onSuccess: () => {
                setShowCall(false);
                setCallLeadId('');
            },
            onFinish: () => setCalling(false),
        });
    };

    const submitLog = (event) => {
        event.preventDefault();

        router.post(route('client.sales.calls.store'), {
            lead_id: logForm.lead_id || null,
            direction: logForm.direction,
            status: logForm.status,
            duration_secs: Math.max(0, Math.round((Number(logForm.minutes) || 0) * 60)),
            notes: logForm.notes || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setShowLog(false);
                setLogForm({ lead_id: filters.lead_id || '', direction: 'outbound', status: 'completed', minutes: '', notes: '' });
            },
        });
    };

    const remove = () => {
        if (!deleting) return;

        router.delete(route('client.sales.calls.destroy', deleting.id), {
            preserveScroll: true,
            onSuccess: () => setDeleting(null),
        });
    };

    const statCards = [
        { label: t('calls.stats_total') || 'Total calls', value: stats.total },
        { label: t('calls.stats_completed') || 'Completed', value: stats.completed },
        { label: t('calls.stats_today') || 'Today', value: stats.today },
        { label: t('calls.stats_avg') || 'Average duration', value: durationLabel(stats.avg_duration) },
    ];

    const activeFields = providerFields[settingsForm.data.provider] || [];

    return (
        <ClientLayout>
            <Head title={t('calls.title') || 'Call Log'} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                {flash?.success && (
                    <div className="flex items-center gap-2 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <CheckCircle2 className="h-4 w-4 shrink-0" />
                        {flash.success}
                    </div>
                )}

                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-400">
                            <Phone className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-xl font-bold text-neutral-900 dark:text-neutral-100">
                                {t('calls.title') || 'Call Log'}
                            </h1>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                {t('calls.subtitle') || 'Every call logged against your leads, with click-to-call through your own provider.'}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={openSettings}
                            className={`inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition-colors ${
                                provider.connected
                                    ? 'border-soft text-neutral-600 hover:bg-neutral-50 dark:text-neutral-300 dark:hover:bg-neutral-800'
                                    : 'border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300'
                            }`}
                        >
                            <Settings className="h-4 w-4" />
                            {provider.connected
                                ? (t('calls.provider_connected', { provider: provider.name }) || `${provider.name} connected`)
                                : (t('calls.connect') || 'Connect calls')}
                        </button>

                        <Button variant="secondary" onClick={() => setShowLog(true)} className="inline-flex items-center gap-2">
                            <Plus className="h-4 w-4" />
                            {t('calls.log_call') || 'Log call'}
                        </Button>

                        <Button onClick={() => setShowCall(true)} className="inline-flex items-center gap-2">
                            <PhoneCall className="h-4 w-4" />
                            {t('calls.call_lead') || 'Call lead'}
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {statCards.map(({ label, value }) => (
                        <div key={label} className="rounded-2xl border border-soft bg-white p-4 dark:bg-neutral-900">
                            <div className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{label}</div>
                            <div className="mt-1 text-2xl font-bold tabular-nums text-neutral-900 dark:text-neutral-100">{value}</div>
                        </div>
                    ))}
                </div>

                <form onSubmit={applyFilters} className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('calls.search_placeholder') || 'Search a lead or number…'}
                            className="w-full rounded-lg border-soft bg-white py-2 pe-3 ps-9 text-sm text-neutral-900 dark:bg-neutral-900 dark:text-neutral-100"
                        />
                    </div>

                    <select
                        value={status}
                        onChange={(event) => setStatus(event.target.value)}
                        className="rounded-lg border-soft bg-white px-3 py-2 text-sm dark:bg-neutral-900 dark:text-neutral-100"
                    >
                        <option value="">{t('calls.all_statuses') || 'All statuses'}</option>
                        {['completed', 'answered', 'initiated', 'ringing', 'failed', 'no_answer'].map((value) => (
                            <option key={value} value={value}>{value.replace('_', ' ')}</option>
                        ))}
                    </select>

                    <select
                        value={direction}
                        onChange={(event) => setDirection(event.target.value)}
                        className="rounded-lg border-soft bg-white px-3 py-2 text-sm dark:bg-neutral-900 dark:text-neutral-100"
                    >
                        <option value="">{t('calls.all_directions') || 'All directions'}</option>
                        <option value="outbound">{t('calls.outbound') || 'Outbound'}</option>
                        <option value="inbound">{t('calls.inbound') || 'Inbound'}</option>
                    </select>

                    <Button type="submit" variant="secondary">{t('common.filter') || 'Filter'}</Button>
                </form>

                <div className="overflow-hidden rounded-2xl border border-soft bg-white shadow-xs dark:bg-neutral-900">
                    {calls.data.length === 0 ? (
                        <div className="py-16 text-center">
                            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-neutral-100 text-neutral-400 dark:bg-neutral-800">
                                <PhoneCall className="h-7 w-7" />
                            </div>
                            <h3 className="mt-4 text-base font-bold text-neutral-900 dark:text-neutral-100">
                                {t('calls.empty_title') || 'No calls yet'}
                            </h3>
                            <p className="mx-auto mt-1 max-w-sm text-sm text-neutral-500 dark:text-neutral-400">
                                {t('calls.empty_subtitle') || 'Log a call manually, or connect your provider and call a lead in one click.'}
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-start text-sm">
                                <thead>
                                    <tr className="border-b border-soft bg-neutral-50/75 text-xs font-medium text-neutral-500 dark:bg-neutral-800/50 dark:text-neutral-400">
                                        <th className="px-5 py-3.5 text-start">{t('calls.th_when') || 'When'}</th>
                                        <th className="px-4 py-3.5 text-start">{t('calls.th_lead') || 'Lead'}</th>
                                        <th className="px-4 py-3.5 text-start">{t('calls.th_direction') || 'Direction'}</th>
                                        <th className="px-4 py-3.5 text-start">{t('calls.th_number') || 'Number'}</th>
                                        <th className="px-4 py-3.5 text-center">{t('calls.th_duration') || 'Duration'}</th>
                                        <th className="px-4 py-3.5 text-start">{t('common.status') || 'Status'}</th>
                                        <th className="px-4 py-3.5 text-center">{t('calls.th_recording') || 'Recording'}</th>
                                        <th className="px-5 py-3.5 text-end">{t('common.actions') || 'Actions'}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-soft">
                                    {calls.data.map((call) => (
                                        <tr key={call.id} className="transition-colors hover:bg-neutral-50/50 dark:hover:bg-neutral-800/40">
                                            <td className="whitespace-nowrap px-5 py-4 text-neutral-600 dark:text-neutral-300">
                                                {formatDate(call.created_at)}
                                            </td>
                                            <td className="px-4 py-4">
                                                {call.lead ? (
                                                    <div className="min-w-0">
                                                        <div className="truncate font-medium text-neutral-900 dark:text-neutral-100">{call.lead.name}</div>
                                                        <div className="truncate text-[11px] text-neutral-400">{call.lead.phone}</div>
                                                    </div>
                                                ) : (
                                                    <span className="text-neutral-400">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-4">
                                                <span className="inline-flex items-center gap-1.5 text-neutral-600 dark:text-neutral-300">
                                                    {call.direction === 'inbound'
                                                        ? <PhoneIncoming className="h-3.5 w-3.5 text-sky-500" />
                                                        : <PhoneOutgoing className="h-3.5 w-3.5 text-emerald-500" />}
                                                    {call.direction === 'inbound'
                                                        ? (t('calls.inbound') || 'Inbound')
                                                        : (t('calls.outbound') || 'Outbound')}
                                                </span>
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-4 text-neutral-600 dark:text-neutral-300">
                                                {call.direction === 'inbound' ? call.from_number : call.to_number || '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-4 text-center tabular-nums text-neutral-600 dark:text-neutral-300">
                                                {durationLabel(call.duration_secs)}
                                            </td>
                                            <td className="px-4 py-4">
                                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold capitalize ${statusStyles[call.status] || statusStyles.no_answer}`}>
                                                    {call.status.replace('_', ' ')}
                                                </span>
                                            </td>
                                            <td className="px-4 py-4 text-center">
                                                {call.recording_url ? (
                                                    <a
                                                        href={call.recording_url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                                    >
                                                        <Headphones className="h-3.5 w-3.5" />
                                                        {t('calls.play') || 'Play'}
                                                    </a>
                                                ) : (
                                                    <span className="text-neutral-300 dark:text-neutral-600">—</span>
                                                )}
                                            </td>
                                            <td className="px-5 py-4">
                                                <div className="flex items-center justify-end gap-1">
                                                    {call.lead && call.lead.phone && (
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setCallLeadId(call.lead.id);
                                                                setShowCall(true);
                                                            }}
                                                            className="rounded-lg p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-brand-600 dark:hover:bg-neutral-800"
                                                            title={t('calls.call_lead') || 'Call lead'}
                                                        >
                                                            <PhoneCall className="h-4 w-4" />
                                                        </button>
                                                    )}
                                                    <button
                                                        type="button"
                                                        onClick={() => setDeleting(call)}
                                                        className="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                                        title={t('common.delete') || 'Delete'}
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {calls.last_page > 1 && (
                        <div className="flex gap-1 border-t border-soft px-4 py-3">
                            {calls.links.map((link, index) => (
                                <button
                                    key={index}
                                    type="button"
                                    disabled={!link.url || link.active}
                                    onClick={() => link.url && router.visit(link.url, { preserveScroll: true, preserveState: true })}
                                    className={`rounded px-3 py-1.5 text-sm ${
                                        link.active
                                            ? 'bg-brand-600 text-white'
                                            : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'
                                    } ${!link.url ? 'cursor-not-allowed opacity-40' : ''}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* ── Log a call ─────────────────────────────────────────── */}
            <Modal show={showLog} onClose={() => setShowLog(false)} maxWidth="md">
                <form onSubmit={submitLog} className="space-y-4 p-6">
                    <div className="flex items-center justify-between border-b border-soft pb-4">
                        <h2 className="flex items-center gap-2 text-lg font-bold text-neutral-900 dark:text-neutral-100">
                            <Plus className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                            {t('calls.log_call') || 'Log call'}
                        </h2>
                        <button type="button" onClick={() => setShowLog(false)} className="text-neutral-400 hover:text-neutral-600">&times;</button>
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                            {t('calls.lead') || 'Lead'}
                        </label>
                        <select
                            value={logForm.lead_id}
                            onChange={(event) => setLogForm({ ...logForm, lead_id: event.target.value })}
                            className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                        >
                            <option value="">{t('calls.no_lead') || 'No lead'}</option>
                            {leads.map((lead) => (
                                <option key={lead.id} value={lead.id}>{lead.name} · {lead.phone}</option>
                            ))}
                        </select>
                        {errors.lead_id && <p className="mt-1 text-xs text-red-600">{errors.lead_id}</p>}
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                {t('calls.th_direction') || 'Direction'}
                            </label>
                            <select
                                value={logForm.direction}
                                onChange={(event) => setLogForm({ ...logForm, direction: event.target.value })}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            >
                                <option value="outbound">{t('calls.outbound') || 'Outbound'}</option>
                                <option value="inbound">{t('calls.inbound') || 'Inbound'}</option>
                            </select>
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                {t('common.status') || 'Status'}
                            </label>
                            <select
                                value={logForm.status}
                                onChange={(event) => setLogForm({ ...logForm, status: event.target.value })}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            >
                                {['completed', 'answered', 'no_answer', 'failed'].map((value) => (
                                    <option key={value} value={value}>{value.replace('_', ' ')}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                            {t('calls.duration_minutes') || 'Duration (minutes)'}
                        </label>
                        <div className="relative mt-1">
                            <Clock className="pointer-events-none absolute start-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400" />
                            <input
                                type="number"
                                min="0"
                                step="0.5"
                                value={logForm.minutes}
                                onChange={(event) => setLogForm({ ...logForm, minutes: event.target.value })}
                                placeholder="0"
                                className="w-full rounded-lg border-soft bg-white p-2.5 ps-9 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                            {t('calls.notes') || 'Notes'}
                        </label>
                        <textarea
                            rows={3}
                            value={logForm.notes}
                            onChange={(event) => setLogForm({ ...logForm, notes: event.target.value })}
                            className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                        />
                    </div>

                    <div className="flex justify-end gap-2 border-t border-soft pt-4">
                        <Button type="button" variant="secondary" onClick={() => setShowLog(false)}>
                            {t('common.cancel') || 'Cancel'}
                        </Button>
                        <Button type="submit">{t('calls.save_call') || 'Save call'}</Button>
                    </div>
                </form>
            </Modal>

            {/* ── Call a lead ────────────────────────────────────────── */}
            <Modal show={showCall} onClose={() => setShowCall(false)} maxWidth="md">
                <div className="space-y-4 p-6">
                    <div className="flex items-center justify-between border-b border-soft pb-4">
                        <h2 className="flex items-center gap-2 text-lg font-bold text-neutral-900 dark:text-neutral-100">
                            <PhoneCall className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                            {t('calls.call_lead') || 'Call lead'}
                        </h2>
                        <button type="button" onClick={() => setShowCall(false)} className="text-neutral-400 hover:text-neutral-600">&times;</button>
                    </div>

                    {!provider.connected && (
                        <p className="rounded-lg bg-amber-50 p-3 text-xs font-medium text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">
                            {t('calls.provider_missing') || 'Connect a call provider in settings before placing calls. You can still log calls manually.'}
                        </p>
                    )}

                    <div>
                        <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                            {t('calls.lead') || 'Lead'}
                        </label>
                        <select
                            value={callLeadId}
                            onChange={(event) => setCallLeadId(event.target.value)}
                            className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                        >
                            <option value="">{t('calls.select_lead') || 'Select a lead…'}</option>
                            {leads.map((lead) => (
                                <option key={lead.id} value={lead.id}>{lead.name} · {lead.phone}</option>
                            ))}
                        </select>
                        {errors.lead_id && <p className="mt-1 text-xs text-red-600">{errors.lead_id}</p>}
                    </div>

                    <div className="flex justify-end gap-2 border-t border-soft pt-4">
                        <Button variant="secondary" onClick={() => setShowCall(false)}>
                            {t('common.cancel') || 'Cancel'}
                        </Button>
                        <Button onClick={placeCall} disabled={!callLeadId || !provider.connected || calling}>
                            {calling ? <Loader2 className="me-1.5 h-4 w-4 animate-spin" /> : <PhoneCall className="me-1.5 h-4 w-4" />}
                            {t('calls.start_call') || 'Start call'}
                        </Button>
                    </div>
                </div>
            </Modal>

            {/* ── Provider settings ─────────────────────────────────── */}
            <Modal show={showSettings} onClose={() => setShowSettings(false)} maxWidth="lg">
                <form onSubmit={saveSettings} className="space-y-4 p-6">
                    <div className="flex items-center justify-between border-b border-soft pb-4">
                        <h2 className="flex items-center gap-2 text-lg font-bold text-neutral-900 dark:text-neutral-100">
                            <Settings className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                            {t('calls.provider_settings') || 'Call provider'}
                        </h2>
                        <button type="button" onClick={() => setShowSettings(false)} className="text-neutral-400 hover:text-neutral-600">&times;</button>
                    </div>

                    <p className="rounded-lg bg-neutral-50 p-3 text-xs text-neutral-600 dark:bg-neutral-800/60 dark:text-neutral-300">
                        {t('calls.byo_hint') || 'Calls are billed by your provider. Credentials are encrypted and never shown again after saving.'}
                    </p>

                    <div>
                        <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                            {t('calls.provider') || 'Provider'}
                        </label>
                        <select
                            value={settingsForm.data.provider}
                            onChange={(event) => settingsForm.setData({ ...settingsForm.data, provider: event.target.value, credentials: {} })}
                            className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                        >
                            {providers.map((value) => (
                                <option key={value} value={value}>{value}</option>
                            ))}
                        </select>
                        {settingsForm.errors.provider && <p className="mt-1 text-xs text-red-600">{settingsForm.errors.provider}</p>}
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                            {t('calls.caller_id') || 'Caller ID (your number)'}
                        </label>
                        <input
                            type="text"
                            value={settingsForm.data.caller_id}
                            onChange={(event) => settingsForm.setData({ ...settingsForm.data, caller_id: event.target.value })}
                            placeholder="+15550001111"
                            className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                        />
                        {settingsForm.errors.caller_id && <p className="mt-1 text-xs text-red-600">{settingsForm.errors.caller_id}</p>}
                    </div>

                    {activeFields.map((field) => {
                        const errorKey = `credentials.${field.key}`;

                        return (
                            <div key={field.key}>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                    {field.label}{field.required ? ' *' : ''}
                                </label>
                                <input
                                    type={field.type === 'password' ? 'password' : 'text'}
                                    value={settingsForm.data.credentials[field.key] ?? ''}
                                    onChange={(event) => settingsForm.setData('credentials', {
                                        ...settingsForm.data.credentials,
                                        [field.key]: event.target.value,
                                    })}
                                    placeholder={field.type === 'password' ? '••••••••' : ''}
                                    autoComplete="off"
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                />
                                {field.hint && <p className="mt-1 text-[11px] text-neutral-400">{field.hint}</p>}
                                {settingsForm.errors[errorKey] && <p className="mt-1 text-xs text-red-600">{settingsForm.errors[errorKey]}</p>}
                            </div>
                        );
                    })}

                    <div className="flex justify-end gap-2 border-t border-soft pt-4">
                        <Button type="button" variant="secondary" onClick={() => setShowSettings(false)}>
                            {t('common.cancel') || 'Cancel'}
                        </Button>
                        <Button type="submit" disabled={settingsForm.processing}>
                            {settingsForm.processing ? (t('common.saving') || 'Saving…') : (t('common.save') || 'Save')}
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* ── Delete ────────────────────────────────────────────── */}
            <Modal show={Boolean(deleting)} onClose={() => setDeleting(null)} maxWidth="sm">
                <div className="space-y-4 p-6">
                    <h2 className="text-lg font-bold text-neutral-900 dark:text-neutral-100">
                        {t('calls.delete_title') || 'Delete call log?'}
                    </h2>
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">
                        {t('calls.delete_body') || 'The call, its notes and recording link will be removed from this lead. This cannot be undone.'}
                    </p>
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDeleting(null)}>
                            {t('common.cancel') || 'Cancel'}
                        </Button>
                        <Button variant="danger" onClick={remove}>
                            {t('common.delete') || 'Delete'}
                        </Button>
                    </div>
                </div>
            </Modal>
        </ClientLayout>
    );
}
