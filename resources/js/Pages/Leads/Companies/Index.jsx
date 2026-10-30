import { useState } from 'react';
import { Head, router, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import Modal from '@/Components/ui/Modal';
import {
    Building2, Plus, Search, Globe, Phone, MapPin,
    Edit2, Trash2, ExternalLink, Users, DollarSign,
    Filter, X, ArrowUpDown
} from 'lucide-react';

export default function CompaniesIndex({
    companies = { data: [], links: [], total: 0 },
    filters = {},
    industries = [],
    sizes = {},
    teamMembers = [],
}) {
    const { t } = useTranslation();
    const { flash } = usePage().props;

    const [search, setSearch] = useState(filters.search || '');
    const [industry, setIndustry] = useState(filters.industry || '');
    const [size, setSize] = useState(filters.size || '');
    const [ownerId, setOwnerId] = useState(filters.owner_id || '');

    const [showModal, setShowModal] = useState(false);
    const [editingCompany, setEditingCompany] = useState(null);
    const [deletingCompany, setDeletingCompany] = useState(null);

    // Form state
    const [formName, setFormName] = useState('');
    const [formWebsite, setFormWebsite] = useState('');
    const [formDomain, setFormDomain] = useState('');
    const [formIndustry, setFormIndustry] = useState('');
    const [formSize, setFormSize] = useState('');
    const [formPhone, setFormPhone] = useState('');
    const [formAddress, setFormAddress] = useState('');
    const [formOwnerId, setFormOwnerId] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const applyFilters = (newFilters = {}) => {
        const payload = {
            search,
            industry,
            size,
            owner_id: ownerId,
            ...newFilters,
        };

        // Strip empty keys
        Object.keys(payload).forEach((k) => {
            if (!payload[k]) delete payload[k];
        });

        router.get(route('client.leads.companies.index'), payload, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        applyFilters({ search });
    };

    const handleClearFilters = () => {
        setSearch('');
        setIndustry('');
        setSize('');
        setOwnerId('');
        router.get(route('client.leads.companies.index'), {}, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const openCreateModal = () => {
        setEditingCompany(null);
        setFormName('');
        setFormWebsite('');
        setFormDomain('');
        setFormIndustry('');
        setFormSize('');
        setFormPhone('');
        setFormAddress('');
        setFormOwnerId('');
        setShowModal(true);
    };

    const openEditModal = (comp) => {
        setEditingCompany(comp);
        setFormName(comp.name || '');
        setFormWebsite(comp.website || '');
        setFormDomain(comp.domain || '');
        setFormIndustry(comp.industry || '');
        setFormSize(comp.size || '');
        setFormPhone(comp.phone || '');
        setFormAddress(comp.address || '');
        setFormOwnerId(comp.owner_id ? String(comp.owner_id) : '');
        setShowModal(true);
    };

    // Auto-extract domain on website input change/blur
    const handleWebsiteChange = (val) => {
        setFormWebsite(val);
        if (!formDomain && val) {
            try {
                let clean = val.trim().toLowerCase();
                if (!clean.startsWith('http://') && !clean.startsWith('https://')) {
                    clean = 'https://' + clean;
                }
                const host = new URL(clean).hostname.replace(/^www\./, '');
                if (host) setFormDomain(host);
            } catch {
                // Ignore parse errors while typing
            }
        }
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (!formName.trim()) return;

        setSubmitting(true);
        const payload = {
            name: formName.trim(),
            website: formWebsite.trim() || null,
            domain: formDomain.trim() || null,
            industry: formIndustry || null,
            size: formSize || null,
            phone: formPhone.trim() || null,
            address: formAddress.trim() || null,
            owner_id: formOwnerId ? Number(formOwnerId) : null,
        };

        if (editingCompany) {
            router.put(route('client.leads.companies.update', editingCompany.id), payload, {
                onSuccess: () => setShowModal(false),
                onFinish: () => setSubmitting(false),
            });
        } else {
            router.post(route('client.leads.companies.store'), payload, {
                onSuccess: () => setShowModal(false),
                onFinish: () => setSubmitting(false),
            });
        }
    };

    const handleDelete = () => {
        if (!deletingCompany) return;
        setSubmitting(true);
        router.delete(route('client.leads.companies.destroy', deletingCompany.id), {
            onSuccess: () => setDeletingCompany(null),
            onFinish: () => setSubmitting(false),
        });
    };

    const hasActiveFilters = Boolean(search || industry || size || ownerId);

    return (
        <ClientLayout>
            <Head title={t('companies.title') || 'Companies'} />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-400">
                                <Building2 className="h-5 w-5" />
                            </div>
                            <div>
                                <h1 className="text-xl font-bold text-neutral-900 dark:text-neutral-100">
                                    {t('companies.title') || 'Companies'}
                                </h1>
                                <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                    {t('companies.subtitle') || 'Organize leads by organization, track company accounts and deal values.'}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button onClick={openCreateModal} className="inline-flex items-center gap-2">
                            <Plus className="h-4 w-4" />
                            {t('companies.new_company') || 'New Company'}
                        </Button>
                    </div>
                </div>

                {/* Filters Bar */}
                <div className="rounded-xl border border-soft bg-white p-4 shadow-xs dark:bg-neutral-900">
                    <form onSubmit={handleSearchSubmit} className="flex flex-wrap items-center gap-3">
                        <div className="relative min-w-[220px] flex-1">
                            <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-neutral-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder={t('companies.search_placeholder') || 'Search by company name, domain, phone...'}
                                className="w-full rounded-lg border-soft bg-neutral-50 ps-9 pe-4 py-2 text-sm text-neutral-900 focus:bg-white focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>

                        <select
                            value={industry}
                            onChange={(e) => {
                                setIndustry(e.target.value);
                                applyFilters({ industry: e.target.value });
                            }}
                            className="rounded-lg border-soft bg-neutral-50 py-2 px-3 text-sm text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200 focus:bg-white"
                        >
                            <option value="">{t('companies.all_industries') || 'All Industries'}</option>
                            {industries.map((ind) => (
                                <option key={ind} value={ind}>{ind}</option>
                            ))}
                        </select>

                        <select
                            value={size}
                            onChange={(e) => {
                                setSize(e.target.value);
                                applyFilters({ size: e.target.value });
                            }}
                            className="rounded-lg border-soft bg-neutral-50 py-2 px-3 text-sm text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200 focus:bg-white"
                        >
                            <option value="">{t('companies.all_sizes') || 'All Sizes'}</option>
                            {Object.entries(sizes).map(([key, lbl]) => (
                                <option key={key} value={key}>{lbl}</option>
                            ))}
                        </select>

                        <select
                            value={ownerId}
                            onChange={(e) => {
                                setOwnerId(e.target.value);
                                applyFilters({ owner_id: e.target.value });
                            }}
                            className="rounded-lg border-soft bg-neutral-50 py-2 px-3 text-sm text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200 focus:bg-white"
                        >
                            <option value="">{t('companies.all_owners') || 'All Owners'}</option>
                            {teamMembers.map((m) => (
                                <option key={m.id} value={m.id}>{m.name}</option>
                            ))}
                        </select>

                        <Button type="submit" variant="secondary" size="sm">
                            <Search className="h-3.5 w-3.5 mr-1" />
                            {t('common.filter') || 'Filter'}
                        </Button>

                        {hasActiveFilters && (
                            <button
                                type="button"
                                onClick={handleClearFilters}
                                className="inline-flex items-center gap-1 text-xs text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"
                            >
                                <X className="h-3.5 w-3.5" />
                                {t('common.clear') || 'Clear'}
                            </button>
                        )}
                    </form>
                </div>

                {/* Companies Table */}
                <div className="overflow-hidden rounded-xl border border-soft bg-white shadow-xs dark:bg-neutral-900">
                    {companies.data.length === 0 ? (
                        <div className="py-16 text-center">
                            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-neutral-100 text-neutral-400 dark:bg-neutral-800">
                                <Building2 className="h-6 w-6" />
                            </div>
                            <h3 className="mt-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                                {hasActiveFilters ? (t('companies.no_matches') || 'No matching companies') : (t('companies.empty_title') || 'No companies yet')}
                            </h3>
                            <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                {hasActiveFilters ? (t('companies.try_filters') || 'Try clearing or modifying your search filters.') : (t('companies.empty_subtitle') || 'Create companies to group leads, track organizational deals, and centralize communications.')}
                            </p>
                            {!hasActiveFilters && (
                                <div className="mt-4">
                                    <Button onClick={openCreateModal} size="sm">
                                        <Plus className="h-3.5 w-3.5 mr-1.5" />
                                        {t('companies.new_company') || 'New Company'}
                                    </Button>
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-start text-sm">
                                <thead>
                                    <tr className="border-b border-soft bg-neutral-50/75 dark:bg-neutral-800/50 text-xs font-medium text-neutral-500 dark:text-neutral-400">
                                        <th className="px-5 py-3 text-start">{t('companies.th_company') || 'Company'}</th>
                                        <th className="px-4 py-3 text-start">{t('companies.th_industry') || 'Industry & Size'}</th>
                                        <th className="px-4 py-3 text-center">{t('companies.th_leads') || 'Leads'}</th>
                                        <th className="px-4 py-3 text-end">{t('companies.th_pipeline_value') || 'Pipeline Value'}</th>
                                        <th className="px-4 py-3 text-start">{t('companies.th_owner') || 'Account Owner'}</th>
                                        <th className="px-5 py-3 text-end">{t('common.actions') || 'Actions'}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-soft">
                                    {companies.data.map((company) => (
                                        <tr key={company.id} className="transition-colors hover:bg-neutral-50/50 dark:hover:bg-neutral-800/40">
                                            <td className="px-5 py-3.5">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 font-bold text-xs text-brand-700 dark:bg-brand-950/60 dark:text-brand-300">
                                                        {company.name.substring(0, 2).toUpperCase()}
                                                    </div>
                                                    <div className="min-w-0">
                                                        <Link
                                                            href={route('client.leads.companies.show', company.id)}
                                                            className="font-semibold text-neutral-900 hover:text-brand-600 dark:text-neutral-100 dark:hover:text-brand-400 truncate block"
                                                        >
                                                            {company.name}
                                                        </Link>
                                                        <div className="flex items-center gap-2 text-xs text-neutral-500 dark:text-neutral-400">
                                                            {company.domain && (
                                                                <span className="font-mono text-[11px] text-neutral-400">{company.domain}</span>
                                                            )}
                                                            {company.website && (
                                                                <a
                                                                    href={company.website}
                                                                    target="_blank"
                                                                    rel="noreferrer"
                                                                    className="hover:text-brand-600 dark:hover:text-brand-400"
                                                                    title={company.website}
                                                                >
                                                                    <ExternalLink className="h-3 w-3" />
                                                                </a>
                                                            )}
                                                            {company.phone && (
                                                                <span className="text-neutral-400 flex items-center gap-1">
                                                                    <Phone className="h-2.5 w-2.5" />
                                                                    {company.phone}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td className="px-4 py-3.5 text-neutral-600 dark:text-neutral-300">
                                                <div>
                                                    <span className="font-medium text-xs text-neutral-800 dark:text-neutral-200">
                                                        {company.industry || '—'}
                                                    </span>
                                                    {company.size && (
                                                        <div className="text-[11px] text-neutral-400">
                                                            {sizes[company.size] || company.size}
                                                        </div>
                                                    )}
                                                </div>
                                            </td>

                                            <td className="px-4 py-3.5 text-center">
                                                <span className="inline-flex items-center rounded-full bg-neutral-100 px-2.5 py-0.5 text-xs font-semibold text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                                    {company.leads_count ?? 0}
                                                </span>
                                            </td>

                                            <td className="px-4 py-3.5 text-end font-semibold text-neutral-900 dark:text-neutral-100 tabular-nums">
                                                ${Number(company.total_deals_value ?? 0).toLocaleString()}
                                            </td>

                                            <td className="px-4 py-3.5 text-neutral-600 dark:text-neutral-300 text-xs">
                                                {company.owner?.name ? (
                                                    <span className="inline-flex items-center gap-1.5 font-medium">
                                                        <span className="h-2 w-2 rounded-full bg-emerald-500"></span>
                                                        {company.owner.name}
                                                    </span>
                                                ) : (
                                                    <span className="text-neutral-400 italic">{t('leads.unassigned') || 'Unassigned'}</span>
                                                )}
                                            </td>

                                            <td className="px-5 py-3.5 text-end">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Link
                                                        href={route('client.leads.companies.show', company.id)}
                                                        className="rounded-lg p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                                        title={t('common.view') || 'View'}
                                                    >
                                                        <ExternalLink className="h-4 w-4" />
                                                    </Link>
                                                    <button
                                                        type="button"
                                                        onClick={() => openEditModal(company)}
                                                        className="rounded-lg p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                                        title={t('common.edit') || 'Edit'}
                                                    >
                                                        <Edit2 className="h-4 w-4" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => setDeletingCompany(company)}
                                                        className="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                                        title={t('common.delete') || 'Delete'}
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {/* Pagination */}
                    {companies.links && companies.links.length > 3 && (
                        <div className="flex items-center justify-between border-t border-soft px-5 py-3 bg-neutral-50/50 dark:bg-neutral-800/30">
                            <div className="text-xs text-neutral-500">
                                {t('common.total') || 'Total'}: <span className="font-semibold text-neutral-800 dark:text-neutral-200">{companies.total}</span>
                            </div>
                            <div className="flex items-center gap-1">
                                {companies.links.map((link, i) => (
                                    link.url ? (
                                        <Link
                                            key={i}
                                            href={link.url}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                            className={`rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${
                                                link.active
                                                    ? 'bg-brand-500 text-white'
                                                    : 'text-neutral-600 hover:bg-neutral-200/60 dark:text-neutral-300 dark:hover:bg-neutral-700'
                                            }`}
                                        />
                                    ) : (
                                        <span
                                            key={i}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                            className="px-2 py-1 text-xs text-neutral-400 cursor-not-allowed"
                                        />
                                    )
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* Create/Edit Modal */}
            <Modal show={showModal} onClose={() => setShowModal(false)} maxWidth="lg">
                <form onSubmit={handleSubmit} className="p-6 space-y-4">
                    <div className="flex items-center justify-between border-b border-soft pb-4">
                        <h2 className="text-lg font-bold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                            <Building2 className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                            {editingCompany ? (t('companies.edit_company') || 'Edit Company') : (t('companies.new_company') || 'New Company')}
                        </h2>
                        <button
                            type="button"
                            onClick={() => setShowModal(false)}
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
                                placeholder="Acme Corporation"
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
                                    onChange={(e) => handleWebsiteChange(e.target.value)}
                                    placeholder="https://example.com"
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
                                    placeholder="example.com"
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
                                    {t('companies.phone') || 'Phone Number'}
                                </label>
                                <input
                                    type="text"
                                    value={formPhone}
                                    onChange={(e) => setFormPhone(e.target.value)}
                                    placeholder="+1 555-0100"
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
                                {t('companies.address') || 'Address / Location'}
                            </label>
                            <textarea
                                rows="2"
                                value={formAddress}
                                onChange={(e) => setFormAddress(e.target.value)}
                                placeholder="100 Main St, Suite 400, New York, NY"
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm text-neutral-900 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-soft pt-4">
                        <Button type="button" variant="secondary" onClick={() => setShowModal(false)}>
                            {t('common.cancel') || 'Cancel'}
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? (t('common.saving') || 'Saving...') : (t('common.save') || 'Save')}
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Delete Confirmation Modal */}
            <Modal show={!!deletingCompany} onClose={() => setDeletingCompany(null)} maxWidth="sm">
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
                        {t('companies.delete_confirm', { name: deletingCompany?.name }) || `Are you sure you want to delete ${deletingCompany?.name}? Associated leads will not be deleted, but their company link will be removed.`}
                    </p>
                    <div className="mt-6 flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDeletingCompany(null)}>
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

