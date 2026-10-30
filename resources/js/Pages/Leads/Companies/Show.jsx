import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import Modal from '@/Components/ui/Modal';
import {
    Building2, ArrowLeft, Globe, Phone, MapPin, Edit2, Trash2,
    Users, DollarSign, CheckCircle2, XCircle, Clock, Plus,
    ExternalLink, Search, Unlink, User
} from 'lucide-react';

export default function CompanyShow({
    company,
    leads = [],
    metrics = { total_leads: 0, won_deals_value: 0, open_deals_value: 0, lost_deals_value: 0 },
    industries = [],
    sizes = {},
    teamMembers = [],
}) {
    const { t } = useTranslation();
    const [showEditModal, setShowEditModal] = useState(false);
    const [showAttachModal, setShowAttachModal] = useState(false);
    const [showDeleteModal, setShowDeleteModal] = useState(false);

    // Edit form
    const [formName, setFormName] = useState(company.name || '');
    const [formWebsite, setFormWebsite] = useState(company.website || '');
    const [formDomain, setFormDomain] = useState(company.domain || '');
    const [formIndustry, setFormIndustry] = useState(company.industry || '');
    const [formSize, setFormSize] = useState(company.size || '');
    const [formPhone, setFormPhone] = useState(company.phone || '');
    const [formAddress, setFormAddress] = useState(company.address || '');
    const [formOwnerId, setFormOwnerId] = useState(company.owner_id ? String(company.owner_id) : '');
    const [submitting, setSubmitting] = useState(false);

    // Lead attach search state
    const [searchLeadQuery, setSearchLeadQuery] = useState('');
    const [leadSearchResults, setLeadSearchResults] = useState([]);
    const [searchingLeads, setSearchingLeads] = useState(false);

    const handleUpdate = (e) => {
        e.preventDefault();
        if (!formName.trim()) return;

        setSubmitting(true);
        router.put(route('client.leads.companies.update', company.id), {
            name: formName.trim(),
            website: formWebsite.trim() || null,
            domain: formDomain.trim() || null,
            industry: formIndustry || null,
            size: formSize || null,
            phone: formPhone.trim() || null,
            address: formAddress.trim() || null,
            owner_id: formOwnerId ? Number(formOwnerId) : null,
        }, {
            onSuccess: () => setShowEditModal(false),
            onFinish: () => setSubmitting(false),
        });
    };

    const handleDelete = () => {
        setSubmitting(true);
        router.delete(route('client.leads.companies.destroy', company.id), {
            onFinish: () => setSubmitting(false),
        });
    };

    const handleDetachLead = (leadId) => {
        router.post(route('client.leads.companies.detach-lead', [company.id, leadId]), {}, {
            preserveScroll: true,
        });
    };

    const handleAttachLead = (leadId) => {
        router.post(route('client.leads.companies.attach-lead', [company.id, leadId]), {}, {
            preserveScroll: true,
            onSuccess: () => setShowAttachModal(false),
        });
    };

    return (
        <ClientLayout>
            <Head title={`${company.name} - ${t('companies.title') || 'Companies'}`} />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 space-y-6">
                {/* Back Link & Actions */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <Link
                        href={route('client.leads.companies.index')}
                        className="inline-flex items-center gap-1.5 text-xs font-semibold text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        {t('companies.back_to_companies') || 'Back to Companies'}
                    </Link>

                    <div className="flex items-center gap-2">
                        <Button variant="secondary" size="sm" onClick={() => setShowEditModal(true)}>
                            <Edit2 className="h-3.5 w-3.5 mr-1.5" />
                            {t('common.edit') || 'Edit'}
                        </Button>
                        <Button variant="danger" size="sm" onClick={() => setShowDeleteModal(true)}>
                            <Trash2 className="h-3.5 w-3.5 mr-1.5" />
                            {t('common.delete') || 'Delete'}
                        </Button>
                    </div>
                </div>

                {/* Company Profile Header Card */}
                <div className="rounded-2xl border border-soft bg-white p-6 shadow-xs dark:bg-neutral-900">
                    <div className="flex flex-col md:flex-row md:items-start justify-between gap-6">
                        <div className="flex items-start gap-4">
                            <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-brand-500 font-bold text-2xl text-white shadow-md shadow-brand-500/20">
                                {company.name.substring(0, 2).toUpperCase()}
                            </div>
                            <div className="min-w-0">
                                <h1 className="text-2xl font-bold text-neutral-900 dark:text-neutral-100">
                                    {company.name}
                                </h1>
                                {company.domain && (
                                    <p className="mt-0.5 font-mono text-xs text-neutral-400">
                                        {company.domain}
                                    </p>
                                )}

                                <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-neutral-600 dark:text-neutral-300">
                                    {company.website && (
                                        <a
                                            href={company.website}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-1.5 font-medium hover:text-brand-600 dark:hover:text-brand-400"
                                        >
                                            <Globe className="h-3.5 w-3.5 text-neutral-400" />
                                            {company.website}
                                        </a>
                                    )}
                                    {company.phone && (
                                        <a
                                            href={`tel:${company.phone}`}
                                            className="inline-flex items-center gap-1.5 font-medium hover:text-brand-600 dark:hover:text-brand-400"
                                        >
                                            <Phone className="h-3.5 w-3.5 text-neutral-400" />
                                            {company.phone}
                                        </a>
                                    )}
                                    {company.address && (
                                        <span className="inline-flex items-center gap-1.5">
                                            <MapPin className="h-3.5 w-3.5 text-neutral-400" />
                                            {company.address}
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Badges & Owner */}
                        <div className="flex flex-wrap items-center gap-2 self-start md:self-auto">
                            {company.industry && (
                                <span className="inline-flex items-center rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700 dark:bg-brand-950/60 dark:text-brand-300">
                                    {company.industry}
                                </span>
                            )}
                            {company.size && (
                                <span className="inline-flex items-center rounded-full bg-neutral-100 px-3 py-1 text-xs font-semibold text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                    {sizes[company.size] || company.size}
                                </span>
                            )}
                            <div className="flex items-center gap-1.5 rounded-full border border-soft bg-neutral-50 px-3 py-1 text-xs font-medium text-neutral-700 dark:bg-neutral-800/80 dark:text-neutral-200">
                                <User className="h-3 w-3 text-neutral-400" />
                                <span>{company.owner?.name || t('leads.unassigned') || 'Unassigned'}</span>
                            </div>
                        </div>
                    </div>

                    {/* KPI Metrics Summary */}
                    <div className="mt-8 grid grid-cols-2 gap-4 border-t border-soft pt-6 sm:grid-cols-4">
                        <div className="rounded-xl bg-neutral-50 p-4 dark:bg-neutral-800/50">
                            <span className="text-xs font-medium text-neutral-500 dark:text-neutral-400 flex items-center gap-1">
                                <Users className="h-3.5 w-3.5" />
                                {t('companies.kpi_leads') || 'Total Leads'}
                            </span>
                            <div className="mt-1 text-2xl font-bold text-neutral-900 dark:text-neutral-100">
                                {metrics.total_leads}
                            </div>
                        </div>

                        <div className="rounded-xl bg-blue-50/60 p-4 dark:bg-blue-950/30">
                            <span className="text-xs font-medium text-blue-700 dark:text-blue-300 flex items-center gap-1">
                                <Clock className="h-3.5 w-3.5" />
                                {t('companies.kpi_open_pipeline') || 'Open Pipeline'}
                            </span>
                            <div className="mt-1 text-2xl font-bold text-blue-900 dark:text-blue-200">
                                ${Number(metrics.open_deals_value).toLocaleString()}
                            </div>
                        </div>

                        <div className="rounded-xl bg-emerald-50/60 p-4 dark:bg-emerald-950/30">
                            <span className="text-xs font-medium text-emerald-700 dark:text-emerald-300 flex items-center gap-1">
                                <CheckCircle2 className="h-3.5 w-3.5" />
                                {t('companies.kpi_won_revenue') || 'Won Revenue'}
                            </span>
                            <div className="mt-1 text-2xl font-bold text-emerald-900 dark:text-emerald-200">
                                ${Number(metrics.won_deals_value).toLocaleString()}
                            </div>
                        </div>

                        <div className="rounded-xl bg-rose-50/60 p-4 dark:bg-rose-950/30">
                            <span className="text-xs font-medium text-rose-700 dark:text-rose-300 flex items-center gap-1">
                                <XCircle className="h-3.5 w-3.5" />
                                {t('companies.kpi_lost_deals') || 'Lost Deals'}
                            </span>
                            <div className="mt-1 text-2xl font-bold text-rose-900 dark:text-rose-200">
                                ${Number(metrics.lost_deals_value).toLocaleString()}
                            </div>
                        </div>
                    </div>
                </div>

                {/* Associated Leads Section */}
                <div className="overflow-hidden rounded-2xl border border-soft bg-white shadow-xs dark:bg-neutral-900">
                    <div className="flex items-center justify-between border-b border-soft px-6 py-4">
                        <div className="flex items-center gap-2">
                            <Users className="h-5 w-5 text-neutral-500" />
                            <h2 className="text-base font-bold text-neutral-900 dark:text-neutral-100">
                                {t('companies.associated_leads') || 'Associated Leads'} ({leads.length})
                            </h2>
                        </div>

                        <div className="flex items-center gap-2">
                            <Link
                                href={route('client.leads.pipeline.index')}
                                className="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400"
                            >
                                {t('companies.go_to_pipeline') || 'Open Pipeline Board'} &rarr;
                            </Link>
                        </div>
                    </div>

                    {leads.length === 0 ? (
                        <div className="py-12 text-center">
                            <Users className="mx-auto h-8 w-8 text-neutral-300 dark:text-neutral-600" />
                            <p className="mt-2 text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                {t('companies.no_associated_leads') || 'No leads linked to this company yet'}
                            </p>
                            <p className="mt-1 text-xs text-neutral-400">
                                {t('companies.link_leads_hint') || 'You can link existing leads to this company from their card in the pipeline board.'}
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-start text-sm">
                                <thead>
                                    <tr className="border-b border-soft bg-neutral-50/75 dark:bg-neutral-800/50 text-xs font-medium text-neutral-500 dark:text-neutral-400">
                                        <th className="px-5 py-3 text-start">{t('leads.th_lead') || 'Lead'}</th>
                                        <th className="px-4 py-3 text-start">{t('leads.th_stage') || 'Stage & Pipeline'}</th>
                                        <th className="px-4 py-3 text-start">{t('leads.th_status') || 'Status'}</th>
                                        <th className="px-4 py-3 text-end">{t('leads.th_deal_value') || 'Deal Value'}</th>
                                        <th className="px-4 py-3 text-start">{t('leads.th_assigned') || 'Assigned'}</th>
                                        <th className="px-5 py-3 text-end">{t('common.actions') || 'Actions'}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-soft">
                                    {leads.map((lead) => (
                                        <tr key={lead.id} className="transition-colors hover:bg-neutral-50/50 dark:hover:bg-neutral-800/40">
                                            <td className="px-5 py-3.5">
                                                <div className="min-w-0">
                                                    <a
                                                        href={route('client.leads.pipeline.index', { lead: lead.id })}
                                                        className="font-semibold text-neutral-900 hover:text-brand-600 dark:text-neutral-100 dark:hover:text-brand-400 truncate block"
                                                    >
                                                        {lead.name}
                                                    </a>
                                                    <div className="flex items-center gap-2 text-xs text-neutral-400">
                                                        {lead.email && <span>{lead.email}</span>}
                                                        {lead.phone && <span>{lead.phone}</span>}
                                                    </div>
                                                </div>
                                            </td>

                                            <td className="px-4 py-3.5">
                                                <span className="inline-flex items-center rounded-md bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200">
                                                    {lead.stage?.name || '—'}
                                                </span>
                                                {lead.pipeline?.name && (
                                                    <div className="text-[10px] text-neutral-400">{lead.pipeline.name}</div>
                                                )}
                                            </td>

                                            <td className="px-4 py-3.5">
                                                <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                                    lead.status === 'won'
                                                        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                                                        : lead.status === 'lost'
                                                        ? 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
                                                        : 'bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'
                                                }`}>
                                                    {lead.status}
                                                </span>
                                            </td>

                                            <td className="px-4 py-3.5 text-end font-semibold text-neutral-900 dark:text-neutral-100 tabular-nums">
                                                {lead.deal_value ? `$${Number(lead.deal_value).toLocaleString()}` : '—'}
                                            </td>

                                            <td className="px-4 py-3.5 text-neutral-600 dark:text-neutral-300 text-xs">
                                                {lead.assigned_user?.name ? (
                                                    <span className="font-medium">{lead.assigned_user.name}</span>
                                                ) : (
                                                    <span className="text-neutral-400 italic">{t('leads.unassigned') || 'Unassigned'}</span>
                                                )}
                                            </td>

                                            <td className="px-5 py-3.5 text-end">
                                                <div className="flex items-center justify-end gap-1">
                                                    <a
                                                        href={route('client.leads.pipeline.index', { lead: lead.id })}
                                                        className="rounded-lg p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                                        title={t('common.view') || 'View in Pipeline'}
                                                    >
                                                        <ExternalLink className="h-4 w-4" />
                                                    </a>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleDetachLead(lead.id)}
                                                        className="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                                        title={t('companies.unlink_lead') || 'Unlink from Company'}
                                                    >
                                                        <Unlink className="h-4 w-4" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            {/* Edit Modal */}
            <Modal show={showEditModal} onClose={() => setShowEditModal(false)} maxWidth="lg">
                <form onSubmit={handleUpdate} className="p-6 space-y-4">
                    <div className="flex items-center justify-between border-b border-soft pb-4">
                        <h2 className="text-lg font-bold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                            <Building2 className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                            {t('companies.edit_company') || 'Edit Company'}
                        </h2>
                        <button
                            type="button"
                            onClick={() => setShowEditModal(false)}
                            className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200"
                        >
                            &times;
                        </button>
                    </div>

                    <div className="space-y-4">
                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                {t('companies.name') || 'Company Name'} <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                required
                                value={formName}
                                onChange={(e) => setFormName(e.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                    {t('companies.website') || 'Website URL'}
                                </label>
                                <input
                                    type="text"
                                    value={formWebsite}
                                    onChange={(e) => setFormWebsite(e.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                    {t('companies.domain') || 'Domain'}
                                </label>
                                <input
                                    type="text"
                                    value={formDomain}
                                    onChange={(e) => setFormDomain(e.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:bg-neutral-800 dark:text-neutral-100 font-mono text-xs"
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                    {t('companies.industry') || 'Industry'}
                                </label>
                                <select
                                    value={formIndustry}
                                    onChange={(e) => setFormIndustry(e.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                                >
                                    <option value="">{t('companies.select_industry') || 'Select industry...'}</option>
                                    {industries.map((ind) => (
                                        <option key={ind} value={ind}>{ind}</option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                    {t('companies.size') || 'Company Size'}
                                </label>
                                <select
                                    value={formSize}
                                    onChange={(e) => setFormSize(e.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                                >
                                    <option value="">{t('companies.select_size') || 'Select size...'}</option>
                                    {Object.entries(sizes).map(([key, lbl]) => (
                                        <option key={key} value={key}>{lbl}</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                    {t('companies.phone') || 'Phone'}
                                </label>
                                <input
                                    type="text"
                                    value={formPhone}
                                    onChange={(e) => setFormPhone(e.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                    {t('companies.owner') || 'Account Owner'}
                                </label>
                                <select
                                    value={formOwnerId}
                                    onChange={(e) => setFormOwnerId(e.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                                >
                                    <option value="">{t('leads.unassigned') || 'Unassigned'}</option>
                                    {teamMembers.map((m) => (
                                        <option key={m.id} value={m.id}>{m.name}</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                                {t('companies.address') || 'Address'}
                            </label>
                            <textarea
                                rows="2"
                                value={formAddress}
                                onChange={(e) => setFormAddress(e.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-soft pt-4">
                        <Button type="button" variant="secondary" onClick={() => setShowEditModal(false)}>
                            {t('common.cancel') || 'Cancel'}
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? (t('common.saving') || 'Saving...') : (t('common.save') || 'Save')}
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Delete Modal */}
            <Modal show={showDeleteModal} onClose={() => setShowDeleteModal(false)} maxWidth="sm">
                <div className="p-6">
                    <div className="flex items-center gap-3 text-red-600 dark:text-red-400">
                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-red-100 dark:bg-red-950/50">
                            <Trash2 className="h-5 w-5" />
                        </div>
                        <h3 className="text-base font-bold text-neutral-900 dark:text-neutral-100">
                            {t('companies.delete_title') || 'Delete Company'}
                        </h3>
                    </div>
                    <p className="mt-3 text-sm text-neutral-600 dark:text-neutral-400">
                        {t('companies.delete_confirm', { name: company.name }) || `Are you sure you want to delete ${company.name}? Associated leads will have their company link removed.`}
                    </p>
                    <div className="mt-6 flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setShowDeleteModal(false)}>
                            {t('common.cancel') || 'Cancel'}
                        </Button>
                        <Button variant="danger" onClick={handleDelete} disabled={submitting}>
                            {submitting ? (t('common.deleting') || 'Deleting...') : (t('common.delete') || 'Delete')}
                        </Button>
                    </div>
                </div>
            </Modal>
        </ClientLayout>
    );
}

