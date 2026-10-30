import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import {
    FileText, ArrowLeft, Plus, Trash2, ArrowUp, ArrowDown,
    Save, Eye, ExternalLink, Settings, Sliders, CheckCircle2,
    Palette, Layers, Tag, User, Globe, Code, Copy, Check
} from 'lucide-react';

export default function FormBuilder({
    form,
    fields: initialFields = [],
    pipelines = [],
    teamMembers = [],
    tags = [],
    customFields = [],
    availableFieldTypes = {},
    mapsToOptions = {},
}) {
    const { t } = useTranslation();
    const [activeTab, setActiveTab] = useState('fields'); // 'fields' | 'settings'

    // Form settings state
    const [name, setName] = useState(form.name || '');
    const [status, setStatus] = useState(form.status || 'published');
    const [pipelineId, setPipelineId] = useState(form.pipeline_id ? String(form.pipeline_id) : '');
    const [targetStageId, setTargetStageId] = useState(form.target_stage_id ? String(form.target_stage_id) : '');
    const [assignmentMode, setAssignmentMode] = useState(form.assignment_mode || 'none');
    const [assignedUserId, setAssignedUserId] = useState(form.assigned_user_id ? String(form.assigned_user_id) : '');
    const [autoTags, setAutoTags] = useState(form.auto_tags || []);
    const [thankYouType, setThankYouType] = useState(form.thank_you_type || 'message');
    const [thankYouValue, setThankYouValue] = useState(form.thank_you_value || '');
    const [primaryColor, setPrimaryColor] = useState(form.settings?.primary_color || '#6366f1');
    const [buttonText, setButtonText] = useState(form.settings?.button_text || 'Submit');
    const [showTitle, setShowTitle] = useState(form.settings?.show_title ?? true);

    // Fields state
    const [fields, setFields] = useState(initialFields);
    const [selectedFieldIndex, setSelectedFieldIndex] = useState(0);
    const [saving, setSaving] = useState(false);

    const selectedPipeline = pipelines.find((p) => String(p.id) === String(pipelineId)) || pipelines[0];
    const availableStages = selectedPipeline?.stages || [];

    const handleAddField = (type) => {
        const newOrder = fields.length + 1;
        const key = `${type}_${Date.now().toString().slice(-4)}`;
        const newField = {
            id: null,
            type,
            label: availableFieldTypes[type] || 'New Field',
            field_key: key,
            placeholder: '',
            help_text: '',
            required: false,
            options: type === 'select' ? ['Option 1', 'Option 2', 'Option 3'] : null,
            maps_to: null,
            sort_order: newOrder,
        };

        const updated = [...fields, newField];
        setFields(updated);
        setSelectedFieldIndex(updated.length - 1);
    };

    const handleUpdateField = (index, key, val) => {
        setFields((prev) => {
            const next = [...prev];
            next[index] = { ...next[index], [key]: val };
            return next;
        });
    };

    const handleRemoveField = (index) => {
        if (fields.length <= 1) return;
        const updated = fields.filter((_, i) => i !== index);
        setFields(updated);
        setSelectedFieldIndex(Math.max(0, index - 1));
    };

    const handleMoveField = (index, direction) => {
        const targetIndex = index + direction;
        if (targetIndex < 0 || targetIndex >= fields.length) return;

        const updated = [...fields];
        const temp = updated[index];
        updated[index] = updated[targetIndex];
        updated[targetIndex] = temp;

        setFields(updated);
        setSelectedFieldIndex(targetIndex);
    };

    const handleSave = (e) => {
        e?.preventDefault();
        setSaving(true);

        const payload = {
            name,
            status,
            pipeline_id: pipelineId ? Number(pipelineId) : null,
            target_stage_id: targetStageId ? Number(targetStageId) : null,
            assignment_mode: assignmentMode,
            assigned_user_id: assignedUserId ? Number(assignedUserId) : null,
            auto_tags: autoTags,
            thank_you_type: thankYouType,
            thank_you_value: thankYouValue,
            settings: {
                primary_color: primaryColor,
                button_text: buttonText,
                show_title: showTitle,
            },
            fields: fields.map((f, i) => ({
                id: f.id,
                type: f.type,
                label: f.label,
                field_key: f.field_key,
                placeholder: f.placeholder,
                help_text: f.help_text,
                required: Boolean(f.required),
                options: f.options,
                maps_to: f.maps_to,
                sort_order: i + 1,
            })),
        };

        router.put(route('client.sales.forms.builder.update', form.id), payload, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    };

    const activeField = fields[selectedFieldIndex];

    return (
        <ClientLayout>
            <Head title={`${form.name} - ${t('forms.builder') || 'Form Builder'}`} />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 space-y-6">
                {/* Top Action Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-soft pb-4">
                    <div className="flex items-center gap-3">
                        <Link
                            href={route('client.sales.forms.index')}
                            className="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </Link>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-lg font-bold text-neutral-900 dark:text-neutral-100 truncate">
                                    {name || 'Untitled Form'}
                                </h1>
                                <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ${
                                    status === 'published'
                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                        : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400'
                                }`}>
                                    {status}
                                </span>
                            </div>
                            <p className="text-xs text-neutral-400 font-mono">
                                {form.public_url}
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <div className="flex rounded-lg border border-soft bg-neutral-100 p-0.5 dark:bg-neutral-800 mr-2">
                            <button
                                type="button"
                                onClick={() => setActiveTab('fields')}
                                className={`rounded-md px-3 py-1.5 text-xs font-semibold transition-colors flex items-center gap-1.5 ${
                                    activeTab === 'fields'
                                        ? 'bg-white text-neutral-900 shadow-xs dark:bg-neutral-900 dark:text-neutral-100'
                                        : 'text-neutral-500 hover:text-neutral-900 dark:text-neutral-400'
                                }`}
                            >
                                <Sliders className="h-3.5 w-3.5" />
                                {t('forms.fields_editor') || 'Form Fields'}
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('settings')}
                                className={`rounded-md px-3 py-1.5 text-xs font-semibold transition-colors flex items-center gap-1.5 ${
                                    activeTab === 'settings'
                                        ? 'bg-white text-neutral-900 shadow-xs dark:bg-neutral-900 dark:text-neutral-100'
                                        : 'text-neutral-500 hover:text-neutral-900 dark:text-neutral-400'
                                }`}
                            >
                                <Settings className="h-3.5 w-3.5" />
                                {t('forms.pipeline_settings') || 'Settings & Pipeline'}
                            </button>
                        </div>

                        <a
                            href={form.public_url}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1.5 rounded-lg border border-soft bg-white px-3 py-1.5 text-xs font-medium text-neutral-700 hover:bg-neutral-50 dark:bg-neutral-800 dark:text-neutral-200"
                        >
                            <ExternalLink className="h-3.5 w-3.5" />
                            {t('forms.preview') || 'Live Preview'}
                        </a>

                        <Button onClick={handleSave} disabled={saving} className="inline-flex items-center gap-1.5">
                            <Save className="h-4 w-4" />
                            {saving ? (t('common.saving') || 'Saving...') : (t('common.save') || 'Save Form')}
                        </Button>
                    </div>
                </div>

                {activeTab === 'fields' ? (
                    /* Fields Editor Layout: Palette + Live Preview Canvas + Field Inspector */
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                        {/* Column 1: Field Palette (3 cols) */}
                        <div className="lg:col-span-3 space-y-4">
                            <div className="rounded-2xl border border-soft bg-white p-4 shadow-xs dark:bg-neutral-900">
                                <h3 className="text-xs font-bold uppercase tracking-wider text-neutral-400 mb-3">
                                    {t('forms.add_field') || 'Add Form Elements'}
                                </h3>
                                <div className="space-y-1.5">
                                    {Object.entries(availableFieldTypes).map(([type, label]) => (
                                        <button
                                            key={type}
                                            type="button"
                                            onClick={() => handleAddField(type)}
                                            className="flex w-full items-center justify-between rounded-xl border border-soft/80 bg-neutral-50/70 p-2.5 text-start text-xs font-medium text-neutral-800 transition-all hover:border-brand-500 hover:bg-brand-50/50 dark:border-neutral-800 dark:bg-neutral-800/60 dark:text-neutral-200 dark:hover:border-brand-500"
                                        >
                                            <span>{label}</span>
                                            <Plus className="h-3.5 w-3.5 text-neutral-400" />
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* Reorder List */}
                            <div className="rounded-2xl border border-soft bg-white p-4 shadow-xs dark:bg-neutral-900">
                                <h3 className="text-xs font-bold uppercase tracking-wider text-neutral-400 mb-2">
                                    {t('forms.field_order') || 'Field Hierarchy'} ({fields.length})
                                </h3>
                                <div className="space-y-1">
                                    {fields.map((f, i) => (
                                        <div
                                            key={i}
                                            onClick={() => setSelectedFieldIndex(i)}
                                            className={`flex items-center justify-between rounded-lg p-2 text-xs cursor-pointer transition-colors ${
                                                selectedFieldIndex === i
                                                    ? 'bg-brand-50 text-brand-700 font-semibold dark:bg-brand-950/60 dark:text-brand-300'
                                                    : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800'
                                            }`}
                                        >
                                            <span className="truncate">{f.label || `Field ${i + 1}`}</span>
                                            <div className="flex items-center gap-0.5">
                                                <button
                                                    type="button"
                                                    disabled={i === 0}
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        handleMoveField(i, -1);
                                                    }}
                                                    className="p-1 hover:text-neutral-900 disabled:opacity-30"
                                                >
                                                    <ArrowUp className="h-3 w-3" />
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={i === fields.length - 1}
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        handleMoveField(i, 1);
                                                    }}
                                                    className="p-1 hover:text-neutral-900 disabled:opacity-30"
                                                >
                                                    <ArrowDown className="h-3 w-3" />
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>

                        {/* Column 2: Live Canvas (5 cols) */}
                        <div className="lg:col-span-5 rounded-2xl border border-soft bg-neutral-100/70 p-6 shadow-inner dark:bg-neutral-950/40">
                            <div className="mx-auto max-w-md rounded-2xl border border-soft bg-white p-6 shadow-md dark:bg-neutral-900 space-y-4">
                                {showTitle && (
                                    <div className="border-b border-soft pb-3">
                                        <h2 className="text-xl font-bold text-neutral-900 dark:text-neutral-100">
                                            {name || 'Contact Us'}
                                        </h2>
                                    </div>
                                )}

                                <div className="space-y-4">
                                    {fields.map((f, i) => (
                                        <div
                                            key={i}
                                            onClick={() => setSelectedFieldIndex(i)}
                                            className={`relative rounded-xl border p-3 cursor-pointer transition-all ${
                                                selectedFieldIndex === i
                                                    ? 'border-brand-500 bg-brand-50/20 ring-2 ring-brand-500/20'
                                                    : 'border-transparent hover:border-neutral-300 dark:hover:border-neutral-700'
                                            }`}
                                        >
                                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300 mb-1">
                                                {f.label || 'Untitled Field'}
                                                {f.required && <span className="text-red-500 ml-1">*</span>}
                                            </label>

                                            {f.type === 'textarea' ? (
                                                <textarea
                                                    disabled
                                                    placeholder={f.placeholder || ''}
                                                    rows="2"
                                                    className="w-full rounded-lg border-soft bg-neutral-50 p-2 text-xs text-neutral-400 cursor-not-allowed dark:bg-neutral-800"
                                                />
                                            ) : f.type === 'select' ? (
                                                <select
                                                    disabled
                                                    className="w-full rounded-lg border-soft bg-neutral-50 p-2 text-xs text-neutral-400 cursor-not-allowed dark:bg-neutral-800"
                                                >
                                                    {(f.options || ['Option 1', 'Option 2']).map((opt, oi) => (
                                                        <option key={oi}>{opt}</option>
                                                    ))}
                                                </select>
                                            ) : f.type === 'checkbox' ? (
                                                <div className="flex items-center gap-2">
                                                    <input type="checkbox" disabled className="rounded text-brand-600" />
                                                    <span className="text-xs text-neutral-500">{f.placeholder || f.label}</span>
                                                </div>
                                            ) : (
                                                <input
                                                    type={f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'}
                                                    disabled
                                                    placeholder={f.placeholder || ''}
                                                    className="w-full rounded-lg border-soft bg-neutral-50 p-2 text-xs text-neutral-400 cursor-not-allowed dark:bg-neutral-800"
                                                />
                                            )}

                                            {f.maps_to && (
                                                <div className="mt-1 flex items-center gap-1 text-[10px] text-brand-600 dark:text-brand-400">
                                                    <span>&rarr; maps to: <strong className="font-mono">{f.maps_to}</strong></span>
                                                </div>
                                            )}
                                        </div>
                                    ))}

                                    <div className="pt-2">
                                        <button
                                            type="button"
                                            disabled
                                            style={{ backgroundColor: primaryColor }}
                                            className="w-full rounded-xl py-2.5 text-xs font-bold text-white shadow-sm opacity-90 cursor-not-allowed"
                                        >
                                            {buttonText || 'Submit'}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Column 3: Field Inspector (4 cols) */}
                        <div className="lg:col-span-4 rounded-2xl border border-soft bg-white p-5 shadow-xs dark:bg-neutral-900 space-y-4">
                            {activeField ? (
                                <>
                                    <div className="flex items-center justify-between border-b border-soft pb-3">
                                        <h3 className="text-sm font-bold text-neutral-900 dark:text-neutral-100 flex items-center gap-1.5">
                                            <Sliders className="h-4 w-4 text-brand-600" />
                                            {t('forms.field_settings') || 'Field Properties'}
                                        </h3>
                                        <button
                                            type="button"
                                            onClick={() => handleRemoveField(selectedFieldIndex)}
                                            className="text-xs text-red-500 hover:text-red-700 flex items-center gap-1"
                                            title="Delete Field"
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                            {t('common.delete') || 'Delete'}
                                        </button>
                                    </div>

                                    <div className="space-y-3 text-xs">
                                        <div>
                                            <label className="block font-semibold text-neutral-700 dark:text-neutral-300">
                                                {t('forms.label') || 'Field Label'}
                                            </label>
                                            <input
                                                type="text"
                                                value={activeField.label}
                                                onChange={(e) => handleUpdateField(selectedFieldIndex, 'label', e.target.value)}
                                                className="mt-1 w-full rounded-lg border-soft bg-white p-2 text-xs text-neutral-900 focus:border-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                                            />
                                        </div>

                                        <div>
                                            <label className="block font-semibold text-neutral-700 dark:text-neutral-300">
                                                {t('forms.placeholder') || 'Placeholder Text'}
                                            </label>
                                            <input
                                                type="text"
                                                value={activeField.placeholder || ''}
                                                onChange={(e) => handleUpdateField(selectedFieldIndex, 'placeholder', e.target.value)}
                                                className="mt-1 w-full rounded-lg border-soft bg-white p-2 text-xs text-neutral-900 focus:border-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                                            />
                                        </div>

                                        <div>
                                            <label className="block font-semibold text-neutral-700 dark:text-neutral-300">
                                                {t('forms.maps_to') || 'Maps to Lead Record'}
                                            </label>
                                            <select
                                                value={activeField.maps_to || ''}
                                                onChange={(e) => handleUpdateField(selectedFieldIndex, 'maps_to', e.target.value || null)}
                                                className="mt-1 w-full rounded-lg border-soft bg-white p-2 text-xs text-neutral-900 focus:border-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                                            >
                                                <option value="">{t('forms.no_mapping') || 'Do not map (keep in submission only)'}</option>
                                                <optgroup label="Lead Standard Fields">
                                                    {Object.entries(mapsToOptions).map(([k, lbl]) => (
                                                        <option key={k} value={k}>{lbl} ({k})</option>
                                                    ))}
                                                </optgroup>
                                                {customFields.length > 0 && (
                                                    <optgroup label="Lead Custom Fields">
                                                        {customFields.map((cf) => (
                                                            <option key={cf.key} value={`custom_field:${cf.key}`}>
                                                                {cf.label} [Custom Field]
                                                            </option>
                                                        ))}
                                                    </optgroup>
                                                )}
                                            </select>
                                        </div>

                                        {activeField.type === 'select' && (
                                            <div>
                                                <label className="block font-semibold text-neutral-700 dark:text-neutral-300">
                                                    {t('forms.options_comma') || 'Options (comma-separated)'}
                                                </label>
                                                <textarea
                                                    rows="3"
                                                    value={(activeField.options || []).join(', ')}
                                                    onChange={(e) => {
                                                        const opts = e.target.value.split(',').map((s) => s.trim()).filter(Boolean);
                                                        handleUpdateField(selectedFieldIndex, 'options', opts);
                                                    }}
                                                    placeholder="Option 1, Option 2, Option 3"
                                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2 text-xs text-neutral-900 focus:border-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                                                />
                                            </div>
                                        )}

                                        <div className="flex items-center gap-2 pt-2 border-t border-soft">
                                            <input
                                                type="checkbox"
                                                id="field_required"
                                                checked={Boolean(activeField.required)}
                                                onChange={(e) => handleUpdateField(selectedFieldIndex, 'required', e.target.checked)}
                                                className="rounded text-brand-600 focus:ring-brand-500"
                                            />
                                            <label htmlFor="field_required" className="font-medium text-neutral-700 dark:text-neutral-300">
                                                {t('forms.is_required') || 'Required Field'}
                                            </label>
                                        </div>
                                    </div>
                                </>
                            ) : (
                                <p className="text-center text-xs text-neutral-400 py-6">
                                    {t('forms.select_field_hint') || 'Select a field from the preview or palette to configure properties.'}
                                </p>
                            )}
                        </div>
                    </div>
                ) : (
                    /* Settings & Pipeline Tab */
                    <div className="max-w-3xl rounded-2xl border border-soft bg-white p-6 shadow-xs dark:bg-neutral-900 space-y-6">
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                    {t('forms.form_name') || 'Form Name'}
                                </label>
                                <input
                                    type="text"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                    {t('common.status') || 'Status'}
                                </label>
                                <select
                                    value={status}
                                    onChange={(e) => setStatus(e.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100"
                                >
                                    <option value="published">{t('forms.status_published') || 'Published (Accepting Submissions)'}</option>
                                    <option value="draft">{t('forms.status_draft') || 'Draft (Paused)'}</option>
                                    <option value="archived">{t('forms.status_archived') || 'Archived'}</option>
                                </select>
                            </div>
                        </div>

                        {/* Destination */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 border-t border-soft pt-4">
                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                    {t('forms.target_pipeline') || 'Pipeline Destination'}
                                </label>
                                <select
                                    value={pipelineId}
                                    onChange={(e) => {
                                        setPipelineId(e.target.value);
                                        setTargetStageId('');
                                    }}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100"
                                >
                                    {pipelines.map((p) => (
                                        <option key={p.id} value={p.id}>{p.name}</option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                    {t('forms.target_stage') || 'Target Stage'}
                                </label>
                                <select
                                    value={targetStageId}
                                    onChange={(e) => setTargetStageId(e.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100"
                                >
                                    <option value="">{t('forms.default_first_stage') || 'First Stage in Pipeline'}</option>
                                    {availableStages.map((s) => (
                                        <option key={s.id} value={s.id}>{s.name}</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {/* Assignment */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 border-t border-soft pt-4">
                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                    {t('forms.assignment_mode') || 'Lead Assignment Strategy'}
                                </label>
                                <select
                                    value={assignmentMode}
                                    onChange={(e) => setAssignmentMode(e.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100"
                                >
                                    <option value="none">{t('leads.unassigned') || 'Unassigned'}</option>
                                    <option value="round_robin">{t('forms.round_robin') || 'Round-Robin Rotation'}</option>
                                    <option value="specific">{t('forms.specific_user') || 'Assign to Specific Agent'}</option>
                                </select>
                            </div>

                            {assignmentMode === 'specific' && (
                                <div>
                                    <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                        {t('forms.assigned_agent') || 'Select Agent'}
                                    </label>
                                    <select
                                        value={assignedUserId}
                                        onChange={(e) => setAssignedUserId(e.target.value)}
                                        className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100"
                                    >
                                        <option value="">{t('forms.select_agent') || 'Choose agent...'}</option>
                                        {teamMembers.map((m) => (
                                            <option key={m.id} value={m.id}>{m.name}</option>
                                        ))}
                                    </select>
                                </div>
                            )}
                        </div>

                        {/* Thank You behavior */}
                        <div className="space-y-3 border-t border-soft pt-4 text-xs">
                            <label className="block font-semibold text-neutral-700 dark:text-neutral-300">
                                {t('forms.after_submission') || 'After Successful Submission'}
                            </label>
                            <div className="flex items-center gap-4">
                                <label className="inline-flex items-center gap-1.5 cursor-pointer">
                                    <input
                                        type="radio"
                                        name="thank_you_type"
                                        checked={thankYouType === 'message'}
                                        onChange={() => setThankYouType('message')}
                                        className="text-brand-600 focus:ring-brand-500"
                                    />
                                    <span>{t('forms.show_message') || 'Display Thank-You Message'}</span>
                                </label>
                                <label className="inline-flex items-center gap-1.5 cursor-pointer">
                                    <input
                                        type="radio"
                                        name="thank_you_type"
                                        checked={thankYouType === 'redirect'}
                                        onChange={() => setThankYouType('redirect')}
                                        className="text-brand-600 focus:ring-brand-500"
                                    />
                                    <span>{t('forms.redirect_url') || 'Redirect to External URL'}</span>
                                </label>
                            </div>

                            {thankYouType === 'message' ? (
                                <textarea
                                    rows="2"
                                    value={thankYouValue}
                                    onChange={(e) => setThankYouValue(e.target.value)}
                                    placeholder="Thank you! We will get in touch with you shortly."
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            ) : (
                                <input
                                    type="url"
                                    value={thankYouValue}
                                    onChange={(e) => setThankYouValue(e.target.value)}
                                    placeholder="https://example.com/thank-you"
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            )}
                        </div>

                        {/* Styling Settings */}
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 border-t border-soft pt-4 text-xs">
                            <div>
                                <label className="block font-semibold text-neutral-700 dark:text-neutral-300 mb-1">
                                    {t('forms.primary_color') || 'Brand Color'}
                                </label>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="color"
                                        value={primaryColor}
                                        onChange={(e) => setPrimaryColor(e.target.value)}
                                        className="h-8 w-10 cursor-pointer rounded border border-soft p-0.5"
                                    />
                                    <span className="font-mono text-xs">{primaryColor}</span>
                                </div>
                            </div>

                            <div>
                                <label className="block font-semibold text-neutral-700 dark:text-neutral-300 mb-1">
                                    {t('forms.button_text') || 'Submit Button Text'}
                                </label>
                                <input
                                    type="text"
                                    value={buttonText}
                                    onChange={(e) => setButtonText(e.target.value)}
                                    className="w-full rounded-lg border-soft bg-white p-2 text-xs text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            </div>

                            <div className="flex items-center gap-2 pt-5">
                                <input
                                    type="checkbox"
                                    id="show_title"
                                    checked={showTitle}
                                    onChange={(e) => setShowTitle(e.target.checked)}
                                    className="rounded text-brand-600 focus:ring-brand-500"
                                />
                                <label htmlFor="show_title" className="font-medium text-neutral-700 dark:text-neutral-300">
                                    {t('forms.show_header') || 'Show Title in Form'}
                                </label>
                            </div>
                        </div>

                        <div className="flex justify-end border-t border-soft pt-4">
                            <Button onClick={handleSave} disabled={saving}>
                                <Save className="h-4 w-4 mr-1.5" />
                                {saving ? (t('common.saving') || 'Saving...') : (t('common.save') || 'Save Form Settings')}
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </ClientLayout>
    );
}

