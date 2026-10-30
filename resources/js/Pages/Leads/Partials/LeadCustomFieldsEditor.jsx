import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/ui/Button';
import { Sliders, Save, Check } from 'lucide-react';

export default function LeadCustomFieldsEditor({ lead, customFields = [] }) {
    const { t } = useTranslation();

    // Initialize state with current values
    const [values, setValues] = useState(() => {
        const initial = {};
        customFields.forEach((f) => {
            initial[f.key] = f.value;
        });
        return initial;
    });

    const [saving, setSaving] = useState(false);

    if (customFields.length === 0) {
        return (
            <div className="rounded-xl border border-dashed border-neutral-200 p-8 text-center dark:border-neutral-800">
                <Sliders className="mx-auto h-8 w-8 text-neutral-300 dark:text-neutral-600" />
                <h4 className="mt-2 text-sm font-semibold text-neutral-800 dark:text-neutral-200">
                    {t('custom_fields.none_for_lead') || 'No custom fields defined for leads.'}
                </h4>
                <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                    {t('custom_fields.define_hint') || 'You can define custom fields in Workspace Settings > Custom Fields.'}
                </p>
                <div className="mt-4">
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => router.visit(route('client.custom-fields.index', { entity_type: 'lead' }))}
                    >
                        {t('custom_fields.manage_fields') || 'Manage Custom Fields'}
                    </Button>
                </div>
            </div>
        );
    }

    const handleChange = (key, val) => {
        setValues((prev) => ({ ...prev, [key]: val }));
    };

    const handleMultiSelectToggle = (key, option) => {
        const current = Array.isArray(values[key]) ? values[key] : [];
        const next = current.includes(option)
            ? current.filter((o) => o !== option)
            : [...current, option];
        handleChange(key, next);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        setSaving(true);

        router.put(
            route('client.leads.custom-fields.update', lead.id),
            { values },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(false),
            }
        );
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <div className="flex items-center justify-between pb-2 border-b border-neutral-100 dark:border-neutral-800">
                <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                    <Sliders className="h-4 w-4 text-brand-600" />
                    {t('custom_fields.title') || 'Custom Fields'}
                </h3>

                <Button size="sm" type="submit" disabled={saving}>
                    <Save className="mr-1.5 h-3.5 w-3.5" />
                    {saving ? (t('common.saving') || 'Saving...') : (t('common.save') || 'Save Fields')}
                </Button>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {customFields.map((field) => {
                    const val = values[field.key] ?? '';

                    return (
                        <div key={field.id} className={field.type === 'multiselect' ? 'sm:col-span-2' : ''}>
                            <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300">
                                {field.label} {field.required && <span className="text-red-500">*</span>}
                            </label>

                            {field.type === 'text' && (
                                <input
                                    type="text"
                                    value={val}
                                    required={field.required}
                                    onChange={(e) => handleChange(field.key, e.target.value)}
                                    className="mt-1 block w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            )}

                            {field.type === 'number' && (
                                <input
                                    type="number"
                                    step="any"
                                    value={val}
                                    required={field.required}
                                    onChange={(e) => handleChange(field.key, e.target.value)}
                                    className="mt-1 block w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            )}

                            {field.type === 'date' && (
                                <input
                                    type="date"
                                    value={val}
                                    required={field.required}
                                    onChange={(e) => handleChange(field.key, e.target.value)}
                                    className="mt-1 block w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            )}

                            {field.type === 'url' && (
                                <input
                                    type="url"
                                    value={val}
                                    required={field.required}
                                    placeholder="https://..."
                                    onChange={(e) => handleChange(field.key, e.target.value)}
                                    className="mt-1 block w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            )}

                            {field.type === 'checkbox' && (
                                <label className="mt-2 flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={Boolean(val)}
                                        onChange={(e) => handleChange(field.key, e.target.checked)}
                                        className="rounded border-neutral-300 text-brand-600 focus:ring-brand-500"
                                    />
                                    <span className="text-xs text-neutral-600 dark:text-neutral-400">
                                        {field.label}
                                    </span>
                                </label>
                            )}

                            {field.type === 'select' && (
                                <select
                                    value={val}
                                    required={field.required}
                                    onChange={(e) => handleChange(field.key, e.target.value)}
                                    className="mt-1 block w-full rounded-soft border-soft bg-white px-3 py-1.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                >
                                    <option value="">{t('common.select') || '-- Select --'}</option>
                                    {(field.options || []).map((opt, i) => (
                                        <option key={i} value={opt}>
                                            {opt}
                                        </option>
                                    ))}
                                </select>
                            )}

                            {field.type === 'multiselect' && (
                                <div className="mt-1.5 flex flex-wrap gap-2 rounded-xl border border-neutral-200 bg-neutral-50/50 p-2.5 dark:border-neutral-800 dark:bg-neutral-800/20">
                                    {(field.options || []).map((opt, i) => {
                                        const isSelected = Array.isArray(val) && val.includes(opt);
                                        return (
                                            <button
                                                key={i}
                                                type="button"
                                                onClick={() => handleMultiSelectToggle(field.key, opt)}
                                                className={`inline-flex items-center gap-1.5 rounded-soft px-2.5 py-1 text-xs font-medium transition ${
                                                    isSelected
                                                        ? 'bg-brand-500 text-white'
                                                        : 'bg-white text-neutral-600 hover:bg-neutral-100 border border-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:border-neutral-700'
                                                }`}
                                            >
                                                {isSelected && <Check className="h-3 w-3" />}
                                                {opt}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </form>
    );
}

