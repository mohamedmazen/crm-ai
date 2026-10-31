import Modal from '@/Components/ui/Modal';
import Button from '@/Components/ui/Button';
import { Phone, Globe, MapPin, Star, History, BarChart3, DollarSign, CheckCircle2, User, CheckSquare, Tag, Sliders, Building2, MessageSquare } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { router } from '@inertiajs/react';
import useFeature from '@/hooks/useFeature';
import ScoreBadge from './ScoreBadge';
import FollowUpBadge from './FollowUpBadge';
import ActivityTimeline from './ActivityTimeline';
import LogActivityForm from './LogActivityForm';
import LeadTasksManager from './LeadTasksManager';
import LeadCustomFieldsEditor from './LeadCustomFieldsEditor';
import LeadConversationPanel from './LeadConversationPanel';

/**
 * Lead detail: the follow-up history first, because "what happened with this
 * lead" is the question people open the card to answer. The score breakdown
 * and deal economics live behind tabs.
 */
export default function LeadDetailModal({ detail, stages, activityTypes, callOutcomes, loading, teamMembers = [], onClose }) {
    const { t } = useTranslation();
    const [tab, setTab] = useState('history');
    const conversationEnabled = useFeature('sales_reports');

    const lead = detail?.lead;
    const open = !!lead || loading;

    const handleAssign = (userId) => {
        if (!lead) return;
        router.put(
            route('client.leads.pipeline.assign', lead.id),
            { user_id: userId ? Number(userId) : null },
            {
                preserveScroll: true,
                preserveState: true,
            }
        );
    };

    const handleAssignCompany = (companyId) => {
        if (!lead) return;
        router.put(
            route('client.leads.assign-company', lead.id),
            { company_id: companyId ? Number(companyId) : null },
            {
                preserveScroll: true,
                preserveState: true,
            }
        );
    };

    const TABS = [
        { key: 'history', label: t('leads.tab_history'), Icon: History },
        ...(conversationEnabled
            ? [{ key: 'conversation', label: t('leads.tab_conversation'), Icon: MessageSquare }]
            : []),
        {
            key: 'tasks',
            label: `${t('leads.tab_tasks') || 'Tasks'}${detail?.tasks?.length ? ` (${detail.tasks.length})` : ''}`,
            Icon: CheckSquare,
        },
        {
            key: 'custom_fields',
            label: `${t('leads.tab_custom_fields') || 'Custom Fields'}${detail?.custom_fields?.length ? ` (${detail.custom_fields.length})` : ''}`,
            Icon: Sliders,
        },
        { key: 'deal', label: t('leads.tab_deal') || 'Deal & Economics', Icon: DollarSign },
        { key: 'score', label: t('leads.tab_score'), Icon: BarChart3 },
    ];

    return (
        <Modal show={open} onClose={onClose} maxWidth="2xl">
            {loading && !lead ? (
                <div className="p-10 text-center text-sm text-neutral-400">{t('common.loading')}</div>
            ) : lead ? (
                <div className="flex max-h-[85vh] flex-col">
                    <div className="border-b border-soft p-5">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <h2 className="truncate text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                                    {lead.name || t('leads.unnamed')}
                                </h2>
                                {lead.category && (
                                    <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">{lead.category}</p>
                                )}
                            </div>
                            <div className="flex shrink-0 items-center gap-1.5">
                                <FollowUpBadge date={lead.next_follow_up_at} />
                                <ScoreBadge band={lead.score_band} score={lead.score} />
                            </div>
                        </div>

                        <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-neutral-600 dark:text-neutral-300">
                            {lead.phone && (
                                <a href={`tel:${lead.phone}`} className="inline-flex items-center gap-1.5 hover:text-brand-600 dark:hover:text-brand-400">
                                    <Phone className="h-3.5 w-3.5 text-neutral-400" aria-hidden="true" />
                                    {lead.phone}
                                </a>
                            )}
                            {lead.website && (
                                <a href={lead.website} target="_blank" rel="noreferrer noopener" className="inline-flex min-w-0 items-center gap-1.5 hover:text-brand-600 dark:hover:text-brand-400">
                                    <Globe className="h-3.5 w-3.5 shrink-0 text-neutral-400" aria-hidden="true" />
                                    <span className="truncate">{lead.website}</span>
                                </a>
                            )}
                            {lead.rating > 0 && (
                                <span className="inline-flex items-center gap-1.5">
                                    <Star className="h-3.5 w-3.5 text-neutral-400" aria-hidden="true" />
                                    {t('leads.rating_summary', { rating: lead.rating, count: lead.review_count })}
                                </span>
                            )}
                            {lead.address && (
                                <span className="inline-flex items-center gap-1.5">
                                    <MapPin className="h-3.5 w-3.5 text-neutral-400" aria-hidden="true" />
                                    {lead.address}
                                </span>
                            )}
                        </div>

                        <div className="mt-3 flex flex-wrap items-center gap-4">
                            <div className="flex items-center gap-2">
                                <User className="h-3.5 w-3.5 text-neutral-400" />
                                <span className="text-xs text-neutral-500 dark:text-neutral-400">
                                    {t('leads.assigned_to') || 'Assigned to'}:
                                </span>
                                <select
                                    value={lead.assigned_user_id ?? ''}
                                    onChange={(e) => handleAssign(e.target.value)}
                                    className="rounded-soft border-soft bg-white py-1 px-2.5 text-xs font-medium text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200 focus:border-brand-500 focus:ring-1 focus:ring-brand-500"
                                >
                                    <option value="">{t('leads.unassigned') || 'Unassigned'}</option>
                                    {teamMembers.map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.name}
                                        </option>
                                    ))}
                                </select>
                                {lead.assigned_at && (
                                    <span className="text-[11px] text-neutral-400">
                                        ({new Date(lead.assigned_at).toLocaleDateString()})
                                    </span>
                                )}
                            </div>

                            <div className="flex items-center gap-2">
                                <Building2 className="h-3.5 w-3.5 text-neutral-400" />
                                <span className="text-xs text-neutral-500 dark:text-neutral-400">
                                    {t('leads.company') || 'Company'}:
                                </span>
                                {lead.company ? (
                                    <span className="inline-flex items-center gap-1.5 text-xs font-medium text-brand-600 dark:text-brand-400">
                                        <a
                                            href={route('client.leads.companies.show', lead.company.id)}
                                            className="hover:underline"
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            {lead.company.name}
                                        </a>
                                        <button
                                            type="button"
                                            onClick={() => handleAssignCompany(null)}
                                            className="text-neutral-400 hover:text-red-500"
                                            title={t('common.remove')}
                                        >
                                            &times;
                                        </button>
                                    </span>
                                ) : (
                                    <CompanyPicker onSelect={(c) => handleAssignCompany(c?.id)} />
                                )}
                            </div>
                        </div>

                        {detail.tags && detail.tags.length > 0 && (
                            <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
                                <Tag className="h-3.5 w-3.5 text-neutral-400" />
                                {detail.tags.map(t => (
                                    <span
                                        key={t.id}
                                        className="inline-flex items-center rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-700 dark:bg-brand-900/30 dark:text-brand-300 border border-brand-200/50 dark:border-brand-800"
                                    >
                                        {t.name}
                                    </span>
                                ))}
                            </div>
                        )}

                        <div className="mt-4 flex gap-1" role="tablist">
                            {TABS.map(({ key, label, Icon }) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={tab === key}
                                    onClick={() => setTab(key)}
                                    className={`inline-flex items-center gap-1.5 rounded-soft px-2.5 py-1 text-xs font-medium transition-colors ${
                                        tab === key
                                            ? 'bg-brand-500 text-white'
                                            : 'text-neutral-500 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800'
                                    }`}
                                >
                                    <Icon className="h-3.5 w-3.5" />
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto p-5">
                        {tab === 'history' ? (
                            <div className="space-y-4">
                                <LogActivityForm
                                    lead={lead}
                                    stages={stages}
                                    types={activityTypes}
                                    outcomes={callOutcomes}
                                />
                                <ActivityTimeline activities={detail.activities} />
                            </div>
                        ) : tab === 'conversation' ? (
                            <LeadConversationPanel
                                conversation={detail.conversation ?? null}
                                timeline={detail.timeline ?? []}
                            />
                        ) : tab === 'tasks' ? (
                            <LeadTasksManager lead={lead} tasks={detail.tasks ?? []} teamMembers={teamMembers} />
                        ) : tab === 'custom_fields' ? (
                            <LeadCustomFieldsEditor lead={lead} customFields={detail.custom_fields ?? []} />
                        ) : tab === 'deal' ? (
                            <DealEditor lead={lead} stageHistory={detail.stage_history ?? []} />
                        ) : (
                            <ScoreBreakdown breakdown={lead.score_breakdown ?? []} />
                        )}
                    </div>

                    <div className="flex justify-end border-t border-soft p-4">
                        <Button variant="secondary" onClick={onClose}>{t('common.close')}</Button>
                    </div>
                </div>
            ) : null}
        </Modal>
    );
}

function DealEditor({ lead, stageHistory }) {
    const { t } = useTranslation();
    const [dealValue, setDealValue] = useState(lead.deal_value ?? '');
    const [currency, setCurrency] = useState(lead.currency ?? 'USD');
    const [probability, setProbability] = useState(lead.probability ?? '');
    const [expectedCloseDate, setExpectedCloseDate] = useState(lead.expected_close_date ?? '');
    const [status, setStatus] = useState(lead.status ?? 'new');
    const [saving, setSaving] = useState(false);

    const handleSave = (e) => {
        e.preventDefault();
        setSaving(true);
        router.put(
            route('client.leads.pipeline.deal.update', lead.id),
            {
                deal_value: dealValue ? Number(dealValue) : null,
                currency: currency || null,
                probability: probability !== '' ? Number(probability) : null,
                expected_close_date: expectedCloseDate || null,
                status,
            },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
            }
        );
    };

    return (
        <div className="space-y-6">
            <form onSubmit={handleSave} className="space-y-4 rounded-xl border border-neutral-100 bg-neutral-50/50 p-4 dark:border-neutral-800 dark:bg-neutral-800/20">
                <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">
                    {t('leads.deal_economics_title') || 'Deal Economics & Status'}
                </h3>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">
                            {t('leads.deal_value') || 'Deal Value'}
                        </label>
                        <div className="mt-1 flex rounded-soft shadow-sm">
                            <span className="inline-flex items-center rounded-l-soft border border-r-0 border-soft bg-neutral-100 px-3 text-sm text-neutral-500 dark:bg-neutral-800">
                                $
                            </span>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                value={dealValue}
                                onChange={(e) => setDealValue(e.target.value)}
                                placeholder="0.00"
                                className="block w-full min-w-0 flex-1 rounded-none rounded-r-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">
                            {t('leads.win_probability') || 'Win Probability (%)'}
                        </label>
                        <input
                            type="number"
                            min="0"
                            max="100"
                            value={probability}
                            onChange={(e) => setProbability(e.target.value)}
                            placeholder="e.g. 50"
                            className="mt-1 block w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                        />
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">
                            {t('leads.expected_close_date') || 'Expected Close Date'}
                        </label>
                        <input
                            type="date"
                            value={expectedCloseDate}
                            onChange={(e) => setExpectedCloseDate(e.target.value)}
                            className="mt-1 block w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                        />
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">
                            {t('leads.status') || 'Lifecycle Status'}
                        </label>
                        <select
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                            className="mt-1 block w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                        >
                            <option value="new">New</option>
                            <option value="contacted">Contacted</option>
                            <option value="qualified">Qualified</option>
                            <option value="converted">Converted (Won)</option>
                            <option value="lost">Lost</option>
                        </select>
                    </div>
                </div>

                {lead.close_reason && (
                    <div className="rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                        <strong>{t('leads.outcome_reason') || 'Close Reason'}:</strong> {lead.close_reason.name} ({lead.close_reason.type})
                    </div>
                )}

                <div className="flex justify-end pt-2">
                    <Button type="submit" size="sm" disabled={saving}>
                        {saving ? (t('common.saving') || 'Saving...') : (t('common.save') || 'Save Changes')}
                    </Button>
                </div>
            </form>

            {/* Stage Transition History */}
            <div>
                <h4 className="text-xs font-semibold uppercase tracking-wider text-neutral-400">
                    {t('leads.stage_history_title') || 'Stage Movement History'}
                </h4>

                {stageHistory.length === 0 ? (
                    <p className="mt-2 text-xs text-neutral-400">{t('leads.no_stage_history') || 'No stage movement recorded yet.'}</p>
                ) : (
                    <ul className="mt-2 space-y-2 border-l-2 border-neutral-100 pl-3 dark:border-neutral-800">
                        {stageHistory.map((h) => (
                            <li key={h.id} className="text-xs text-neutral-600 dark:text-neutral-300">
                                <span className="font-medium text-neutral-900 dark:text-neutral-100">
                                    {h.from_stage ? `${h.from_stage} → ` : ''}{h.to_stage}
                                </span>
                                {h.user && <span className="text-neutral-400"> by {h.user}</span>}
                                {h.changed_at && (
                                    <span className="block text-[11px] text-neutral-400 mt-0.5">
                                        {new Date(h.changed_at).toLocaleString()}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

function ScoreBreakdown({ breakdown }) {
    const { t } = useTranslation();

    if (!breakdown.length) {
        return <p className="py-6 text-center text-sm text-neutral-400">{t('leads.not_scored_yet')}</p>;
    }

    return (
        <div>
            <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('leads.why_this_score')}</h3>
            <ul className="mt-3 space-y-3">
                {breakdown.map((row) => (
                    <li key={row.rule}>
                        <div className="flex items-center justify-between gap-2 text-xs">
                            <span className="text-neutral-600 dark:text-neutral-300">{row.detail}</span>
                            <span className="shrink-0 font-medium tabular-nums text-neutral-500 dark:text-neutral-400">
                                {row.points} / {row.max}
                            </span>
                        </div>
                        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                            <div
                                className="h-full rounded-full bg-brand-500 transition-all"
                                style={{ width: `${row.max > 0 ? (row.points / row.max) * 100 : 0}%` }}
                            />
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function CompanyPicker({ onSelect }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(false);

    const handleSearch = async (val) => {
        setQuery(val);
        setLoading(true);
        try {
            const res = await fetch(route('client.leads.companies.search', { q: val }));
            if (res.ok) {
                const data = await res.json();
                setResults(data);
            }
        } catch {
            setResults([]);
        } finally {
            setLoading(false);
        }
    };

    const handleOpen = () => {
        setOpen(true);
        handleSearch('');
    };

    if (!open) {
        return (
            <button
                type="button"
                onClick={handleOpen}
                className="text-xs text-brand-600 hover:text-brand-700 dark:text-brand-400 font-medium underline"
            >
                {t('leads.link_company') || '+ Link Company'}
            </button>
        );
    }

    return (
        <div className="relative inline-block">
            <div className="flex items-center gap-1">
                <input
                    type="text"
                    value={query}
                    autoFocus
                    placeholder={t('common.search') || 'Search company...'}
                    onChange={(e) => handleSearch(e.target.value)}
                    className="w-36 rounded-soft border-soft bg-white px-2 py-0.5 text-xs text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200 focus:border-brand-500 focus:ring-1 focus:ring-brand-500"
                />
                <button
                    type="button"
                    onClick={() => setOpen(false)}
                    className="text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200"
                >
                    &times;
                </button>
            </div>
            {open && (
                <div className="absolute left-0 top-full z-20 mt-1 max-h-48 w-52 overflow-y-auto rounded-soft border border-soft bg-white p-1 shadow-lg dark:bg-neutral-800">
                    {loading ? (
                        <div className="p-2 text-center text-xs text-neutral-400">{t('common.loading')}</div>
                    ) : results.length === 0 ? (
                        <div className="p-2 text-center text-xs text-neutral-400">{t('common.no_results') || 'No companies found'}</div>
                    ) : (
                        results.map((comp) => (
                            <button
                                key={comp.id}
                                type="button"
                                onClick={() => {
                                    onSelect(comp);
                                    setOpen(false);
                                }}
                                className="flex w-full flex-col items-start rounded-soft px-2 py-1.5 text-start text-xs text-neutral-700 hover:bg-neutral-100 dark:text-neutral-200 dark:hover:bg-neutral-700/60"
                            >
                                <span className="font-medium">{comp.name}</span>
                                {comp.domain && (
                                    <span className="text-[10px] text-neutral-400">{comp.domain}</span>
                                )}
                            </button>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}
