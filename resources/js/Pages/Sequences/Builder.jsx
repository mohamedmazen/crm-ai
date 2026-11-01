import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import Modal from '@/Components/ui/Modal';
import {
    ArrowLeft, Mail, MessageCircle, Plus, Trash2, ArrowUp, ArrowDown, Save,
    Users, Send, MessageSquareText, CheckCircle2, Pause, Play, Clock, ListOrdered,
} from 'lucide-react';

const statusStyles = {
    active: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
    draft: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
    paused: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
};

function Toggle({ label, hint, checked, onChange }) {
    return (
        <label className="flex cursor-pointer items-start justify-between gap-3 rounded-xl border border-soft p-3">
            <span>
                <span className="block text-sm font-semibold text-neutral-800 dark:text-neutral-100">{label}</span>
                <span className="mt-0.5 block text-xs text-neutral-500 dark:text-neutral-400">{hint}</span>
            </span>
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                className="mt-1 h-4 w-4 rounded border-neutral-300 text-brand-600 focus:ring-brand-500"
            />
        </label>
    );
}

export default function SequenceBuilder({
    sequence,
    enrollments = [],
    leads = [],
    stats = { sent: 0, replies: 0, active: 0, completed: 0, stopped: 0 },
}) {
    const { t } = useTranslation();

    const form = useForm({
        name: sequence.name,
        status: sequence.status,
        stop_on_reply: Boolean(sequence.stop_on_reply),
        stop_on_won: Boolean(sequence.stop_on_won),
        respect_hours: Boolean(sequence.respect_hours),
        steps: (sequence.steps || []).map((step) => ({
            id: step.id,
            channel: step.channel,
            delay_hours: step.delay_hours,
            subject: step.subject || '',
            body: step.body || '',
        })),
    });

    const [showEnroll, setShowEnroll] = useState(false);
    const [selectedLeads, setSelectedLeads] = useState([]);

    const updateStep = (index, key, value) => {
        const steps = form.data.steps.map((step, position) => (
            position === index ? { ...step, [key]: value } : step
        ));
        form.setData('steps', steps);
    };

    const addStep = () => {
        form.setData('steps', [
            ...form.data.steps,
            { id: null, channel: 'email', delay_hours: 24, subject: '', body: '' },
        ]);
    };

    const removeStep = (index) => {
        form.setData('steps', form.data.steps.filter((_, position) => position !== index));
    };

    const moveStep = (index, direction) => {
        const target = index + direction;
        if (target < 0 || target >= form.data.steps.length) {
            return;
        }

        const steps = [...form.data.steps];
        [steps[index], steps[target]] = [steps[target], steps[index]];
        form.setData('steps', steps);
    };

    const save = (event) => {
        event.preventDefault();
        form.put(route('client.sales.sequences.builder.update', sequence.id), { preserveScroll: true });
    };

    const setStatus = (status) => {
        router.post(route('client.sales.sequences.status', sequence.id), { status }, { preserveScroll: true });
    };

    const enroll = () => {
        if (selectedLeads.length === 0) {
            return;
        }

        router.post(route('client.sales.sequences.enroll', sequence.id), {
            lead_ids: selectedLeads,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setShowEnroll(false);
                setSelectedLeads([]);
            },
        });
    };

    const toggleLead = (id) => {
        setSelectedLeads((current) => (
            current.includes(id) ? current.filter((leadId) => leadId !== id) : [...current, id]
        ));
    };

    const statCards = [
        { icon: Send, label: t('sequences.stats_sent') || 'Sent', value: stats.sent },
        { icon: MessageSquareText, label: t('sequences.stats_replies') || 'Replies', value: stats.replies },
        { icon: Users, label: t('sequences.stats_active') || 'Active leads', value: stats.active },
        { icon: CheckCircle2, label: t('sequences.stats_completed') || 'Completed', value: stats.completed },
    ];

    return (
        <ClientLayout>
            <Head title={sequence.name} />

            <div className="mx-auto max-w-7xl px-4 py-6 space-y-6 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-start gap-3">
                        <Link
                            href={route('client.sales.sequences.index')}
                            className="mt-1 rounded-lg p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-800 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                        >
                            <ArrowLeft className="h-5 w-5" />
                        </Link>
                        <div>
                            <h1 className="flex items-center gap-2 text-xl font-bold text-neutral-900 dark:text-neutral-100">
                                <Mail className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                                {sequence.name}
                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold capitalize ${statusStyles[sequence.status] || statusStyles.draft}`}>
                                    {sequence.status}
                                </span>
                            </h1>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                {t('sequences.builder_subtitle') || 'Add steps, set the delay between them, then activate the sequence to start sending.'}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {sequence.status === 'active' ? (
                            <Button variant="secondary" onClick={() => setStatus('paused')}>
                                <Pause className="me-1.5 h-4 w-4" />
                                {t('sequences.pause') || 'Pause'}
                            </Button>
                        ) : (
                            <Button variant="secondary" onClick={() => setStatus('active')}>
                                <Play className="me-1.5 h-4 w-4" />
                                {t('sequences.activate') || 'Activate'}
                            </Button>
                        )}
                        <Button onClick={() => setShowEnroll(true)}>
                            <Users className="me-1.5 h-4 w-4" />
                            {t('sequences.enroll_leads') || 'Enroll leads'}
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {statCards.map(({ icon: Icon, label, value }) => (
                        <div key={label} className="rounded-2xl border border-soft bg-white p-4 dark:bg-neutral-900">
                            <div className="flex items-center justify-between text-xs font-medium text-neutral-500 dark:text-neutral-400">
                                {label}
                                <Icon className="h-4 w-4 text-brand-500" />
                            </div>
                            <div className="mt-1 text-2xl font-bold tabular-nums text-neutral-900 dark:text-neutral-100">{value}</div>
                        </div>
                    ))}
                </div>

                <form onSubmit={save} className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <section className="rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                            <h2 className="flex items-center gap-2 text-sm font-bold text-neutral-900 dark:text-neutral-100">
                                <ListOrdered className="h-4 w-4 text-brand-600 dark:text-brand-400" />
                                {t('sequences.steps') || 'Steps'}
                            </h2>

                            {form.data.steps.length === 0 && (
                                <p className="mt-3 rounded-xl border border-dashed border-soft p-4 text-sm text-neutral-500 dark:text-neutral-400">
                                    {t('sequences.no_steps') || 'Add at least one step before activating this sequence.'}
                                </p>
                            )}

                            <div className="mt-4 space-y-4">
                                {form.data.steps.map((step, index) => (
                                    <div key={step.id ?? `new-${index}`} className="rounded-xl border border-soft p-4">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="flex items-center gap-2 text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                                                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-brand-50 text-xs font-bold text-brand-600 dark:bg-brand-950/60 dark:text-brand-400">
                                                    {index + 1}
                                                </span>
                                                {step.channel === 'whatsapp'
                                                    ? (t('sequences.whatsapp') || 'WhatsApp')
                                                    : (t('sequences.email') || 'Email')}
                                            </div>
                                            <div className="flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    onClick={() => moveStep(index, -1)}
                                                    disabled={index === 0}
                                                    className="rounded-lg p-1.5 text-neutral-400 hover:bg-neutral-100 disabled:opacity-30 dark:hover:bg-neutral-800"
                                                >
                                                    <ArrowUp className="h-4 w-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => moveStep(index, 1)}
                                                    disabled={index === form.data.steps.length - 1}
                                                    className="rounded-lg p-1.5 text-neutral-400 hover:bg-neutral-100 disabled:opacity-30 dark:hover:bg-neutral-800"
                                                >
                                                    <ArrowDown className="h-4 w-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => removeStep(index)}
                                                    className="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </button>
                                            </div>
                                        </div>

                                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                                    {t('sequences.channel') || 'Channel'}
                                                </label>
                                                <select
                                                    value={step.channel}
                                                    onChange={(event) => updateStep(index, 'channel', event.target.value)}
                                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                                >
                                                    <option value="email">{t('sequences.email') || 'Email'}</option>
                                                    <option value="whatsapp">{t('sequences.whatsapp') || 'WhatsApp'}</option>
                                                </select>
                                            </div>

                                            <div>
                                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                                    {t('sequences.delay_hours') || 'Delay (hours after the previous step)'}
                                                </label>
                                                <div className="relative mt-1">
                                                    <Clock className="pointer-events-none absolute start-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400" />
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        max="8760"
                                                        value={step.delay_hours}
                                                        onChange={(event) => updateStep(index, 'delay_hours', Number(event.target.value))}
                                                        className="w-full rounded-lg border-soft bg-white p-2.5 ps-9 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                                    />
                                                </div>
                                            </div>
                                        </div>

                                        {step.channel === 'email' && (
                                            <div className="mt-3">
                                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                                    {t('sequences.subject') || 'Subject'}
                                                </label>
                                                <input
                                                    type="text"
                                                    value={step.subject}
                                                    onChange={(event) => updateStep(index, 'subject', event.target.value)}
                                                    placeholder={t('sequences.subject_placeholder') || 'Following up on your enquiry'}
                                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                                />
                                            </div>
                                        )}

                                        <div className="mt-3">
                                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                                {t('sequences.body') || 'Message'}
                                            </label>
                                            <textarea
                                                required
                                                rows={4}
                                                value={step.body}
                                                onChange={(event) => updateStep(index, 'body', event.target.value)}
                                                placeholder={t('sequences.body_placeholder') || 'Hi {{contact.first_name}}, …'}
                                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                            />
                                            <p className="mt-1 text-[11px] text-neutral-400">
                                                {t('sequences.tokens_hint') || 'Tokens: {{contact.first_name}}, {{contact.name}}, {{contact.email}}, {{contact.phone}}'}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <div className="mt-4 flex flex-wrap items-center gap-2">
                                <Button type="button" variant="secondary" size="sm" onClick={addStep}>
                                    <Plus className="me-1.5 h-4 w-4" />
                                    {t('sequences.add_step') || 'Add step'}
                                </Button>
                            </div>

                            {form.errors.steps && (
                                <p className="mt-3 text-xs font-medium text-red-600">{form.errors.steps}</p>
                            )}
                            {Object.entries(form.errors).filter(([key]) => key.startsWith('steps.')).map(([key, message]) => (
                                <p key={key} className="mt-1 text-xs font-medium text-red-600">{message}</p>
                            ))}
                        </section>
                    </div>

                    <div className="space-y-6">
                        <section className="space-y-4 rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                            <h2 className="text-sm font-bold text-neutral-900 dark:text-neutral-100">
                                {t('sequences.settings') || 'Settings'}
                            </h2>

                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                    {t('sequences.name') || 'Sequence name'}
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                />
                                {form.errors.name && <p className="mt-1 text-xs text-red-600">{form.errors.name}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                    {t('common.status') || 'Status'}
                                </label>
                                <select
                                    value={form.data.status}
                                    onChange={(event) => form.setData('status', event.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                >
                                    <option value="draft">{t('sequences.status_draft') || 'Draft'}</option>
                                    <option value="active">{t('sequences.status_active') || 'Active'}</option>
                                    <option value="paused">{t('sequences.status_paused') || 'Paused'}</option>
                                </select>
                                {form.errors.status && <p className="mt-1 text-xs text-red-600">{form.errors.status}</p>}
                            </div>

                            <Toggle
                                label={t('sequences.stop_on_reply') || 'Stop on reply'}
                                hint={t('sequences.stop_on_reply_hint') || 'Stop sending as soon as the lead replies.'}
                                checked={form.data.stop_on_reply}
                                onChange={(value) => form.setData('stop_on_reply', value)}
                            />
                            <Toggle
                                label={t('sequences.stop_on_won') || 'Stop when won or lost'}
                                hint={t('sequences.stop_on_won_hint') || 'Stop when the lead moves to a won or lost stage.'}
                                checked={form.data.stop_on_won}
                                onChange={(value) => form.setData('stop_on_won', value)}
                            />
                            <Toggle
                                label={t('sequences.respect_hours') || 'Respect business hours'}
                                hint={t('sequences.respect_hours_hint') || 'Only send during your workspace opening hours.'}
                                checked={form.data.respect_hours}
                                onChange={(value) => form.setData('respect_hours', value)}
                            />

                            <Button type="submit" disabled={form.processing} className="w-full">
                                <Save className="me-1.5 h-4 w-4" />
                                {form.processing ? (t('common.saving') || 'Saving…') : (t('common.save') || 'Save changes')}
                            </Button>
                        </section>

                        <section className="rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                            <h2 className="flex items-center gap-2 text-sm font-bold text-neutral-900 dark:text-neutral-100">
                                <MessageCircle className="h-4 w-4 text-brand-600 dark:text-brand-400" />
                                {t('sequences.recent_enrollments') || 'Enrolled leads'}
                            </h2>

                            {enrollments.length === 0 ? (
                                <p className="mt-3 text-sm text-neutral-500 dark:text-neutral-400">
                                    {t('sequences.no_enrollments') || 'No leads enrolled yet.'}
                                </p>
                            ) : (
                                <ul className="mt-3 divide-y divide-soft">
                                    {enrollments.slice(0, 8).map((enrollment) => (
                                        <li key={enrollment.id} className="flex items-center justify-between gap-2 py-2 text-sm">
                                            <span className="min-w-0">
                                                <span className="block truncate font-medium text-neutral-800 dark:text-neutral-100">
                                                    {enrollment.lead?.name || `#${enrollment.lead_id}`}
                                                </span>
                                                <span className="block truncate text-[11px] text-neutral-400">
                                                    {enrollment.lead?.email || ''}
                                                </span>
                                            </span>
                                            <span className="shrink-0 text-[11px] font-semibold capitalize text-neutral-500 dark:text-neutral-400">
                                                {enrollment.status} · {enrollment.current_step}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </div>
                </form>
            </div>

            <Modal show={showEnroll} onClose={() => setShowEnroll(false)} maxWidth="lg">
                <div className="space-y-4 p-6">
                    <h2 className="flex items-center gap-2 text-lg font-bold text-neutral-900 dark:text-neutral-100">
                        <Users className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                        {t('sequences.enroll_leads') || 'Enroll leads'}
                    </h2>

                    {sequence.status !== 'active' && (
                        <p className="rounded-lg bg-amber-50 p-3 text-xs font-medium text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">
                            {t('sequences.activate_first') || 'Activate the sequence before enrolling leads.'}
                        </p>
                    )}

                    {leads.length === 0 ? (
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">
                            {t('sequences.no_leads') || 'Every lead is already enrolled, or no leads exist yet.'}
                        </p>
                    ) : (
                        <div className="max-h-72 space-y-1 overflow-y-auto">
                            {leads.map((lead) => (
                                <label key={lead.id} className="flex cursor-pointer items-center gap-3 rounded-lg p-2 hover:bg-neutral-50 dark:hover:bg-neutral-800/60">
                                    <input
                                        type="checkbox"
                                        checked={selectedLeads.includes(lead.id)}
                                        onChange={() => toggleLead(lead.id)}
                                        className="h-4 w-4 rounded border-neutral-300 text-brand-600 focus:ring-brand-500"
                                    />
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm font-medium text-neutral-800 dark:text-neutral-100">{lead.name}</span>
                                        <span className="block truncate text-[11px] text-neutral-400">{lead.email || lead.phone || '—'}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    )}

                    <div className="flex justify-end gap-2 border-t border-soft pt-4">
                        <Button variant="secondary" onClick={() => setShowEnroll(false)}>
                            {t('common.cancel') || 'Cancel'}
                        </Button>
                        <Button onClick={enroll} disabled={selectedLeads.length === 0 || sequence.status !== 'active'}>
                            {selectedLeads.length > 0
                                ? `${t('sequences.enroll') || 'Enroll'} (${selectedLeads.length})`
                                : (t('sequences.enroll') || 'Enroll')}
                        </Button>
                    </div>
                </div>
            </Modal>
        </ClientLayout>
    );
}
