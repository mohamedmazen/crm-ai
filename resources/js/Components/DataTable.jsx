import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ArrowUp, ArrowDown, ChevronsUpDown, Columns3, Bookmark, ChevronDown, X } from 'lucide-react';
import Pagination from '@/Components/ui/Pagination';
import { TableSkeleton, ListSkeleton } from '@/Components/ui/Skeleton';

/**
 * Shared data table (Phase 3 — unified core screens).
 *
 * Every list screen renders through this component so that search, filters,
 * pagination, sorting, column management, bulk selection and the mobile card
 * view behave and look identical everywhere.
 *
 * Column visibility and saved views persist per `storageKey` in localStorage.
 *
 * Sorting is server-side when `onSortChange` is supplied; otherwise it sorts the
 * rows of the current page in place.
 */

const LS_PREFIX = 'crm.dt.';

function readStore(key, fallback) {
    if (typeof window === 'undefined') return fallback;
    try {
        const raw = window.localStorage.getItem(LS_PREFIX + key);
        const parsed = raw ? JSON.parse(raw) : null;
        return parsed ?? fallback;
    } catch {
        return fallback;
    }
}

function writeStore(key, value) {
    if (typeof window === 'undefined') return;
    try {
        window.localStorage.setItem(LS_PREFIX + key, JSON.stringify(value));
    } catch {
        /* ignore */
    }
}

function useOutsideClose(onClose) {
    const ref = useRef(null);
    useEffect(() => {
        const handler = (e) => {
            if (ref.current && !ref.current.contains(e.target)) onClose();
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [onClose]);
    return ref;
}

function CellValue({ column, row }) {
    if (typeof column.render === 'function') return column.render(row);
    const value = row[column.key];
    return value === null || value === undefined || value === '' ? '—' : String(value);
}

export default function DataTable({
    columns = [],
    rows = [],
    rowKey = 'id',
    pagination = null,
    selectable = false,
    selectedKeys = [],
    onSelectedKeysChange,
    onRowClick,
    emptyState = null,
    storageKey = null,
    sort = null,
    onSortChange,
    bulkActions = null,
    toolbar = null,
    columnsButton = true,
    stickyHeader = false,
    loading = false,
}) {
    const { t } = useTranslation();
    const getKey = (row) => (typeof rowKey === 'function' ? rowKey(row) : row[rowKey]);

    const [hidden, setHidden] = useState(() => (storageKey ? readStore(storageKey + '.cols', []) : []));
    const [views, setViews] = useState(() => (storageKey ? readStore(storageKey + '.views', {}) : {}));
    const [colsOpen, setColsOpen] = useState(false);
    const [localSort, setLocalSort] = useState(null);
    const [viewsOpen, setViewsOpen] = useState(false);
    const colRef = useOutsideClose(() => setColsOpen(false));
    const viewRef = useOutsideClose(() => setViewsOpen(false));

    useEffect(() => { if (storageKey) writeStore(storageKey + '.cols', hidden); }, [hidden, storageKey]);
    useEffect(() => { if (storageKey) writeStore(storageKey + '.views', views); }, [views, storageKey]);

    const visibleColumns = useMemo(() => columns.filter((c) => !hidden.includes(c.key)), [columns, hidden]);

    const effectiveSort = onSortChange ? sort : localSort;

    const sortedRows = useMemo(() => {
        if (onSortChange || !effectiveSort?.key) return rows;
        const { key, dir } = effectiveSort;
        const factor = dir === 'desc' ? -1 : 1;
        return [...rows].sort((a, b) => {
            const av = a[key];
            const bv = b[key];
            if (av === bv) return 0;
            if (av === null || av === undefined) return 1;
            if (bv === null || bv === undefined) return -1;
            if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * factor;
            return String(av).localeCompare(String(bv)) * factor;
        });
    }, [rows, effectiveSort, onSortChange]);

    const pageKeys = sortedRows.map(getKey);
    const selected = new Set(selectedKeys);
    const allSelected = pageKeys.length > 0 && pageKeys.every((k) => selected.has(k));

    const emitSelection = (next) => onSelectedKeysChange?.([...next]);

    const toggleAll = () => {
        const next = new Set(selected);
        if (allSelected) pageKeys.forEach((k) => next.delete(k));
        else pageKeys.forEach((k) => next.add(k));
        emitSelection(next);
    };

    const toggleOne = (key) => {
        const next = new Set(selected);
        if (next.has(key)) next.delete(key);
        else next.add(key);
        emitSelection(next);
    };

    const handleSort = (column) => {
        if (!column.sortable) return;
        const current = effectiveSort?.key === column.key ? effectiveSort.dir : null;
        const dir = current === 'asc' ? 'desc' : 'asc';
        if (onSortChange) onSortChange(column.key, dir);
        else setLocalSort({ key: column.key, dir });
    };

    const toggleColumn = (column) => {
        setHidden((prev) => (prev.includes(column.key) ? prev.filter((k) => k !== column.key) : [...prev, column.key]));
    };

    const saveView = () => {
        const name = window.prompt(t('dt.view_name_prompt', { defaultValue: 'Name this view' }));
        if (!name) return;
        setViews((prev) => ({ ...prev, [name]: { hidden, sort: effectiveSort ?? null } }));
    };

    const applyView = (name) => {
        const view = views[name];
        if (!view) return;
        setHidden(view.hidden ?? []);
        if (!onSortChange) setLocalSort(view.sort ?? null);
        else if (view.sort && onSortChange) onSortChange(view.sort.key, view.sort.dir);
        setViewsOpen(false);
    };

    const removeView = (name) => {
        setViews((prev) => {
            const next = { ...prev };
            delete next[name];
            return next;
        });
    };

    const sortIcon = (column) => {
        if (!column.sortable) return null;
        if (effectiveSort?.key !== column.key) return <ChevronsUpDown className="h-3 w-3 opacity-50" aria-hidden />;
        return effectiveSort.dir === 'asc'
            ? <ArrowUp className="h-3 w-3" aria-hidden />
            : <ArrowDown className="h-3 w-3" aria-hidden />;
    };

    const colSpan = visibleColumns.length + (selectable ? 1 : 0);
    const viewNames = Object.keys(views);

    const toolbarNode = (
        (columnsButton && columns.length > 1) || viewNames.length > 0 || storageKey
    ) ? (
        <div className="flex items-center justify-end gap-2">
            {storageKey && (
                <div className="relative" ref={viewRef}>
                    <button
                        type="button"
                        onClick={() => setViewsOpen((v) => !v)}
                        aria-expanded={viewsOpen}
                        className="inline-flex items-center gap-1.5 rounded-soft border border-neutral-200 px-2.5 py-1.5 text-xs font-medium text-neutral-600 transition hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    >
                        <Bookmark className="h-3.5 w-3.5" aria-hidden />
                        {t('dt.views', { defaultValue: 'Views' })}
                        <ChevronDown className="h-3 w-3" aria-hidden />
                    </button>
                    {viewsOpen && (
                        <div className="absolute end-0 z-30 mt-1 w-56 rounded-lg border border-neutral-200 bg-white py-1 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                            {viewNames.length === 0 && (
                                <p className="px-3 py-2 text-xs text-neutral-500 dark:text-neutral-400">
                                    {t('dt.no_views', { defaultValue: 'No saved views yet' })}
                                </p>
                            )}
                            {viewNames.map((name) => (
                                <div key={name} className="flex items-center">
                                    <button
                                        type="button"
                                        onClick={() => applyView(name)}
                                        className="flex-1 px-3 py-1.5 text-start text-sm text-neutral-700 hover:bg-neutral-50 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    >
                                        {name}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => removeView(name)}
                                        aria-label={t('dt.delete_view', { defaultValue: 'Delete view' })}
                                        className="me-2 rounded p-1 text-neutral-500 hover:bg-neutral-100 hover:text-red-600 dark:text-neutral-400 dark:hover:bg-neutral-800"
                                    >
                                        <X className="h-3.5 w-3.5" aria-hidden />
                                    </button>
                                </div>
                            ))}
                            <button
                                type="button"
                                onClick={saveView}
                                className="mt-1 w-full border-t border-neutral-100 px-3 py-1.5 text-start text-sm font-medium text-brand-600 hover:bg-brand-50 dark:border-neutral-800 dark:text-brand-400 dark:hover:bg-brand-900/20"
                            >
                                + {t('dt.save_view', { defaultValue: 'Save current view' })}
                            </button>
                        </div>
                    )}
                </div>
            )}

            {columnsButton && columns.length > 1 && (
                <div className="relative" ref={colRef}>
                    <button
                        type="button"
                        onClick={() => setColsOpen((v) => !v)}
                        aria-expanded={colsOpen}
                        className="inline-flex items-center gap-1.5 rounded-soft border border-neutral-200 px-2.5 py-1.5 text-xs font-medium text-neutral-600 transition hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    >
                        <Columns3 className="h-3.5 w-3.5" aria-hidden />
                        {t('dt.columns', { defaultValue: 'Columns' })}
                    </button>
                    {colsOpen && (
                        <div className="absolute end-0 z-30 mt-1 w-56 rounded-lg border border-neutral-200 bg-white py-1 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                            {columns.map((column) => (
                                <label key={column.key} className="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm text-neutral-700 hover:bg-neutral-50 dark:text-neutral-200 dark:hover:bg-neutral-800">
                                    <input
                                        type="checkbox"
                                        checked={!hidden.includes(column.key)}
                                        disabled={column.alwaysVisible}
                                        onChange={() => toggleColumn(column)}
                                        className="h-3.5 w-3.5 rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600"
                                    />
                                    {column.header}
                                </label>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    ) : null;

    return (
        <div className="rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
            {(toolbar || toolbarNode) && (
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 px-4 py-2.5 dark:border-neutral-800">
                    <div className="min-w-0 flex-1">{toolbar}</div>
                    {toolbarNode}
                </div>
            )}

            {bulkActions && selected.size > 0 && (
                <div className="border-b border-neutral-100 bg-neutral-50 px-4 py-2.5 dark:border-neutral-800 dark:bg-neutral-800/40">
                    {bulkActions}
                </div>
            )}

            {/* Desktop table */}
            <div className="hidden overflow-x-auto md:block" aria-busy={loading || undefined}>
                {loading ? (
                    <TableSkeleton rows={6} columns={Math.max(1, visibleColumns.length + (selectable ? 1 : 0))} />
                ) : (
                <table className="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                    <thead className={`bg-neutral-50 dark:bg-neutral-800 ${stickyHeader ? 'sticky top-0 z-10' : ''}`}>
                        <tr>
                            {selectable && (
                                <th scope="col" className="w-10 px-4 py-3">
                                    <input
                                        type="checkbox"
                                        checked={allSelected}
                                        onChange={toggleAll}
                                        aria-label={t('dt.select_all', { defaultValue: 'Select all rows' })}
                                        className="h-4 w-4 rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600"
                                    />
                                </th>
                            )}
                            {visibleColumns.map((column) => (
                                <th
                                    key={column.key}
                                    scope="col"
                                    aria-sort={effectiveSort?.key === column.key ? (effectiveSort.dir === 'asc' ? 'ascending' : 'descending') : undefined}
                                    className={[
                                        'px-4 py-3 text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400',
                                        column.align === 'right' ? 'text-right' : column.align === 'center' ? 'text-center' : 'text-left',
                                        column.headerClassName ?? '',
                                    ].join(' ')}
                                >
                                    {column.sortable ? (
                                        <button
                                            type="button"
                                            onClick={() => handleSort(column)}
                                            className="inline-flex items-center gap-1 font-semibold uppercase tracking-wider hover:text-neutral-800 dark:hover:text-neutral-200"
                                        >
                                            {column.header}
                                            {sortIcon(column)}
                                        </button>
                                    ) : (
                                        column.header
                                    )}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                        {sortedRows.map((row) => {
                            const key = getKey(row);
                            const isSelected = selected.has(key);
                            return (
                                <tr
                                    key={key}
                                    onClick={onRowClick ? () => onRowClick(row) : undefined}
                                    className={[
                                        'transition-colors',
                                        isSelected ? 'bg-brand-50 dark:bg-brand-900/10' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800/50',
                                        onRowClick ? 'cursor-pointer' : '',
                                    ].join(' ')}
                                >
                                    {selectable && (
                                        <td className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
                                            <input
                                                type="checkbox"
                                                checked={isSelected}
                                                onChange={() => toggleOne(key)}
                                                aria-label={t('dt.select_row', { defaultValue: 'Select row' })}
                                                className="h-4 w-4 rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600"
                                            />
                                        </td>
                                    )}
                                    {visibleColumns.map((column) => (
                                        <td
                                            key={column.key}
                                            className={[
                                                'px-4 py-3 text-neutral-700 dark:text-neutral-300',
                                                column.align === 'right' ? 'text-right' : column.align === 'center' ? 'text-center' : 'text-left',
                                                column.className ?? '',
                                            ].join(' ')}
                                        >
                                            <CellValue column={column} row={row} />
                                        </td>
                                    ))}
                                </tr>
                            );
                        })}
                        {sortedRows.length === 0 && (
                            <tr>
                                <td colSpan={colSpan}>
                                    {emptyState ?? (
                                        <p className="px-4 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                                            {t('dt.empty', { defaultValue: 'Nothing here yet.' })}
                                        </p>
                                    )}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
                )}
            </div>

            {/* Mobile cards */}
            <div className="divide-y divide-neutral-100 dark:divide-neutral-800 md:hidden" aria-busy={loading || undefined}>
                {loading ? (
                    <ListSkeleton rows={6} />
                ) : (
                <>
                {sortedRows.map((row) => {
                    const key = getKey(row);
                    const isSelected = selected.has(key);
                    const titleColumn = visibleColumns.find((c) => c.cardTitle) ?? visibleColumns[0];
                    const detailColumns = visibleColumns.filter((c) => c !== titleColumn && !c.hideInCard);
                    return (
                        <div
                            key={key}
                            onClick={onRowClick ? () => onRowClick(row) : undefined}
                            className={[
                                'px-4 py-3',
                                isSelected ? 'bg-brand-50 dark:bg-brand-900/10' : '',
                                onRowClick ? 'cursor-pointer' : '',
                            ].join(' ')}
                        >
                            <div className="flex items-start gap-3">
                                {selectable && (
                                    <input
                                        type="checkbox"
                                        checked={isSelected}
                                        onChange={() => toggleOne(key)}
                                        onClick={(e) => e.stopPropagation()}
                                        aria-label={t('dt.select_row', { defaultValue: 'Select row' })}
                                        className="mt-1 h-4 w-4 rounded border-neutral-300 text-brand-600 focus:ring-brand-500 dark:border-neutral-600"
                                    />
                                )}
                                <div className="min-w-0 flex-1">
                                    <div className="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                        <CellValue column={titleColumn} row={row} />
                                    </div>
                                    <dl className="mt-1.5 space-y-0.5">
                                        {detailColumns.map((column) => (
                                            <div key={column.key} className="flex items-start gap-2 text-xs">
                                                <dt className="shrink-0 text-neutral-500 dark:text-neutral-400">{column.header}</dt>
                                                <dd className="min-w-0 flex-1 text-neutral-700 dark:text-neutral-300">
                                                    <CellValue column={column} row={row} />
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </div>
                            </div>
                        </div>
                    );
                })}
                {sortedRows.length === 0 && (
                    <div>{emptyState ?? (
                        <p className="px-4 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                            {t('dt.empty', { defaultValue: 'Nothing here yet.' })}
                        </p>
                    )}</div>
                )}
                </>
                )}
            </div>

            {pagination && <Pagination data={pagination} className="px-4" />}
        </div>
    );
}
