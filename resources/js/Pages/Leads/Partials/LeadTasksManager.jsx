import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/ui/Button';
import {
    Plus, Trash2, CheckCircle2, Circle, Clock, AlertCircle,
    Phone, Mail, MessageCircle, Users, CheckSquare, Calendar, User
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

export default function LeadTasksManager({ lead, tasks = [], teamMembers = [] }) {
    const { t } = useTranslation();
    const [title, setTitle] = useState('');
    const [type, setType] = useState('todo');
    const [priority, setPriority] = useState('medium');
    const [dueAt, setDueAt] = useState('');
    const [assignedUserId, setAssignedUserId] = useState(lead.assigned_user_id ?? '');
    const [submitting, setSubmitting] = useState(false);

    const handleCreateTask = (e) => {
        e.preventDefault();
        if (!title.trim()) return;

        setSubmitting(true);
        router.post(
            route('client.leads.tasks.store', lead.id),
            {
                title: title.trim(),
                type,
                priority,
                due_at: dueAt || null,
                assigned_user_id: assignedUserId ? Number(assignedUserId) : null,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setTitle('');
                    setDueAt('');
                },
                onFinish: () => setSubmitting(false),
            }
        );
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

    return (
        <div className="space-y-6">
            {/* Quick Add Task Form */}
            <form onSubmit={handleCreateTask} className="rounded-xl border border-neutral-200 bg-neutral-50/60 p-4 dark:border-neutral-800 dark:bg-neutral-800/30">
                <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100 mb-3 flex items-center gap-2">
                    <CheckSquare className="h-4 w-4 text-brand-600" />
                    {t('leads.add_task') || 'Add Follow-up Task'}
                </h3>

                <div className="space-y-3">
                    <div>
                        <input
                            type="text"
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            placeholder={t('leads.task_title_placeholder') || 'What needs to be done? (e.g. Call to discuss pricing)'}
                            className="w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100 focus:border-brand-500 focus:ring-1 focus:ring-brand-500"
                        />
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-4 gap-2">
                        <div>
                            <select
                                value={type}
                                onChange={(e) => setType(e.target.value)}
                                className="w-full rounded-soft border-soft bg-white py-1.5 px-2.5 text-xs dark:bg-neutral-800 dark:text-neutral-200"
                            >
                                <option value="todo">{t('leads.task_todo') || 'To-Do'}</option>
                                <option value="call">{t('leads.task_call') || 'Phone Call'}</option>
                                <option value="whatsapp">{t('leads.task_whatsapp') || 'WhatsApp'}</option>
                                <option value="email">{t('leads.task_email') || 'Email'}</option>
                                <option value="meeting">{t('leads.task_meeting') || 'Meeting'}</option>
                            </select>
                        </div>

                        <div>
                            <select
                                value={priority}
                                onChange={(e) => setPriority(e.target.value)}
                                className="w-full rounded-soft border-soft bg-white py-1.5 px-2.5 text-xs dark:bg-neutral-800 dark:text-neutral-200"
                            >
                                <option value="medium">{t('leads.priority_medium') || 'Medium Priority'}</option>
                                <option value="high">{t('leads.priority_high') || 'High Priority'}</option>
                                <option value="low">{t('leads.priority_low') || 'Low Priority'}</option>
                            </select>
                        </div>

                        <div>
                            <input
                                type="datetime-local"
                                value={dueAt}
                                onChange={(e) => setDueAt(e.target.value)}
                                className="w-full rounded-soft border-soft bg-white py-1.5 px-2 text-xs dark:bg-neutral-800 dark:text-neutral-200"
                            />
                        </div>

                        <div>
                            <select
                                value={assignedUserId}
                                onChange={(e) => setAssignedUserId(e.target.value)}
                                className="w-full rounded-soft border-soft bg-white py-1.5 px-2 text-xs dark:bg-neutral-800 dark:text-neutral-200"
                            >
                                <option value="">{t('leads.assign_to') || 'Assignee...'}</option>
                                {teamMembers.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="flex justify-end pt-1">
                        <Button size="sm" type="submit" disabled={submitting || !title.trim()}>
                            <Plus className="mr-1 h-3.5 w-3.5" />
                            {t('leads.create_task') || 'Add Task'}
                        </Button>
                    </div>
                </div>
            </form>

            {/* Tasks List */}
            <div className="space-y-2">
                <h4 className="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">
                    {t('leads.tasks_list') || 'Tasks'} ({tasks.length})
                </h4>

                {tasks.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-neutral-200 p-8 text-center dark:border-neutral-800">
                        <CheckSquare className="mx-auto h-8 w-8 text-neutral-300 dark:text-neutral-600" />
                        <p className="mt-2 text-sm text-neutral-500 dark:text-neutral-400">
                            {t('leads.no_tasks_yet') || 'No tasks scheduled yet for this lead.'}
                        </p>
                    </div>
                ) : (
                    <div className="divide-y divide-neutral-100 rounded-xl border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
                        {tasks.map((task) => {
                            const Icon = TYPE_ICONS[task.type] ?? CheckSquare;
                            const isOverdue = task.is_overdue;

                            return (
                                <div
                                    key={task.id}
                                    className={`flex items-start justify-between gap-3 p-3.5 transition-colors ${
                                        task.is_completed ? 'bg-neutral-50/50 opacity-60 dark:bg-neutral-800/20' : 'hover:bg-neutral-50/70 dark:hover:bg-neutral-800/40'
                                    }`}
                                >
                                    <div className="flex items-start gap-3 min-w-0">
                                        <button
                                            type="button"
                                            onClick={() => handleToggleTask(task)}
                                            className="mt-0.5 text-neutral-400 hover:text-brand-600 dark:hover:text-brand-400 focus:outline-none"
                                            title={task.is_completed ? (t('leads.mark_incomplete') || 'Mark incomplete') : (t('leads.mark_complete') || 'Mark complete')}
                                        >
                                            {task.is_completed ? (
                                                <CheckCircle2 className="h-5 w-5 text-emerald-500" />
                                            ) : (
                                                <Circle className="h-5 w-5 hover:text-brand-500" />
                                            )}
                                        </button>

                                        <div className="min-w-0">
                                            <p className={`text-sm font-medium ${task.is_completed ? 'line-through text-neutral-400 dark:text-neutral-500' : 'text-neutral-900 dark:text-neutral-100'}`}>
                                                {task.title}
                                            </p>

                                            <div className="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                                <span className="inline-flex items-center gap-1 rounded bg-neutral-100 px-1.5 py-0.5 text-[11px] text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                                    <Icon className="h-3 w-3" />
                                                    {task.type}
                                                </span>

                                                <span className={`inline-flex items-center rounded border px-1.5 py-0.2 text-[10px] font-medium uppercase ${PRIORITY_BADGES[task.priority] || PRIORITY_BADGES.medium}`}>
                                                    {task.priority}
                                                </span>

                                                {task.due_at && (
                                                    <span className={`inline-flex items-center gap-1 text-[11px] font-medium ${isOverdue ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-neutral-500 dark:text-neutral-400'}`}>
                                                        {isOverdue && <AlertCircle className="h-3 w-3 text-red-500" />}
                                                        <Clock className="h-3 w-3" />
                                                        {new Date(task.due_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}
                                                    </span>
                                                )}

                                                {task.assigned_user && (
                                                    <span className="inline-flex items-center gap-1 text-[11px] text-neutral-500 dark:text-neutral-400">
                                                        <User className="h-3 w-3" />
                                                        {task.assigned_user.name}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        onClick={() => handleDeleteTask(task)}
                                        className="rounded p-1 text-neutral-400 hover:bg-neutral-100 hover:text-red-600 dark:hover:bg-neutral-800"
                                        title={t('common.delete') || 'Delete'}
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </button>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}
