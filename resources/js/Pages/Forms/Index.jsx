import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import Modal from '@/Components/ui/Modal';
import {
    FileText, Plus, Eye, BarChart2, Code, ExternalLink,
    Trash2, Copy, Check, Sliders, Globe, Layers, ArrowUpRight
} from 'lucide-react';

export default function FormsIndex({
    forms = { data: [], links: [], total: 0 },
    pipelines = [],
    teamMembers = [],
    usage = { current: 0, limit: null, unlimited: true },
}) {
    const { t } = useTranslation();
    const { flash } = usePage().props;

    const [showCreateModal, setShowCreateModal] = useState(false);
    const [selectedEmbedForm, setSelectedEmbedForm] = useState(null);
    const [deletingForm, setDeletingForm] = useState(null);
    const [copied, setCopied] = useState(false);

    // Create form state
    const [formName, setFormName] = useState('');
    const [pipelineId, setPipelineId] = useState(pipelines[0]?.id ? String(pipelines[0].id) : '');
    const [targetStageId, setTargetStageId] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const selectedPipeline = pipelines.find((p) => String(p.id) === String(pipelineId)) || pipelines[0];
    const availableStages = selectedPipeline?.stages || [];

    const handleCreate = (e) => {
        e.preventDefault();
        if (!formName.trim()) return;

        setSubmitting(true);
        router.post(route('client.sales.forms.store'), {
            name: formName.trim(),
            pipeline_id: pipelineId ? Number(pipelineId) : null,
            target_stage_id: targetStageId ? Number(targetStageId) : null,
        }, {
            onSuccess: () => setShowCreateModal(false),
            onFinish: () => setSubmitting(false),
        });
    };

    const handleDelete = () => {
        if (!deletingForm) return;
        setSubmitting(true);
        router.delete(route('client.sales.forms.destroy', deletingForm.id), {
            onSuccess: () => setDeletingForm(null),
            onFinish: () => setSubmitting(false),
        });
    };

    const copyToClipboard = (text) => {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <ClientLayout>
            <Head title={t('forms.title') || 'Lead Capture Forms'} />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-400">
                                <FileText className="h-5 w-5" />
                            </div>
                            <div>
                                <h1 className="text-xl font-bold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                                    {t('forms.title') || 'Lead Capture Forms'}
                                    {!usage.unlimited && (
                                        <span className="text-xs font-normal text-neutral-500">
                                            ({usage.current} / {usage.limit} {t('common.used') || 'used'})
                                        </span>
                                    )}
                                </h1>
                                <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                    {t('forms.subtitle') || 'Create high-converting lead capture forms, embed them anywhere, and auto-route submissions into your pipeline.'}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button onClick={() => setShowCreateModal(true)} className="inline-flex items-center gap-2">
                            <Plus className="h-4 w-4" />
                            {t('forms.new_form') || 'New Form'}
                        </Button>
                    </div>
                </div>

                {/* Forms Table */}
                <div className="overflow-hidden rounded-2xl border border-soft bg-white shadow-xs dark:bg-neutral-900">
                    {forms.data.length === 0 ? (
                        <div className="py-16 text-center">
                            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-neutral-100 text-neutral-400 dark:bg-neutral-800">
                                <FileText className="h-7 w-7" />
                            </div>
                            <h3 className="mt-4 text-base font-bold text-neutral-900 dark:text-neutral-100">
                                {t('forms.empty_title') || 'No forms created yet'}
                            </h3>
                            <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400 max-w-sm mx-auto">
                                {t('forms.empty_subtitle') || 'Build your first web form to capture inbound leads from your website, campaigns, or social landing pages.'}
                            </p>
                            <div className="mt-6">
                                <Button onClick={() => setShowCreateModal(true)} size="sm">
                                    <Plus className="h-4 w-4 mr-1.5" />
                                    {t('forms.create_first_form') || 'Create Your First Form'}
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-start text-sm">
                                <thead>
                                    <tr className="border-b border-soft bg-neutral-50/75 dark:bg-neutral-800/50 text-xs font-medium text-neutral-500 dark:text-neutral-400">
                                        <th className="px-5 py-3.5 text-start">{t('forms.th_form_name') || 'Form Name'}</th>
                                        <th className="px-4 py-3.5 text-start">{t('forms.th_destination') || 'Pipeline Destination'}</th>
                                        <th className="px-4 py-3.5 text-center">{t('forms.th_views') || 'Views'}</th>
                                        <th className="px-4 py-3.5 text-center">{t('forms.th_submissions') || 'Submissions'}</th>
                                        <th className="px-4 py-3.5 text-center">{t('forms.th_conversion') || 'Conversion'}</th>
                                        <th className="px-4 py-3.5 text-start">{t('common.status') || 'Status'}</th>
                                        <th className="px-5 py-3.5 text-end">{t('common.actions') || 'Actions'}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-soft">
                                    {forms.data.map((form) => (
                                        <tr key={form.id} className="transition-colors hover:bg-neutral-50/50 dark:hover:bg-neutral-800/40">
                                            <td className="px-5 py-4">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-400 font-bold text-xs">
                                                        <FileText className="h-4 w-4" />
                                                    </div>
                                                    <div className="min-w-0">
                                                        <Link
                                                            href={route('client.sales.forms.builder', form.id)}
                                                            className="font-semibold text-neutral-900 hover:text-brand-600 dark:text-neutral-100 dark:hover:text-brand-400 truncate block"
                                                        >
                                                            {form.name}
                                                        </Link>
                                                        <a
                                                            href={form.public_url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="inline-flex items-center gap-1 font-mono text-[11px] text-neutral-400 hover:text-brand-600 dark:hover:text-brand-400"
                                                        >
                                                            /f/{form.slug}
                                                            <ArrowUpRight className="h-2.5 w-2.5" />
                                                        </a>
                                                    </div>
                                                </div>
                                            </td>

                                            <td className="px-4 py-4 text-xs text-neutral-600 dark:text-neutral-300">
                                                <div className="flex items-center gap-1.5 font-medium">
                                                    <Layers className="h-3 w-3 text-neutral-400" />
                                                    {form.pipeline?.name || 'Default Pipeline'}
                                                </div>
                                                {form.stage?.name && (
                                                    <div className="text-[11px] text-neutral-400 ps-4">
                                                        &rarr; {form.stage.name}
                                                    </div>
                                                )}
                                            </td>

                                            <td className="px-4 py-4 text-center font-medium tabular-nums text-neutral-700 dark:text-neutral-300">
                                                {form.views_count.toLocaleString()}
                                            </td>

                                            <td className="px-4 py-4 text-center font-bold tabular-nums text-brand-600 dark:text-brand-400">
                                                {form.submissions_count.toLocaleString()}
                                            </td>

                                            <td className="px-4 py-4 text-center">
                                                <span className="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                    {form.conversion_rate}%
                                                </span>
                                            </td>

                                            <td className="px-4 py-4">
                                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold capitalize ${
                                                    form.status === 'published'
                                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                                        : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400'
                                                }`}>
                                                    {form.status}
                                                </span>
                                            </td>

                                            <td className="px-5 py-4 text-end">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Link
                                                        href={route('client.sales.forms.builder', form.id)}
                                                        className="rounded-lg p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-800 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                                        title={t('forms.builder') || 'Visual Builder'}
                                                    >
                                                        <Sliders className="h-4 w-4" />
                                                    </Link>
                                                    <Link
                                                        href={route('client.sales.forms.analytics', form.id)}
                                                        className="rounded-lg p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-800 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                                        title={t('forms.analytics') || 'Analytics & Submissions'}
                                                    >
                                                        <BarChart2 className="h-4 w-4" />
                                                    </Link>
                                                    <button
                                                        type="button"
                                                        onClick={() => setSelectedEmbedForm(form)}
                                                        className="rounded-lg p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-800 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                                        title={t('forms.embed_share') || 'Share & Embed'}
                                                    >
                                                        <Code className="h-4 w-4" />
                                                    </button>
                                                    <a
                                                        href={form.public_url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="rounded-lg p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-800 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                                        title={t('forms.view_public') || 'Open Form'}
                                                    >
                                                        <ExternalLink className="h-4 w-4" />
                                                    </a>
                                                    <button
                                                        type="button"
                                                        onClick={() => setDeletingForm(form)}
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
            </div>

            {/* Create Form Modal */}
            <Modal show={showCreateModal} onClose={() => setShowCreateModal(false)} maxWidth="md">
                <form onSubmit={handleCreate} className="p-6 space-y-4">
                    <div className="flex items-center justify-between border-b border-soft pb-4">
                        <h2 className="text-lg font-bold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                            <FileText className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                            {t('forms.create_new_form') || 'Create New Lead Form'}
                        </h2>
                        <button
                            type="button"
                            onClick={() => setShowCreateModal(false)}
                            className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200"
                        >
                            &times;
                        </button>
                    </div>

                    <div className="space-y-4 text-xs">
                        <div>
                            <label className="block font-semibold text-neutral-700 dark:text-neutral-300">
                                {t('forms.form_name') || 'Form Name'} <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                required
                                value={formName}
                                onChange={(e) => setFormName(e.target.value)}
                                placeholder="Contact Sales / Get a Quote"
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>

                        <div>
                            <label className="block font-semibold text-neutral-700 dark:text-neutral-300">
                                {t('forms.target_pipeline') || 'Target Pipeline'}
                            </label>
                            <select
                                value={pipelineId}
                                onChange={(e) => {
                                    setPipelineId(e.target.value);
                                    setTargetStageId('');
                                }}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                            >
                                {pipelines.map((p) => (
                                    <option key={p.id} value={p.id}>{p.name}</option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block font-semibold text-neutral-700 dark:text-neutral-300">
                                {t('forms.target_stage') || 'Target Stage (First Stage by Default)'}
                            </label>
                            <select
                                value={targetStageId}
                                onChange={(e) => setTargetStageId(e.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                            >
                                <option value="">{t('forms.default_first_stage') || 'First Stage in Pipeline'}</option>
                                {availableStages.map((s) => (
                                    <option key={s.id} value={s.id}>{s.name}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-soft pt-4">
                        <Button type="button" variant="secondary" onClick={() => setShowCreateModal(false)}>
                            {t('common.cancel') || 'Cancel'}
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? (t('common.saving') || 'Creating...') : (t('forms.continue_to_builder') || 'Continue to Builder')}
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Embed & Share Modal */}
            <Modal show={!!selectedEmbedForm} onClose={() => setSelectedEmbedForm(null)} maxWidth="lg">
                <div className="p-6 space-y-5">
                    <div className="flex items-center justify-between border-b border-soft pb-4">
                        <h3 className="text-base font-bold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                            <Code className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                            {t('forms.embed_title') || 'Share & Embed Form'}
                        </h3>
                        <button
                            type="button"
                            onClick={() => setSelectedEmbedForm(null)}
                            className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200"
                        >
                            &times;
                        </button>
                    </div>

                    {selectedEmbedForm && (
                        <div className="space-y-4 text-xs">
                            {/* Direct Link */}
                            <div>
                                <label className="block font-semibold text-neutral-700 dark:text-neutral-300 mb-1">
                                    {t('forms.public_url') || 'Shareable Public Link'}
                                </label>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="text"
                                        readOnly
                                        value={selectedEmbedForm.public_url}
                                        className="w-full rounded-lg border-soft bg-neutral-50 p-2.5 font-mono text-xs text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                    />
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => copyToClipboard(selectedEmbedForm.public_url)}
                                    >
                                        {copied ? <Check className="h-4 w-4 text-emerald-600" /> : <Copy className="h-4 w-4" />}
                                    </Button>
                                </div>
                            </div>

                            {/* Embed Code */}
                            <div>
                                <label className="block font-semibold text-neutral-700 dark:text-neutral-300 mb-1">
                                    {t('forms.iframe_embed') || 'HTML iFrame Embed Code (WordPress, Wix, Webflow, Custom Sites)'}
                                </label>
                                <div className="flex items-start gap-2">
                                    <textarea
                                        readOnly
                                        rows="3"
                                        value={selectedEmbedForm.embed_code}
                                        className="w-full rounded-lg border-soft bg-neutral-50 p-2.5 font-mono text-xs text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                    />
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => copyToClipboard(selectedEmbedForm.embed_code)}
                                    >
                                        {copied ? <Check className="h-4 w-4 text-emerald-600" /> : <Copy className="h-4 w-4" />}
                                    </Button>
                                </div>
                            </div>

                            {/* JS Script Embed */}
                            <div>
                                <label className="block font-semibold text-neutral-700 dark:text-neutral-300 mb-1">
                                    {t('forms.js_embed') || 'JavaScript Auto-inject Widget'}
                                </label>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="text"
                                        readOnly
                                        value={`<script src="${window.location.origin}/f/${selectedEmbedForm.slug}/embed.js"></script>`}
                                        className="w-full rounded-lg border-soft bg-neutral-50 p-2.5 font-mono text-xs text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                    />
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => copyToClipboard(`<script src="${window.location.origin}/f/${selectedEmbedForm.slug}/embed.js"></script>`)}
                                    >
                                        {copied ? <Check className="h-4 w-4 text-emerald-600" /> : <Copy className="h-4 w-4" />}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="flex justify-end border-t border-soft pt-4">
                        <Button variant="secondary" onClick={() => setSelectedEmbedForm(null)}>
                            {t('common.close') || 'Close'}
                        </Button>
                    </div>
                </div>
            </Modal>

            {/* Delete Modal */}
            <Modal show={!!deletingForm} onClose={() => setDeletingForm(null)} maxWidth="sm">
                <div className="p-6">
                    <div className="flex items-center gap-3 text-red-600 dark:text-red-400">
                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-red-100 dark:bg-red-950/50">
                            <Trash2 className="h-5 w-5" />
                        </div>
                        <h3 className="text-base font-bold text-neutral-900 dark:text-neutral-100">
                            {t('forms.delete_title') || 'Delete Form'}
                        </h3>
                    </div>
                    <p className="mt-3 text-sm text-neutral-600 dark:text-neutral-400">
                        {t('forms.delete_confirm', { name: deletingForm?.name }) || `Are you sure you want to delete ${deletingForm?.name}? Any existing submissions and captured leads will be kept.`}
                    </p>
                    <div className="mt-6 flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDeletingForm(null)}>
                            {t('common.cancel') || 'Cancel'}
                        </Button>
                        <Button variant="danger" onClick={handleDelete} disabled={submitting}>
                            {submitting ? (t('common.deleting') || 'Deleting...') : (t('common.delete') || 'Delete')}
                        </Button>
                    </div>
                </div>
            </Modal>
        </ClientLayout>
    );
}

