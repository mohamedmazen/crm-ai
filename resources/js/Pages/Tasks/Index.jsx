import { useState } from 'react';
import { Head, router, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import {
    CheckSquare, Circle, CheckCircle2, Clock, AlertCircle,
    Search, Filter, Trash2, Phone, Mail, MessageCircle, Users,
    ArrowUpRight, User, Calendar
} from 'lucide-react';

const TYPE_ICONS = {
    call: Phone,
    email: Mail,
    whatsapp: MessageCircle,
    meeting: Users,
    todo: CheckSquare,
};

const PRIORITY_BADGES = {
    high: 'bg-red-50 text-red-700 border-red-200 dark:bg-red-950/40 dark:text-red-400 dark:border-red-900',
    medium: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-400 dark:border-amber-900',
    low: 'bg-neutral-50 text-neutral-600 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-400 dark:border-neutral-700',
};

export default function TasksIndex({
    tasks,
    filters = {},
    counts = {},
    teamMembers = [],
    types = [],
    priorities = [],
}) {
    const { t } = useTranslation();
    const { flash } = usePage().props;

    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilter = (overrides) => {
        const next = { ...filters, ...overrides };
        Object.keys(next).forEach((key) => {
            if (next[key] === undefined || next[key] === null || next[key] === '') {
                delete next[key];
            }
        });

        router.get(route('client.tasks.index'), next, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        applyFilter({ search: search.trim() || undefined });
    };

    const handleToggleTask = (task) => {
        router.patch(
            route('client.leads.tasks.toggle', task.id),
            {},
            { preserveScroll: true, preserveState: true }
        );
    };

    const handleDeleteTask = (task) => {
        if (!confirm(t('leads.confirm_delete_task') || `Delete task "${task.title}"?`)) {
            return;
        }

        router.delete(
            route('client.leads.tasks.destroy', task.id),
            { preserveScroll: true, preserveState: true }
        );
    };

    const tabs = [
        { key: 'today', label: t('tasks.tab_today') || 'Today', count: counts.today ?? 0 },
        { key: 'overdue', label: t('tasks.tab_overdue') || 'Overdue', count: counts.overdue ?? 0, alert: (counts.overdue ?? 0) > 0 },
        { key: 'upcoming', label: t('tasks.tab_upcoming') || 'Upcoming', count: counts.upcoming ?? 0 },
        { key: 'all', label: t('tasks.tab_all_pending') || 'All Open', count: counts.all ?? 0 },
        { key: 'completed', label: t('tasks.tab_completed') || 'Completed', count: counts.completed ?? 0 },
    ];

    const currentStatus = filters.status ?? 'today';

    return (
        <ClientLayout title={t('tasks.title') || 'Tasks & Follow-ups'}>
            <Head title={t('tasks.title') || 'Tasks & Follow-ups'} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                            <CheckSquare className="h-5 w-5 text-brand-600" />
                            {t('tasks.title') || 'Tasks & Follow-ups'}
                        </h1>
                        <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                            {t('tasks.subtitle') || 'Manage follow-ups, calls, and action items across your pipeline.'}
                        </p>
                    </div>

                    <Button variant="secondary" onClick={() => router.visit(route('client.leads.pipeline.index'))}>
                        {t('leads.view_pipeline') || 'View Pipeline Board'}
                    </Button>
                </div>

                {flash?.success && (
                    <div className="rounded-soft border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">
                        {flash.success}
                    </div>
                )}

                {/* Status Tabs */}
                <div className="flex flex-wrap items-center gap-2 border-b border-neutral-200 dark:border-neutral-800 pb-3">
                    {tabs.map((tab) => {
                        const isActive = currentStatus === tab.key;
                        return (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => applyFilter({ status: tab.key })}
                                className={`inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-medium transition-all ${
                                    isActive
                                        ? 'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900 shadow-sm'
                                        : 'bg-white text-neutral-600 hover:bg-neutral-100 dark:bg-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800 border border-neutral-200/80 dark:border-neutral-800'
                                }`}
                            >
                                <span>{tab.label}</span>
                                <span
                                    className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                                        isActive
                                            ? 'bg-neutral-800 text-neutral-200 dark:bg-neutral-200 dark:text-neutral-800'
                                            : tab.alert
                                            ? 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-400'
                                            : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'
                                    }`}
                                >
                                    {tab.count}
                                </span>
                            </button>
                        );
                    })}
                </div>

                {/* Filter & Search Bar */}
                <div className="flex flex-wrap items-center justify-between gap-3 bg-white p-3.5 rounded-2xl border border-neutral-200 dark:bg-neutral-900 dark:border-neutral-800">
                    <form onSubmit={handleSearchSubmit} className="relative flex-1 min-w-[220px]">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder={t('tasks.search_placeholder') || 'Search tasks or leads...'}
                            className="w-full rounded-soft border-soft bg-white py-1.5 pl-9 text-sm dark:bg-neutral-800 dark:text-neutral-100 focus:border-brand-500 focus:ring-1 focus:ring-brand-500"
                        />
                    </form>

                    <div className="flex flex-wrap items-center gap-2">
                        {/* Assignee Filter */}
                        <select
                            value={filters.assigned ?? 'me'}
                            onChange={(e) => applyFilter({ assigned: e.target.value })}
                            className="rounded-soft border-soft bg-white py-1.5 px-3 text-xs font-medium dark:bg-neutral-800 dark:text-neutral-200"
                        >
                            <option value="me">{t('tasks.my_tasks') || 'My Tasks'}</option>
                            <option value="all">{t('tasks.all_assignees') || 'All Assignees'}</option>
                            <option value="unassigned">{t('tasks.unassigned') || 'Unassigned'}</option>
                            {teamMembers.length > 0 && (
                                <optgroup label={t('leads.team_members') || 'Team Members'}>
                                    {teamMembers.map((m) => (
                                        <option key={m.id} value={m.id}>
                                            {m.name}
                                        </option>
                                    ))}
                                </optgroup>
                            )}
                        </select>

                        {/* Priority Filter */}
                        <select
                            value={filters.priority ?? ''}
                            onChange={(e) => applyFilter({ priority: e.target.value || undefined })}
                            className="rounded-soft border-soft bg-white py-1.5 px-3 text-xs font-medium dark:bg-neutral-800 dark:text-neutral-200"
                        >
                            <option value="">{t('tasks.all_priorities') || 'All Priorities'}</option>
                            <option value="high">{t('leads.priority_high') || 'High Priority'}</option>
                            <option value="medium">{t('leads.priority_medium') || 'Medium Priority'}</option>
                            <option value="low">{t('leads.priority_low') || 'Low Priority'}</option>
                        </select>

                        {/* Type Filter */}
                        <select
                            value={filters.type ?? ''}
                            onChange={(e) => applyFilter({ type: e.target.value || undefined })}
                            className="rounded-soft border-soft bg-white py-1.5 px-3 text-xs font-medium dark:bg-neutral-800 dark:text-neutral-200"
                        >
                            <option value="">{t('tasks.all_types') || 'All Types'}</option>
                            <option value="todo">{t('leads.task_todo') || 'To-Do'}</option>
                            <option value="call">{t('leads.task_call') || 'Phone Call'}</option>
                            <option value="whatsapp">{t('leads.task_whatsapp') || 'WhatsApp'}</option>
                            <option value="email">{t('leads.task_email') || 'Email'}</option>
                            <option value="meeting">{t('leads.task_meeting') || 'Meeting'}</option>
                        </select>
                    </div>
                </div>

                {/* Tasks List */}
                <div className="rounded-2xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900 overflow-hidden">
                    {tasks.data.length === 0 ? (
                        <div className="p-12 text-center">
                            <CheckSquare className="mx-auto h-10 w-10 text-neutral-300 dark:text-neutral-600" />
                            <h3 className="mt-3 text-sm font-semibold text-neutral-800 dark:text-neutral-200">
                                {t('tasks.empty_title') || 'No tasks found'}
                            </h3>
                            <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                                {t('tasks.empty_subtitle') || 'There are no tasks matching your selected filters.'}
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y divide-neutral-100 dark:divide-neutral-800">
                            {tasks.data.map((task) => {
                                const Icon = TYPE_ICONS[task.type] ?? CheckSquare;
                                const isCompleted = !!task.completed_at;
                                const isOverdue = !isCompleted && task.due_at && new Date(task.due_at) < new Date();

                                return (
                                    <div
                                        key={task.id}
                                        className={`flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 transition-colors ${
                                            isCompleted
                                                ? 'bg-neutral-50/50 opacity-60 dark:bg-neutral-800/20'
                                                : isOverdue
                                                ? 'bg-red-50/30 dark:bg-red-950/10 hover:bg-red-50/50'
                                                : 'hover:bg-neutral-50/70 dark:hover:bg-neutral-800/40'
                                        }`}
                                    >
                                        <div className="flex items-start gap-3 min-w-0 flex-1">
                                            <button
                                                type="button"
                                                onClick={() => handleToggleTask(task)}
                                                className="mt-0.5 text-neutral-400 hover:text-brand-600 dark:hover:text-brand-400 focus:outline-none"
                                                title={isCompleted ? (t('leads.mark_incomplete') || 'Mark incomplete') : (t('leads.mark_complete') || 'Mark complete')}
                                            >
                                                {isCompleted ? (
                                                    <CheckCircle2 className="h-5 w-5 text-emerald-500" />
                                                ) : (
                                                    <Circle className="h-5 w-5 hover:text-brand-500" />
                                                )}
                                            </button>

                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className={`text-sm font-medium ${isCompleted ? 'line-through text-neutral-400 dark:text-neutral-500' : 'text-neutral-900 dark:text-neutral-100'}`}>
                                                        {task.title}
                                                    </span>

                                                    <span className="inline-flex items-center gap-1 rounded bg-neutral-100 px-2 py-0.5 text-[11px] font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                                        <Icon className="h-3 w-3" />
                                                        {task.type}
                                                    </span>

                                                    <span className={`inline-flex items-center rounded border px-1.5 py-0.2 text-[10px] font-medium uppercase ${PRIORITY_BADGES[task.priority] || PRIORITY_BADGES.medium}`}>
                                                        {task.priority}
                                                    </span>
                                                </div>

                                                {task.description && (
                                                    <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400 line-clamp-2">
                                                        {task.description}
                                                    </p>
                                                )}

                                                <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-neutral-500 dark:text-neutral-400">
                                                    {task.lead && (
                                                        <Link
                                                            href={route('client.leads.pipeline.index', { lead: task.lead.id })}
                                                            className="inline-flex items-center gap-1 font-medium text-brand-600 hover:underline dark:text-brand-400"
                                                        >
                                                            <span>{task.lead.name}</span>
                                                            <ArrowUpRight className="h-3 w-3" />
                                                        </Link>
                                                    )}

                                                    {task.due_at && (
                                                        <span className={`inline-flex items-center gap-1 font-medium ${isOverdue ? 'text-red-600 dark:text-red-400 font-semibold' : ''}`}>
                                                            {isOverdue && <AlertCircle className="h-3.5 w-3.5 text-red-500" />}
                                                            <Clock className="h-3.5 w-3.5" />
                                                            {new Date(task.due_at).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })}
                                                        </span>
                                                    )}

                                                    {task.assigned_user && (
                                                        <span className="inline-flex items-center gap-1">
                                                            <User className="h-3.5 w-3.5" />
                                                            {task.assigned_user.name}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        <div className="flex items-center justify-end gap-2 shrink-0 self-end sm:self-center">
                                            <button
                                                type="button"
                                                onClick={() => handleDeleteTask(task)}
                                                className="rounded p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-red-600 dark:hover:bg-neutral-800"
                                                title={t('common.delete') || 'Delete'}
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {/* Pagination links */}
                    {tasks.links && tasks.links.length > 3 && (
                        <div className="flex items-center justify-between border-t border-neutral-100 px-4 py-3 dark:border-neutral-800">
                            <div className="text-xs text-neutral-500">
                                {t('common.showing_results', { from: tasks.from, to: tasks.to, total: tasks.total }) || `Showing ${tasks.from}-${tasks.to} of ${tasks.total}`}
                            </div>
                            <div className="flex gap-1">
                                {tasks.links.map((link, i) => (
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
        </ClientLayout>
    );
}
