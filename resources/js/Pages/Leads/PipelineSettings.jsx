import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import { Plus, Trash2, ArrowLeft, Check, Trophy, XCircle, KanbanSquare } from 'lucide-react';
import useFeature from '@/hooks/useFeature';

export default function PipelineSettings({ pipelines, closeReasons }) {
    const { t } = useTranslation();
    const { flash } = usePage().props;
    const canMultiPipeline = useFeature('multi_pipeline');

    const [newPipelineName, setNewPipelineName] = useState('');
    const [isDefaultPipeline, setIsDefaultPipeline] = useState(false);
    const [submittingPipeline, setSubmittingPipeline] = useState(false);

    const [newReasonType, setNewReasonType] = useState('won');
    const [newReasonName, setNewReasonName] = useState('');
    const [submittingReason, setSubmittingReason] = useState(false);

    const handleCreatePipeline = (e) => {
        e.preventDefault();
        if (!newPipelineName.trim()) return;

        setSubmittingPipeline(true);
        router.post(
            route('client.leads.pipelines.store'),
            { name: newPipelineName, is_default: isDefaultPipeline },
            {
                onSuccess: () => {
                    setNewPipelineName('');
                    setIsDefaultPipeline(false);
                },
                onFinish: () => setSubmittingPipeline(false),
            }
        );
    };

    const handleDeletePipeline = (pipeline) => {
        if (!confirm(t('leads.confirm_delete_pipeline') || `Are you sure you want to delete pipeline "${pipeline.name}"?`)) {
            return;
        }

        router.delete(route('client.leads.pipelines.destroy', pipeline.id));
    };

    const handleCreateReason = (e) => {
        e.preventDefault();
        if (!newReasonName.trim()) return;

        setSubmittingReason(true);
        router.post(
            route('client.leads.pipelines.reasons.store'),
            { type: newReasonType, name: newReasonName },
            {
                onSuccess: () => setNewReasonName(''),
                onFinish: () => setSubmittingReason(false),
            }
        );
    };

    const handleDeleteReason = (reason) => {
        router.delete(route('client.leads.pipelines.reasons.destroy', reason.id));
    };

    return (
        <ClientLayout title={t('leads.pipeline_settings_title') || 'Pipeline Settings'}>
            <Head title={t('leads.pipeline_settings_title') || 'Pipeline Settings'} />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Button
                            variant="secondary"
                            onClick={() => router.visit(route('client.leads.pipeline.index'))}
                            className="p-2"
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                        <div>
                            <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                                {t('leads.pipeline_settings_title') || 'Pipelines & Outcome Reasons'}
                            </h1>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                {t('leads.pipeline_settings_subtitle') || 'Configure multiple sales pipelines and win/loss reason codes.'}
                            </p>
                        </div>
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-soft border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">
                        {flash.success}
                    </div>
                )}

                {/* Pipelines Management */}
                <div className="rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex items-center justify-between pb-4 border-b border-neutral-100 dark:border-neutral-800">
                        <div className="flex items-center gap-2">
                            <KanbanSquare className="h-5 w-5 text-brand-600" />
                            <h2 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">
                                {t('leads.pipelines') || 'Sales Pipelines'}
                            </h2>
                        </div>
                        {!canMultiPipeline && (
                            <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                {t('plans.pro_feature') || 'Pro Feature'}
                            </span>
                        )}
                    </div>

                    <div className="mt-4 divide-y divide-neutral-100 dark:divide-neutral-800">
                        {pipelines.map((pipeline) => (
                            <div key={pipeline.id} className="flex items-center justify-between py-3.5">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <h3 className="font-medium text-neutral-900 dark:text-neutral-100">
                                            {pipeline.name}
                                        </h3>
                                        {pipeline.is_default && (
                                            <span className="inline-flex items-center gap-1 rounded bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">
                                                <Check className="h-3 w-3" />
                                                {t('common.default') || 'Default'}
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">
                                        {pipeline.stages_count} {t('leads.stages') || 'stages'} • {pipeline.leads_count} {t('leads.leads_count') || 'leads'}
                                    </p>
                                </div>

                                <div className="flex items-center gap-2">
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        onClick={() => router.visit(route('client.leads.pipeline.index', { pipeline_id: pipeline.id }))}
                                    >
                                        {t('leads.view_board') || 'View Board'}
                                    </Button>

                                    {pipelines.length > 1 && (
                                        <button
                                            type="button"
                                            onClick={() => handleDeletePipeline(pipeline)}
                                            className="rounded p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-red-600 dark:hover:bg-neutral-800"
                                            title={t('common.delete') || 'Delete'}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>

                    <form onSubmit={handleCreatePipeline} className="mt-6 flex flex-wrap items-center gap-3 pt-4 border-t border-neutral-100 dark:border-neutral-800">
                        <input
                            type="text"
                            value={newPipelineName}
                            onChange={(e) => setNewPipelineName(e.target.value)}
                            placeholder={t('leads.new_pipeline_placeholder') || 'New pipeline name (e.g. Enterprise Sales)'}
                            className="flex-1 min-w-[200px] rounded-soft border-soft bg-white px-3 py-2 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                        />
                        <label className="flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-300 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={isDefaultPipeline}
                                onChange={(e) => setIsDefaultPipeline(e.target.checked)}
                                className="rounded border-neutral-300 text-brand-600 focus:ring-brand-500"
                            />
                            {t('leads.set_as_default') || 'Set as default'}
                        </label>
                        <Button type="submit" disabled={submittingPipeline || !newPipelineName.trim()}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            {t('leads.create_pipeline') || 'Create Pipeline'}
                        </Button>
                    </form>
                </div>

                {/* Close Reasons Management */}
                <div className="rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="pb-4 border-b border-neutral-100 dark:border-neutral-800">
                        <h2 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">
                            {t('leads.close_reasons_title') || 'Win / Loss Reasons'}
                        </h2>
                        <p className="text-xs text-neutral-500 dark:text-neutral-400 mt-1">
                            {t('leads.close_reasons_subtitle') || 'Categorize why deals are won or lost to analyze sales bottlenecks and objection patterns.'}
                        </p>
                    </div>

                    <div className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-6">
                        {/* Won Reasons */}
                        <div>
                            <div className="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400 font-medium text-sm mb-3">
                                <Trophy className="h-4 w-4" />
                                <span>{t('leads.won_reasons') || 'Won Reasons'}</span>
                            </div>

                            <ul className="space-y-1.5 divide-y divide-neutral-50 dark:divide-neutral-800/50">
                                {closeReasons.filter((r) => r.type === 'won').map((reason) => (
                                    <li key={reason.id} className="flex items-center justify-between py-1.5 text-sm">
                                        <span className="text-neutral-700 dark:text-neutral-300">{reason.name}</span>
                                        <button
                                            type="button"
                                            onClick={() => handleDeleteReason(reason)}
                                            className="text-neutral-300 hover:text-red-500 p-1"
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>

                        {/* Lost Reasons */}
                        <div>
                            <div className="flex items-center gap-1.5 text-coral-600 dark:text-coral-400 font-medium text-sm mb-3">
                                <XCircle className="h-4 w-4" />
                                <span>{t('leads.lost_reasons') || 'Lost Reasons'}</span>
                            </div>

                            <ul className="space-y-1.5 divide-y divide-neutral-50 dark:divide-neutral-800/50">
                                {closeReasons.filter((r) => r.type === 'lost').map((reason) => (
                                    <li key={reason.id} className="flex items-center justify-between py-1.5 text-sm">
                                        <span className="text-neutral-700 dark:text-neutral-300">{reason.name}</span>
                                        <button
                                            type="button"
                                            onClick={() => handleDeleteReason(reason)}
                                            className="text-neutral-300 hover:text-red-500 p-1"
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>

                    <form onSubmit={handleCreateReason} className="mt-6 flex flex-wrap items-center gap-3 pt-4 border-t border-neutral-100 dark:border-neutral-800">
                        <select
                            value={newReasonType}
                            onChange={(e) => setNewReasonType(e.target.value)}
                            className="rounded-soft border-soft bg-white px-3 py-2 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                        >
                            <option value="won">{t('leads.won') || 'Won Reason'}</option>
                            <option value="lost">{t('leads.lost') || 'Lost Reason'}</option>
                        </select>
                        <input
                            type="text"
                            value={newReasonName}
                            onChange={(e) => setNewReasonName(e.target.value)}
                            placeholder={t('leads.new_reason_placeholder') || 'Reason name (e.g. Budget constraint, Price match)'}
                            className="flex-1 min-w-[200px] rounded-soft border-soft bg-white px-3 py-2 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                        />
                        <Button type="submit" disabled={submittingReason || !newReasonName.trim()}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            {t('leads.add_reason') || 'Add Reason'}
                        </Button>
                    </form>
                </div>
            </div>
        </ClientLayout>
    );
}

