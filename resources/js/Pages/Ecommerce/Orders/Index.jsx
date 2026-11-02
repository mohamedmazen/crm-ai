import { Head, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import PageHeader from '@/Components/PageHeader';
import DataTable from '@/Components/DataTable';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Search, ShoppingBag, DollarSign, PackageCheck, Clock } from 'lucide-react';
import useInertiaLoading from '@/hooks/useInertiaLoading';

function StatCard({ label, value, Icon, tone = 'neutral' }) {
    const tones = {
        neutral: 'text-neutral-900 dark:text-neutral-100',
        green: 'text-green-700 dark:text-green-400',
        amber: 'text-amber-700 dark:text-amber-400',
    };
    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4 flex items-center gap-3">
            {Icon && <Icon className={`h-5 w-5 ${tones[tone]}`} />}
            <div>
                <p className="text-xs text-neutral-500 dark:text-neutral-400">{label}</p>
                <p className={`text-xl font-semibold ${tones[tone]}`}>{value}</p>
            </div>
        </div>
    );
}

function Badge({ value, map = {} }) {
    if (!value) return <span className="text-neutral-500 dark:text-neutral-400 text-xs">—</span>;
    const cls = map[value] || 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300';
    return <span className={`px-2 py-0.5 rounded-full text-xs ${cls}`}>{value}</span>;
}

const FULFILL_COLORS = {
    fulfilled: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
};

export default function OrdersIndex({ orders, filters = {}, stores = [], stats = {} }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const [search, setSearch] = useState(filters.search ?? '');
    const loading = useInertiaLoading();

    const apply = (next) => {
        router.get(route('client.ecommerce.orders.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    };

    const columns = [
        {
            key: 'number',
            header: t('ecommerce.order') || 'Order',
            sortable: true,
            cardTitle: true,
            render: (o) => <span className="font-medium text-neutral-800 dark:text-neutral-200">{o.number}</span>,
        },
        {
            key: 'customer',
            header: t('ecommerce.customer') || 'Customer',
            render: (o) => o.contact?.name || '—',
        },
        {
            key: 'total',
            header: t('ecommerce.total') || 'Total',
            sortable: true,
            align: 'right',
            render: (o) => <span className="font-medium">{o.currency} {o.total}</span>,
        },
        {
            key: 'financial_status',
            header: t('ecommerce.payment') || 'Payment',
            render: (o) => <Badge value={o.financial_status} />,
        },
        {
            key: 'fulfillment_status',
            header: t('ecommerce.fulfillment') || 'Fulfillment',
            render: (o) => <Badge value={o.fulfillment_status} map={FULFILL_COLORS} />,
        },
        {
            key: 'placed_at',
            header: t('ecommerce.date') || 'Date',
            sortable: true,
            render: (o) => <span className="text-neutral-500 dark:text-neutral-400">{o.placed_at ? new Date(o.placed_at).toLocaleDateString() : '—'}</span>,
        },
    ];

    const toolbar = (
        <div className="flex flex-wrap items-center gap-2">
            <form onSubmit={e => { e.preventDefault(); apply({ search }); }} className="relative flex-1 min-w-[200px]">
                <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-neutral-500 dark:text-neutral-400" />
                <input value={search} onChange={e => setSearch(e.target.value)} placeholder={t('ecommerce.search_orders') || 'Search order # or customer...'}
                    className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 ps-9 pe-3 py-2 text-sm" />
            </form>
            <select value={filters.store_id ?? ''} onChange={e => apply({ store_id: e.target.value || undefined })}
                aria-label={t('ecommerce.filter_store', { defaultValue: 'Filter by store' })}
                className="rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm">
                <option value="">{t('ecommerce.all_stores') || 'All stores'}</option>
                {stores.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
            <select value={filters.fulfillment ?? ''} onChange={e => apply({ fulfillment: e.target.value || undefined })}
                aria-label={t('ecommerce.filter_fulfillment', { defaultValue: 'Filter by fulfillment' })}
                className="rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm">
                <option value="">{t('ecommerce.all_fulfillment') || 'All fulfillment'}</option>
                <option value="fulfilled">Fulfilled</option>
            </select>
        </div>
    );

    return (
        <ClientLayout title={t('ecommerce.orders') || 'Orders'}>
            <Head title={t('ecommerce.orders') || 'Orders'} />
            <div className="space-y-5">
                <PageHeader
                    icon={ShoppingBag}
                    title={t('ecommerce.orders') || 'Orders'}
                    description={t('ecommerce.orders_sub') || 'Orders synced from your connected stores.'}
                />

                {flash.success && <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">{flash.success}</div>}
                {flash.error && <div className="rounded-lg bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 px-4 py-2 text-sm">{flash.error}</div>}

                <div className="grid gap-3 sm:grid-cols-4">
                    <StatCard label={t('ecommerce.total_orders') || 'Orders'} value={stats.total ?? 0} Icon={ShoppingBag} />
                    <StatCard label={t('ecommerce.revenue') || 'Revenue'} value={stats.revenue ?? 0} Icon={DollarSign} tone="green" />
                    <StatCard label={t('ecommerce.fulfilled') || 'Fulfilled'} value={stats.fulfilled ?? 0} Icon={PackageCheck} tone="green" />
                    <StatCard label={t('ecommerce.unfulfilled') || 'Unfulfilled'} value={stats.unfulfilled ?? 0} Icon={Clock} tone="amber" />
                </div>

                <DataTable
                    columns={columns}
                    rows={orders.data}
                    pagination={orders}
                    storageKey="orders"
                    loading={loading}
                    toolbar={toolbar}
                    onRowClick={(o) => router.visit(route('client.ecommerce.orders.show', o.id))}
                    emptyState={
                        <p className="px-4 py-8 text-center text-neutral-500 dark:text-neutral-400">{t('ecommerce.no_orders') || 'No orders synced yet.'}</p>
                    }
                />
            </div>
        </ClientLayout>
    );
}
