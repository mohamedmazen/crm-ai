import { Head, useForm, router, usePage, Link } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import Button from '@/Components/ui/Button';
import Modal from '@/Components/ui/Modal';
import {
    Search, MapPin, UserPlus, Trash2, Star, KanbanSquare,
    Download, Upload, Tag, User, Filter, ArrowRight, CheckSquare, Plus
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import ScoreBadge from './Partials/ScoreBadge';

const JOB_STATUS = {
    pending: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    running: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    done: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    failed: 'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-300',
};

export default function LeadsIndex({
    leads,
    scrapeJobs = [],
    filters = {},
    pipelines = [],
    stages = [],
    tags = [],
    teamMembers = [],
}) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};

    const [selected, setSelected] = useState([]);
    const [showScraper, setShowScraper] = useState(false);
    const [showImport, setShowImport] = useState(false);
    const [showTagModal, setShowTagModal] = useState(false);
    const [newTagInput, setNewTagInput] = useState('');
    const [search, setSearch] = useState(filters.search ?? '');

    // Import form
    const [importFile, setImportFile] = useState(null);
    const [importPipelineId, setImportPipelineId] = useState('');
    const [importTags, setImportTags] = useState('');
    const [importing, setImporting] = useState(false);

    // Auto-refresh when scrapers run
    const hasActiveJob = scrapeJobs.some(j => j.status === 'pending' || j.status === 'running');
    useEffect(() => {
        if (!hasActiveJob) return undefined;
        const id = setInterval(() => router.reload({ only: ['scrapeJobs', 'leads'], preserveScroll: true }), 5000);
        return () => clearInterval(id);
    }, [hasActiveJob]);

    const { data: scrapeData, setData: setScrapeData, post: postScrape, processing: scrapeProcessing, reset: resetScrape } = useForm({
        keyword: '',
        location: '',
        radius_meters: 5000,
    });

    const handleScrape = (e) => {
        e.preventDefault();
        postScrape(route('client.leads.scrape'), { onSuccess: () => { resetScrape(); setShowScraper(false); } });
    };

    const applyFilter = (overrides) => {
        const next = { ...filters, ...overrides };
        Object.keys(next).forEach((key) => {
            if (next[key] === undefined || next[key] === null || next[key] === '') {
                delete next[key];
            }
        });

        router.get(route('client.leads.index'), next, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        applyFilter({ search: search.trim() || undefined });
    };

    const toggleSelect = (id) => {
        setSelected(prev => prev.includes(id) ? prev.filter(s => s !== id) : [...prev, id]);
    };

    const selectAllOnPage = (e) => {
        setSelected(e.target.checked ? leads.data.map(l => l.id) : []);
    };

    // Bulk actions
    const handleBulkAssign = (userId) => {
        if (!selected.length) return;
        router.post(
            route('client.leads.bulk.assign'),
            { ids: selected, user_id: userId ? Number(userId) : null },
            { preserveScroll: true, onSuccess: () => setSelected([]) }
        );
    };

    const handleBulkMoveStage = (stageId) => {
        if (!selected.length || !stageId) return;
        router.post(
            route('client.leads.bulk.stage'),
            { ids: selected, stage_id: Number(stageId) },
            { preserveScroll: true, onSuccess: () => setSelected([]) }
        );
    };

    const handleBulkAddTags = (e) => {
        e.preventDefault();
        if (!selected.length || !newTagInput.trim()) return;

        const tagNames = newTagInput.split(',').map(s => s.trim()).filter(Boolean);
        router.post(
            route('client.leads.bulk.tags'),
            { ids: selected, action: 'add', tag_names: tagNames },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected([]);
                    setNewTagInput('');
                    setShowTagModal(false);
                },
            }
        );
    };

    const handleBulkDelete = () => {
        if (!confirm(t('leads.confirm_bulk_delete', { count: selected.length }) || `Delete ${selected.length} selected lead(s)?`)) {
            return;
        }

        router.post(
            route('client.leads.bulk.destroy'),
            { ids: selected },
            { preserveScroll: true, onSuccess: () => setSelected([]) }
        );
    };

    const pushToContacts = () => {
        router.post(route('client.leads.push-to-contacts'), { ids: selected }, { preserveScroll: true, onSuccess: () => setSelected([]) });
    };

    const handleDeleteSingle = (id) => {
        if (confirm(t('leads.confirm_delete'))) {
            router.delete(route('client.leads.destroy', id), { preserveScroll: true });
        }
    };

    const handleImportSubmit = (e) => {
        e.preventDefault();
        if (!importFile) return;

        setImporting(true);
        const formData = new FormData();
        formData.append('file', importFile);
        if (importPipelineId) formData.append('pipeline_id', importPipelineId);
        if (importTags.trim()) {
            importTags.split(',').map(s => s.trim()).filter(Boolean).forEach(t => formData.append('tag_names[]', t));
        }

        router.post(route('client.leads.import'), formData, {
            preserveScroll: true,
            onSuccess: () => {
                setShowImport(false);
                setImportFile(null);
                setImportTags('');
            },
            onFinish: () => setImporting(false),
        });
    };

    return (
        <ClientLayout title={t('leads.title')}>
            <Head title={t('leads.title')} />
            <div className="space-y-5">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('leads.title')}</h2>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400 mt-0.5">{t('leads.subtitle')}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href={route('client.leads.pipeline.index')}
                            className="flex items-center gap-1.5 rounded-lg border border-neutral-200 dark:border-neutral-700 px-3 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-200 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                        >
                            <KanbanSquare className="h-4 w-4" /> {t('leads.view_pipeline')}
                        </Link>

                        <a
                            href={route('client.leads.export', filters)}
                            className="flex items-center gap-1.5 rounded-lg border border-neutral-200 dark:border-neutral-700 px-3 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-200 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                            title={t('leads.export_csv') || 'Export CSV'}
                        >
                            <Download className="h-4 w-4" /> {t('leads.export') || 'Export'}
                        </a>

                        <button
                            type="button"
                            onClick={() => setShowImport(true)}
                            className="flex items-center gap-1.5 rounded-lg border border-neutral-200 dark:border-neutral-700 px-3 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-200 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                        >
                            <Upload className="h-4 w-4" /> {t('leads.import') || 'Import CSV'}
                        </button>

                        <button
                            type="button"
                            onClick={() => setShowScraper(true)}
                            className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700 transition"
                        >
                            <Search className="h-4 w-4" /> {t('leads.new_search')}
                        </button>
                    </div>
                </div>

                {flash.success && <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">{flash.success}</div>}
                {flash.error   && <div className="rounded-lg bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 px-4 py-2 text-sm">{flash.error}</div>}

                {/* Scrape Jobs */}
                {scrapeJobs.length > 0 && (
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4">
                        <p className="text-xs font-semibold text-neutral-500 uppercase mb-2">{t('leads.recent_searches')}</p>
                        <div className="space-y-1.5">
                            {scrapeJobs.map(job => (
                                <div key={job.id} className="text-sm">
                                    <div className="flex items-center gap-3">
                                        <span className="font-medium text-neutral-800 dark:text-neutral-200">{t('leads.keyword_in_location', { keyword: job.keyword, location: job.location })}</span>
                                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${JOB_STATUS[job.status] ?? ''}`}>{t(`leads.job_status_${job.status}`, job.status)}</span>
                                        {job.leads_found > 0 && <span className="text-neutral-400 text-xs">{t('leads.found_count', { count: job.leads_found })}</span>}
                                    </div>
                                    {job.status === 'failed' && job.error && (
                                        <p className="mt-0.5 text-xs text-red-600 dark:text-red-400 break-words">{job.error}</p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Filter Toolbar */}
                <div className="flex flex-wrap items-center justify-between gap-3 bg-white p-3.5 rounded-2xl border border-neutral-200 dark:bg-neutral-900 dark:border-neutral-800">
                    <form onSubmit={handleSearchSubmit} className="relative flex-1 min-w-[200px]">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder={t('leads.search_placeholder') || 'Search name, phone, email, category...'}
                            className="w-full rounded-soft border-soft bg-white py-1.5 pl-9 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                        />
                    </form>

                    <div className="flex flex-wrap items-center gap-2">
                        {/* Pipeline filter */}
                        {pipelines.length > 1 && (
                            <select
                                value={filters.pipeline_id ?? ''}
                                onChange={(e) => applyFilter({ pipeline_id: e.target.value || undefined, stage_id: undefined })}
                                className="rounded-soft border-soft bg-white py-1.5 px-2.5 text-xs font-medium dark:bg-neutral-800 dark:text-neutral-200"
                            >
                                <option value="">{t('leads.all_pipelines') || 'All Pipelines'}</option>
                                {pipelines.map(p => (
                                    <option key={p.id} value={p.id}>{p.name}</option>
                                ))}
                            </select>
                        )}

                        {/* Stage filter */}
                        <select
                            value={filters.stage_id ?? ''}
                            onChange={(e) => applyFilter({ stage_id: e.target.value || undefined })}
                            className="rounded-soft border-soft bg-white py-1.5 px-2.5 text-xs font-medium dark:bg-neutral-800 dark:text-neutral-200"
                        >
                            <option value="">{t('leads.all_stages') || 'All Stages'}</option>
                            {stages.map(s => (
                                <option key={s.id} value={s.id}>{s.name}</option>
                            ))}
                        </select>

                        {/* Assignee filter */}
                        <select
                            value={filters.assigned ?? ''}
                            onChange={(e) => applyFilter({ assigned: e.target.value || undefined })}
                            className="rounded-soft border-soft bg-white py-1.5 px-2.5 text-xs font-medium dark:bg-neutral-800 dark:text-neutral-200"
                        >
                            <option value="">{t('leads.all_assignees') || 'All Assignees'}</option>
                            <option value="me">{t('leads.my_leads') || 'My Leads'}</option>
                            <option value="unassigned">{t('leads.unassigned') || 'Unassigned'}</option>
                            {teamMembers.map(m => (
                                <option key={m.id} value={m.id}>{m.name}</option>
                            ))}
                        </select>

                        {/* Tag filter */}
                        {tags.length > 0 && (
                            <select
                                value={filters.tag_id ?? ''}
                                onChange={(e) => applyFilter({ tag_id: e.target.value || undefined })}
                                className="rounded-soft border-soft bg-white py-1.5 px-2.5 text-xs font-medium dark:bg-neutral-800 dark:text-neutral-200"
                            >
                                <option value="">{t('leads.all_tags') || 'All Tags'}</option>
                                {tags.map(t => (
                                    <option key={t.id} value={t.id}>{t.name}</option>
                                ))}
                            </select>
                        )}
                    </div>
                </div>

                {/* Bulk Actions Floating Bar */}
                {selected.length > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-neutral-900 text-white p-3 px-4 shadow-lg dark:bg-neutral-800">
                        <div className="flex items-center gap-2">
                            <span className="font-semibold text-sm">
                                {t('leads.selected_count', { count: selected.length })}
                            </span>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            {/* Bulk Assign */}
                            <select
                                defaultValue=""
                                onChange={(e) => {
                                    if (e.target.value !== '') {
                                        handleBulkAssign(e.target.value);
                                        e.target.value = '';
                                    }
                                }}
                                className="rounded border-neutral-700 bg-neutral-800 text-white py-1 px-2.5 text-xs font-medium focus:ring-1 focus:ring-brand-500"
                            >
                                <option value="" disabled>{t('leads.bulk_assign') || 'Assign to...'}</option>
                                <option value="0">{t('leads.unassigned') || 'Unassign'}</option>
                                {teamMembers.map(m => (
                                    <option key={m.id} value={m.id}>{m.name}</option>
                                ))}
                            </select>

                            {/* Bulk Stage */}
                            <select
                                defaultValue=""
                                onChange={(e) => {
                                    if (e.target.value !== '') {
                                        handleBulkMoveStage(e.target.value);
                                        e.target.value = '';
                                    }
                                }}
                                className="rounded border-neutral-700 bg-neutral-800 text-white py-1 px-2.5 text-xs font-medium focus:ring-1 focus:ring-brand-500"
                            >
                                <option value="" disabled>{t('leads.bulk_stage') || 'Move stage...'}</option>
                                {stages.map(s => (
                                    <option key={s.id} value={s.id}>{s.name}</option>
                                ))}
                            </select>

                            {/* Bulk Tags */}
                            <button
                                type="button"
                                onClick={() => setShowTagModal(true)}
                                className="flex items-center gap-1 rounded bg-neutral-800 hover:bg-neutral-700 px-2.5 py-1 text-xs font-medium transition"
                            >
                                <Tag className="h-3.5 w-3.5" />
                                {t('leads.add_tags') || 'Add Tags'}
                            </button>

                            {/* Push to contacts */}
                            <button
                                type="button"
                                onClick={pushToContacts}
                                className="flex items-center gap-1 rounded bg-brand-600 hover:bg-brand-500 px-2.5 py-1 text-xs font-medium text-white transition"
                            >
                                <UserPlus className="h-3.5 w-3.5" />
                                {t('leads.push_to_contacts')}
                            </button>

                            {/* Bulk Delete */}
                            <button
                                type="button"
                                onClick={handleBulkDelete}
                                className="flex items-center gap-1 rounded bg-red-600/80 hover:bg-red-600 px-2.5 py-1 text-xs font-medium text-white transition"
                            >
                                <Trash2 className="h-3.5 w-3.5" />
                                {t('common.delete')}
                            </button>
                        </div>
                    </div>
                )}

                {/* Leads table */}
                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 overflow-hidden shadow-sm">
                    <table className="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700 text-sm">
                        <thead className="bg-neutral-50 dark:bg-neutral-800/80">
                            <tr>
                                <th className="px-3 py-3 w-8">
                                    <input
                                        type="checkbox"
                                        checked={selected.length > 0 && selected.length === leads.data.length}
                                        onChange={selectAllOnPage}
                                        className="rounded border-neutral-300 text-brand-600 focus:ring-brand-500"
                                    />
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{t('leads.col_business')}</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{t('leads.col_phone')}</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{t('leads.col_stage') || 'Pipeline / Stage'}</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{t('leads.col_assigned') || 'Assigned'}</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{t('leads.col_score')}</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{t('leads.col_tags') || 'Tags'}</th>
                                <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-neutral-500"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                            {leads.data.map(lead => (
                                <tr key={lead.id} className="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                    <td className="px-3 py-3">
                                        <input
                                            type="checkbox"
                                            checked={selected.includes(lead.id)}
                                            onChange={() => toggleSelect(lead.id)}
                                            className="rounded border-neutral-300 text-brand-600 focus:ring-brand-500"
                                        />
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-start gap-1.5">
                                            <MapPin className="h-3.5 w-3.5 text-neutral-400 mt-0.5 shrink-0" />
                                            <div>
                                                <Link
                                                    href={route('client.leads.pipeline.index', { lead: lead.id })}
                                                    className="font-medium text-neutral-900 hover:text-brand-600 dark:text-neutral-100 dark:hover:text-brand-400"
                                                >
                                                    {lead.name}
                                                </Link>
                                                {lead.deal_value > 0 && (
                                                    <span className="ml-2 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                                        {lead.currency || '$'}{Number(lead.deal_value).toLocaleString()}
                                                    </span>
                                                )}
                                                {lead.address && <p className="text-xs text-neutral-400 truncate max-w-xs">{lead.address}</p>}
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">
                                        <div>{lead.phone ?? '—'}</div>
                                        {lead.email && <div className="text-xs text-neutral-400">{lead.email}</div>}
                                    </td>
                                    <td className="px-4 py-3">
                                        {lead.stage ? (
                                            <span className="inline-flex items-center gap-1 rounded bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                                {lead.stage.name}
                                            </span>
                                        ) : '—'}
                                    </td>
                                    <td className="px-4 py-3 text-xs">
                                        {lead.assigned_user ? (
                                            <span className="inline-flex items-center gap-1 text-neutral-700 dark:text-neutral-300 font-medium">
                                                <User className="h-3.5 w-3.5 text-neutral-400" />
                                                {lead.assigned_user.name}
                                            </span>
                                        ) : (
                                            <span className="text-neutral-400 italic">{t('leads.unassigned') || 'Unassigned'}</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        {lead.score_band ? <ScoreBadge band={lead.score_band} score={lead.score} /> : '—'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap items-center gap-1">
                                            {lead.tags && lead.tags.length > 0 ? (
                                                lead.tags.map(tag => (
                                                    <span
                                                        key={tag.id}
                                                        className="inline-flex items-center rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-medium text-brand-700 dark:bg-brand-900/30 dark:text-brand-300 border border-brand-200/50 dark:border-brand-800"
                                                    >
                                                        {tag.name}
                                                    </span>
                                                ))
                                            ) : (
                                                <span className="text-neutral-300 dark:text-neutral-600 text-xs">—</span>
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            onClick={() => handleDeleteSingle(lead.id)}
                                            className="text-neutral-400 hover:text-red-500 transition"
                                            title={t('common.delete')}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </button>
                                    </td>
                                </tr>
                            ))}
                            {leads.data.length === 0 && (
                                <tr>
                                    <td colSpan={8}>
                                        <EmptyState
                                            icon={<MapPin className="h-8 w-8" />}
                                            title={t('leads.no_leads_title')}
                                            description={t('leads.no_leads_description')}
                                            action={{ label: t('leads.new_search'), onClick: () => setShowScraper(true) }}
                                        />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>

                    {/* Pagination */}
                    {leads.links && leads.links.length > 3 && (
                        <div className="flex items-center justify-between border-t border-neutral-100 px-4 py-3 dark:border-neutral-800">
                            <div className="text-xs text-neutral-500">
                                {t('common.showing_results', { from: leads.from, to: leads.to, total: leads.total }) || `Showing ${leads.from}-${leads.to} of ${leads.total}`}
                            </div>
                            <div className="flex gap-1">
                                {leads.links.map((link, i) => (
                                    <button
                                        key={i}
                                        disabled={!link.url || link.active}
                                        onClick={() => link.url && router.visit(link.url, { preserveScroll: true, preserveState: true })}
                                        className={`rounded px-2.5 py-1 text-xs font-medium ${
                                            link.active
                                                ? 'bg-brand-500 text-white'
                                                : link.url
                                                ? 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'
                                                : 'text-neutral-300 dark:text-neutral-600 cursor-not-allowed'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* Tag Modal */}
            {showTagModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-sm rounded-xl bg-white dark:bg-neutral-900 p-6 shadow-xl space-y-4">
                        <h3 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">
                            {t('leads.add_tags') || 'Add Tags to Selected Leads'}
                        </h3>
                        <form onSubmit={handleBulkAddTags} className="space-y-3">
                            <div>
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">
                                    {t('leads.tags_comma_separated') || 'Tag names (comma-separated):'}
                                </label>
                                <input
                                    type="text"
                                    value={newTagInput}
                                    onChange={(e) => setNewTagInput(e.target.value)}
                                    placeholder="e.g. High Value, VIP, Q4"
                                    required
                                    className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm"
                                />
                            </div>
                            <div className="flex justify-end gap-2 pt-2">
                                <Button variant="secondary" type="button" onClick={() => setShowTagModal(false)}>
                                    {t('common.cancel')}
                                </Button>
                                <Button type="submit">
                                    {t('common.save')}
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Import CSV Modal */}
            {showImport && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white dark:bg-neutral-900 p-6 shadow-xl space-y-4">
                        <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                            {t('leads.import_leads_title') || 'Import Leads from CSV'}
                        </h3>
                        <p className="text-xs text-neutral-500 dark:text-neutral-400">
                            {t('leads.import_subtitle') || 'Upload a CSV file containing columns like Name, Phone, Email, Category, Address, etc.'}
                        </p>

                        <form onSubmit={handleImportSubmit} className="space-y-4">
                            <div>
                                <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                    {t('leads.select_csv_file') || 'Select CSV File (.csv)'}
                                </label>
                                <input
                                    type="file"
                                    accept=".csv,.txt"
                                    required
                                    onChange={(e) => setImportFile(e.target.files[0])}
                                    className="w-full text-xs text-neutral-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-soft file:border-0 file:text-xs file:font-medium file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100"
                                />
                            </div>

                            {pipelines.length > 0 && (
                                <div>
                                    <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                        {t('leads.target_pipeline') || 'Target Pipeline'}
                                    </label>
                                    <select
                                        value={importPipelineId}
                                        onChange={(e) => setImportPipelineId(e.target.value)}
                                        className="w-full rounded-soft border-soft bg-white py-1.5 px-3 text-xs dark:bg-neutral-800 dark:text-neutral-200"
                                    >
                                        <option value="">{t('common.default') || 'Default Pipeline'}</option>
                                        {pipelines.map(p => (
                                            <option key={p.id} value={p.id}>{p.name}</option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            <div>
                                <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                    {t('leads.apply_tags') || 'Apply Tags (comma-separated, optional)'}
                                </label>
                                <input
                                    type="text"
                                    value={importTags}
                                    onChange={(e) => setImportTags(e.target.value)}
                                    placeholder="e.g. Exhibition 2026, Inbound"
                                    className="w-full rounded-soft border-soft bg-white py-1.5 px-3 text-xs dark:bg-neutral-800 dark:text-neutral-200"
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <Button variant="secondary" type="button" onClick={() => setShowImport(false)}>
                                    {t('common.cancel')}
                                </Button>
                                <Button type="submit" disabled={importing || !importFile}>
                                    <Upload className="mr-1.5 h-4 w-4" />
                                    {importing ? (t('common.uploading') || 'Importing...') : (t('leads.start_import') || 'Start Import')}
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Scraper Modal */}
            {showScraper && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white dark:bg-neutral-900 p-6 shadow-xl space-y-4">
                        <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{t('leads.search_businesses')}</h3>
                        <form onSubmit={handleScrape} className="space-y-3">
                            <div>
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('leads.business_type_keyword')}</label>
                                <input type="text" value={scrapeData.keyword} onChange={e => setScrapeData('keyword', e.target.value)} required placeholder={t('leads.keyword_placeholder')} className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                            </div>
                            <div>
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('leads.city_location')}</label>
                                <input type="text" value={scrapeData.location} onChange={e => setScrapeData('location', e.target.value)} required placeholder={t('leads.location_placeholder')} className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                            </div>
                            <div>
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('leads.radius_meters')}</label>
                                <input type="number" min={100} max={50000} value={scrapeData.radius_meters} onChange={e => setScrapeData('radius_meters', Number(e.target.value))} className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                            </div>
                            <div className="flex gap-2 pt-2">
                                <button type="submit" disabled={scrapeProcessing} className="flex-1 flex items-center justify-center gap-1.5 rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition">
                                    <Search className="h-4 w-4" /> {scrapeProcessing ? t('leads.queuing') : t('leads.start_scrape')}
                                </button>
                                <button type="button" onClick={() => setShowScraper(false)} className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                    {t('common.cancel')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </ClientLayout>
    );
}
