import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { Card } from '@/Components/ui';
import { Database, Download, Trash2, Plus, AlertCircle, CheckCircle, Loader2, ShieldCheck, ShieldAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import axios from 'axios';

function formatBytes(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return `${(bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString();
}

export default function BackupIndex({ backups = [], disk, databaseDriver, canCreate, error }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const [creating, setCreating] = useState(false);
    const [deleting, setDeleting] = useState(null);
    const [verifying, setVerifying] = useState(null);
    const [checks, setChecks] = useState({});

    const createBackup = () => {
        setCreating(true);
        router.post(route('admin.backups.create'), {}, {
            preserveScroll: true,
            onFinish: () => setCreating(false),
        });
    };

    const deleteBackup = (name) => {
        if (!confirm(t('backup.delete_confirm') || `Delete ${name}? This cannot be undone.`)) return;
        setDeleting(name);
        router.delete(route('admin.backups.destroy', name), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });
    };

    const verifyBackup = async (name) => {
        setVerifying(name);
        try {
            const { data } = await axios.get(route('admin.backups.verify', name));
            setChecks((prev) => ({ ...prev, [name]: data }));
        } catch (err) {
            setChecks((prev) => ({
                ...prev,
                [name]: { valid: false, message: err.response?.data?.message || t('backup.verify_failed') || 'Verification failed.' },
            }));
        } finally {
            setVerifying(null);
        }
    };

    return (
        <AdminLayout title={t('admin.nav.backups') || 'Backups'}>
            <Head title={`${t('admin.nav.backups') || 'Backups'} · ${t('head.admin') || 'Admin'}`} />
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                            {t('admin.nav.backups') || 'Database backups'}
                        </h2>
                        <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                            {t('backup.subtitle') || 'Download or remove database dumps stored on the backup disk.'}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={createBackup}
                        disabled={creating || !canCreate}
                        title={!canCreate ? (t('backup.mysql_only') || 'Only MySQL databases are supported.') : undefined}
                        className="inline-flex items-center gap-2 rounded-soft bg-brand-600 hover:bg-brand-700 disabled:opacity-60 px-4 py-2 text-sm font-medium text-white transition"
                    >
                        {creating ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
                        {creating ? (t('backup.creating') || 'Creating…') : (t('backup.create') || 'Create backup')}
                    </button>
                </div>

                {flash.success && (
                    <div className="flex items-center gap-2 rounded-soft bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2.5 text-sm">
                        <CheckCircle className="h-4 w-4 shrink-0" />
                        {flash.success}
                    </div>
                )}
                {(flash.error || error) && (
                    <div className="flex items-center gap-2 rounded-soft bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 px-4 py-2.5 text-sm">
                        <AlertCircle className="h-4 w-4 shrink-0" />
                        {flash.error || error}
                    </div>
                )}

                <Card>
                    <Card.Body className="space-y-4">
                        <div className="flex flex-wrap items-center gap-2 text-xs text-neutral-500 dark:text-neutral-400">
                            <Database className="h-4 w-4" />
                            <span>{t('backup.disk') || 'Disk'}: <strong className="font-mono">{disk}</strong></span>
                            <span className="mx-1">·</span>
                            <span>{t('backup.driver') || 'Driver'}: <strong className="font-mono">{databaseDriver}</strong></span>
                        </div>

                        {backups.length === 0 ? (
                            <p className="py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                                {t('backup.empty') || 'No backups yet. Create one, or schedule db:backup on the server.'}
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-neutral-200 dark:border-neutral-800 text-left text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                                            <th className="py-2 pr-4">{t('backup.file') || 'File'}</th>
                                            <th className="py-2 pr-4">{t('backup.size') || 'Size'}</th>
                                            <th className="py-2 pr-4">{t('backup.created') || 'Created'}</th>
                                            <th className="py-2 text-right">{t('common.actions') || 'Actions'}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {backups.map((backup) => (
                                            <tr key={backup.name} className="border-b border-neutral-100 dark:border-neutral-800/60 last:border-0">
                                                <td className="py-3 pr-4 font-mono text-xs text-neutral-700 dark:text-neutral-300 break-all">
                                                    {backup.name}
                                                    {checks[backup.name] && (
                                                        <span className={`ml-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ${checks[backup.name].valid ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-300' : 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300'}`}>
                                                            {checks[backup.name].valid ? <ShieldCheck className="h-3 w-3" /> : <ShieldAlert className="h-3 w-3" />}
                                                            {checks[backup.name].valid ? (t('backup.verified') || 'Verified') : (t('backup.invalid') || 'Problem')}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-400 whitespace-nowrap">{formatBytes(backup.size)}</td>
                                                <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-400 whitespace-nowrap">{formatDate(backup.created_at)}</td>
                                                <td className="py-3 text-right whitespace-nowrap">
                                                    <button
                                                        type="button"
                                                        onClick={() => verifyBackup(backup.name)}
                                                        disabled={verifying === backup.name}
                                                        className="inline-flex items-center gap-1 rounded-soft px-2 py-1 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:text-neutral-300 dark:hover:bg-neutral-800 disabled:opacity-60 transition"
                                                    >
                                                        {verifying === backup.name ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <ShieldCheck className="h-3.5 w-3.5" />}
                                                        {verifying === backup.name ? (t('backup.verifying') || 'Checking…') : (t('backup.verify') || 'Verify')}
                                                    </button>
                                                    <a
                                                        href={route('admin.backups.download', backup.name)}
                                                        className="inline-flex items-center gap-1 rounded-soft px-2 py-1 text-xs font-medium text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-900/20 transition"
                                                    >
                                                        <Download className="h-3.5 w-3.5" />
                                                        {t('backup.download') || 'Download'}
                                                    </a>
                                                    <button
                                                        type="button"
                                                        onClick={() => deleteBackup(backup.name)}
                                                        disabled={deleting === backup.name}
                                                        className="ml-2 inline-flex items-center gap-1 rounded-soft px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20 disabled:opacity-60 transition"
                                                    >
                                                        <Trash2 className="h-3.5 w-3.5" />
                                                        {t('common.delete') || 'Delete'}
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card.Body>
                </Card>
            </div>
        </AdminLayout>
    );
}
