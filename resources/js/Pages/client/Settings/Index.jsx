import ClientLayout from '@/Layouts/ClientLayout';
import { Button } from '@/Components/ui';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Settings as SettingsIcon, Bell, Download, Globe } from 'lucide-react';
import { browserTz } from '@/Utils/datetime';
import TimezonePicker from '@/Components/TimezonePicker';

export default function ClientSettingsIndex({
    preferences = {},
    supportedLocales = [],
    supportedCurrencies = [],
    client = null,
    digestEnabled = true,
    tenantBaseDomain = null,
    tenantBaseDomainEnabled = false,
}) {
    const { t } = useTranslation();
    const { flash = {} } = usePage().props;
    const form = useForm({
        locale: preferences.locale ?? 'en',
        display_currency: preferences.display_currency ?? 'USD',
        theme: preferences.theme ?? 'light',
        timezone: preferences.timezone ?? browserTz() ?? 'Asia/Dhaka',
        client_name: client?.name ?? '',
        client_email: client?.email ?? '',
        client_phone: client?.phone ?? '',
        client_address: client?.address ?? '',
        client_subdomain: client?.subdomain ?? '',
        client_custom_domain: client?.custom_domain ?? '',
        weekly_digest_enabled: digestEnabled,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        form.put(route('client.settings.update'));
    };

    return (
        <ClientLayout title={t('settings.page_title') || 'Settings'}>
            <Head title={t('settings.page_title') || 'Settings'} />
            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-bold text-neutral-900 dark:text-white">
                        {t('settings.page_title') || 'Settings'}
                    </h1>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        {t('client.settings_subtitle') || 'Preferences and organization'}
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-200 px-4 py-3 text-sm">
                        {flash.success}
                    </div>
                )}

                <form onSubmit={handleSubmit} className="space-y-8">
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800/50 p-6">
                        <h2 className="text-lg font-semibold text-neutral-900 dark:text-white mb-4 flex items-center gap-2">
                            <SettingsIcon className="h-5 w-5" />
                            {t('client.preferences') || 'Preferences'}
                        </h2>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                    {t('client.language') || 'Language'}
                                </label>
                                <select
                                    value={form.data.locale}
                                    onChange={e => form.setData('locale', e.target.value)}
                                    className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                                >
                                    {supportedLocales.map((l) => (
                                        <option key={l.code} value={l.code}>{l.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                    {t('client.display_currency') || 'Display currency'}
                                </label>
                                <select
                                    value={form.data.display_currency}
                                    onChange={e => form.setData('display_currency', e.target.value)}
                                    className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                                >
                                    {supportedCurrencies.map((c) => (
                                        <option key={c.code} value={c.code}>
                                            {c.code} {c.symbol ? `(${c.symbol})` : ''}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                    {t('client.theme') || 'Theme'}
                                </label>
                                <select
                                    value={form.data.theme}
                                    onChange={e => form.setData('theme', e.target.value)}
                                    className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                                >
                                    <option value="light">{t('client.theme_light') || 'Light'}</option>
                                    <option value="dark">{t('client.theme_dark') || 'Dark'}</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                    {t('settings.timezone')}
                                </label>
                                <TimezonePicker
                                    value={form.data.timezone}
                                    onChange={tz => form.setData('timezone', tz)}
                                />
                                {form.errors.timezone && <p className="mt-1 text-xs text-red-500">{form.errors.timezone}</p>}
                                <p className="mt-1 text-xs text-neutral-400">{t('settings.timezone_hint')}</p>
                            </div>
                        </div>
                    </div>

                    {client && (
                        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800/50 p-6">
                            <h2 className="text-lg font-semibold text-neutral-900 dark:text-white mb-4">
                                {t('client.organization') || 'Organization'}
                            </h2>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="sm:col-span-2">
                                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                        {t('client.organization_name') || 'Organization name'}
                                    </label>
                                    <input
                                        type="text"
                                        value={form.data.client_name}
                                        onChange={e => form.setData('client_name', e.target.value)}
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                        {t('client.organization_email') || 'Email'}
                                    </label>
                                    <input
                                        type="email"
                                        value={form.data.client_email}
                                        onChange={e => form.setData('client_email', e.target.value)}
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                        {t('client.phone') || 'Phone'}
                                    </label>
                                    <input
                                        type="text"
                                        value={form.data.client_phone}
                                        onChange={e => form.setData('client_phone', e.target.value)}
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                                    />
                                </div>
                                <div className="sm:col-span-2">
                                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                        {t('client.address') || 'Address'}
                                    </label>
                                    <textarea
                                        value={form.data.client_address}
                                        onChange={e => form.setData('client_address', e.target.value)}
                                        rows={2}
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                                    />
                                </div>
                            </div>
                        </div>
                    )}

                    {client && (
                        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800/50 p-6">
                            <h2 className="text-lg font-semibold text-neutral-900 dark:text-white mb-1 flex items-center gap-2">
                                <Globe className="h-5 w-5" />
                                {t('client.domains') || 'Domains'}
                            </h2>
                            <p className="mb-4 text-xs text-neutral-500 dark:text-neutral-400">
                                {t('client.domains_hint') || 'Point a wildcard DNS record at the platform to use subdomains. Custom domains need a CNAME record; SSL is issued automatically once DNS resolves.'}
                            </p>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                        {t('client.subdomain') || 'Workspace subdomain'}
                                    </label>
                                    <div className="flex items-stretch">
                                        <input
                                            type="text"
                                            value={form.data.client_subdomain}
                                            onChange={e => form.setData('client_subdomain', e.target.value.toLowerCase())}
                                            disabled={!tenantBaseDomainEnabled}
                                            placeholder="acme"
                                            className="min-w-0 flex-1 rounded-l-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm disabled:opacity-60"
                                        />
                                        <span className="flex items-center rounded-r-lg border border-l-0 border-neutral-300 dark:border-neutral-600 bg-neutral-50 dark:bg-neutral-800/60 px-3 text-xs font-mono text-neutral-500 dark:text-neutral-400">
                                            .{tenantBaseDomain || 'example.com'}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-xs text-neutral-400">
                                        {tenantBaseDomainEnabled
                                            ? (t('client.subdomain_hint') || 'Lowercase letters, digits and hyphens only.')
                                            : (t('client.subdomains_disabled') || 'Subdomains are disabled on this installation.')}
                                    </p>
                                    {form.errors.client_subdomain && <p className="mt-1 text-xs text-red-500">{form.errors.client_subdomain}</p>}
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                        {t('client.custom_domain') || 'Custom domain'}
                                    </label>
                                    <input
                                        type="text"
                                        value={form.data.client_custom_domain}
                                        onChange={e => form.setData('client_custom_domain', e.target.value.toLowerCase())}
                                        placeholder="crm.yourcompany.com"
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                                    />
                                    <p className="mt-1 text-xs text-neutral-400">
                                        {t('client.custom_domain_hint') || 'Optional. Example: crm.yourcompany.com'}
                                    </p>
                                    {form.errors.client_custom_domain && <p className="mt-1 text-xs text-red-500">{form.errors.client_custom_domain}</p>}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Email Digest */}
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 p-4 sm:p-5">
                        <h3 className="text-sm font-semibold text-neutral-600 dark:text-neutral-300 uppercase tracking-wide mb-3">{t('settings.email_reports')}</h3>
                        <label className="flex items-center gap-3 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={!!form.data.weekly_digest_enabled}
                                onChange={e => form.setData('weekly_digest_enabled', e.target.checked)}
                                className="h-4 w-4 rounded border-gray-300 text-indigo-600"
                            />
                            <span className="text-sm text-neutral-700 dark:text-neutral-300">
                                {t('settings.weekly_digest_label')}
                            </span>
                        </label>
                    </div>

                    {(
                        <Button type="submit" variant="primary" disabled={form.processing}>
                            {t('client.save_settings') || 'Save settings'}
                        </Button>
                    )}
                </form>

                <div className="mt-6 pt-6 border-t border-neutral-200 dark:border-neutral-700 space-y-3">
                    <Link
                        href={route('client.settings.notifications')}
                        className="flex items-center gap-2 text-sm text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300"
                    >
                        <Bell className="h-4 w-4" />
                        {t('settings.manage_notifications_link')} →
                    </Link>
                    <Link
                        href={route('client.settings.data-export')}
                        className="flex items-center gap-2 text-sm text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300"
                    >
                        <Download className="h-4 w-4" />
                        {t('settings.export_data_link')} →
                    </Link>
                </div>
            </div>
        </ClientLayout>
    );
}
