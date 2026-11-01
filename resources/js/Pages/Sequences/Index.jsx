import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import Modal from '@/Components/ui/Modal';
import {
    Mail, Plus, Sliders, Trash2, Play, Pause, Users, ListOrdered, CheckCircle2, XCircle,
} from 'lucide-react';

const statusStyles = {
    active: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
    draft: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
    paused: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
};

export default function SequencesIndex({
    sequences = { data: [], links: [], total: 0 },
    usage = { current: 0, limit: null, unlimited: true },
}) {
    const { t } = useTranslation();
    usePage();

    const [showCreate, setShowCreate] = useState(false);
    const [deleting, setDeleting] = useState(null);
    const [name, setName] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const create = (event) => {
        event.preventDefault();
        if (!name.trim()) {
            return;
        }

        setSubmitting(true);
        router.post(route('client.sales.sequences.store'), { name: name.trim() }, {
            onSuccess: () => {
                setShowCreate(false);
                setName('');
            },
            onFinish: () => setSubmitting(false),
        });
    };

    const setStatus = (sequence, status) => {
        router.post(route('client.sales.sequences.status', sequence.id), { status }, { preserveScroll: true });
    };

    const remove = () => {
        if (!deleting) {
            return;
        }

        router.delete(route('client.sales.sequences.destroy', deleting.id), {
            onSuccess: () => setDeleting(null),
        });
    };

    return (
        <ClientLayout>
            <Head title={t('sequences.title') || 'Sequences'} />

            <div className="mx-auto max-w-7xl px-4 py-6 space-y-6 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-400">
                            <Mail className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="flex items-center gap-2 text-xl font-bold text-neutral-900 dark:text-neutral-100">
                                {t('sequences.title') || 'Sequences'}
                                {!usage.unlimited && (
                                    <span className="text-xs font-normal text-neutral-500">
                                        ({usage.current} / {usage.limit} {t('common.used') || 'used'})
                                    </span>
                                )}
                            </h1>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                {t('sequences.subtitle') || 'Automated email and WhatsApp follow-up sequences that stop when a lead replies or converts.'}
                            </p>
                        </div>
                    </div>

                    <Button onClick={() => setShowCreate(true)} className="inline-flex items-center gap-2">
                        <Plus className="h-4 w-4" />
                        {t('sequences.new') || 'New Sequence'}
                    </Button>
                </div>

                <div className="overflow-hidden rounded-2xl border border-soft bg-white shadow-xs dark:bg-neutral-900">
                    {sequences.data.length === 0 ? (
                        <div className="py-16 text-center">
                            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-neutral-100 text-neutral-400 dark:bg-neutral-800">
                                <Mail className="h-7 w-7" />
                            </div>
                            <h3 className="mt-4 text-base font-bold text-neutral-900 dark:text-neutral-100">
                                {t('sequences.empty_title') || 'No sequences yet'}
                            </h3>
                            <p className="mx-auto mt-1 max-w-sm text-sm text-neutral-500 dark:text-neutral-400">
                                {t('sequences.empty_subtitle') || 'Build a multi-step follow-up once and let it run for every lead you enroll.'}
                            </p>
                            <div className="mt-6">
                                <Button onClick={() => setShowCreate(true)} size="sm">
                                    <Plus className="me-1.5 h-4 w-4" />
                                    {t('sequences.create_first') || 'Create your first sequence'}
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-start text-sm">
                                <thead>
                                    <tr className="border-b border-soft bg-neutral-50/75 text-xs font-medium text-neutral-500 dark:bg-neutral-800/50 dark:text-neutral-400">
                                        <th className="px-5 py-3.5 text-start">{t('sequences.th_name') || 'Sequence'}</th>
                                        <th className="px-4 py-3.5 text-center">{t('sequences.th_steps') || 'Steps'}</th>
                                        <th className="px-4 py-3.5 text-center">{t('sequences.th_active') || 'Active'}</th>
                                        <th className="px-4 py-3.5 text-center">{t('sequences.th_enrolled') || 'Enrolled'}</th>
                                        <th className="px-4 py-3.5 text-start">{t('common.status') || 'Status'}</th>
                                        <th className="px-5 py-3.5 text-end">{t('common.actions') || 'Actions'}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-soft">
                                    {sequences.data.map((sequence) => (
                                        <tr key={sequence.id} className="transition-colors hover:bg-neutral-50/50 dark:hover:bg-neutral-800/40">
                                            <td className="px-5 py-4">
                                                <Link
                                                    href={route('client.sales.sequences.builder', sequence.id)}
                                                    className="flex items-center gap-3 font-semibold text-neutral-900 hover:text-brand-600 dark:text-neutral-100 dark:hover:text-brand-400"
                                                >
                                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-400">
                                                        <ListOrdered className="h-4 w-4" />
                                                    </span>
                                                    {sequence.name}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-4 text-center tabular-nums text-neutral-700 dark:text-neutral-300">
                                                {sequence.steps_count}
                                            </td>
                                            <td className="px-4 py-4 text-center font-semibold tabular-nums text-brand-600 dark:text-brand-400">
                                                {sequence.active_enrollments_count}
                                            </td>
                                            <td className="px-4 py-4 text-center tabular-nums text-neutral-700 dark:text-neutral-300">
                                                {sequence.enrollments_count}
                                            </td>
                                            <td className="px-4 py-4">
                                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold capitalize ${statusStyles[sequence.status] || statusStyles.draft}`}>
                                                    {sequence.status}
                                                </span>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Link
                                                        href={route('client.sales.sequences.builder', sequence.id)}
                                                        className="rounded-lg p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-800 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                                        title={t('sequences.edit') || 'Edit sequence'}
                                                    >
                                                        <Sliders className="h-4 w-4" />
                                                    </Link>

                                                    {sequence.status === 'active' ? (
                                                        <button
                                                            type="button"
                                                            onClick={() => setStatus(sequence, 'paused')}
                                                            className="rounded-lg p-1.5 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/30"
                                                            title={t('sequences.pause') || 'Pause'}
                                                        >
                                                            <Pause className="h-4 w-4" />
                                                        </button>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            onClick={() => setStatus(sequence, 'active')}
                                                            className="rounded-lg p-1.5 text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/30"
                                                            title={t('sequences.activate') || 'Activate'}
                                                        >
                                                            <Play className="h-4 w-4" />
                                                        </button>
                                                    )}

                                                    <button
                                                        type="button"
                                                        onClick={() => setDeleting(sequence)}
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
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    {[
                        { icon: Users, label: t('sequences.hint_enroll') || 'Enroll leads manually or from an automation' },
                        { icon: CheckCircle2, label: t('sequences.hint_reply') || 'Replies and won deals stop the sequence' },
                        { icon: XCircle, label: t('sequences.hint_hours') || 'Sends respect your business hours' },
                    ].map(({ icon: Icon, label }) => (
                        <div key={label} className="flex items-center gap-3 rounded-xl border border-soft bg-white p-4 text-sm text-neutral-600 dark:bg-neutral-900 dark:text-neutral-300">
                            <Icon className="h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400" />
                            {label}
                        </div>
                    ))}
                </div>
            </div>

            <Modal show={showCreate} onClose={() => setShowCreate(false)} maxWidth="md">
                <form onSubmit={create} className="space-y-4 p-6">
                    <div className="flex items-center justify-between border-b border-soft pb-4">
                        <h2 className="flex items-center gap-2 text-lg font-bold text-neutral-900 dark:text-neutral-100">
                            <Mail className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                            {t('sequences.new') || 'New Sequence'}
                        </h2>
                        <button
                            type="button"
                            onClick={() => setShowCreate(false)}
                            className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200"
                        >
                            &times;
                        </button>
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                            {t('sequences.name') || 'Sequence name'} <span className="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            required
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder={t('sequences.name_placeholder') || 'e.g. New lead follow-up'}
                            className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                        />
                    </div>

                    <div className="flex justify-end gap-2 border-t border-soft pt-4">
                        <Button type="button" variant="secondary" onClick={() => setShowCreate(false)}>
                            {t('common.cancel') || 'Cancel'}
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? (t('common.saving') || 'Saving…') : (t('common.create') || 'Create')}
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal show={Boolean(deleting)} onClose={() => setDeleting(null)} maxWidth="sm">
                <div className="space-y-4 p-6">
                    <h2 className="text-lg font-bold text-neutral-900 dark:text-neutral-100">
                        {t('sequences.delete_title') || 'Delete sequence?'}
                    </h2>
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">
                        {t('sequences.delete_body') || 'All steps and enrollment history for this sequence will be removed. This cannot be undone.'}
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
