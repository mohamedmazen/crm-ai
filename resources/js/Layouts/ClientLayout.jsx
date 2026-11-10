import { Link, usePage, router } from '@inertiajs/react';
import { useOneSignal, isOnInboxOrDashboard, showBrowserNotification } from '@/hooks/useOneSignal';
import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Toaster, toast } from 'sonner';
import Topbar from '@/Components/Topbar';
import Sidebar from '@/Components/Sidebar';
import AreaNav from '@/Components/AreaNav';
import MobileTabBar from '@/Components/MobileTabBar';
import CommandPalette from '@/Components/CommandPalette';
import UpgradeModal from '@/Components/UpgradeModal';
import CookieConsent from '@/Components/CookieConsent';
import useClientNav from '@/Layouts/useClientNav';
import useNavPrefs from '@/hooks/useNavPrefs';
import { LayoutDashboard, Users, Inbox, KanbanSquare } from 'lucide-react';

function safeRoute(name, ...args) {
    try { return route(name, ...args); } catch { return '#'; }
}

function UsageBanner({ usage }) {
    const { t } = useTranslation();
    const [dismissed, setDismissed] = useState(false);

    const entries = Object.entries(usage ?? {});
    const overLimit = entries.filter(([, v]) => v.is_over);
    const nearLimit = entries.filter(([, v]) => ! v.is_over && v.percent >= 80);

    if (dismissed || (overLimit.length === 0 && nearLimit.length === 0)) return null;

    // An over-limit resource is more urgent than one merely near its cap, and
    // the worse overage matters most. Fall back to the highest percentage.
    const isOver = overLimit.length > 0;
    const [key, data] = (isOver ? overLimit : nearLimit).sort(
        (a, b) => (b[1].over_by ?? 0) - (a[1].over_by ?? 0) || b[1].percent - a[1].percent,
    )[0];
    const label = key.replace(/_per_month$/, '').replace(/_/g, ' ');

    return (
        <div className={[
            'flex items-center justify-between gap-4 border-b px-4 py-2 text-sm',
            isOver
                ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800'
                : 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800',
        ].join(' ')}>
            <div className={[
                'flex items-center gap-2',
                isOver ? 'text-red-800 dark:text-red-300' : 'text-amber-800 dark:text-amber-300',
            ].join(' ')}>
                <svg className="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                </svg>
                <span>
                    {isOver
                        ? t('ui.over_limit_banner', { label, current: data.current, limit: data.limit, over: data.over_by })
                        : t('ui.usage_banner', { percent: data.percent, label, current: data.current, limit: data.limit })}{' '}
                    <Link href={safeRoute('client.pricing')} className="font-semibold underline hover:no-underline">
                        {t('ui.usage_banner_upgrade')}
                    </Link>{' '}
                    {t('ui.usage_banner_suffix')}
                </span>
            </div>
            <button
                onClick={() => setDismissed(true)}
                className={[
                    'flex-shrink-0',
                    isOver
                        ? 'text-red-700 hover:text-red-800 dark:text-red-400 dark:hover:text-red-200'
                        : 'text-amber-700 hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-200',
                ].join(' ')}
                aria-label={t('common.dismiss')}
            >
                <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                </svg>
            </button>
        </div>
    );
}

function ClientLayoutFooter() {
    const { app_version: appVersion } = usePage().props;
    const v = typeof appVersion === 'string' && appVersion.trim() !== '' ? appVersion.trim() : '1.0.0';
    return (
        <div className="rounded-soft px-3 py-2 text-xs text-neutral-500 dark:text-neutral-400 tabular-nums">
            v{v}
        </div>
    );
}

export default function ClientLayout({ header, children, title }) {
    const { t } = useTranslation();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const { auth, impersonation, current_workspace_usage, unreadNotificationsCount, branding, onesignal } = usePage().props;
    const logoUrl = branding?.logo_url;
    const [unreadCount, setUnreadCount] = useState(unreadNotificationsCount ?? 0);
    const { home: navHome, areas: navAreas, help: navHelp } = useClientNav();
    const { collapsed, toggleCollapsed } = useNavPrefs();

    const mobileTabs = [
        { label: t('nav.dashboard'), href: safeRoute('client.dashboard'), icon: <LayoutDashboard className="h-5 w-5" />, activePattern: 'client.dashboard' },
        { label: t('nav.inbox'), href: safeRoute('client.inbox.index'), icon: <Inbox className="h-5 w-5" />, activePattern: ['client.inbox.index', 'client.inbox.show'] },
        { label: t('nav.lead_pipeline'), href: safeRoute('client.leads.pipeline.index'), icon: <KanbanSquare className="h-5 w-5" />, activePattern: 'client.leads.pipeline.*' },
        { label: t('nav.contacts'), href: safeRoute('client.contacts.index'), icon: <Users className="h-5 w-5" />, activePattern: 'client.contacts.*' },
    ];

    // Initialise OneSignal SDK (registers SW, requests push permission)
    useOneSignal({ appId: onesignal?.app_id, enabled: onesignal?.enabled });

    // Sync unread count from server on page changes
    useEffect(() => {
        setUnreadCount(unreadNotificationsCount ?? 0);
    }, [unreadNotificationsCount]);

    // Subscribe to broadcast notifications for this user
    useEffect(() => {
        if (! window.Echo || ! auth?.user?.id) return;

        window.Echo.private(`App.Models.User.${auth.user.id}`)
            .notification((notification) => {
                setUnreadCount(prev => prev + 1);
                const msg = notification.snippet ?? notification.name ?? notification.automation ?? notification.error ?? t('ui.notif_new');
                const title = {
                    new_message:          t('ui.notif_new_message'),
                    mention:              t('ui.notif_mention'),
                    conversation_assigned:t('ui.notif_conversation_assigned'),
                    lead_assigned:        t('ui.notif_lead_assigned'),
                    campaign_completed:   t('ui.notif_campaign_completed'),
                    automation_failed:    t('ui.notif_automation_failed'),
                    billing_failed:       t('ui.notif_billing_failed'),
                }[notification.type] ?? t('ui.notif_default');

                toast(title, {
                    description: msg,
                    action: notification.url ? { label: t('common.view'), onClick: () => router.visit(notification.url) } : undefined,
                });

                // Show a browser push notification when the user is not on inbox or dashboard
                if (!isOnInboxOrDashboard()) {
                    showBrowserNotification(title, msg, notification.url);
                }
            });

        return () => {
            window.Echo.leave(`App.Models.User.${auth.user.id}`);
        };
    }, [auth?.user?.id]);

    const returnToAdmin = () => {
        router.post(impersonation?.returnUrl ?? route('admin.impersonation.stop'));
    };

    const demoMode = usePage().props.demo_mode === true;

    const userNavItems = [
        { label: t('nav.profile') || 'Profile',           href: safeRoute('client.profile.edit'),    as: 'link' },
        { label: t('nav.twoFactor') || 'Two-Factor Auth', href: safeRoute('client.profile.2fa'),     as: 'link' },
        { label: t('nav.sessions') || 'Sessions',         href: safeRoute('client.profile.sessions'),as: 'link' },
        { type: 'divider' },
        { label: t('nav.logout') || 'Log Out',            href: route('logout'), method: 'post', as: 'link' },
    ];

    return (
        <div className="min-h-screen bg-neutral-50 dark:bg-neutral-950">
            {impersonation?.active && (
                <div className="flex items-center justify-between gap-4 bg-amber-500/90 text-white px-4 py-2 text-sm font-medium">
                    <span>{t('impersonation.impersonating', { name: impersonation.clientName })}</span>
                    <button
                        type="button"
                        onClick={returnToAdmin}
                        className="rounded-soft bg-white/20 px-3 py-1.5 font-medium hover:bg-white/30 transition"
                    >
                        {t('impersonation.return_to_admin')}
                    </button>
                </div>
            )}

            <Sidebar
                open={sidebarOpen}
                onClose={() => setSidebarOpen(false)}
                showCreateButton={false}
                home={navHome}
                areas={navAreas}
                help={navHelp}
                collapsed={collapsed}
                onToggleCollapsed={toggleCollapsed}
                enableCollapse
                enableSearch
                enableFavorites
                footer={<ClientLayoutFooter />}
            />

            <div className={[
                collapsed ? 'lg:ps-16' : 'lg:ps-64',
            ].join(' ')}>
                <Topbar
                    showLogo={false}
                    title={title}
                    userNavItems={userNavItems}
                    unreadCount={unreadCount}
                    showGlobalSearch
                />
                <UsageBanner usage={current_workspace_usage} />
                <AreaNav areas={navAreas} />

                <main className="p-4 pb-24 sm:p-6 lg:p-8">
                    {header && typeof header === 'object' && (
                        <div className="mb-6">
                            {header}
                        </div>
                    )}
                    {children}
                </main>
            </div>

            <CommandPalette searchRoute={safeRoute('client.search')} nav={{ home: navHome, areas: navAreas }} />

            <MobileTabBar items={mobileTabs} />

            {/* Mobile menu button (opens the full sidebar) */}
            <button
                type="button"
                onClick={() => setSidebarOpen(true)}
                className="fixed bottom-20 end-4 z-40 flex h-12 w-12 items-center justify-center rounded-soft-lg border border-soft border-gray-200 bg-white shadow-soft-lg text-neutral-600 hover:bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800 lg:hidden"
                aria-label={t('open_menu')}
            >
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <UpgradeModal />
            <CookieConsent />
            <Toaster richColors position="top-right" />
            {/* Demo notice — pinned to the bottom of the viewport */}
            {demoMode && (
                <div className="fixed inset-x-0 bottom-16 z-20 flex items-center justify-center gap-2 bg-amber-500/90 text-amber-950 px-4 py-2 text-sm font-medium pointer-events-none lg:bottom-0">
                    <span>{t('demo.banner') || 'Demo mode: changes are disabled.'}</span>
                </div>
            )}
        </div>
    );
}
