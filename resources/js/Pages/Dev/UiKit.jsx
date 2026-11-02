import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { Moon, Sun, ArrowLeftRight, Palette, Type, MousePointerClick, LayoutList, AlertOctagon, Inbox, CheckCircle2 } from 'lucide-react';
import { Badge, Button, Card, ErrorState, Skeleton, TableSkeleton, ListSkeleton, CardSkeleton, Spinner } from '@/Components/ui';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import OnboardingChecklist from '@/Components/OnboardingChecklist';

/**
 * Component gallery used as the manual visual-regression surface (Phase 4).
 *
 * Renders every shared primitive in one place with live LTR/RTL and
 * light/dark switches, so a reviewer can flip through the matrix without
 * touching Storybook (no extra dependency).
 */

// Static class names so Tailwind's JIT keeps them in the build.
const TOKENS = [
    { name: 'brand-50', className: 'bg-brand-50' },
    { name: 'brand-100', className: 'bg-brand-100' },
    { name: 'brand-200', className: 'bg-brand-200' },
    { name: 'brand-300', className: 'bg-brand-300' },
    { name: 'brand-400', className: 'bg-brand-400' },
    { name: 'brand-500', className: 'bg-brand-500' },
    { name: 'brand-600', className: 'bg-brand-600' },
    { name: 'brand-700', className: 'bg-brand-700' },
    { name: 'brand-800', className: 'bg-brand-800' },
    { name: 'brand-900', className: 'bg-brand-900' },
    { name: 'neutral-100', className: 'bg-neutral-100' },
    { name: 'neutral-200', className: 'bg-neutral-200' },
    { name: 'neutral-300', className: 'bg-neutral-300' },
    { name: 'neutral-400', className: 'bg-neutral-400' },
    { name: 'neutral-500', className: 'bg-neutral-500' },
    { name: 'neutral-600', className: 'bg-neutral-600' },
    { name: 'neutral-700', className: 'bg-neutral-700' },
    { name: 'neutral-800', className: 'bg-neutral-800' },
    { name: 'neutral-900', className: 'bg-neutral-900' },
    { name: 'emerald-500', className: 'bg-emerald-500' },
    { name: 'amber-500', className: 'bg-amber-500' },
    { name: 'coral-500', className: 'bg-coral-500' },
    { name: 'blue-500', className: 'bg-blue-500' },
];

const ROWS = [
    { id: 1, name: 'Nadia Haddad', email: 'nadia@example.com', status: 'Active', plan: 'Business' },
    { id: 2, name: 'Omar Rahal', email: 'omar@example.com', status: 'Pending', plan: 'Pro' },
    { id: 3, name: 'Lina Farah', email: 'lina@example.com', status: 'Inactive', plan: 'Starter' },
];

function Section({ id, title, icon: Icon, children }) {
    return (
        <section id={id} data-section={id} className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
            <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-neutral-600 dark:text-neutral-300">
                {Icon && <Icon className="h-4 w-4 text-brand-500" aria-hidden="true" />}
                {title}
            </h2>
            {children}
        </section>
    );
}

export default function UiKit() {
    const [dark, setDark] = useState(() =>
        typeof document !== 'undefined' && document.documentElement.classList.contains('dark'));
    const [rtl, setRtl] = useState(() =>
        typeof document !== 'undefined' && document.documentElement.getAttribute('dir') === 'rtl');
    const [sample, setSample] = useState(true);

    const toggleDark = () => {
        const next = !dark;
        setDark(next);
        document.documentElement.classList.toggle('dark', next);
    };

    const toggleDir = () => {
        const next = !rtl;
        setRtl(next);
        document.documentElement.setAttribute('dir', next ? 'rtl' : 'ltr');
    };

    const columns = [
        { key: 'name', header: 'Name', sortable: true, cardTitle: true, render: (r) => <span className="font-medium">{r.name}</span> },
        { key: 'email', header: 'Email', render: (r) => r.email },
        { key: 'status', header: 'Status', render: (r) => <Badge variant={r.status === 'Active' ? 'success' : r.status === 'Pending' ? 'warning' : 'default'}>{r.status}</Badge> },
        { key: 'plan', header: 'Plan', render: (r) => r.plan },
    ];

    const steps = [
        { key: 'channel', title: 'Connect a channel', description: 'WhatsApp, Instagram, Messenger, SMS or email.', href: '#' },
        { key: 'contacts', title: 'Import your contacts', description: 'CSV upload or bulk import from your old CRM.', done: true },
        { key: 'automation', title: 'Launch your first automation', description: 'Send a welcome message and follow up automatically.' },
    ];

    return (
        <>
            <Head title="UI Kit — Dev" />
            <div className="min-h-screen bg-neutral-50 p-6 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
                <div className="mx-auto max-w-6xl space-y-6">
                    <header className="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-widest text-brand-600 dark:text-brand-400">Phase 4</p>
                            <h1 className="text-2xl font-bold">UI Kit &amp; States</h1>
                            <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                                Visual-regression surface: every shared primitive in light/dark and LTR/RTL.
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Button variant="outline" size="sm" onClick={toggleDark} aria-pressed={dark}>
                                {dark ? <Sun className="me-1.5 h-4 w-4" /> : <Moon className="me-1.5 h-4 w-4" />}
                                {dark ? 'Light' : 'Dark'}
                            </Button>
                            <Button variant="outline" size="sm" onClick={toggleDir} aria-pressed={rtl}>
                                <ArrowLeftRight className="me-1.5 h-4 w-4" />
                                {rtl ? 'LTR' : 'RTL'}
                            </Button>
                        </div>
                    </header>

                    <Section id="colors" title="Color tokens" icon={Palette}>
                        <div className="grid grid-cols-4 gap-2 sm:grid-cols-6 lg:grid-cols-8">
                            {TOKENS.map((token) => (
                                <div key={token.name} className="space-y-1">
                                    <div className={`h-10 w-full rounded-lg ${token.className}`} title={token.name} />
                                    <p className="truncate text-3xs text-neutral-500 dark:text-neutral-400">{token.name}</p>
                                </div>
                            ))}
                        </div>
                    </Section>

                    <Section id="typography" title="Typography" icon={Type}>
                        <div className="space-y-2">
                            <p className="text-3xl font-bold">Aa — العنوان الرئيسي</p>
                            <p className="text-xl font-semibold">Aa — عنوان فرعي</p>
                            <p className="text-base">Aa — نص أساسي بالعربية</p>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">Aa — secondary body text in English.</p>
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">الأرقام: ١٩٨٤٬٥٦٧٫٨٩</p>
                        </div>
                    </Section>

                    <Section id="buttons" title="Buttons" icon={MousePointerClick}>
                        <div className="flex flex-wrap items-center gap-3">
                            <Button>Primary</Button>
                            <Button variant="secondary">Secondary</Button>
                            <Button variant="outline">Outline</Button>
                            <Button variant="ghost">Ghost</Button>
                            <Button variant="danger">Danger</Button>
                            <Button disabled>Disabled</Button>
                            <Button size="sm">Small</Button>
                            <Button size="lg">Large</Button>
                            <Button><Spinner size="sm" className="me-2" />Saving</Button>
                        </div>
                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            <Badge>Default</Badge>
                            <Badge variant="brand">Brand</Badge>
                            <Badge variant="success">Success</Badge>
                            <Badge variant="warning">Warning</Badge>
                            <Badge variant="danger">Danger</Badge>
                        </div>
                    </Section>

                    <Section id="form" title="Form controls">
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <label className="block text-sm">
                                <span className="mb-1 block font-medium text-neutral-700 dark:text-neutral-300">Input</span>
                                <input className="w-full rounded-soft border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800" placeholder="Placeholder" />
                            </label>
                            <label className="block text-sm">
                                <span className="mb-1 block font-medium text-neutral-700 dark:text-neutral-300">Select</span>
                                <select className="w-full rounded-soft border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800">
                                    <option>Option one</option>
                                    <option>Option two</option>
                                </select>
                            </label>
                            <div className="flex items-end gap-4 text-sm">
                                <label className="inline-flex items-center gap-2"><input type="checkbox" defaultChecked /> Checkbox</label>
                                <label className="inline-flex items-center gap-2"><input type="radio" name="r" defaultChecked /> Radio</label>
                            </div>
                        </div>
                    </Section>

                    <Section id="skeletons" title="Loading skeletons" icon={LayoutList}>
                        <div className="grid gap-4 lg:grid-cols-2">
                            <div>
                                <p className="mb-2 text-xs font-medium text-neutral-500 dark:text-neutral-400">Cards</p>
                                <div className="grid grid-cols-2 gap-3">
                                    <CardSkeleton />
                                    <CardSkeleton />
                                </div>
                                <p className="mb-2 mt-4 text-xs font-medium text-neutral-500 dark:text-neutral-400">List</p>
                                <div className="rounded-lg border border-neutral-200 dark:border-neutral-700">
                                    <ListSkeleton rows={4} />
                                </div>
                            </div>
                            <div>
                                <p className="mb-2 text-xs font-medium text-neutral-500 dark:text-neutral-400">Table</p>
                                <div className="rounded-lg border border-neutral-200 dark:border-neutral-700">
                                    <TableSkeleton rows={5} columns={4} />
                                </div>
                            </div>
                        </div>
                        <div className="mt-4 flex items-center gap-4">
                            <Skeleton className="h-10 w-10" variant="circle" />
                            <Skeleton className="h-4 flex-1" />
                            <Skeleton className="h-8 w-24" />
                        </div>
                    </Section>

                    <Section id="states" title="Empty & error states" icon={AlertOctagon}>
                        <div className="grid gap-4 lg:grid-cols-2">
                            <div className="rounded-lg border border-neutral-200 dark:border-neutral-700">
                                <EmptyState
                                    icon={<Inbox className="h-8 w-8" />}
                                    title="No conversations yet"
                                    description="Connect a channel to start receiving messages."
                                    action={{ label: 'Connect channel', onClick: () => {} }}
                                />
                            </div>
                            <div className="rounded-lg border border-neutral-200 dark:border-neutral-700">
                                <ErrorState
                                    description="We could not load the dashboard. Check your connection and try again."
                                    onRetry={() => {}}
                                />
                            </div>
                        </div>
                    </Section>

                    <Section id="table" title="DataTable" icon={CheckCircle2}>
                        <DataTable columns={columns} rows={ROWS} rowKey="id" storageKey="dev-ui" />
                    </Section>

                    <Section id="onboarding" title="Onboarding checklist">
                        <OnboardingChecklist
                            steps={steps}
                            sampleEnabled={sample}
                            onToggleSample={setSample}
                            onDismiss={() => {}}
                        />
                    </Section>

                    <Section id="tokens" title="Spacing & radius">
                        <div className="flex flex-wrap items-end gap-3">
                            {['w-1', 'w-2', 'w-3', 'w-4', 'w-6', 'w-8'].map((w) => (
                                <div key={w} className={`h-8 ${w} rounded-soft bg-brand-100 dark:bg-brand-900/40`} title={w} />
                            ))}
                            <div className="rounded-soft bg-neutral-200 px-4 py-2 text-xs dark:bg-neutral-700">rounded-soft</div>
                            <div className="rounded-soft-lg bg-neutral-200 px-4 py-2 text-xs dark:bg-neutral-700">rounded-soft-lg</div>
                        </div>
                    </Section>
                </div>
            </div>
        </>
    );
}
