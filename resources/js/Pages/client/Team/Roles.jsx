import ClientLayout from '@/Layouts/ClientLayout';
import { Button, Modal } from '@/Components/ui';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ArrowLeft, Pencil, Plus, ShieldCheck, Trash2, UserCog, Users } from 'lucide-react';

const EMPTY = { name: '', description: '', permissions: [] };

export default function TeamRoles({
    roles = [],
    catalog = { areas: {}, modules: [], templates: [] },
    client = {},
}) {
    const { t } = useTranslation();
    const { flash = {} } = usePage().props;

    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [templateKey, setTemplateKey] = useState('');

    const form = useForm({ ...EMPTY });

    const modulesByArea = useMemo(() => {
        return Object.entries(catalog.areas ?? {}).map(([key, label]) => ({
            key,
            label,
            modules: (catalog.modules ?? []).filter((module) => module.area === key),
        }));
    }, [catalog]);

    const openCreate = () => {
        form.reset();
        setEditing(null);
        setTemplateKey('');
        setOpen(true);
    };

    const openEdit = (role) => {
        form.setData({
            name: role.name,
            description: role.description ?? '',
            permissions: role.permissions ?? [],
        });
        form.clearErrors();
        setEditing(role);
        setTemplateKey('');
        setOpen(true);
    };

    const applyTemplate = (key) => {
        setTemplateKey(key);
        const template = (catalog.templates ?? []).find((item) => item.key === key);
        if (!template) return;
        form.setData({
            name: form.data.name || t(template.label),
            description: form.data.description,
            permissions: template.permissions,
        });
    };

    const has = (key) => form.data.permissions.includes(key);

    const toggle = (key) => {
        let next = has(key)
            ? form.data.permissions.filter((item) => item !== key)
            : [...form.data.permissions, key];

        // "Edit" without "view" is meaningless in this UI: ticking edit opens
        // the screen too, unticking view closes it.
        if (next.includes(key) && key.endsWith('.write')) {
            const view = key.replace(/\.write$/, '.view');
            if (!next.includes(view)) next = [...next, view];
        }
        if (!next.includes(key) && key.endsWith('.view')) {
            next = next.filter((item) => item !== key.replace(/\.view$/, '.write'));
        }

        form.setData('permissions', next);
    };

    const submit = (event) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => { setOpen(false); setEditing(null); form.reset(); },
        };

        if (editing) {
            form.put(route('client.roles.update', { role: editing.id }), options);
        } else {
            form.post(route('client.roles.store'), options);
        }
    };

    const confirmDelete = () => {
        if (!deleteTarget) return;
        const target = deleteTarget;
        // Close first: the Inertia visit re-renders this page, and a modal left
        // open through that transition would flash an empty title.
        setDeleteTarget(null);
        router.delete(route('client.roles.destroy', { role: target.id }), {
            preserveScroll: true,
        });
    };

    const canDelete = (role) => !role.system && (role.members_count ?? 0) === 0;

    return (
        <ClientLayout title={t('team.roles_title') || 'Roles & permissions'}>
            <Head title={t('team.roles_title') || 'Roles & permissions'} />
            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <Link
                            href={route('client.team.index')}
                            className="inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                        >
                            <ArrowLeft className="h-4 w-4 rtl:rotate-180" />
                            {t('team.back_to_team') || 'Back to team'}
                        </Link>
                        <h1 className="mt-1 text-2xl font-bold text-neutral-900 dark:text-white">
                            {t('team.roles_title') || 'Roles & permissions'}
                        </h1>
                        <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                            {t('team.roles_subtitle') || 'Decide which parts of the workspace each role can open and change.'}
                            {client?.name ? ` · ${client.name}` : ''}
                        </p>
                    </div>
                    <Button variant="primary" onClick={openCreate} className="inline-flex items-center gap-2">
                        <Plus className="h-4 w-4" />
                        {t('team.add_role') || 'Add role'}
                    </Button>
                </div>

                {flash?.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-200 px-4 py-3 text-sm">{flash.success}</div>
                )}
                {flash?.error && (
                    <div className="rounded-lg bg-coral-50 dark:bg-coral-900/20 text-coral-800 dark:text-coral-200 px-4 py-3 text-sm">{flash.error}</div>
                )}

                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800/50 overflow-hidden">
                    <table className="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                        <thead className="bg-neutral-50 dark:bg-neutral-800">
                            <tr>
                                <th className="px-4 py-3 text-start text-xs font-medium uppercase text-neutral-500 dark:text-neutral-400">{t('team.role_name') || 'Role'}</th>
                                <th className="px-4 py-3 text-start text-xs font-medium uppercase text-neutral-500 dark:text-neutral-400">{t('team.role_description') || 'Description'}</th>
                                <th className="px-4 py-3 text-start text-xs font-medium uppercase text-neutral-500 dark:text-neutral-400">{t('team.permissions_count') || 'Permissions'}</th>
                                <th className="px-4 py-3 text-start text-xs font-medium uppercase text-neutral-500 dark:text-neutral-400">{t('team.members_count') || 'Members'}</th>
                                <th className="px-4 py-3 text-end text-xs font-medium uppercase text-neutral-500 dark:text-neutral-400">{t('client.actions') || 'Actions'}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-200 dark:divide-neutral-700">
                            {roles.map((role) => (
                                <tr key={role.slug} className="bg-white dark:bg-neutral-800/30">
                                    <td className="px-4 py-3 text-sm font-medium text-neutral-900 dark:text-white">
                                        <span className="inline-flex items-center gap-2">
                                            {role.system ? <ShieldCheck className="h-4 w-4 text-neutral-400" aria-hidden /> : <UserCog className="h-4 w-4 text-neutral-400" aria-hidden />}
                                            {role.system ? t(role.name) : role.name}
                                        </span>
                                        {role.system && (
                                            <span className="ms-2 rounded-full bg-neutral-100 dark:bg-neutral-700 px-2 py-0.5 text-3xs font-medium text-neutral-600 dark:text-neutral-300">
                                                {t('team.builtin_role') || 'Built-in'}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-300">{role.description || '—'}</td>
                                    <td className="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-300">{role.permissions?.length ?? 0}</td>
                                    <td className="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-300">
                                        <span className="inline-flex items-center gap-1.5"><Users className="h-3.5 w-3.5" aria-hidden />{role.members_count ?? 0}</span>
                                    </td>
                                    <td className="px-4 py-3 text-end">
                                        {!role.system && (
                                            <div className="inline-flex gap-1">
                                                <button
                                                    type="button"
                                                    onClick={() => openEdit(role)}
                                                    className="rounded p-1.5 text-neutral-600 dark:text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-700"
                                                    aria-label={t('team.edit_role') || 'Edit role'}
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setDeleteTarget(role)}
                                                    disabled={!canDelete(role)}
                                                    title={canDelete(role) ? (t('team.delete_role') || 'Delete role') : (t('team.role_in_use') || 'Reassign the members using this role first')}
                                                    className="rounded p-1.5 text-neutral-500 hover:bg-coral-50 hover:text-coral-600 disabled:cursor-not-allowed disabled:opacity-40 dark:text-neutral-400 dark:hover:bg-coral-900/20"
                                                    aria-label={t('team.delete_role') || 'Delete role'}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </button>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {roles.length === 0 && (
                        <div className="px-6 py-12 text-center text-neutral-500 dark:text-neutral-400">
                            <UserCog className="mx-auto mb-3 h-12 w-12" />
                            <p>{t('team.no_roles') || 'No roles yet'}</p>
                        </div>
                    )}
                </div>

                <Modal show={open} onClose={() => setOpen(false)} maxWidth="2xl">
                    <Modal.Header
                        title={editing ? (t('team.edit_role') || 'Edit role') : (t('team.add_role') || 'Add role')}
                        onClose={() => setOpen(false)}
                    />
                    <Modal.Body>
                        <form id="roleForm" onSubmit={submit} className="space-y-5">
                            {!editing && (catalog.templates ?? []).length > 0 && (
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                        {t('team.start_from_template') || 'Start from a template'}
                                    </label>
                                    <select
                                        value={templateKey}
                                        onChange={(event) => applyTemplate(event.target.value)}
                                        className="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800"
                                    >
                                        <option value="">{t('team.no_template') || 'Start from scratch'}</option>
                                        {(catalog.templates ?? []).map((template) => (
                                            <option key={template.key} value={template.key}>{t(template.label)}</option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('team.role_name') || 'Role name'}</label>
                                    <input
                                        type="text"
                                        value={form.data.name}
                                        onChange={(event) => form.setData('name', event.target.value)}
                                        className="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800"
                                        required
                                    />
                                    {form.errors.name && <p className="mt-1 text-xs text-coral-600 dark:text-coral-400">{form.errors.name}</p>}
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('team.role_description') || 'Description'}</label>
                                    <input
                                        type="text"
                                        value={form.data.description}
                                        onChange={(event) => form.setData('description', event.target.value)}
                                        className="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800"
                                    />
                                    {form.errors.description && <p className="mt-1 text-xs text-coral-600 dark:text-coral-400">{form.errors.description}</p>}
                                </div>
                            </div>

                            <div className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('team.permissions') || 'Permissions'}</h3>
                                    <div className="flex gap-2 text-xs">
                                        <button type="button" onClick={() => form.setData('permissions', (catalog.modules ?? []).filter((m) => !m.admin_only).flatMap((m) => [m.view, m.edit].filter(Boolean)))} className="text-brand-700 hover:underline dark:text-brand-300">
                                            {t('team.select_all') || 'Select all'}
                                        </button>
                                        <button type="button" onClick={() => form.setData('permissions', [])} className="text-neutral-500 hover:underline dark:text-neutral-400">
                                            {t('team.clear_all') || 'Clear'}
                                        </button>
                                    </div>
                                </div>

                                {modulesByArea.map((area) => (
                                    <div key={area.key} className="rounded-lg border border-neutral-200 dark:border-neutral-700">
                                        <div className="border-b border-neutral-200 bg-neutral-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                                            {t(area.label)}
                                        </div>
                                        <div className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                            {area.modules.map((module) => (
                                                <div key={module.key} className="flex items-center justify-between gap-3 px-3 py-2">
                                                    <span className="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                                                        {t(module.label)}
                                                        {module.admin_only && (
                                                            <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-3xs font-medium text-neutral-500 dark:bg-neutral-700 dark:text-neutral-300">
                                                                {t('team.admins_only') || 'Administrators only'}
                                                            </span>
                                                        )}
                                                    </span>
                                                    <span className="flex items-center gap-4">
                                                        <label className={`inline-flex items-center gap-1.5 text-xs ${module.admin_only ? 'text-neutral-400 dark:text-neutral-500' : 'text-neutral-600 dark:text-neutral-400'}`}>
                                                            <input
                                                                type="checkbox"
                                                                checked={has(module.view)}
                                                                onChange={() => toggle(module.view)}
                                                                disabled={module.admin_only}
                                                                className="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 disabled:opacity-40"
                                                            />
                                                            {t('permissions.actions.view') || 'View'}
                                                        </label>
                                                        {module.edit && (
                                                            <label className={`inline-flex items-center gap-1.5 text-xs ${module.admin_only ? 'text-neutral-400 dark:text-neutral-500' : 'text-neutral-600 dark:text-neutral-400'}`}>
                                                                <input
                                                                    type="checkbox"
                                                                    checked={has(module.edit)}
                                                                    onChange={() => toggle(module.edit)}
                                                                    disabled={module.admin_only}
                                                                    className="rounded border-neutral-300 text-brand-600 focus:ring-brand-500 disabled:opacity-40"
                                                                />
                                                                {t('permissions.actions.edit') || 'Create & edit'}
                                                            </label>
                                                        )}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                                {form.errors.permissions && <p className="text-xs text-coral-600 dark:text-coral-400">{form.errors.permissions}</p>}
                            </div>
                        </form>
                    </Modal.Body>
                    <Modal.Footer>
                        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>{t('client.cancel') || 'Cancel'}</Button>
                        <Button type="submit" form="roleForm" variant="primary" disabled={form.processing}>
                            {editing ? (t('client.save') || 'Save') : (t('team.add_role') || 'Add role')}
                        </Button>
                    </Modal.Footer>
                </Modal>

                <Modal show={!!deleteTarget} onClose={() => setDeleteTarget(null)} maxWidth="sm">
                    <Modal.Body>
                        <p className="text-neutral-700 dark:text-neutral-200">
                            {t('team.delete_role_confirm', { name: deleteTarget?.name })}
                        </p>
                    </Modal.Body>
                    <Modal.Footer>
                        <Button variant="secondary" onClick={() => setDeleteTarget(null)}>{t('client.cancel') || 'Cancel'}</Button>
                        <Button variant="primary" className="bg-coral-600 hover:bg-coral-500" onClick={confirmDelete}>{t('client.remove') || 'Remove'}</Button>
                    </Modal.Footer>
                </Modal>
            </div>
        </ClientLayout>
    );
}