import { Head, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Plus, SlidersHorizontal, RefreshCw, Search, LayoutGrid, AlarmClock } from 'lucide-react';
import {
    DndContext,
    DragOverlay,
    KeyboardSensor,
    PointerSensor,
    closestCorners,
    pointerWithin,
    rectIntersection,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import { arrayMove, sortableKeyboardCoordinates } from '@dnd-kit/sortable';
import StageColumn from './Partials/StageColumn';
import { LeadCardView } from './Partials/LeadCard';
import LeadDetailModal from './Partials/LeadDetailModal';
import StageModal from './Partials/StageModal';
import ScoringModal from './Partials/ScoringModal';

const BANDS = ['hot', 'warm', 'cold'];

/**
 * Board columns stretch to the height of the fullest one, so a column droppable
 * can be thousands of pixels tall. closestCorners averages the distance between
 * all four corners of the dragged card and all four of each droppable, so a tall
 * column's far-off bottom corners inflate its score until every neighbouring card
 * outranks it — the drop target then never leaves the source column.
 *
 * Whichever column the pointer is actually inside is the honest answer, so ask
 * that first. rectIntersection covers a drag past the board edge, and
 * closestCorners is the last resort for the keyboard sensor, which has no pointer.
 */
function collisionDetectionStrategy(args) {
    const pointer = pointerWithin(args);
    if (pointer.length > 0) {
        return pointer;
    }

    const intersections = rectIntersection(args);

    return intersections.length > 0 ? intersections : closestCorners(args);
}

export default function Pipeline({
    stages,
    colors,
    filters,
    scoring,
    leadDetail,
    activityTypes,
    callOutcomes,
    boardTotal,
    hotTotal,
    pipelines = [],
    activePipelineId,
    boardDealValue = 0,
    boardWeightedValue = 0,
    closeReasons = [],
}) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};

    // Mirrored locally so a drag repaints instantly instead of waiting on the round trip.
    const [board, setBoard] = useState(stages);
    const [activeLead, setActiveLead] = useState(null);
    const [editingStage, setEditingStage] = useState(null);
    const [showScoring, setShowScoring] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [loadingDetail, setLoadingDetail] = useState(false);
    const [pendingCloseMove, setPendingCloseMove] = useState(null);
    const [selectedCloseReasonId, setSelectedCloseReasonId] = useState('');

    // Re-sync when the server sends a new board (filter, reload, rejected move).
    // Adjusted during render rather than in an effect so the stale board is never
    // painted first: https://react.dev/learn/you-might-not-need-an-effect
    const [syncedStages, setSyncedStages] = useState(stages);
    if (stages !== syncedStages) {
        setSyncedStages(stages);
        setBoard(stages);
    }

    const sensors = useSensors(
        // A few pixels of travel before a drag starts, so clicking a card to open
        // it still works.
        useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    // Whole-board counts come from the server; the rendered slice is capped, so
    // deriving them client-side would undercount past the cap.
    const totals = { all: boardTotal ?? 0, hot: hotTotal ?? 0 };

    const findStageOfLead = (leadId, source = board) =>
        source.find((s) => s.leads.some((l) => l.id === leadId));

    const resolveTarget = (overId) => {
        if (typeof overId === 'string' && overId.startsWith('stage-')) {
            return board.find((s) => s.id === Number(overId.replace('stage-', '')));
        }
        return findStageOfLead(overId);
    };

    const handleDragStart = ({ active }) => {
        setActiveLead(active.data.current?.lead ?? null);
    };

    // Moves the card between columns mid-drag so the board previews the drop.
    const handleDragOver = ({ active, over }) => {
        if (!over) return;

        const from = findStageOfLead(active.id);
        const to = resolveTarget(over.id);
        if (!from || !to || from.id === to.id) return;

        setBoard((prev) => {
            const lead = prev.flatMap((s) => s.leads).find((l) => l.id === active.id);
            if (!lead) return prev;

            return prev.map((stage) => {
                if (stage.id === from.id) {
                    return { ...stage, leads: stage.leads.filter((l) => l.id !== active.id) };
                }
                if (stage.id === to.id) {
                    const overIndex = stage.leads.findIndex((l) => l.id === over.id);
                    const insertAt = overIndex >= 0 ? overIndex : stage.leads.length;
                    const next = [...stage.leads];
                    next.splice(insertAt, 0, lead);
                    return { ...stage, leads: next };
                }
                return stage;
            });
        });
    };

    const handleDragEnd = ({ active, over }) => {
        setActiveLead(null);
        if (!over) return;

        const stage = resolveTarget(over.id);
        if (!stage) return;

        const currentIndex = stage.leads.findIndex((l) => l.id === active.id);
        const overIndex = stage.leads.findIndex((l) => l.id === over.id);
        const position = overIndex >= 0 ? overIndex : Math.max(0, stage.leads.length - 1);

        // Reorder within the column we already previewed into.
        if (currentIndex >= 0 && overIndex >= 0 && currentIndex !== overIndex) {
            setBoard((prev) => prev.map((s) => (s.id === stage.id
                ? { ...s, leads: arrayMove(s.leads, currentIndex, overIndex) }
                : s)));
        }

        if ((stage.is_won || stage.is_lost) && closeReasons.some((r) => r.type === (stage.is_won ? 'won' : 'lost'))) {
            setPendingCloseMove({ leadId: active.id, stage, position });
            return;
        }

        executeMove(active.id, stage.id, position);
    };

    const executeMove = (leadId, stageId, position, closeReasonId = null) => {
        router.put(
            route('client.leads.pipeline.move', leadId),
            { stage_id: stageId, position, close_reason_id: closeReasonId || undefined },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => router.reload({ only: ['stages'] }),
            },
        );
    };

    const query = (overrides = {}) => ({
        pipeline_id: activePipelineId || undefined,
        band: filters.band || undefined,
        search: search || undefined,
        due: filters.due ? 1 : undefined,
        ...overrides,
    });

    const applyFilter = (overrides) => {
        router.get(route('client.leads.pipeline.index'), query(overrides), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const submitSearch = (e) => {
        e.preventDefault();
        applyFilter({});
    };

    // Only the opened lead's history is fetched, and only when it's opened. The
    // ?lead= in the URL means the open card survives a refresh and can be linked.
    const openLead = (lead) => {
        setLoadingDetail(true);
        router.get(route('client.leads.pipeline.index'), query({ lead: lead.id }), {
            only: ['leadDetail'],
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setLoadingDetail(false),
        });
    };

    const closeLead = () => {
        router.get(route('client.leads.pipeline.index'), query({ lead: undefined }), {
            only: ['leadDetail'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <ClientLayout title={t('leads.pipeline_title')}>
            <Head title={t('leads.pipeline_title')} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                                {t('leads.pipeline_title')}
                            </h1>

                            {pipelines.length > 0 && (
                                <select
                                    value={activePipelineId}
                                    onChange={(e) => applyFilter({ pipeline_id: e.target.value })}
                                    className="rounded-soft border-soft bg-white py-1 px-3 text-sm font-medium shadow-sm dark:bg-neutral-900 dark:text-neutral-100 focus:border-brand-500 focus:ring-1 focus:ring-brand-500"
                                >
                                    {pipelines.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name} {p.is_default ? `(${t('common.default') || 'Default'})` : ''}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </div>

                        <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-neutral-500 dark:text-neutral-400">
                            <span>{t('leads.pipeline_summary', { total: totals.all, hot: totals.hot })}</span>
                            {(boardDealValue > 0 || boardWeightedValue > 0) && (
                                <>
                                    <span>•</span>
                                    <span>
                                        {t('leads.total_pipeline_value') || 'Pipeline Value'}:{' '}
                                        <strong className="text-emerald-600 dark:text-emerald-400 font-semibold">
                                            ${Number(boardDealValue).toLocaleString()}
                                        </strong>
                                    </span>
                                    <span>•</span>
                                    <span>
                                        {t('leads.weighted_value') || 'Weighted'}:{' '}
                                        <strong className="text-brand-600 dark:text-brand-400 font-semibold">
                                            ${Number(boardWeightedValue).toLocaleString()}
                                        </strong>
                                    </span>
                                </>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="secondary" onClick={() => router.visit(route('client.leads.pipelines.index'))}>
                            <SlidersHorizontal className="mr-1.5 h-4 w-4" />
                            {t('leads.manage_pipelines') || 'Pipelines'}
                        </Button>
                        <Button variant="secondary" onClick={() => router.visit(route('client.leads.index'))}>
                            <LayoutGrid className="mr-1.5 h-4 w-4" />
                            {t('leads.view_table')}
                        </Button>
                        <Button variant="secondary" onClick={() => setShowScoring(true)}>
                            <SlidersHorizontal className="mr-1.5 h-4 w-4" />
                            {t('leads.scoring_button')}
                        </Button>
                        <Button
                            variant="secondary"
                            onClick={() => router.post(route('client.leads.pipeline.rescore'), {}, { preserveScroll: true })}
                        >
                            <RefreshCw className="mr-1.5 h-4 w-4" />
                            {t('leads.rescore_button')}
                        </Button>
                        <Button onClick={() => setEditingStage({ isNew: true })}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            {t('leads.new_stage')}
                        </Button>
                    </div>
                </div>

                {flash.success && (
                    <div className="rounded-soft border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">
                        {flash.success}
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <form onSubmit={submitSearch} className="relative">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder={t('leads.search_placeholder')}
                            aria-label={t('leads.search_placeholder')}
                            className="w-56 rounded-soft border-soft bg-white py-1.5 pl-8 text-sm dark:bg-neutral-900 dark:text-neutral-100"
                        />
                    </form>

                    <div className="flex items-center gap-1">
                        <button
                            type="button"
                            onClick={() => applyFilter({ band: undefined, due: undefined })}
                            aria-pressed={!filters.band && !filters.due}
                            className={`rounded-soft px-2.5 py-1 text-xs font-medium transition-colors ${
                                !filters.band && !filters.due
                                    ? 'bg-brand-500 text-white'
                                    : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300'
                            }`}
                        >
                            {t('leads.filter_all')}
                        </button>
                        {BANDS.map((band) => (
                            <button
                                key={band}
                                type="button"
                                onClick={() => applyFilter({ band })}
                                aria-pressed={filters.band === band}
                                className={`rounded-soft px-2.5 py-1 text-xs font-medium transition-colors ${
                                    filters.band === band
                                        ? 'bg-brand-500 text-white'
                                        : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300'
                                }`}
                            >
                                {t(`leads.band_${band}`)}
                            </button>
                        ))}

                        <span className="mx-1 h-4 w-px bg-neutral-200 dark:bg-neutral-700" aria-hidden="true" />

                        <button
                            type="button"
                            onClick={() => applyFilter({ due: filters.due ? undefined : 1 })}
                            aria-pressed={!!filters.due}
                            className={`inline-flex items-center gap-1 rounded-soft px-2.5 py-1 text-xs font-medium transition-colors ${
                                filters.due
                                    ? 'bg-coral-500 text-white'
                                    : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300'
                            }`}
                        >
                            <AlarmClock className="h-3 w-3" />
                            {t('leads.filter_due')}
                        </button>
                    </div>
                </div>

                <DndContext
                    sensors={sensors}
                    collisionDetection={collisionDetectionStrategy}
                    onDragStart={handleDragStart}
                    onDragOver={handleDragOver}
                    onDragEnd={handleDragEnd}
                    onDragCancel={() => setActiveLead(null)}
                >
                    <div className="flex gap-4 overflow-x-auto pb-4">
                        {board.map((stage) => (
                            <StageColumn
                                key={stage.id}
                                stage={stage}
                                onOpenLead={openLead}
                                onEditStage={setEditingStage}
                            />
                        ))}
                    </div>

                    <DragOverlay>
                        {activeLead && <LeadCardView lead={activeLead} overlay />}
                    </DragOverlay>
                </DndContext>
            </div>

            <LeadDetailModal
                detail={leadDetail}
                stages={board}
                activityTypes={activityTypes}
                callOutcomes={callOutcomes}
                loading={loadingDetail}
                onClose={closeLead}
            />

            <StageModal
                key={editingStage?.id ?? 'new'}
                stage={editingStage}
                colors={colors}
                canDelete={board.length > 1}
                onClose={() => setEditingStage(null)}
            />

            {showScoring && (
                <ScoringModal show={showScoring} scoring={scoring} onClose={() => setShowScoring(false)} />
            )}

            {pendingCloseMove && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800">
                        <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                            {pendingCloseMove.stage.is_won
                                ? (t('leads.reason_won_title') || 'Reason for Won Deal')
                                : (t('leads.reason_lost_title') || 'Reason for Lost Deal')}
                        </h3>
                        <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                            {t('leads.reason_subtitle') || 'Select the main reason for this outcome (optional):'}
                        </p>

                        <div className="mt-4">
                            <select
                                value={selectedCloseReasonId}
                                onChange={(e) => setSelectedCloseReasonId(e.target.value)}
                                className="w-full rounded-soft border-soft bg-white py-2 px-3 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            >
                                <option value="">{t('common.select_reason') || '-- Select reason --'}</option>
                                {closeReasons
                                    .filter((r) => r.type === (pendingCloseMove.stage.is_won ? 'won' : 'lost'))
                                    .map((r) => (
                                        <option key={r.id} value={r.id}>
                                            {r.name}
                                        </option>
                                    ))}
                            </select>
                        </div>

                        <div className="mt-6 flex justify-end gap-2">
                            <Button
                                variant="secondary"
                                onClick={() => {
                                    // Move anyway without reason
                                    executeMove(pendingCloseMove.leadId, pendingCloseMove.stage.id, pendingCloseMove.position);
                                    setPendingCloseMove(null);
                                    setSelectedCloseReasonId('');
                                }}
                            >
                                {t('common.skip') || 'Skip'}
                            </Button>
                            <Button
                                onClick={() => {
                                    executeMove(
                                        pendingCloseMove.leadId,
                                        pendingCloseMove.stage.id,
                                        pendingCloseMove.position,
                                        selectedCloseReasonId ? Number(selectedCloseReasonId) : null
                                    );
                                    setPendingCloseMove(null);
                                    setSelectedCloseReasonId('');
                                }}
                            >
                                {t('common.confirm') || 'Confirm'}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </ClientLayout>
    );
}
