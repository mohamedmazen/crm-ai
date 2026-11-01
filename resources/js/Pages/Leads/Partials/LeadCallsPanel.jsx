import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/ui/Button';
import { Headphones, PhoneIncoming, PhoneOutgoing, Plus } from 'lucide-react';

function durationLabel(seconds) {
    const value = Math.max(0, Number(seconds) || 0);
    if (!value) return '—';
    const minutes = Math.floor(value / 60);
    const rest = value % 60;
    return minutes > 0 ? `${minutes}m ${String(rest).padStart(2, '0')}s` : `${rest}s`;
}

/**
 * Call history for one lead, plus a quick "log a call" form.
 *
 * Calls are logged through the Sales call-log endpoints; the log itself lives in
 * the Call Log page, this is the lead-scoped view of the same rows.
 */
export default function LeadCallsPanel({ lead, calls = [] }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState({
        direction: 'outbound',
        status: 'completed',
        minutes: '',
        notes: '',
    });

    const submit = (event) => {
        event.preventDefault();
        setSaving(true);

        router.post(route('client.sales.calls.store'), {
            lead_id: lead.id,
            direction: form.direction,
            status: form.status,
            duration_secs: Math.max(0, Math.round((Number(form.minutes) || 0) * 60)),
            notes: form.notes || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setForm({ direction: 'outbound', status: 'completed', minutes: '', notes: '' });
                router.reload({ only: ['leadDetail'] });
            },
            onFinish: () => setSaving(false),
        });
    };

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">
                    {t('calls.title')}
                </h3>
                <Button size="sm" variant="secondary" onClick={() => setOpen((value) => !value)} className="inline-flex items-center gap-1.5">
                    <Plus className="h-3.5 w-3.5" />
                    {t('calls.log_call')}
                </Button>
            </div>

            {open && (
                <form onSubmit={submit} className="space-y-3 rounded-xl border border-neutral-100 bg-neutral-50/50 p-4 dark:border-neutral-800 dark:bg-neutral-800/20">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">
                                {t('calls.th_direction')}
                            </label>
                            <select
                                value={form.direction}
                                onChange={(event) => setForm({ ...form, direction: event.target.value })}
                                className="mt-1 block w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            >
                                <option value="outbound">{t('calls.outbound')}</option>
                                <option value="inbound">{t('calls.inbound')}</option>
                            </select>
                        </div>

                        <div>
                            <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">
                                {t('common.status')}
                            </label>
                            <select
                                value={form.status}
                                onChange={(event) => setForm({ ...form, status: event.target.value })}
                                className="mt-1 block w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            >
                                {['completed', 'answered', 'no_answer', 'failed'].map((value) => (
                                    <option key={value} value={value}>{value.replace('_', ' ')}</option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">
                                {t('calls.duration_minutes')}
                            </label>
                            <input
                                type="number"
                                min="0"
                                step="0.5"
                                value={form.minutes}
                                onChange={(event) => setForm({ ...form, minutes: event.target.value })}
                                placeholder="0"
                                className="mt-1 block w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">
                            {t('calls.notes')}
                        </label>
                        <textarea
                            rows={2}
                            value={form.notes}
                            onChange={(event) => setForm({ ...form, notes: event.target.value })}
                            className="mt-1 block w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                        />
                    </div>

                    <div className="flex justify-end gap-2">
                        <Button type="button" size="sm" variant="secondary" onClick={() => setOpen(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" size="sm" disabled={saving}>
                            {saving ? t('common.saving') : t('calls.save_call')}
                        </Button>
                    </div>
                </form>
            )}

            {calls.length === 0 ? (
                <p className="rounded-xl border border-dashed border-neutral-200 px-4 py-6 text-center text-xs text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                    {t('calls.empty_title')}
                </p>
            ) : (
                <ul className="divide-y divide-neutral-100 overflow-hidden rounded-xl border border-neutral-100 dark:divide-neutral-800 dark:border-neutral-800">
                    {calls.map((call) => (
                        <li key={call.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm">
                            <span className="inline-flex items-center gap-1.5 font-medium text-neutral-700 dark:text-neutral-200">
                                {call.direction === 'inbound'
                                    ? <PhoneIncoming className="h-3.5 w-3.5 text-sky-500" />
                                    : <PhoneOutgoing className="h-3.5 w-3.5 text-emerald-500" />}
                                {call.direction === 'inbound' ? call.from_number : call.to_number || '—'}
                            </span>
                            <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] font-medium capitalize text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                {call.status.replace('_', ' ')}
                            </span>
                            <span className="text-xs tabular-nums text-neutral-500 dark:text-neutral-400">
                                {durationLabel(call.duration_secs)}
                            </span>
                            {call.recording_url && (
                                <a
                                    href={call.recording_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                >
                                    <Headphones className="h-3.5 w-3.5" />
                                    {t('calls.play')}
                                </a>
                            )}
                            <span className="ms-auto text-[11px] text-neutral-400">
                                {call.created_at ? new Date(call.created_at).toLocaleString() : '—'}
                                {call.user ? ` · ${call.user.name}` : ''}
                            </span>
                            {call.notes && (
                                <p className="w-full text-xs text-neutral-500 dark:text-neutral-400">{call.notes}</p>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
