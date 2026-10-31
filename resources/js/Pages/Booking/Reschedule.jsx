import { useState, useEffect } from 'react';
import { Head, useForm } from '@inertiajs/react';
import {
    Clock, Video, Globe,
    ChevronLeft, ChevronRight, ArrowLeft, AlertCircle, Loader2,
} from 'lucide-react';

/**
 * Guest-facing reschedule screen. Reached from the booking confirmation via the
 * reschedule_token; the guest only picks a new time — their identity is already
 * captured on the booking.
 */
export default function BookingReschedule({
    booking = {},
    meeting_type = {},
    host = {},
}) {
    const [currentMonth, setCurrentMonth] = useState(new Date());
    const [selectedDate, setSelectedDate] = useState(null);
    const [selectedTimezone, setSelectedTimezone] = useState(
        booking.guest_timezone || Intl?.DateTimeFormat()?.resolvedOptions()?.timeZone || host.timezone || 'UTC'
    );
    const [slots, setSlots] = useState([]);
    const [loadingSlots, setLoadingSlots] = useState(false);
    const [selectedSlot, setSelectedSlot] = useState(null);

    const form = useForm({
        starts_at: '',
        guest_timezone: selectedTimezone,
    });

    const currentDate = booking.starts_at
        ? new Date(booking.starts_at).toLocaleString('en-US', {
            weekday: 'long', month: 'long', day: 'numeric',
            year: 'numeric', hour: 'numeric', minute: 'numeric', timeZoneName: 'short',
        })
        : '';

    const nextMonth = () => {
        setCurrentMonth(new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 1));
    };

    const prevMonth = () => {
        const prev = new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 1, 1);
        const today = new Date();
        if (prev.getFullYear() < today.getFullYear() || (prev.getFullYear() === today.getFullYear() && prev.getMonth() < today.getMonth())) {
            return;
        }
        setCurrentMonth(prev);
    };

    const year = currentMonth.getFullYear();
    const month = currentMonth.getMonth();
    const firstDayIndex = new Date(year, month, 1).getDay();
    const totalDaysInMonth = new Date(year, month + 1, 0).getDate();

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const maxDate = new Date();
    maxDate.setDate(maxDate.getDate() + (meeting_type.booking_window_days || 14));
    maxDate.setHours(23, 59, 59, 999);

    useEffect(() => {
        if (!selectedDate) return;

        setLoadingSlots(true);
        setSelectedSlot(null);

        const dateStr = selectedDate.toISOString().split('T')[0];
        fetch(`/b/${meeting_type.slug}/slots?date=${dateStr}&timezone=${encodeURIComponent(selectedTimezone)}`, {
            headers: { 'Accept': 'application/json' },
        })
            .then((res) => res.json())
            .then((data) => {
                setSlots(data.slots || []);
                setLoadingSlots(false);
            })
            .catch(() => {
                setSlots([]);
                setLoadingSlots(false);
            });
    }, [selectedDate, selectedTimezone, meeting_type.slug]);

    const handleSelectSlot = (slot) => {
        setSelectedSlot(slot);
        form.setData('starts_at', slot.time_utc);
        form.setData('guest_timezone', selectedTimezone);
        form.post(`/b/reschedule/${booking.reschedule_token}`);
    };

    const monthNames = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    return (
        <div className="min-h-screen bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 flex items-center justify-center p-4 sm:p-6 lg:p-8">
            <Head title={`Reschedule — ${meeting_type.name}`} />

            <div className="w-full max-w-4xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-3xl shadow-xl overflow-hidden flex flex-col md:flex-row">
                {/* Left Host & Info Column */}
                <div className="p-6 md:p-8 md:w-80 border-b md:border-b-0 md:border-r border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 flex flex-col justify-between space-y-6">
                    <div className="space-y-4">
                        <div className="flex items-center gap-3">
                            {host.avatar ? (
                                <img
                                    src={host.avatar}
                                    alt={host.name}
                                    className="w-12 h-12 rounded-full object-cover border-2 border-indigo-500"
                                />
                            ) : (
                                <div className="w-12 h-12 rounded-full bg-indigo-600 text-white font-bold flex items-center justify-center text-lg">
                                    {host.name?.charAt(0) || 'H'}
                                </div>
                            )}
                            <div>
                                <h3 className="font-semibold text-sm text-zinc-900 dark:text-zinc-100">{host.name}</h3>
                                <p className="text-xs text-zinc-500">Meeting Host</p>
                            </div>
                        </div>

                        <div>
                            <h1 className="text-xl font-bold text-zinc-900 dark:text-zinc-100">
                                Reschedule {meeting_type.name}
                            </h1>
                            <p className="text-xs text-zinc-500 dark:text-zinc-400 mt-2 leading-relaxed">
                                Pick a new time below. Your existing appointment will be moved.
                            </p>
                        </div>

                        <div className="space-y-2 pt-2 text-xs text-zinc-600 dark:text-zinc-400">
                            <div className="flex items-center gap-2">
                                <Clock className="h-4 w-4 text-indigo-500 shrink-0" />
                                <span>{meeting_type.duration_minutes} Minutes</span>
                            </div>
                            <div className="flex items-center gap-2 capitalize">
                                <Video className="h-4 w-4 text-rose-500 shrink-0" />
                                <span>{(meeting_type.location_type || 'online').replace('_', ' ')}</span>
                            </div>
                        </div>

                        {currentDate && (
                            <div className="p-3 bg-zinc-100 dark:bg-zinc-800/60 rounded-xl border border-zinc-200 dark:border-zinc-700 text-xs space-y-1">
                                <div className="font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider text-[10px]">
                                    Current Appointment
                                </div>
                                <div className="text-zinc-700 dark:text-zinc-300 font-mono">{currentDate}</div>
                            </div>
                        )}

                        {selectedSlot && (
                            <div className="p-3 bg-indigo-50 dark:bg-indigo-950/40 rounded-xl border border-indigo-200/60 dark:border-indigo-800/40 text-xs space-y-1">
                                <div className="font-semibold text-indigo-700 dark:text-indigo-300">
                                    New Appointment
                                </div>
                                <div className="text-zinc-700 dark:text-zinc-300 font-mono">
                                    {selectedSlot.datetime_formatted}
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="space-y-1 pt-4 border-t border-zinc-200 dark:border-zinc-800">
                        <label className="text-[11px] font-semibold text-zinc-400 uppercase tracking-wider flex items-center gap-1">
                            <Globe className="h-3 w-3" />
                            Timezone
                        </label>
                        <select
                            value={selectedTimezone}
                            onChange={(e) => setSelectedTimezone(e.target.value)}
                            className="w-full text-xs bg-transparent border-0 text-zinc-700 dark:text-zinc-300 font-medium cursor-pointer p-0 focus:ring-0"
                        >
                            <option value="UTC">UTC</option>
                            <option value="Africa/Cairo">Cairo (GMT+2)</option>
                            <option value="Asia/Riyadh">Riyadh (GMT+3)</option>
                            <option value="Asia/Dubai">Dubai (GMT+4)</option>
                            <option value="Europe/London">London (GMT+0)</option>
                            <option value="America/New_York">New York (EST)</option>
                        </select>
                    </div>
                </div>

                {/* Right Calendar / Slots */}
                <div className="p-6 md:p-8 flex-1 flex flex-col justify-center">
                    {form.errors.starts_at && (
                        <div className="mb-4 p-3 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 rounded-xl text-xs text-rose-600 dark:text-rose-400 flex items-center gap-2">
                            <AlertCircle className="h-4 w-4 shrink-0" />
                            <span>{form.errors.starts_at}</span>
                        </div>
                    )}

                    <div className="flex flex-col lg:flex-row gap-6">
                        {/* Calendar Days Picker */}
                        <div className="flex-1 space-y-4">
                            <div className="flex items-center justify-between">
                                <h2 className="font-bold text-sm text-zinc-900 dark:text-zinc-100">
                                    {monthNames[month]} {year}
                                </h2>
                                <div className="flex items-center gap-1">
                                    <button
                                        type="button"
                                        onClick={prevMonth}
                                        className="p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-600 dark:text-zinc-400 transition-colors"
                                    >
                                        <ChevronLeft className="h-4 w-4" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={nextMonth}
                                        className="p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-600 dark:text-zinc-400 transition-colors"
                                    >
                                        <ChevronRight className="h-4 w-4" />
                                    </button>
                                </div>
                            </div>

                            <div className="grid grid-cols-7 gap-1 text-center text-xs text-zinc-400 font-medium">
                                <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                            </div>

                            <div className="grid grid-cols-7 gap-1">
                                {Array.from({ length: firstDayIndex }).map((_, i) => (
                                    <div key={`empty-${i}`} className="h-9" />
                                ))}

                                {Array.from({ length: totalDaysInMonth }).map((_, i) => {
                                    const dayNumber = i + 1;
                                    const dateObj = new Date(year, month, dayNumber);
                                    const isPast = dateObj < today;
                                    const isBeyondWindow = dateObj > maxDate;
                                    const isDisabled = isPast || isBeyondWindow;
                                    const isSelected = selectedDate && selectedDate.toDateString() === dateObj.toDateString();

                                    return (
                                        <button
                                            key={`day-${dayNumber}`}
                                            type="button"
                                            disabled={isDisabled || form.processing}
                                            onClick={() => setSelectedDate(dateObj)}
                                            className={`h-9 w-9 mx-auto rounded-full text-xs font-medium transition-all ${
                                                isDisabled
                                                    ? 'text-zinc-300 dark:text-zinc-700 cursor-not-allowed'
                                                    : isSelected
                                                    ? 'bg-indigo-600 text-white font-bold shadow-md shadow-indigo-500/20'
                                                    : 'hover:bg-indigo-50 dark:hover:bg-indigo-950/40 text-zinc-800 dark:text-zinc-200 hover:text-indigo-600'
                                            }`}
                                        >
                                            {dayNumber}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Time Slots Column */}
                        <div className="lg:w-48 border-t lg:border-t-0 lg:border-l border-zinc-200 dark:border-zinc-800 pt-4 lg:pt-0 lg:pl-6 space-y-3">
                            <h3 className="font-semibold text-xs text-zinc-700 dark:text-zinc-300">
                                {selectedDate ? selectedDate.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) : 'Select a date'}
                            </h3>

                            <div className="max-h-72 overflow-y-auto pr-1 space-y-2">
                                {loadingSlots || form.processing ? (
                                    <div className="py-8 text-center text-xs text-zinc-400 animate-pulse flex items-center justify-center gap-2">
                                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                        {form.processing ? 'Rescheduling...' : 'Checking open slots...'}
                                    </div>
                                ) : selectedDate && slots.length > 0 ? (
                                    slots.map((s, idx) => (
                                        <button
                                            key={idx}
                                            type="button"
                                            disabled={form.processing}
                                            onClick={() => handleSelectSlot(s)}
                                            className="w-full py-2.5 px-3 rounded-xl border border-zinc-200 dark:border-zinc-800 hover:border-indigo-500 dark:hover:border-indigo-500 hover:bg-indigo-50/50 dark:hover:bg-indigo-950/30 text-xs font-semibold text-indigo-600 dark:text-indigo-400 transition-all text-center disabled:opacity-50"
                                        >
                                            {s.time_formatted}
                                        </button>
                                    ))
                                ) : selectedDate ? (
                                    <div className="py-8 text-center text-xs text-zinc-400">
                                        No available times on this date.
                                    </div>
                                ) : (
                                    <div className="py-8 text-center text-xs text-zinc-400">
                                        Pick a date from the calendar to view available times.
                                    </div>
                                )}
                            </div>

                            <a
                                href={`/b/${meeting_type.slug}/confirmed/${booking.uuid}`}
                                className="inline-flex items-center gap-1.5 text-xs font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100 transition-colors"
                            >
                                <ArrowLeft className="h-3.5 w-3.5" />
                                Back to booking
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
