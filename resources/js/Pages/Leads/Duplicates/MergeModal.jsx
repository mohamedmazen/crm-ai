import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Modal from '@/Components/ui/Modal';
import Button from '@/Components/ui/Button';
import {
    GitMerge, CheckCircle2, AlertCircle, ArrowRight,
    Users, DollarSign, Calendar, ShieldCheck
} from 'lucide-react';

export default function MergeModal({ group, show, onClose }) {
    const { t } = useTranslation();
    const leads = group?.leads || [];

    const [primaryId, setPrimaryId] = useState(null);
    const [selectedFields, setSelectedFields] = useState({
        name: '',
        phone: '',
        email: '',
        company_id: '',
        deal_value: '',
        assigned_user_id: '',
    });
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (leads.length > 0) {
            // Default primary to the lead with the earliest creation or most activities
            const defaultPrimary = leads[0];
            setPrimaryId(defaultPrimary.id);

            setSelectedFields({
                name: defaultPrimary.name || '',
                phone: defaultPrimary.phone || '',
                email: defaultPrimary.email || '',
                company_id: defaultPrimary.company_id ? String(defaultPrimary.company_id) : '',
                deal_value: defaultPrimary.deal_value !== null ? String(defaultPrimary.deal_value) : '',
                assigned_user_id: defaultPrimary.assigned_user_id ? String(defaultPrimary.assigned_user_id) : '',
            });
        }
    }, [group]);

    const handlePrimaryChange = (lead) => {
        setPrimaryId(lead.id);
        setSelectedFields({
            name: lead.name || '',
            phone: lead.phone || '',
            email: lead.email || '',
            company_id: lead.company_id ? String(lead.company_id) : '',
            deal_value: lead.deal_value !== null ? String(lead.deal_value) : '',
            assigned_user_id: lead.assigned_user_id ? String(lead.assigned_user_id) : '',
        });
    };

    const handleMerge = (e) => {
        e.preventDefault();
        if (!primaryId) return;

        const secondaryIds = leads.map((l) => l.id).filter((id) => id !== primaryId);
        if (secondaryIds.length === 0) return;

        setSubmitting(true);

        const overrides = {
            name: selectedFields.name || null,
            phone: selectedFields.phone || null,
            email: selectedFields.email || null,
            company_id: selectedFields.company_id ? Number(selectedFields.company_id) : null,
            deal_value: selectedFields.deal_value !== '' ? Number(selectedFields.deal_value) : null,
            assigned_user_id: selectedFields.assigned_user_id ? Number(selectedFields.assigned_user_id) : null,
        };

        router.post(
            route('client.leads.duplicates.merge', group.id),
            {
                primary_lead_id: primaryId,
                secondary_lead_ids: secondaryIds,
                overrides,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onClose();
                },
                onFinish: () => setSubmitting(false),
            }
        );
    };

    if (!group || leads.length < 2) return null;

    const primaryLead = leads.find((l) => l.id === primaryId) || leads[0];
    const secondaryLeads = leads.filter((l) => l.id !== primaryId);

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={handleMerge} className="p-6 space-y-6">
                {/* Header */}
                <div className="flex items-start justify-between border-b border-soft pb-4">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-400">
                            <GitMerge className="h-5 w-5" />
                        </div>
                        <div>
                            <h2 className="text-lg font-bold text-neutral-900 dark:text-neutral-100">
                                {t('duplicates.merge_title') || 'Merge Duplicate Leads'}
                            </h2>
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                {t('duplicates.merge_subtitle') || 'Select the primary master record and choose which field values to retain.'}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200"
                    >
                        &times;
                    </button>
                </div>

                {/* Step 1: Choose Primary Record */}
                <div className="space-y-3">
                    <label className="block text-xs font-bold text-neutral-800 dark:text-neutral-200">
                        {t('duplicates.step_choose_primary') || '1. Choose Primary Record'}
                        <span className="ml-1 text-[11px] font-normal text-neutral-500">
                            ({t('duplicates.primary_explanation') || 'Secondary records will be merged into this lead and removed.'})
                        </span>
                    </label>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        {leads.map((lead) => {
                            const isPrimary = lead.id === primaryId;
                            return (
                                <div
                                    key={lead.id}
                                    onClick={() => handlePrimaryChange(lead)}
                                    className={`relative cursor-pointer rounded-xl border p-4 transition-all ${
                                        isPrimary
                                            ? 'border-brand-500 bg-brand-50/40 shadow-xs dark:border-brand-500 dark:bg-brand-950/30'
                                            : 'border-soft bg-white hover:border-neutral-300 dark:bg-neutral-900 dark:hover:border-neutral-700'
                                    }`}
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold text-sm text-neutral-900 dark:text-neutral-100 truncate">
                                                    {lead.name}
                                                </span>
                                                <span className="font-mono text-[10px] text-neutral-400">#{lead.id}</span>
                                            </div>
                                            <p className="text-xs text-neutral-500 dark:text-neutral-400 truncate mt-0.5">
                                                {lead.phone || lead.email || '—'}
                                            </p>
                                        </div>
                                        <div className="shrink-0">
                                            <div
                                                className={`flex h-5 w-5 items-center justify-center rounded-full border ${
                                                    isPrimary
                                                        ? 'border-brand-600 bg-brand-600 text-white'
                                                        : 'border-neutral-300 dark:border-neutral-700'
                                                }`}
                                            >
                                                {isPrimary && <CheckCircle2 className="h-3.5 w-3.5" />}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="mt-3 flex flex-wrap items-center gap-2 text-[11px] text-neutral-500 dark:text-neutral-400 border-t border-soft/60 pt-2">
                                        <span>{lead.stage?.name || 'No Stage'}</span>
                                        <span>•</span>
                                        <span>${Number(lead.deal_value || 0).toLocaleString()}</span>
                                        <span>•</span>
                                        <span>{lead.activities_count ?? 0} activities</span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* Step 2: Choose Conflicting Field Values */}
                <div className="space-y-3">
                    <label className="block text-xs font-bold text-neutral-800 dark:text-neutral-200">
                        {t('duplicates.step_choose_values') || '2. Conflicting Fields to Keep'}
                    </label>

                    <div className="rounded-xl border border-soft divide-y divide-soft bg-neutral-50/50 dark:bg-neutral-900/50 text-xs">
                        {/* Name */}
                        <div className="p-3 flex items-center justify-between gap-4">
                            <span className="font-semibold text-neutral-700 dark:text-neutral-300 w-28">
                                {t('common.name') || 'Name'}:
                            </span>
                            <div className="flex-1 flex flex-wrap gap-2">
                                {Array.from(new Set(leads.map((l) => l.name).filter(Boolean))).map((val) => (
                                    <label key={val} className="inline-flex items-center gap-1.5 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="merge_name"
                                            checked={selectedFields.name === val}
                                            onChange={() => setSelectedFields((prev) => ({ ...prev, name: val }))}
                                            className="text-brand-600 focus:ring-brand-500 h-3.5 w-3.5"
                                        />
                                        <span className="text-neutral-800 dark:text-neutral-200 font-medium">{val}</span>
                                    </label>
                                ))}
                            </div>
                        </div>

                        {/* Phone */}
                        <div className="p-3 flex items-center justify-between gap-4">
                            <span className="font-semibold text-neutral-700 dark:text-neutral-300 w-28">
                                {t('leads.phone') || 'Phone'}:
                            </span>
                            <div className="flex-1 flex flex-wrap gap-2">
                                {Array.from(new Set(leads.map((l) => l.phone).filter(Boolean))).map((val) => (
                                    <label key={val} className="inline-flex items-center gap-1.5 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="merge_phone"
                                            checked={selectedFields.phone === val}
                                            onChange={() => setSelectedFields((prev) => ({ ...prev, phone: val }))}
                                            className="text-brand-600 focus:ring-brand-500 h-3.5 w-3.5"
                                        />
                                        <span className="text-neutral-800 dark:text-neutral-200 font-medium">{val}</span>
                                    </label>
                                ))}
                            </div>
                        </div>

                        {/* Email */}
                        <div className="p-3 flex items-center justify-between gap-4">
                            <span className="font-semibold text-neutral-700 dark:text-neutral-300 w-28">
                                {t('leads.email') || 'Email'}:
                            </span>
                            <div className="flex-1 flex flex-wrap gap-2">
                                {Array.from(new Set(leads.map((l) => l.email).filter(Boolean))).map((val) => (
                                    <label key={val} className="inline-flex items-center gap-1.5 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="merge_email"
                                            checked={selectedFields.email === val}
                                            onChange={() => setSelectedFields((prev) => ({ ...prev, email: val }))}
                                            className="text-brand-600 focus:ring-brand-500 h-3.5 w-3.5"
                                        />
                                        <span className="text-neutral-800 dark:text-neutral-200 font-medium">{val}</span>
                                    </label>
                                ))}
                            </div>
                        </div>

                        {/* Deal Value */}
                        <div className="p-3 flex items-center justify-between gap-4">
                            <span className="font-semibold text-neutral-700 dark:text-neutral-300 w-28">
                                {t('leads.deal_value') || 'Deal Value'}:
                            </span>
                            <div className="flex-1 flex flex-wrap gap-2">
                                {Array.from(new Set(leads.map((l) => (l.deal_value !== null ? String(l.deal_value) : '')).filter(Boolean))).map((val) => (
                                    <label key={val} className="inline-flex items-center gap-1.5 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="merge_deal_value"
                                            checked={selectedFields.deal_value === val}
                                            onChange={() => setSelectedFields((prev) => ({ ...prev, deal_value: val }))}
                                            className="text-brand-600 focus:ring-brand-500 h-3.5 w-3.5"
                                        />
                                        <span className="text-neutral-800 dark:text-neutral-200 font-medium">${Number(val).toLocaleString()}</span>
                                    </label>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>

                {/* Safe Preservation Notice */}
                <div className="flex items-start gap-3 rounded-xl bg-emerald-50/70 p-3.5 text-xs text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200/50 dark:border-emerald-800/40">
                    <ShieldCheck className="h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <div>
                        <span className="font-bold">{t('duplicates.safe_merge_title') || 'Lossless Merge Guarantee'}: </span>
                        {t('duplicates.safe_merge_body') || 'All logged activities, scheduled tasks, tags, custom fields, and stage history from the secondary records will be automatically moved to the primary lead before secondary records are removed.'}
                    </div>
                </div>

                {/* Footer */}
                <div className="flex justify-end gap-2 border-t border-soft pt-4">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        {t('common.cancel') || 'Cancel'}
                    </Button>
                    <Button type="submit" disabled={submitting}>
                        <GitMerge className="h-4 w-4 mr-1.5" />
                        {submitting ? (t('duplicates.merging') || 'Merging...') : (t('duplicates.confirm_merge') || 'Confirm & Merge')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

