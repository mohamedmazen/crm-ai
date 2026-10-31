import { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import {
    Calendar as CalendarIcon, Clock, Video, Phone, MapPin, Globe,
    Plus, Copy, Check, ExternalLink, Trash2, Edit2, ShieldAlert,
    User, CheckCircle2, XCircle, AlertCircle, Sparkles, Filter,
} from 'lucide-react';

export default function BookingIndex({
    meeting_types = [],
    bookings = { data: [] },
    rules = [],
    team_members = [],
    user_timezone = 'UTC',
}) {
    const { t } = useTranslation();
    const [activeTab, setActiveTab] = useState('types'); // 'types' | 'bookings' | 'availability'
    const [copiedId, setCopiedId] = useState(null);
    const [isAddModalOpen, setIsAddModalOpen] = useState(false);
    const [editingType, setEditingType] = useState(null);

    const addForm = useForm({
        name: '',
        duration_minutes: 30,
        location_type: 'google_meet',
        location_details: '',
        description: '',
        buffer_before: 5,
        buffer_after: 5,
        min_notice_hours: 4,
        booking_window_days: 14,
        color: '#6366f1',
        user_id: '',
    });

    const editForm = useForm({
        name: '',
        duration_minutes: 30,
        location_type: 'google_meet',
        location_details: '',
        description: '',
        buffer_before: 5,
        buffer_after: 5,
        min_notice_hours: 4,
        booking_window_days: 14,
        color: '#6366f1',
        status: 'active',
        user_id: '',
    });

    // Build default 7 days availability
    const initialDays = [0, 1, 2, 3, 4, 5, 6].map((day) => {
        const found = rules.find((r) => r.day_of_week === day);
        return {
            day_of_week: day,
            enabled: !!found || (day >= 1 && day <= 5 && rules.length === 0),
            start_time: found ? found.start_time.substring(0, 5) : '09:00',
            end_time: found ? found.end_time.substring(0, 5) : '17:00',
        };
    });

    const [availDays, setAvailDays] = useState(initialDays);
    const [selectedTimezone, setSelectedTimezone] = useState(user_timezone);
    const [isSavingAvail, setIsSavingAvail] = useState(false);

    const dayNames = [
        t('booking.day_sunday', 'Sunday'),
        t('booking.day_monday', 'Monday'),
        t('booking.day_tuesday', 'Tuesday'),
        t('booking.day_wednesday', 'Wednesday'),
        t('booking.day_thursday', 'Thursday'),
        t('booking.day_friday', 'Friday'),
        t('booking.day_saturday', 'Saturday'),
    ];

    const copyLink = (id, url) => {
        navigator.clipboard.writeText(url);
        setCopiedId(id);
        setTimeout(() => setCopiedId(null), 2000);
    };

    const handleCreateType = (e) => {
        e.preventDefault();
        addForm.post(route('client.sales.booking.store'), {
            onSuccess: () => {
                setIsAddModalOpen(false);
                addForm.reset();
            },
        });
    };

    const handleUpdateType = (e) => {
        e.preventDefault();
        if (!editingType) return;
        editForm.put(route('client.sales.booking.update', editingType.id), {
            onSuccess: () => {
                setEditingType(null);
            },
        });
    };

    const handleDeleteType = (type) => {
        if (!confirm(t('common.delete_confirm', 'Are you sure you want to delete this meeting type?'))) {
            return;
        }
        router.delete(route('client.sales.booking.destroy', type.id));
    };

    const handleSaveAvailability = (e) => {
        e.preventDefault();
        setIsSavingAvail(true);
        const activeRules = availDays
            .filter((d) => d.enabled)
            .map((d) => ({
                day_of_week: d.day_of_week,
                start_time: d.start_time + ':00',
                end_time: d.end_time + ':00',
            }));

        router.put(route('client.sales.booking.availability.update'), {
            timezone: selectedTimezone,
            rules: activeRules,
        }, {
            onFinish: () => setIsSavingAvail(false),
        });
    };

    const handleCancelBooking = (booking) => {
        const reason = window.prompt(t('booking.cancel_reason', 'Reason for cancellation (optional):'), 'Schedule conflict');
        if (reason === null) return;
        router.post(route('client.sales.booking.bookings.cancel', booking.id), { reason });
    };

    const getLocationIcon = (loc) => {
        switch (loc) {
            case 'phone': return <Phone className="h-4 w-4 text-blue-500" />;
            case 'in_person': return <MapPin className="h-4 w-4 text-emerald-500" />;
            case 'custom': return <Globe className="h-4 w-4 text-purple-500" />;
            case 'google_meet':
            default:
                return <Video className="h-4 w-4 text-rose-500" />;
        }
    };

    return (
        <ClientLayout>
            <Head title={t('booking.title', 'Booking Pages & Scheduling')} />

            <div className="py-8 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-6">
                {/* Header */}
                <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="p-2 bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 rounded-xl">
                                <CalendarIcon className="h-6 w-6" />
                            </span>
                            <h1 className="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                                {t('booking.title', 'Booking Pages & Scheduling')}
                            </h1>
                        </div>
                        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            {t('booking.subtitle', 'Create smart booking pages, set working hours and availability, and let leads book meetings automatically.')}
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setIsAddModalOpen(true)}
                            className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl shadow-sm transition-colors"
                        >
                            <Plus className="h-4 w-4" />
                            {t('booking.new_meeting_type', 'New Meeting Type')}
                        </button>
                    </div>
                </div>

                {/* Tabs */}
                <div className="flex border-b border-zinc-200 dark:border-zinc-800 gap-6">
                    <button
                        type="button"
                        onClick={() => setActiveTab('types')}
                        className={`pb-3 text-sm font-medium flex items-center gap-2 border-b-2 transition-colors ${
                            activeTab === 'types'
                                ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400 font-semibold'
                                : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'
                        }`}
                    >
                        <CalendarIcon className="h-4 w-4" />
                        {t('booking.meeting_types', 'Meeting Types')}
                        <span className="px-2 py-0.5 text-xs bg-zinc-100 dark:bg-zinc-800 rounded-full font-normal">
                            {meeting_types.length}
                        </span>
                    </button>

                    <button
                        type="button"
                        onClick={() => setActiveTab('bookings')}
                        className={`pb-3 text-sm font-medium flex items-center gap-2 border-b-2 transition-colors ${
                            activeTab === 'bookings'
                                ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400 font-semibold'
                                : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'
                        }`}
                    >
                        <Clock className="h-4 w-4" />
                        {t('booking.bookings_list', 'Appointments & Bookings')}
                        {bookings.total > 0 && (
                            <span className="px-2 py-0.5 text-xs bg-zinc-100 dark:bg-zinc-800 rounded-full font-normal">
                                {bookings.total}
                            </span>
                        )}
                    </button>

                    <button
                        type="button"
                        onClick={() => setActiveTab('availability')}
                        className={`pb-3 text-sm font-medium flex items-center gap-2 border-b-2 transition-colors ${
                            activeTab === 'availability'
                                ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400 font-semibold'
                                : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'
                        }`}
                    >
                        <Globe className="h-4 w-4" />
                        {t('booking.availability_settings', 'Working Hours & Availability')}
                    </button>
                </div>

                {/* TAB 1: Meeting Types */}
                {activeTab === 'types' && (
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                        {meeting_types.map((type) => (
                            <div
                                key={type.id}
                                className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5 shadow-xs flex flex-col justify-between hover:border-zinc-300 dark:hover:border-zinc-700 transition-colors"
                            >
                                <div className="space-y-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex items-center gap-2.5">
                                            <span
                                                className="w-3.5 h-3.5 rounded-full"
                                                style={{ backgroundColor: type.color || '#6366f1' }}
                                            />
                                            <h3 className="font-bold text-zinc-900 dark:text-zinc-100 text-base">
                                                {type.name}
                                            </h3>
                                        </div>
                                        <span className={`px-2 py-0.5 text-xs rounded-full font-medium ${
                                            type.status === 'active'
                                                ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                                                : 'bg-zinc-100 text-zinc-500'
                                        }`}>
                                            {t(`booking.${type.status}`, type.status)}
                                        </span>
                                    </div>

                                    {type.description && (
                                        <p className="text-xs text-zinc-500 dark:text-zinc-400 line-clamp-2">
                                            {type.description}
                                        </p>
                                    )}

                                    <div className="flex flex-wrap items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                                        <div className="flex items-center gap-1">
                                            <Clock className="h-3.5 w-3.5" />
                                            <span>{type.duration_minutes}m</span>
                                        </div>
                                        <div className="flex items-center gap-1 capitalize">
                                            {getLocationIcon(type.location_type)}
                                            <span>{type.location_type.replace('_', ' ')}</span>
                                        </div>
                                        {type.user && (
                                            <div className="flex items-center gap-1">
                                                <User className="h-3.5 w-3.5" />
                                                <span>{type.user.name}</span>
                                            </div>
                                        )}
                                    </div>
                                </div>

                                <div className="mt-5 pt-4 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between gap-2">
                                    <div className="flex items-center gap-1.5">
                                        <button
                                            type="button"
                                            onClick={() => copyLink(type.id, type.public_url)}
                                            className="inline-flex items-center gap-1 text-xs px-2.5 py-1.5 rounded-lg border border-zinc-200 dark:border-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors"
                                        >
                                            {copiedId === type.id ? <Check className="h-3.5 w-3.5 text-emerald-500" /> : <Copy className="h-3.5 w-3.5" />}
                                            {copiedId === type.id ? t('booking.copy_link', 'Copied') : t('booking.copy_link', 'Copy Link')}
                                        </button>
                                        <a
                                            href={type.public_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="p-1.5 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                                            title={t('booking.open_page', 'Open Booking Page')}
                                        >
                                            <ExternalLink className="h-4 w-4" />
                                        </a>
                                    </div>

                                    <div className="flex items-center gap-1">
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setEditingType(type);
                                                editForm.setData({
                                                    name: type.name,
                                                    duration_minutes: type.duration_minutes,
                                                    location_type: type.location_type,
                                                    location_details: type.location_details || '',
                                                    description: type.description || '',
                                                    buffer_before: type.buffer_before || 0,
                                                    buffer_after: type.buffer_after || 0,
                                                    min_notice_hours: type.min_notice_hours || 4,
                                                    booking_window_days: type.booking_window_days || 14,
                                                    color: type.color || '#6366f1',
                                                    status: type.status,
                                                    user_id: type.user_id || '',
                                                });
                                            }}
                                            className="p-1.5 text-zinc-400 hover:text-indigo-600 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                                            title={t('common.edit', 'Edit')}
                                        >
                                            <Edit2 className="h-4 w-4" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => handleDeleteType(type)}
                                            className="p-1.5 text-zinc-400 hover:text-rose-600 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                                            title={t('common.delete', 'Delete')}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {/* TAB 2: Bookings List */}
                {activeTab === 'bookings' && (
                    <div className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl overflow-hidden shadow-xs">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                                <thead className="bg-zinc-50/80 dark:bg-zinc-800/40 text-xs uppercase font-medium text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-800">
                                    <tr>
                                        <th className="py-3.5 px-4">{t('booking.attendee', 'Attendee')}</th>
                                        <th className="py-3.5 px-4">{t('booking.meeting_name', 'Meeting Type')}</th>
                                        <th className="py-3.5 px-4">{t('booking.host', 'Host')}</th>
                                        <th className="py-3.5 px-4">{t('booking.time', 'Date & Time')}</th>
                                        <th className="py-3.5 px-4">{t('booking.status', 'Status')}</th>
                                        <th className="py-3.5 px-4">{t('booking.meet_link', 'Meeting Link')}</th>
                                        <th className="py-3.5 px-4 text-right">{t('common.actions', 'Actions')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-zinc-200 dark:divide-zinc-800">
                                    {bookings.data && bookings.data.length > 0 ? (
                                        bookings.data.map((b) => (
                                            <tr key={b.id} className="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 transition-colors">
                                                <td className="py-3.5 px-4 font-medium text-zinc-900 dark:text-zinc-100">
                                                    <div>{b.guest_name}</div>
                                                    <div className="text-xs text-zinc-400 font-normal">{b.guest_email}</div>
                                                </td>
                                                <td className="py-3.5 px-4">
                                                    <div className="flex items-center gap-1.5">
                                                        <span
                                                            className="w-2.5 h-2.5 rounded-full"
                                                            style={{ backgroundColor: b.meeting_type?.color || '#6366f1' }}
                                                        />
                                                        <span>{b.meeting_type?.name}</span>
                                                    </div>
                                                </td>
                                                <td className="py-3.5 px-4">
                                                    {b.host?.name}
                                                </td>
                                                <td className="py-3.5 px-4 text-xs font-mono">
                                                    {new Date(b.starts_at).toLocaleString()}
                                                </td>
                                                <td className="py-3.5 px-4">
                                                    <span className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                        b.status === 'confirmed'
                                                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                                                            : b.status === 'cancelled'
                                                            ? 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400'
                                                            : 'bg-zinc-100 text-zinc-600'
                                                    }`}>
                                                        {b.status}
                                                    </span>
                                                </td>
                                                <td className="py-3.5 px-4">
                                                    {b.meet_link ? (
                                                        <a
                                                            href={b.meet_link}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="text-xs text-indigo-600 dark:text-indigo-400 hover:underline inline-flex items-center gap-1"
                                                        >
                                                            <Video className="h-3 w-3" />
                                                            {t('booking.meet_link', 'Join Call')}
                                                        </a>
                                                    ) : '—'}
                                                </td>
                                                <td className="py-3.5 px-4 text-right">
                                                    {b.status === 'confirmed' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleCancelBooking(b)}
                                                            className="text-xs text-rose-600 hover:text-rose-800 font-medium"
                                                        >
                                                            {t('booking.cancel_booking', 'Cancel')}
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan={7} className="py-8 text-center text-zinc-400 text-sm">
                                                {t('booking.no_bookings', 'No bookings scheduled yet.')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* TAB 3: Availability & Working Hours */}
                {activeTab === 'availability' && (
                    <div className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 shadow-xs max-w-3xl space-y-6">
                        <div>
                            <h2 className="text-lg font-bold text-zinc-900 dark:text-zinc-100">
                                {t('booking.working_hours', 'Weekly Working Hours')}
                            </h2>
                            <p className="text-xs text-zinc-500 mt-1">
                                {t('booking.availability_subtitle', 'Define standard weekly working windows when leads are allowed to book appointments with you.')}
                            </p>
                        </div>

                        <form onSubmit={handleSaveAvailability} className="space-y-5">
                            <div>
                                <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                    {t('booking.timezone', 'Your Timezone')}
                                </label>
                                <input
                                    type="text"
                                    value={selectedTimezone}
                                    onChange={(e) => setSelectedTimezone(e.target.value)}
                                    placeholder="e.g. UTC, Africa/Cairo, America/New_York"
                                    className="w-full max-w-sm px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm"
                                    required
                                />
                            </div>

                            <div className="space-y-3 pt-2">
                                {availDays.map((day, idx) => (
                                    <div
                                        key={day.day_of_week}
                                        className="flex items-center justify-between p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/30"
                                    >
                                        <div className="flex items-center gap-3 w-32">
                                            <input
                                                type="checkbox"
                                                id={`day-${day.day_of_week}`}
                                                checked={day.enabled}
                                                onChange={(e) => {
                                                    const updated = [...availDays];
                                                    updated[idx].enabled = e.target.checked;
                                                    setAvailDays(updated);
                                                }}
                                                className="rounded text-indigo-600 focus:ring-indigo-500 h-4 w-4"
                                            />
                                            <label
                                                htmlFor={`day-${day.day_of_week}`}
                                                className="text-sm font-medium text-zinc-900 dark:text-zinc-100 cursor-pointer"
                                            >
                                                {dayNames[day.day_of_week]}
                                            </label>
                                        </div>

                                        {day.enabled ? (
                                            <div className="flex items-center gap-2">
                                                <input
                                                    type="time"
                                                    value={day.start_time}
                                                    onChange={(e) => {
                                                        const updated = [...availDays];
                                                        updated[idx].start_time = e.target.value;
                                                        setAvailDays(updated);
                                                    }}
                                                    className="px-2.5 py-1.5 bg-white dark:bg-zinc-900 border border-zinc-300 dark:border-zinc-700 rounded-lg text-xs font-mono"
                                                />
                                                <span className="text-zinc-400 text-xs">—</span>
                                                <input
                                                    type="time"
                                                    value={day.end_time}
                                                    onChange={(e) => {
                                                        const updated = [...availDays];
                                                        updated[idx].end_time = e.target.value;
                                                        setAvailDays(updated);
                                                    }}
                                                    className="px-2.5 py-1.5 bg-white dark:bg-zinc-900 border border-zinc-300 dark:border-zinc-700 rounded-lg text-xs font-mono"
                                                />
                                            </div>
                                        ) : (
                                            <span className="text-xs text-zinc-400 italic">
                                                {t('booking.closed', 'Off')}
                                            </span>
                                        )}
                                    </div>
                                ))}
                            </div>

                            <div className="pt-2">
                                <button
                                    type="submit"
                                    disabled={isSavingAvail}
                                    className="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl transition-colors disabled:opacity-50"
                                >
                                    {isSavingAvail ? t('common.saving', 'Saving...') : t('booking.save_availability', 'Save Working Hours')}
                                </button>
                            </div>
                        </form>
                    </div>
                )}

                {/* Add Modal */}
                {isAddModalOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-zinc-900/60 backdrop-blur-xs">
                        <div className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 w-full max-w-lg shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
                            <h3 className="text-lg font-bold text-zinc-900 dark:text-zinc-100">
                                {t('booking.new_meeting_type', 'New Meeting Type')}
                            </h3>

                            <form onSubmit={handleCreateType} className="space-y-4">
                                <div>
                                    <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                        {t('booking.meeting_name', 'Meeting Name')} *
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        value={addForm.data.name}
                                        onChange={(e) => addForm.setData('name', e.target.value)}
                                        placeholder="e.g. 30 Min Strategy Call"
                                        className="w-full px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm"
                                    />
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                            {t('booking.duration_minutes', 'Duration (minutes)')} *
                                        </label>
                                        <input
                                            type="number"
                                            required
                                            min="5"
                                            max="480"
                                            value={addForm.data.duration_minutes}
                                            onChange={(e) => addForm.setData('duration_minutes', e.target.value)}
                                            className="w-full px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                            {t('booking.location', 'Location')} *
                                        </label>
                                        <select
                                            value={addForm.data.location_type}
                                            onChange={(e) => addForm.setData('location_type', e.target.value)}
                                            className="w-full px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm"
                                        >
                                            <option value="google_meet">Google Meet</option>
                                            <option value="phone">Phone Call</option>
                                            <option value="in_person">In Person</option>
                                            <option value="custom">Custom Link</option>
                                        </select>
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                            {t('booking.buffer_before', 'Buffer Before (mins)')}
                                        </label>
                                        <input
                                            type="number"
                                            value={addForm.data.buffer_before}
                                            onChange={(e) => addForm.setData('buffer_before', e.target.value)}
                                            className="w-full px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                            {t('booking.buffer_after', 'Buffer After (mins)')}
                                        </label>
                                        <input
                                            type="number"
                                            value={addForm.data.buffer_after}
                                            onChange={(e) => addForm.setData('buffer_after', e.target.value)}
                                            className="w-full px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                        {t('common.description', 'Description')}
                                    </label>
                                    <textarea
                                        rows={2}
                                        value={addForm.data.description}
                                        onChange={(e) => addForm.setData('description', e.target.value)}
                                        className="w-full px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm"
                                    />
                                </div>

                                <div className="flex justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setIsAddModalOpen(false)}
                                        className="px-4 py-2 bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-xl transition-colors"
                                    >
                                        {t('common.cancel', 'Cancel')}
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={addForm.processing}
                                        className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl transition-colors disabled:opacity-50"
                                    >
                                        {t('common.save', 'Save')}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                {/* Edit Modal */}
                {editingType && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-zinc-900/60 backdrop-blur-xs">
                        <div className="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 w-full max-w-lg shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
                            <h3 className="text-lg font-bold text-zinc-900 dark:text-zinc-100">
                                {t('common.edit', 'Edit')}: {editingType.name}
                            </h3>

                            <form onSubmit={handleUpdateType} className="space-y-4">
                                <div>
                                    <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                        {t('booking.meeting_name', 'Meeting Name')} *
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        value={editForm.data.name}
                                        onChange={(e) => editForm.setData('name', e.target.value)}
                                        className="w-full px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm"
                                    />
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                            {t('booking.duration_minutes', 'Duration (minutes)')} *
                                        </label>
                                        <input
                                            type="number"
                                            required
                                            min="5"
                                            max="480"
                                            value={editForm.data.duration_minutes}
                                            onChange={(e) => editForm.setData('duration_minutes', e.target.value)}
                                            className="w-full px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                            {t('booking.status', 'Status')} *
                                        </label>
                                        <select
                                            value={editForm.data.status}
                                            onChange={(e) => editForm.setData('status', e.target.value)}
                                            className="w-full px-3 py-2 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm"
                                        >
                                            <option value="active">{t('booking.confirmed', 'Active')}</option>
                                            <option value="inactive">{t('common.inactive', 'Inactive')}</option>
                                        </select>
                                    </div>
                                </div>

                                <div className="flex justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setEditingType(null)}
                                        className="px-4 py-2 bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-xl transition-colors"
                                    >
                                        {t('common.cancel', 'Cancel')}
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={editForm.processing}
                                        className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl transition-colors disabled:opacity-50"
                                    >
                                        {t('common.save', 'Save')}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        </ClientLayout>
    );
}

