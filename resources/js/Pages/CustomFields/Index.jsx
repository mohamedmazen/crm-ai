import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import Modal from '@/Components/ui/Modal';
import {
    Sliders, Plus, Trash2, Edit2, CheckCircle2,
    Layers, AlertCircle, Check, ArrowRight
} from 'lucide-react';

export default function CustomFieldsIndex({
    fields = [],
    currentEntityType = 'lead',
    entityTypes = ['lead', 'company', 'contact'],
    availableTypes = ['text', 'number', 'date', 'select', 'multiselect', 'checkbox', 'url'],
    usage = { current: 0, limit: null, unlimited: true },
}) {
    const { t } = useTranslation();
    const { flash } = usePage().props;

    const [showCreateModal, setShowCreateModal] = useState(false);
    const [editingField, setEditingField] = useState(null);

    // Form state
    const [label, setLabel] = useState('');
    const [key, setKey] = useState('');
    const [type, setType] = useState('text');
    const [options, setOptions] = useState('');
    const [required, setRequired] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const switchEntityType = (eType) => {
        router.get(route('client.custom-fields.index'), { entity_type: eType }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleCreate = (e) => {
        e.preventDefault();
        if (!label.trim()) return;

        setSubmitting(true);
        const optionsList = inArray(type, ['select', 'multiselect'])
            ? options.split(',').map(s => s.trim()).filter(Boolean)
            : [];

        router.post(
            route('client.custom-fields.store'),
            {
                entity_type: currentEntityType,
                label: label.trim(),
                key: key.trim() || undefined,
                type,
                options: optionsList,
                required,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShowCreateModal(false);
                    setLabel('');
                    setKey('');
                    setOptions('');
                    setRequired(false);
                },
                onFinish: () => setSubmitting(false),
            }
        );
    };

    const handleUpdate = (e) => {
        e.preventDefault();
        if (!editingField || !editingField.label.trim()) return;

        setSubmitting(true);
        const optionsList = inArray(editingField.type, ['select', 'multiselect'])
            ? (typeof editingField.optionsText === 'string'
                ? editingField.optionsText.split(',').map(s => s.trim()).filter(Boolean)
                : editingField.options ?? [])
            : [];

        router.put(
            route('client.custom-fields.update', editingField.id),
            {
                label: editingField.label.trim(),
                options: optionsList,
                required: editingField.required,
            },
            {
                preserveScroll: true,
                onSuccess: () => setEditingField(null),
                onFinish: () => setSubmitting(false),
            }
        );
    };

    const handleDelete = (field) => {
        if (!confirm(t('custom_fields.confirm_delete', { name: field.label }) || `Delete field "${field.label}" and all its saved values?`)) {
            return;
        }

        router.delete(route('client.custom-fields.destroy', field.id), {
            preserveScroll: true,
        });
    };

    const inArray = (val, arr) => arr.indexOf(val) !== -1;

    return (
        <ClientLayout title={t('custom_fields.title') || 'Custom Fields'}>
            <Head title={t('custom_fields.title') || 'Custom Fields'} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                            <Sliders className="h-5 w-5 text-brand-600" />
                            {t('custom_fields.title') || 'Custom Fields'}
                        </h1>
                        <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                            {t('custom_fields.subtitle') || 'Create structured custom attributes for your Leads, Companies, and Contacts.'}
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        {/* Usage Pill */}
                        <div className="rounded-full bg-neutral-100 px-3 py-1 text-xs font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                            {usage.unlimited ? (
                                <span>{t('custom_fields.unlimited') || 'Unlimited fields (Business)'}</span>
                            ) : (
                                <span>{usage.current} / {usage.limit} {t('custom_fields.used') || 'fields used'}</span>
                            )}
                        </div>

                        <Button onClick={() => setShowCreateModal(true)}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            {t('custom_fields.add_field') || 'New Custom Field'}
                        </Button>
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-soft border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">
                        {flash.success}
                    </div>
                )}

                {/* Entity Type Tabs */}
                <div className="flex items-center gap-2 border-b border-neutral-200 dark:border-neutral-800 pb-3">
                    {entityTypes.map((eType) => {
                        const isActive = currentEntityType === eType;
                        return (
                            <button
                                key={eType}
                                type="button"
                                onClick={() => switchEntityType(eType)}
                                className={`rounded-xl px-4 py-2 text-sm font-medium capitalize transition-all ${
                                    isActive
                                        ? 'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900 shadow-sm'
                                        : 'bg-white text-neutral-600 hover:bg-neutral-100 dark:bg-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800 border border-neutral-200/80 dark:border-neutral-800'
                                }`}
                            >
                                {eType === 'lead' ? (t('leads.title') || 'Leads') : eType === 'company' ? (t('companies.title') || 'Companies') : (t('contacts.title') || 'Contacts')}
                            </button>
                        );
                    })}
                </div>

                {/* Fields Table */}
                <div className="rounded-2xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900 overflow-hidden">
                    {fields.length === 0 ? (
                        <div className="p-12 text-center">
                            <Sliders className="mx-auto h-10 w-10 text-neutral-300 dark:text-neutral-600" />
                            <h3 className="mt-3 text-sm font-semibold text-neutral-800 dark:text-neutral-200">
                                {t('custom_fields.empty_title') || 'No custom fields yet'}
                            </h3>
                            <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                                {t('custom_fields.empty_subtitle') || `Add fields to capture unique attributes for your ${currentEntityType} records.`}
                            </p>
                            <div className="mt-4">
                                <Button size="sm" onClick={() => setShowCreateModal(true)}>
                                    <Plus className="mr-1 h-3.5 w-3.5" />
                                    {t('custom_fields.add_first') || 'Add Field'}
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <table className="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700 text-sm">
                            <thead className="bg-neutral-50 dark:bg-neutral-800/80">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{t('custom_fields.label') || 'Field Label'}</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{t('custom_fields.key') || 'System Key'}</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{t('custom_fields.type') || 'Type'}</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{t('custom_fields.options') || 'Options / Validation'}</th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-neutral-500">{t('common.actions')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                {fields.map((field) => (
                                    <tr key={field.id} className="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                        <td className="px-4 py-3 font-medium text-neutral-900 dark:text-neutral-100">
                                            <div className="flex items-center gap-2">
                                                <span>{field.label}</span>
                                                {field.required && (
                                                    <span className="rounded bg-red-100 px-1.5 py-0.2 text-[10px] font-semibold text-red-700 dark:bg-red-950/40 dark:text-red-400">
                                                        {t('common.required') || 'Required'}
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-xs font-mono text-neutral-500 dark:text-neutral-400">
                                            {field.key}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                                {field.type}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-neutral-500 dark:text-neutral-400">
                                            {field.options && field.options.length > 0 ? (
                                                <div className="flex flex-wrap gap-1">
                                                    {field.options.map((opt, i) => (
                                                        <span key={i} className="rounded bg-neutral-100 px-1.5 py-0.2 text-[11px] dark:bg-neutral-800">
                                                            {opt}
                                                        </span>
                                                    ))}
                                                </div>
                                            ) : (
                                                <span>—</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => setEditingField({ ...field, optionsText: field.options?.join(', ') ?? '' })}
                                                    className="rounded p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800"
                                                    title={t('common.edit')}
                                                >
                                                    <Edit2 className="h-4 w-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => handleDelete(field)}
                                                    className="rounded p-1 text-neutral-400 hover:bg-neutral-100 hover:text-red-600 dark:hover:bg-neutral-800"
                                                    title={t('common.delete')}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>

            {/* Create Field Modal */}
            {showCreateModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white dark:bg-neutral-900 p-6 shadow-xl space-y-4">
                        <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                            {t('custom_fields.new_title', { entity: currentEntityType }) || `New Custom Field for ${currentEntityType}`}
                        </h3>

                        <form onSubmit={handleCreate} className="space-y-3.5">
                            <div>
                                <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300">
                                    {t('custom_fields.label') || 'Field Label'} *
                                </label>
                                <input
                                    type="text"
                                    value={label}
                                    onChange={(e) => setLabel(e.target.value)}
                                    placeholder="e.g. Budget Range, Decision Maker"
                                    required
                                    className="mt-1 w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300">
                                    {t('custom_fields.key') || 'Field Key (optional)'}
                                </label>
                                <input
                                    type="text"
                                    value={key}
                                    onChange={(e) => setKey(e.target.value)}
                                    placeholder="e.g. budget_range (auto-generated if empty)"
                                    className="mt-1 w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm font-mono dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300">
                                    {t('custom_fields.type') || 'Field Type'}
                                </label>
                                <select
                                    value={type}
                                    onChange={(e) => setType(e.target.value)}
                                    className="mt-1 w-full rounded-soft border-soft bg-white py-1.5 px-3 text-sm dark:bg-neutral-800 dark:text-neutral-200"
                                >
                                    {availableTypes.map((t) => (
                                        <option key={t} value={t}>
                                            {t}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {inArray(type, ['select', 'multiselect']) && (
                                <div>
                                    <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300">
                                        {t('custom_fields.options_comma') || 'Options (comma-separated)'} *
                                    </label>
                                    <input
                                        type="text"
                                        value={options}
                                        onChange={(e) => setOptions(e.target.value)}
                                        placeholder="e.g. Under $10k, $10k-$50k, Over $50k"
                                        required
                                        className="mt-1 w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                    />
                                </div>
                            )}

                            <label className="flex items-center gap-2 cursor-pointer pt-1">
                                <input
                                    type="checkbox"
                                    checked={required}
                                    onChange={(e) => setRequired(e.target.checked)}
                                    className="rounded border-neutral-300 text-brand-600 focus:ring-brand-500"
                                />
                                <span className="text-sm text-neutral-700 dark:text-neutral-300">
                                    {t('custom_fields.is_required') || 'Required field'}
                                </span>
                            </label>

                            <div className="flex justify-end gap-2 pt-3 border-t border-neutral-100 dark:border-neutral-800">
                                <Button variant="secondary" type="button" onClick={() => setShowCreateModal(false)}>
                                    {t('common.cancel')}
                                </Button>
                                <Button type="submit" disabled={submitting || !label.trim()}>
                                    {t('common.create') || 'Create Field'}
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Edit Field Modal */}
            {editingField && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white dark:bg-neutral-900 p-6 shadow-xl space-y-4">
                        <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                            {t('custom_fields.edit_title') || 'Edit Custom Field'}
                        </h3>

                        <form onSubmit={handleUpdate} className="space-y-3.5">
                            <div>
                                <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300">
                                    {t('custom_fields.label') || 'Field Label'} *
                                </label>
                                <input
                                    type="text"
                                    value={editingField.label}
                                    onChange={(e) => setEditingField({ ...editingField, label: e.target.value })}
                                    required
                                    className="mt-1 w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            </div>

                            {inArray(editingField.type, ['select', 'multiselect']) && (
                                <div>
                                    <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300">
                                        {t('custom_fields.options_comma') || 'Options (comma-separated)'} *
                                    </label>
                                    <input
                                        type="text"
                                        value={editingField.optionsText}
                                        onChange={(e) => setEditingField({ ...editingField, optionsText: e.target.value })}
                                        className="mt-1 w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                    />
                                </div>
                            )}

                            <label className="flex items-center gap-2 cursor-pointer pt-1">
                                <input
                                    type="checkbox"
                                    checked={editingField.required}
                                    onChange={(e) => setEditingField({ ...editingField, required: e.target.checked })}
                                    className="rounded border-neutral-300 text-brand-600 focus:ring-brand-500"
                                />
                                <span className="text-sm text-neutral-700 dark:text-neutral-300">
                                    {t('custom_fields.is_required') || 'Required field'}
                                </span>
                            </label>

                            <div className="flex justify-end gap-2 pt-3 border-t border-neutral-100 dark:border-neutral-800">
                                <Button variant="secondary" type="button" onClick={() => setEditingField(null)}>
                                    {t('common.cancel')}
                                </Button>
                                <Button type="submit" disabled={submitting}>
                                    {t('common.save')}
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </ClientLayout>
    );
}

