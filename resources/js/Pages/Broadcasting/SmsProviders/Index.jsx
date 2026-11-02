import { Head, useForm, usePage } from '@inertiajs/react';
import { router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Eye, EyeOff, CheckCircle, Trash2, BookOpen, ChevronDown, ChevronUp } from 'lucide-react';

const SETUP_GUIDES = {
    twilio: {
        steps: [
            'Sign up at twilio.com and verify your account.',
            'From the Console Dashboard, copy your Account SID and Auth Token.',
            'Go to Phone Numbers → Buy a Number, choose one with SMS capability.',
            'Enter the Account SID, Auth Token, and the Twilio phone number below.',
        ],
        link: 'https://console.twilio.com',
        linkLabel: 'Open Twilio Console',
    },
    nexmo: {
        steps: [
            'Sign up at vonage.com (formerly Nexmo).',
            'From the API Dashboard, copy your API Key and API Secret.',
            'Purchase a virtual number under Numbers → Buy Numbers.',
            'Enter your API Key and API Secret below.',
        ],
        link: 'https://dashboard.nexmo.com',
        linkLabel: 'Open Vonage Dashboard',
    },
    telnyx: {
        steps: [
            'Sign up at telnyx.com and complete verification.',
            'Create a Messaging Profile under Messaging → Programmable Messaging.',
            'Go to API Keys and create a V2 API Key.',
            'Buy a number and assign it to the messaging profile.',
            'Enter the API Key, From number, and (optionally) Messaging Profile ID below.',
        ],
        link: 'https://portal.telnyx.com',
        linkLabel: 'Open Telnyx Portal',
    },
    clicksend: {
        steps: [
            'Sign up at clicksend.com and add credit.',
            'Open Account → API Credentials to copy your Username and API Key.',
            'Optionally register a dedicated number or alphanumeric Sender ID.',
            'Enter the Username, API Key, and From below.',
        ],
        link: 'https://dashboard.clicksend.com/account/subaccounts',
        linkLabel: 'Open ClickSend Dashboard',
    },
    amazon_sns: {
        steps: [
            'Log in to the AWS Console and navigate to IAM → Users → Add user.',
            'Attach the "AmazonSNSFullAccess" policy (or a scoped SNS SMS policy).',
            'Under Security Credentials, create an Access Key and copy the Access Key ID and Secret.',
            'Choose the AWS Region closest to your recipients (e.g. ap-south-1 for India, us-east-1 for global).',
            'Optionally set a Sender ID (alphanumeric, supported in select countries).',
            'For production, request an SNS SMS spending limit increase in AWS Support.',
            'Enter the Access Key, Secret, and Region below.',
        ],
        link: 'https://console.aws.amazon.com/sns/v3/home#/mobile/text-messaging',
        linkLabel: 'Open AWS SNS Console',
    },
    sms_misr: {
        steps: [
            'Sign in to your SMS Misr account at sms.com.eg.',
            'Copy your Username and Password from your account settings.',
            'Request a Sender (Sender Token) and wait for it to be approved.',
            'Use environment 1 for live sending or 2 for testing; language 2 supports Arabic/Unicode.',
            'Enter the Username, Password, Sender Token and options below.',
        ],
        link: 'https://sms.com.eg/',
        linkLabel: 'Open SMS Misr',
    },
    cequens: {
        steps: [
            'Log in to the CEQUENS Communication Platform.',
            'Under Developers, create an API Key (used together with your service username).',
            'Request and activate an approved Sender Name for your target countries.',
            'Enter the service Username, API Key and Sender Name below.',
        ],
        link: 'https://www.cequens.com/',
        linkLabel: 'Open CEQUENS',
    },
    unifonic: {
        steps: [
            'Sign up at unifonic.com and open the Developers section.',
            'Create an Application to obtain your AppSid (Application ID).',
            'Register and get a Sender ID approved for your account.',
            'Enter the AppSid and Sender ID below.',
        ],
        link: 'https://www.unifonic.com/',
        linkLabel: 'Open Unifonic',
    },
    taqnyat: {
        steps: [
            'Log in to your Taqnyat account.',
            'Under Developer → Application, create an app and copy the Bearer Token.',
            'Register a Sender Name under Settings → Manage Senders.',
            'Optionally set the SMS Webhook to your delivery-status URL for delivery reports.',
            'Enter the Bearer Token and Sender Name below.',
        ],
        link: 'https://www.taqnyat.sa/',
        linkLabel: 'Open Taqnyat',
    },
    msegat: {
        steps: [
            'Sign up at msegat.com and note your account Username.',
            'Request your API Key from the Msegat dashboard or support.',
            'Register and activate a Sender Name (userSender).',
            'Enter the Username, API Key and Sender Name below.',
        ],
        link: 'https://www.msegat.com/en/',
        linkLabel: 'Open Msegat',
    },
    infobip: {
        steps: [
            'Log in to your Infobip account.',
            'Copy your API Key from the Developer → API keys section.',
            'Note your personal Base URL (for example xyz.api.infobip.com).',
            'Register an approved Sender (From) for your traffic.',
            'Enter the API Key, Base URL and Sender below.',
        ],
        link: 'https://portal.infobip.com/',
        linkLabel: 'Open Infobip Portal',
    },
};

function SetupGuide({ providerKey }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const guide = SETUP_GUIDES[providerKey];
    if (!guide) return null;
    return (
        <div className="pt-2 border-t border-neutral-100 dark:border-neutral-800">
            <button
                type="button"
                onClick={() => setOpen(v => !v)}
                className="flex items-center gap-1.5 text-xs text-brand-600 dark:text-brand-400 hover:text-brand-700 font-medium transition w-full text-left"
            >
                <BookOpen className="h-3.5 w-3.5 shrink-0" />
                {t('sms.setup_guide')}
                {open ? <ChevronUp className="h-3 w-3 ml-auto" /> : <ChevronDown className="h-3 w-3 ml-auto" />}
            </button>
            {open && (
                <div className="mt-2 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-900/40 px-3 py-2.5 space-y-2">
                    <ol className="space-y-1">
                        {guide.steps.map((step, i) => (
                            <li key={i} className="flex gap-2 text-xs text-blue-700 dark:text-blue-400">
                                <span className="shrink-0 font-medium text-blue-500">{i + 1}.</span>
                                <span>{step}</span>
                            </li>
                        ))}
                    </ol>
                    {guide.link && (
                        <a href={guide.link} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs font-medium text-blue-700 dark:text-blue-400 hover:underline">
                            {guide.linkLabel} →
                        </a>
                    )}
                </div>
            )}
        </div>
    );
}

const BRAND = {
    twilio: {
        color: '#F22F46',
        logo: 'twilio',
    },
    nexmo: {
        color: '#6E3697',
        logo: 'vonage',
    },
    telnyx: {
        color: '#00C08B',
        logo: 'telnyx',
    },
    clicksend: {
        color: '#00AEEF',
        logo: 'clicksend',
    },
    amazon_sns: {
        color: '#FF9900',
        logo: 'amazonsns',
    },
    sms_misr: {
        color: '#0F9D58',
        logo: 'smsmisr',
    },
    cequens: {
        color: '#0091D5',
        logo: 'cequens',
    },
    unifonic: {
        color: '#2E5BFF',
        logo: 'unifonic',
    },
    taqnyat: {
        color: '#0E63B3',
        logo: 'taqnyat',
    },
    msegat: {
        color: '#F26522',
        logo: 'msegat',
    },
    infobip: {
        color: '#F04E37',
        logo: 'infobip',
    },
};

function ProviderBadge({ provider }) {
    const brand = BRAND[provider] ?? { color: '#6b7280', logo: null };
    return (
        <div
            className="flex items-center justify-center w-9 h-9 rounded-xl shrink-0"
            style={{ backgroundColor: brand.color }}
        >
            <img
                src={`/images/integrations/${brand.logo}.svg`}
                alt={provider}
                className="h-5 w-5"
                style={{ filter: 'brightness(0) invert(1)' }}
            />
        </div>
    );
}

function ProviderCard({ provider }) {
    const { t } = useTranslation();
    const [showSecrets, setShowSecrets] = useState({});

    const initialCredentials = {};
    (provider.fields ?? []).forEach(f => {
        initialCredentials[f.key] = provider.masked?.[f.key] ?? '';
    });

    const { data, setData, put, processing, errors } = useForm({
        credentials: initialCredentials,
        sender_id: provider.sender_id ?? '',
        default: provider.default ?? false,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('client.sms-gateways.update', provider.provider), { preserveScroll: true });
    };

    const handleDelete = () => {
        if (!confirm(t('sms.remove_confirm', { label: provider.label }))) return;
        router.delete(route('client.sms-gateways.destroy', provider.provider), { preserveScroll: true });
    };

    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-4">
            <div className="flex items-center justify-between gap-2">
                <div className="flex items-center gap-2.5">
                    <ProviderBadge provider={provider.provider} />
                    <h3 className="font-semibold text-sm text-neutral-900 dark:text-neutral-100 leading-tight">
                        {provider.label}
                    </h3>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                    {provider.configured && (
                        <span className="flex items-center gap-1 text-xs text-green-700 dark:text-green-400">
                            <CheckCircle className="h-3.5 w-3.5" /> {t('sms.configured')}
                        </span>
                    )}
                    {provider.default && (
                        <span className="text-xs px-2 py-0.5 rounded-full bg-brand-100 text-brand-700 dark:bg-brand-900/30 dark:text-brand-300 font-medium">
                            {t('sms.default')}
                        </span>
                    )}
                </div>
            </div>

            <form onSubmit={handleSubmit} className="space-y-3">
                {(provider.fields ?? []).map(field => (
                    <div key={field.key}>
                        <label htmlFor={`sms-${provider.provider}-${field.key}`} className="text-xs font-medium text-neutral-500 dark:text-neutral-400">
                            {field.label}{field.required && ' *'}
                        </label>
                        <div className="relative mt-1">
                            <input
                                id={`sms-${provider.provider}-${field.key}`}
                                type={field.type === 'password' && !showSecrets[field.key] ? 'password' : 'text'}
                                value={data.credentials[field.key] ?? ''}
                                onChange={e => setData('credentials', { ...data.credentials, [field.key]: e.target.value })}
                                placeholder={provider.configured ? t('sms.encrypted_placeholder') : ''}
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 pr-10 text-sm"
                            />
                            {field.type === 'password' && (
                                <button
                                    type="button"
                                    onClick={() => setShowSecrets(s => ({ ...s, [field.key]: !s[field.key] }))}
                                    aria-label={showSecrets[field.key] ? t('common.hide', { defaultValue: 'Hide' }) : t('common.show', { defaultValue: 'Show' })}
                                    className="absolute right-1 top-1/2 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center text-neutral-500 dark:text-neutral-400 hover:text-neutral-600"
                                >
                                    {showSecrets[field.key] ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                </button>
                            )}
                        </div>
                        {errors[`credentials.${field.key}`] && (
                            <p className="mt-1 text-xs text-red-500">{errors[`credentials.${field.key}`]}</p>
                        )}
                    </div>
                ))}

                <div>
                    <label htmlFor={`sms-${provider.provider}-sender_id`} className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('sms.sender_id')}</label>
                    <input
                        id={`sms-${provider.provider}-sender_id`}
                        type="text"
                        value={data.sender_id}
                        onChange={e => setData('sender_id', e.target.value)}
                        placeholder={t('sms.sender_id_placeholder')}
                        className="mt-1 w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                    />
                </div>

                <label className="flex items-center gap-2 text-sm cursor-pointer">
                    <input
                        type="checkbox"
                        checked={data.default}
                        onChange={e => setData('default', e.target.checked)}
                        className="rounded"
                    />
                    {t('sms.set_default')}
                </label>

                <div className="flex gap-2 pt-1">
                    <button
                        type="submit"
                        disabled={processing}
                        className="flex-1 rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition"
                    >
                        {processing ? t('sms.saving') : t('common.save')}
                    </button>
                    {provider.configured && (
                        <button
                            type="button"
                            onClick={handleDelete}
                            aria-label={t('common.delete', { defaultValue: 'Delete' })}
                            className="rounded-lg border border-neutral-200 dark:border-neutral-700 px-3 py-2 text-neutral-500 dark:text-neutral-400 hover:text-red-500 hover:border-red-300 transition"
                        >
                            <Trash2 className="h-4 w-4" />
                        </button>
                    )}
                </div>
            </form>
            <SetupGuide providerKey={provider.provider} />
        </div>
    );
}

const REGION_ORDER = ['egypt', 'gcc', 'global'];

export default function SmsProvidersIndex({ providers }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};

    const grouped = REGION_ORDER
        .map(region => ({
            region,
            items: (providers ?? []).filter(p => (p.region ?? 'global') === region),
        }))
        .filter(group => group.items.length > 0);

    return (
        <ClientLayout title={t('sms.title')}>
            <Head title={t('sms.title')} />
            <div className="space-y-5">
                <div>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('sms.title')}</h2>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        {t('sms.subtitle')}
                    </p>
                </div>

                {flash.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="rounded-lg bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 px-4 py-2 text-sm">
                        {flash.error}
                    </div>
                )}

                {grouped.map(group => (
                    <div key={group.region} className="space-y-3">
                        <h3 className="flex items-center gap-2 text-sm font-semibold text-neutral-700 dark:text-neutral-300">
                            <span className="inline-block h-2 w-2 rounded-full bg-brand-500" />
                            {t(`sms.region_${group.region}`)}
                        </h3>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {group.items.map(p => (
                                <ProviderCard key={p.provider} provider={p} />
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </ClientLayout>
    );
}
