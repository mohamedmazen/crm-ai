import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { ChannelBrandIcon } from '@/Components/BrandIcons';
import {
    LayoutDashboard, CreditCard, Package, FileText, Users, Settings,
    Layers, Webhook, Key, BookOpen, Image, Radio, Inbox, Bot, Database,
    Zap, Share2, MapPin, Tag, LifeBuoy, HelpCircle, Mail, MessageSquare,
    ShoppingBag, KanbanSquare, SlidersHorizontal, CheckSquare, Building2, Copy, Calendar, BarChart3, Phone, Receipt,
    ShieldCheck, Sparkles, Bell, UserCog,
} from 'lucide-react';

const iconClass = 'h-4 w-4';
const whatsappNavIcon = <ChannelBrandIcon channel="whatsapp" className={iconClass} />;

function safeRoute(name, ...args) {
    try { return route(name, ...args); } catch { return '#'; }
}

/**
 * Single source of truth for the client-panel navigation.
 *
 * The sidebar is organised into a small, fixed set of top-level **areas**
 * (Phase 2 — navigation reorganisation). Every screen lives under one of them,
 * so the default view is short and predictable; each area's sub-nav is only
 * expanded when the area is active. Both ClientLayout and InboxLayout consume
 * this so the two never drift — keep every nav change here only.
 *
 * Two independent gates apply to every item:
 *  - `feature`      plan gate; locked items collapse into one "Upgrade" row.
 *  - `permission`   role gate (`module.view`); hidden items disappear entirely,
 *                   because an upgrade cannot grant them. The server enforces
 *                   the same keys in EnsureClientPermission.
 */
export default function useClientNav() {
    const { auth, branding, plan_features } = usePage().props;
    const { t } = useTranslation();
    const user = auth?.user;
    const docsUrl = branding?.docs_url;
    const isClientAdmin = user?.client_role === 'administrator';
    const planFeatures = plan_features ?? {};
    const rolePermissions = auth?.permissions ?? [];

    return useMemo(() => {
        const item = (label, href, icon, activePattern, feature, permission) => ({
            label,
            href,
            icon,
            activePattern,
            feature,
            permission,
        });

        // ---- Inbox -------------------------------------------------------
        const inboxItems = [
            item(t('nav.inbox'), safeRoute('client.inbox.index'), <Inbox className={iconClass} />, ['client.inbox.index', 'client.inbox.show'], null, 'inbox.view'),
            item(t('nav.channel_setup'), safeRoute('client.inbox.setup'), <SlidersHorizontal className={iconClass} />, 'client.inbox.setup*', null, 'inbox.view'),
            item(t('nav.canned_replies') || 'Canned Replies', safeRoute('client.inbox.canned-replies.index'), <MessageSquare className={iconClass} />, 'client.inbox.canned-replies.*', null, 'inbox.view'),
            item(t('nav.labels') || 'Labels', safeRoute('client.inbox.labels.index'), <Tag className={iconClass} />, 'client.inbox.labels.*', null, 'inbox.view'),
        ];

        // ---- Contacts ----------------------------------------------------
        const contactsItems = [
            item(t('nav.contacts'), safeRoute('client.contacts.index'), <Users className={iconClass} />, 'client.contacts.*', null, 'contacts.view'),
            item(t('nav.segments'), safeRoute('client.segments.index'), <Tag className={iconClass} />, 'client.segments.*', null, 'contacts.view'),
        ];

        // ---- Sales -------------------------------------------------------
        const salesItems = [
            item(t('nav.lead_scraper'), safeRoute('client.leads.index'), <MapPin className={iconClass} />, ['client.leads.index', 'client.leads.show'], null, 'leads.view'),
            item(t('nav.lead_pipeline'), safeRoute('client.leads.pipeline.index'), <KanbanSquare className={iconClass} />, 'client.leads.pipeline.*', null, 'leads.view'),
            item(t('nav.companies') || 'Companies', safeRoute('client.leads.companies.index'), <Building2 className={iconClass} />, 'client.leads.companies.*', 'companies', 'leads.view'),
            item(t('nav.pipelines') || 'Pipelines', safeRoute('client.leads.pipelines.index'), <SlidersHorizontal className={iconClass} />, 'client.leads.pipelines.*', 'multi_pipeline', 'leads.view'),
            item(t('nav.duplicates') || 'Duplicates', safeRoute('client.leads.duplicates.index'), <Copy className={iconClass} />, 'client.leads.duplicates.*', 'duplicates', 'leads.view'),
            item(t('nav.tasks') || 'Tasks', safeRoute('client.tasks.index'), <CheckSquare className={iconClass} />, 'client.tasks.*', 'tasks', 'leads.view'),
            item(t('nav.quotes') || 'Quotes', safeRoute('client.sales.quotes.index'), <FileText className={iconClass} />, 'client.sales.quotes.*', 'quotes', 'quotes.view'),
            item(t('nav.invoices') || 'Invoices', safeRoute('client.sales.invoices.index'), <Receipt className={iconClass} />, 'client.sales.invoices.*', 'quotes', 'invoices.view'),
            item(t('nav.sales_products') || 'Products', safeRoute('client.sales.products.index'), <Package className={iconClass} />, 'client.sales.products.*', 'quotes', 'quotes.view'),
            item(t('nav.sequences') || 'Sequences', safeRoute('client.sales.sequences.index'), <Mail className={iconClass} />, 'client.sales.sequences.*', 'sequences', 'sequences.view'),
            item(t('nav.calls') || 'Call Log', safeRoute('client.sales.calls.index'), <Phone className={iconClass} />, 'client.sales.calls.*', 'call_logs', 'calls.view'),
            item(t('nav.ai_sdr') || 'AI SDR', safeRoute('client.sales.ai-sdr.index'), <Sparkles className={iconClass} />, 'client.sales.ai-sdr.*', 'ai_sdr', 'ai_sdr.view'),
            item(t('nav.orders'), safeRoute('client.ecommerce.orders.index'), <ShoppingBag className={iconClass} />, 'client.ecommerce.orders.*', null, 'ecommerce.view'),
            item(t('nav.products'), safeRoute('client.ecommerce.products.index'), <Tag className={iconClass} />, 'client.ecommerce.products.*', null, 'ecommerce.view'),
            item(t('nav.stores'), safeRoute('client.ecommerce.stores.index'), <ShoppingBag className={iconClass} />, 'client.ecommerce.stores.*', null, 'ecommerce.view'),
        ];

        // ---- Marketing ---------------------------------------------------
        const marketingItems = [
            item(t('nav.campaigns'), safeRoute('client.campaigns.index'), <Radio className={iconClass} />, 'client.campaigns.*', null, 'campaigns.view'),
            item(t('nav.templates'), safeRoute('client.whatsapp.templates.index'), whatsappNavIcon, 'client.whatsapp.templates.*', null, 'whatsapp_marketing.view'),
            item(t('nav.auto_replies'), safeRoute('client.whatsapp.auto-replies.index'), whatsappNavIcon, 'client.whatsapp.auto-replies.*', null, 'whatsapp_marketing.view'),
            item(t('nav.chat_widget'), safeRoute('client.whatsapp.widget.index'), whatsappNavIcon, 'client.whatsapp.widget.*', null, 'whatsapp_marketing.view'),
            item(t('nav.sms_gateways'), safeRoute('client.sms-gateways.index'), <MessageSquare className={iconClass} />, 'client.sms-gateways.*', null, 'sms.view'),
            item(t('nav.email_server'), safeRoute('client.email-server.index'), <Mail className={iconClass} />, 'client.email-server.*', null, 'email.view'),
            item(t('nav.post_composer'), safeRoute('client.social.composer'), <FileText className={iconClass} />, 'client.social.composer', null, 'social.view'),
            item(t('nav.posts'), safeRoute('client.social.posts.index'), <Radio className={iconClass} />, 'client.social.posts.*', null, 'social.view'),
            item(t('nav.calendar'), safeRoute('client.social.calendar'), <Calendar className={iconClass} />, 'client.social.calendar', null, 'social.view'),
            item(t('nav.social_accounts'), safeRoute('client.social.accounts.index'), <Share2 className={iconClass} />, 'client.social.accounts.*', null, 'social.view'),
            item(t('nav.forms') || 'Forms', safeRoute('client.sales.forms.index'), <FileText className={iconClass} />, 'client.sales.forms.*', 'forms', 'bookings.view'),
            item(t('nav.booking') || 'Booking Pages', safeRoute('client.sales.booking.index'), <Calendar className={iconClass} />, 'client.sales.booking.*', 'booking', 'bookings.view'),
            item(t('nav.lead_sources') || 'Lead Sources', safeRoute('client.sales.lead-sources.index'), <Radio className={iconClass} />, 'client.sales.lead-sources.*', 'lead_sources', 'bookings.view'),
        ];

        // ---- Automation --------------------------------------------------
        const automationItems = [
            item(t('nav.automations'), safeRoute('client.automations.index'), <Zap className={iconClass} />, 'client.automations.*', null, 'automations.view'),
        ];

        // ---- Reports -----------------------------------------------------
        const reportsItems = [
            item(t('nav.sales_reports'), safeRoute('client.reports.sales.volume'), <BarChart3 className={iconClass} />, 'client.reports.sales.*', 'sales_reports', 'reports.view'),
            item(t('nav.reports_inbox'), safeRoute('client.reports.inbox.index'), <Inbox className={iconClass} />, 'client.reports.inbox.*', null, 'reports.view'),
            item(t('nav.campaigns'), safeRoute('client.reports.campaigns.index'), <Radio className={iconClass} />, 'client.reports.campaigns.*', null, 'reports.view'),
            item(t('nav.automations'), safeRoute('client.reports.automations.index'), <Zap className={iconClass} />, 'client.reports.automations.*', null, 'reports.view'),
            item(t('nav.ai_usage'), safeRoute('client.reports.ai.index'), <Bot className={iconClass} />, 'client.reports.ai.*', null, 'reports.view'),
            item(t('nav.social'), safeRoute('client.reports.social.index'), <Share2 className={iconClass} />, 'client.reports.social.*', null, 'reports.view'),
        ];

        // ---- Settings (everything administrative lives here) -------------
        const settingsItems = [
            item(t('nav.settings'), safeRoute('client.settings.index'), <Settings className={iconClass} />, 'client.settings.index'),
            item(t('nav.workspaces'), safeRoute('client.workspaces.index'), <Layers className={iconClass} />, 'client.workspaces.*', null, 'settings.view'),
            item(t('nav.notifications') || 'Notifications', safeRoute('client.notifications.index'), <Bell className={iconClass} />, 'client.notifications.*'),
            item(t('nav.media_library'), safeRoute('client.media.index'), <Image className={iconClass} />, 'client.media.*', null, 'settings.view'),
            item(t('nav.custom_fields'), safeRoute('client.custom-fields.index'), <SlidersHorizontal className={iconClass} />, 'client.custom-fields.*', 'custom_fields', 'contacts.view'),
            item(t('nav.subscription'), safeRoute('client.subscription.show'), <CreditCard className={iconClass} />, 'client.subscription.*', null, 'billing.view'),
            item(t('nav.billing'), safeRoute('client.billing.index'), <CreditCard className={iconClass} />, 'client.billing.*', null, 'billing.view'),
            item(t('nav.plans'), safeRoute('client.pricing'), <Package className={iconClass} />, 'client.pricing', null, 'billing.view'),
            item(t('nav.api_tokens'), safeRoute('client.api-tokens.index'), <Key className={iconClass} />, 'client.api-tokens.*', null, 'settings.view'),
            item(t('nav.webhooks'), safeRoute('client.webhooks.index'), <Webhook className={iconClass} />, 'client.webhooks.*', null, 'settings.view'),
            item(t('nav.api_docs'), safeRoute('client.api-docs'), <BookOpen className={iconClass} />, 'client.api-docs', null, 'settings.view'),
            item(t('nav.support_tickets'), safeRoute('client.support.index'), <LifeBuoy className={iconClass} />, 'client.support.*', null, 'settings.view'),
            item(t('nav.chatbots'), safeRoute('client.ai.chatbots.index'), <Bot className={iconClass} />, 'client.ai.chatbots.*', null, 'ai.view'),
            item(t('nav.knowledge_bases'), safeRoute('client.ai.knowledge-bases.index'), <Database className={iconClass} />, 'client.ai.knowledge-bases.*', null, 'ai.view'),
            item(t('nav.ai_providers'), safeRoute('client.ai.providers.index'), <Bot className={iconClass} />, 'client.ai.providers.*', null, 'ai.view'),
        ];

        if (isClientAdmin) {
            settingsItems.push(
                item(t('nav.team'), safeRoute('client.team.index'), <Users className={iconClass} />, 'client.team.*', null, 'team.view'),
                item(t('nav.roles_permissions') || 'Roles & Permissions', safeRoute('client.roles.index'), <UserCog className={iconClass} />, 'client.roles.*', null, 'team.view'),
                item(t('nav.audit_log'), safeRoute('client.audit-log.index'), <FileText className={iconClass} />, 'client.audit-log.*', null, 'audit.view'),
                item(t('nav.gdpr'), safeRoute('client.settings.gdpr'), <ShieldCheck className={iconClass} />, 'client.settings.gdpr*', null, 'privacy.view'),
            );
        }

        const areas = [
            { key: 'inbox', label: t('nav.group_inbox'), icon: <Inbox className="h-5 w-5" />, items: inboxItems },
            { key: 'contacts', label: t('nav.group_contacts'), icon: <Users className="h-5 w-5" />, items: contactsItems },
            { key: 'sales', label: t('nav.group_sales'), icon: <KanbanSquare className="h-5 w-5" />, items: salesItems },
            { key: 'marketing', label: t('nav.area_marketing') || 'Marketing', icon: <Radio className="h-5 w-5" />, items: marketingItems },
            { key: 'automation', label: t('nav.group_automations'), icon: <Zap className="h-5 w-5" />, items: automationItems },
            { key: 'reports', label: t('nav.group_reports'), icon: <BarChart3 className="h-5 w-5" />, items: reportsItems },
            { key: 'settings', label: t('nav.settings'), icon: <Settings className="h-5 w-5" />, items: settingsItems },
        ];

        const canSee = (permission) => !permission || rolePermissions.includes(permission);

        // Split gated items: available ones stay in the sub-nav, plan-locked ones
        // collapse into one "Upgrade" row, and role-denied ones disappear (an
        // upgrade cannot grant a permission, so offering one would be a dead end).
        const visibleAreas = areas
            .map((area) => {
                const available = [];
                const locked = [];
                area.items.forEach((navItem) => {
                    if (!canSee(navItem.permission)) {
                        return;
                    }
                    if (navItem.feature && !planFeatures[navItem.feature]) {
                        locked.push(navItem);
                    } else {
                        available.push(navItem);
                    }
                });
                return { ...area, items: available, lockedItems: locked };
            })
            .filter((area) => area.items.length > 0 || area.lockedItems.length > 0);

        return {
            home: {
                key: 'home',
                label: t('nav.dashboard'),
                href: safeRoute('client.dashboard'),
                icon: <LayoutDashboard className="h-5 w-5" />,
                activePattern: 'client.dashboard',
            },
            areas: visibleAreas,
            help: docsUrl
                ? {
                    label: t('nav.help_center'),
                    href: docsUrl,
                    icon: <HelpCircle className="h-5 w-5" />,
                    external: true,
                }
                : null,
        };
    }, [t, planFeatures, isClientAdmin, docsUrl, rolePermissions]);
}
