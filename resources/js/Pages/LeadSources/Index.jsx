import { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import {
    Radio, Plus, RefreshCw, Copy, Check, Trash2, Globe, MessageSquare,
    Webhook, Code, BarChart3, ShieldCheck, AlertCircle, ExternalLink,
    Filter, Layers, ArrowUpRight, FileText, Smartphone, Mail, Eye,
} from 'lucide-react';

export default function LeadSourcesIndex({
    sources = [],
    stats = {},
    utm_distribution = [],
    pipelines = [],
    limits = {},
    tracker_url = '',
}) {
    const { t } = useTranslation();
    const [copiedTracker, setCopiedTracker] = useState(false);
    const [copiedWebhookId, setCopiedWebhookId] = useState(null);
    const [isAddModalOpen, setIsAddModalOpen] = useState(false);
    const [editingSource, setEditingSource] = useState(null);
    const [snippetSource, setSnippetSource] = useState(null);

    const addForm = useForm({
        name: '',
        type: 'webhook',
        pipeline_id: pipelines[0]?.id || '',
        stage_id: '',
    });

    const editForm = useForm({
        name: '',
        status: 'active',
        pipeline_id: '',
        stage_id: '',
    });

    const copyTrackerCode = () => {
        const code = `<script src="${tracker_url}" defer></script>`;
        navigator.clipboard.writeText(code);
        setCopiedTracker(true);
        setTimeout(() => setCopiedTracker(false), 2000);
    };

    const copyWebhookUrl = (id, url) => {
        navigator.clipboard.writeText(url);
        setCopiedWebhookId(id);
        setTimeout(() => setCopiedWebhookId(null), 2000);
    };

    const handleCreateSource = (e) => {
        e.preventDefault();
        addForm.post(route('client.sales.lead-sources.store'), {
            onSuccess: () => {
                setIsAddModalOpen(false);
                addForm.reset();
            },
        });
    };

    const handleEditSource = (e) => {
        e.preventDefault();
        if (!editingSource) return;
        editForm.put(route('client.sales.lead-sources.update', editingSource.id), {
            onSuccess: () => {
                setEditingSource(null);
            },
        });
    };

    const handleRegenerateToken = (source) => {
        if (!confirm(t('lead_sources.confirm_regenerate', 'Are you sure you want to regenerate this webhook token?'))) {
            return;
        }
        router.post(route('client.sales.lead-sources.regenerate-token', source.id));
    };

    const handleDeleteSource = (source) => {
        if (!confirm(t('lead_sources.delete_confirm', 'Are you sure you want to delete this source?'))) {
            return;
        }
        router.delete(route('client.sales.lead-sources.destroy', source.id));
    };

    const getTypeIcon = (type) => {
        switch (type) {
            case 'form':
                return <FileText className="h-4 w-4 text-emerald-500" />;
            case 'whatsapp':
                return <MessageSquare className="h-4 w-4 text-green-500" />;
            case 'meta_ads':
                return <Smartphone className="h-4 w-4 text-blue-500" />;
            case 'imap':
                return <Mail className="h-4 w-4 text-amber-500" />;
            case 'api':
                return <Code className="h-4 w-4 text-purple-500" />;
            case 'webhook':
            default:
                return <Webhook className="h-4 w-4 text-indigo-500" />;
        }
    };

    const getStatusBadge = (status) => {
        if (status === 'active') {
            return (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 border border-emerald-200/60 dark:border-emerald-800/40">
                    <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />
                    {t('common.active', 'Active')}
                </span>
            );
        }
        if (status === 'error') {
            return (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400 border border-rose-200/60 dark:border-rose-800/40">
                    <AlertCircle className="h-3 w-3 text-rose-500" />
                    {t('lead_sources.status_error', 'Error')}
                </span>
            );
        }
        return (
            <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                {t('common.inactive', 'Inactive')}
            </span>
        );
    };

    return (
        <ClientLayout>
            <Head title={t('lead_sources.title', 'Lead Sources & Attribution')} />

            <div className="py-8 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-6">
                {/* Header */}
                <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="p-2 bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 rounded-xl">
                                <Radio className="h-6 w-6" />
                            </span>
                            <h1 className="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                                {t('lead_sources.title', 'Lead Sources & Attribution')}
                            </h1>
                        </div>
                        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            {t('lead_sources.subtitle', 'Connect and monitor all inbound lead channels (Webhooks, Forms, WhatsApp, Ads) with full marketing UTM tracking.')}
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            onClick={() => setIsAddModalOpen(true)}
                            className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl shadow-sm transition-colors"
                        >
                            <Plus className="h-4 w-4" />
                            {t('lead_sources.add_source', 'Add Lead Source')}
                        </button>
                    </div>
                </div>

                {/* KPI Stats */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-xs">
                        <div className="flex items-center justify-between text-zinc-500 dark:text-zinc-400 text-sm">
                            <span>{t('lead_sources.total_sources', 'Total Channels')}</span>
                            <Radio className="h-5 w-5 text-indigo-500" />
                        </div>
                        <div className="mt-2 flex items-baseline gap-2">
                            <span className="text-3xl font-bold text-zinc-900 dark:text-zinc-100">
                                {stats.total_sources || 0}
                            </span>
                            {limits.max !== null && (
                                <span className="text-xs text-zinc-400">/ {limits.max} limit</span>
                            )}
                        </div>
                    </div>

                    <div className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-xs">
                        <div className="flex items-center justify-between text-zinc-500 dark:text-zinc-400 text-sm">
                            <span>{t('lead_sources.active_sources', 'Active Channels')}</span>
                            <ShieldCheck className="h-5 w-5 text-emerald-500" />
                        </div>
                        <div className="mt-2 text-3xl font-bold text-emerald-600 dark:text-emerald-400">
                            {stats.active_sources || 0}
                        </div>
                    </div>

                    <div className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-xs">
                        <div className="flex items-center justify-between text-zinc-500 dark:text-zinc-400 text-sm">
                            <span>{t('lead_sources.total_leads', 'Total Leads Captured')}</span>
                            <BarChart3 className="h-5 w-5 text-purple-500" />
                        </div>
                        <div className="mt-2 text-3xl font-bold text-zinc-900 dark:text-zinc-100">
                            {stats.total_leads || 0}
                        </div>
                    </div>
                </div>

                {/* Website Tracker Banner */}
                <div className="bg-gradient-to-r from-indigo-500/10 via-purple-500/10 to-pink-500/10 border border-indigo-200/60 dark:border-indigo-800/40 rounded-2xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <Globe className="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                            <h3 className="font-semibold text-zinc-900 dark:text-zinc-100">
                                {t('lead_sources.tracker_title', 'Website Auto-Tracker Script (UTM Attribution)')}
                            </h3>
                        </div>
                        <p className="text-sm text-zinc-600 dark:text-zinc-400 max-w-2xl">
                            {t('lead_sources.tracker_subtitle', 'Place this single snippet before the closing </body> tag on your website or landing pages to persist UTM parameters for 30 days and auto-attribute every new lead.')}
                        </p>
                    </div>

                    <div className="flex items-center gap-2 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 px-3 py-2 rounded-xl text-xs font-mono text-zinc-700 dark:text-zinc-300">
                        <code className="select-all">&lt;script src="{tracker_url}" defer&gt;&lt;/script&gt;</code>
                        <button
                            type="button"
                            onClick={copyTrackerCode}
                            className="p-1 hover:bg-zinc-100 dark:hover:bg-zinc-800 rounded text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100 transition-colors"
                            title={t('lead_sources.copy_tracker', 'Copy Tracker Snippet')}
                        >
                            {copiedTracker ? <Check className="h-4 w-4 text-emerald-500" /> : <Copy className="h-4 w-4" />}
                        </button>
                    </div>
                </div>

                {/* Top UTM Campaigns Widget */}
                {utm_distribution.length > 0 && (
                    <div className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5">
                        <h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-4 flex items-center gap-2">
                            <BarChart3 className="h-4 w-4 text-indigo-500" />
                            {t('lead_sources.utm_breakdown_title', 'Top Marketing UTM Sources')}
                        </h3>
                        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3">
                            {utm_distribution.map((item, idx) => (
                                <div key={idx} className="bg-zinc-50 dark:bg-zinc-800/50 p-3 rounded-xl border border-zinc-100 dark:border-zinc-800">
                                    <div className="text-xs text-zinc-500 dark:text-zinc-400 truncate font-mono">
                                        {item.utm_source}
                                    </div>
                                    <div className="text-lg font-bold text-zinc-900 dark:text-zinc-100 mt-1">
                                        {item.count} <span className="text-xs font-normal text-zinc-400">leads</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Sources Table */}
                <div className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl overflow-hidden shadow-xs">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                            <thead className="bg-zinc-50/80 dark:bg-zinc-800/40 text-xs uppercase font-medium text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <th className="py-3.5 px-4">{t('lead_sources.source_name', 'Source Name')}</th>
                                    <th className="py-3.5 px-4">{t('lead_sources.source_type', 'Type')}</th>
                                    <th className="py-3.5 px-4">{t('lead_sources.status', 'Status')}</th>
                                    <th className="py-3.5 px-4">{t('lead_sources.leads_count', 'Leads')}</th>
                                    <th className="py-3.5 px-4">{t('lead_sources.last_activity', 'Last Lead')}</th>
                                    <th className="py-3.5 px-4">{t('lead_sources.webhook_url', 'Webhook URL')}</th>
                                    <th className="py-3.5 px-4 text-right">{t('lead_sources.actions', 'Actions')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-zinc-200 dark:divide-zinc-800">
                                {sources.map((src) => (
                                    <tr key={src.id} className="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 transition-colors">
                                        <td className="py-3.5 px-4">
                                            <div className="font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                                                {getTypeIcon(src.type)}
                                                <span>{src.name}</span>
                                            </div>
                                            {src.last_error && (
                                                <p className="text-xs text-rose-500 mt-1 line-clamp-1">
                                                    {src.last_error}
                                                </p>
                                            )}
                                        </td>
                                        <td className="py-3.5 px-4">
                                            <span className="capitalize text-xs font-mono bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 rounded text-zinc-600 dark:text-zinc-300">
                                                {t(`lead_sources.type_${src.type}`, src.type)}
                                            </span>
                                        </td>
                                        <td className="py-3.5 px-4">
                                            {getStatusBadge(src.status)}
                                        </td>
                                        <td className="py-3.5 px-4 font-semibold text-zinc-900 dark:text-zinc-100">
                                            {src.leads_count || 0}
                                        </td>
                                        <td className="py-3.5 px-4 text-xs text-zinc-500">
                                            {src.last_lead_at ? new Date(src.last_lead_at).toLocaleString() : '—'}
                                        </td>
                                        <td className="py-3.5 px-4">
                                            {src.webhook_url ? (
                                                <div className="flex items-center gap-1.5">
                                                    <span className="font-mono text-xs max-w-[200px] truncate text-zinc-500 dark:text-zinc-400 bg-zinc-100 dark:bg-zinc-800 px-2 py-1 rounded">
                                                        {src.webhook_url}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => copyWebhookUrl(src.id, src.webhook_url)}
                                                        className="p-1 hover:bg-zinc-100 dark:hover:bg-zinc-800 rounded text-zinc-500 hover:text-zinc-900 transition-colors"
                                                        title={t('lead_sources.copy_webhook', 'Copy Webhook URL')}
                                                    >
                                                        {copiedWebhookId === src.id ? (
                                                            <Check className="h-3.5 w-3.5 text-emerald-500" />
                                                        ) : (
                                                            <Copy className="h-3.5 w-3.5" />
                                                        )}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => setSnippetSource(src)}
                                                        className="p-1 hover:bg-zinc-100 dark:hover:bg-zinc-800 rounded text-zinc-500 hover:text-indigo-600 transition-colors"
                                                        title={t('lead_sources.view_example', 'View Code Snippet')}
                                                    >
                                                        <Code className="h-3.5 w-3.5" />
                                                    </button>
                                                </div>
                                            ) : (
                                                <span className="text-xs text-zinc-400 italic">N/A</span>
                                            )}
                                        </td>
                                        <td className="py-3.5 px-4 text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                {src.webhook_token && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleRegenerateToken(src)}
                                                        className="p-1.5 hover:bg-zinc-100 dark:hover:bg-zinc-800 rounded-lg text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100 transition-colors"
                                                        title={t('lead_sources.regenerate_token', 'Regenerate Token')}
                                                    >
                                                        <RefreshCw className="h-3.5 w-3.5" />
                                                    </button>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setEditingSource(src);
                                                        editForm.setData({
                                                            name: src.name,
                                                            status: src.status,
                                                            pipeline_id: src.config?.pipeline_id || '',
                                                            stage_id: src.config?.stage_id || '',
                                                        });
                                                    }}
                                                    className="p-1.5 hover:bg-zinc-100 dark:hover:bg-zinc-800 rounded-lg text-zinc-500 hover:text-indigo-600 transition-colors"
                                                    title={t('common.edit', 'Edit')}
                                                >
                                                    <Layers className="h-3.5 w-3.5" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => handleDeleteSource(src)}
                                                    className="p-1.5 hover:bg-rose-50 dark:hover:bg-rose-950/40 rounded-lg text-zinc-500 hover:text-rose-600 transition-colors"
                                                    title={t('common.delete', 'Delete')}
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Add Modal */}
                {isAddModalOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-zinc-900/60 backdrop-blur-xs">
                        <div className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 w-full max-w-md shadow-2xl space-y-4">
                            <h3 className="text-lg font-bold text-zinc-900 dark:text-zinc-100">
                                {t('lead_sources.add_source', 'Add Lead Source')}
                            </h3>

                            <form onSubmit={handleCreateSource} className="space-y-4">
                                <div>
                                    <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                        {t('lead_sources.source_name', 'Source Name')} *
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        value={addForm.data.name}
                                        onChange={(e) => addForm.setData('name', e.target.value)}
                                        placeholder="e.g. Landing Page Webhook, Facebook Ads"
                                        className="w-full px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                        {t('lead_sources.source_type', 'Channel Type')} *
                                    </label>
                                    <select
                                        value={addForm.data.type}
                                        onChange={(e) => addForm.setData('type', e.target.value)}
                                        className="w-full px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <option value="webhook">{t('lead_sources.type_webhook', 'Custom Webhook')}</option>
                                        <option value="api">{t('lead_sources.type_api', 'API Key')}</option>
                                        <option value="meta_ads">{t('lead_sources.type_meta_ads', 'Meta Lead Ads')}</option>
                                        <option value="whatsapp">{t('lead_sources.type_whatsapp', 'WhatsApp Chat')}</option>
                                        <option value="form">{t('lead_sources.type_form', 'Website Form')}</option>
                                        <option value="manual">{t('lead_sources.type_manual', 'Manual Entry')}</option>
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                        {t('lead_sources.target_pipeline', 'Target Pipeline')}
                                    </label>
                                    <select
                                        value={addForm.data.pipeline_id}
                                        onChange={(e) => addForm.setData('pipeline_id', e.target.value)}
                                        className="w-full px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500"
                                    >
                                        {pipelines.map((pipe) => (
                                            <option key={pipe.id} value={pipe.id}>{pipe.name}</option>
                                        ))}
                                    </select>
                                </div>

                                <div className="flex justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setIsAddModalOpen(false)}
                                        className="px-4 py-2 bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-xl transition-colors"
                                    >
                                        {t('common.cancel', 'Cancel')}
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={addForm.processing}
                                        className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl transition-colors disabled:opacity-50"
                                    >
                                        {t('common.save', 'Save')}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                {/* Edit Modal */}
                {editingSource && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-zinc-900/60 backdrop-blur-xs">
                        <div className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 w-full max-w-md shadow-2xl space-y-4">
                            <h3 className="text-lg font-bold text-zinc-900 dark:text-zinc-100">
                                {t('common.edit', 'Edit')}: {editingSource.name}
                            </h3>

                            <form onSubmit={handleEditSource} className="space-y-4">
                                <div>
                                    <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                        {t('lead_sources.source_name', 'Source Name')} *
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        value={editForm.data.name}
                                        onChange={(e) => editForm.setData('name', e.target.value)}
                                        className="w-full px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                        {t('lead_sources.status', 'Status')} *
                                    </label>
                                    <select
                                        value={editForm.data.status}
                                        onChange={(e) => editForm.setData('status', e.target.value)}
                                        className="w-full px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <option value="active">{t('common.active', 'Active')}</option>
                                        <option value="inactive">{t('common.inactive', 'Inactive')}</option>
                                    </select>
                                </div>

                                <div className="flex justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setEditingSource(null)}
                                        className="px-4 py-2 bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-xl transition-colors"
                                    >
                                        {t('common.cancel', 'Cancel')}
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={editForm.processing}
                                        className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl transition-colors disabled:opacity-50"
                                    >
                                        {t('common.save', 'Save')}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                {/* Code Snippet Modal */}
                {snippetSource && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-zinc-900/60 backdrop-blur-xs">
                        <div className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 w-full max-w-lg shadow-2xl space-y-4">
                            <div className="flex items-center justify-between">
                                <h3 className="text-base font-bold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                                    <Code className="h-5 w-5 text-indigo-500" />
                                    {t('lead_sources.code_example', 'Integration Payload Example')}
                                </h3>
                                <button
                                    type="button"
                                    onClick={() => setSnippetSource(null)}
                                    className="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200"
                                >
                                    ✕
                                </button>
                            </div>

                            <div className="space-y-2">
                                <p className="text-xs text-zinc-500 dark:text-zinc-400">
                                    Send a standard POST request with a JSON payload to your webhook endpoint:
                                </p>
                                <pre className="p-4 bg-zinc-950 text-emerald-400 rounded-xl text-xs font-mono overflow-x-auto leading-relaxed">
{`curl -X POST "${snippetSource.webhook_url}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "name": "Jane Doe",
    "email": "jane@example.com",
    "phone": "+1234567890",
    "deal_value": 5000,
    "utm_source": "facebook",
    "utm_campaign": "summer_sale",
    "message": "Interested in premium package"
  }'`}
                                </pre>
                            </div>

                            <div className="flex justify-end">
                                <button
                                    type="button"
                                    onClick={() => setSnippetSource(null)}
                                    className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl transition-colors"
                                >
                                    {t('common.close', 'Close')}
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </ClientLayout>
    );
}

