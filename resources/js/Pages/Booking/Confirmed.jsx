import { Head } from '@inertiajs/react';
import {
    CheckCircle2, Calendar as CalendarIcon, Video, User,
    CalendarClock, XCircle,
} from 'lucide-react';

export default function BookingConfirmed({
    booking = {},
    meeting_type = {},
    host = {},
    google_calendar_url = '#',
}) {
    const formattedDate = new Date(booking.starts_at).toLocaleString('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: 'numeric',
        timeZoneName: 'short',
    });

    return (
        <div className="min-h-screen bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 flex items-center justify-center p-4">
            <Head title="Booking Confirmed!" />

            <div className="w-full max-w-lg bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-3xl p-8 shadow-xl text-center space-y-6">
                <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-50 dark:bg-emerald-950/50 text-emerald-500 border border-emerald-200 dark:border-emerald-800">
                    <CheckCircle2 className="h-8 w-8" />
                </div>

                <div className="space-y-1">
                    <h1 className="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                        You&apos;re Scheduled!
                    </h1>
                    <p className="text-xs text-zinc-500 dark:text-zinc-400">
                        A calendar invitation has been registered for your meeting.
                    </p>
                </div>

                <div className="p-5 bg-zinc-50 dark:bg-zinc-800/50 rounded-2xl border border-zinc-200/80 dark:border-zinc-800 text-left space-y-3">
                    <div>
                        <span className="text-xs text-zinc-400 font-medium">Meeting</span>
                        <div className="font-bold text-sm text-zinc-900 dark:text-zinc-100">
                            {meeting_type.name}
                        </div>
                    </div>

                    <div className="flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-300">
                        <CalendarIcon className="h-4 w-4 text-indigo-500 shrink-0" />
                        <span>{formattedDate}</span>
                    </div>

                    <div className="flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-300">
                        <User className="h-4 w-4 text-purple-500 shrink-0" />
                        <span>With {host.name} ({host.email})</span>
                    </div>

                    {booking.meet_link && (
                        <div className="pt-2 border-t border-zinc-200/60 dark:border-zinc-700/60 flex items-center justify-between">
                            <span className="text-xs text-zinc-500">Google Meet</span>
                            <a
                                href={booking.meet_link}
                                target="_blank"
                                rel="noreferrer"
                                className="text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1"
                            >
                                <Video className="h-3.5 w-3.5" />
                                Join Meeting
                            </a>
                        </div>
                    )}
                </div>

                <div className="space-y-3">
                    <a
                        href={google_calendar_url}
                        target="_blank"
                        rel="noreferrer"
                        className="w-full inline-flex items-center justify-center gap-2 py-3 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-medium text-sm rounded-xl transition-colors shadow-sm"
                    >
                        <CalendarIcon className="h-4 w-4" />
                        Add to Google Calendar
                    </a>

                    {booking.reschedule_token && (
                        <a
                            href={`/b/reschedule/${booking.reschedule_token}`}
                            className="w-full inline-flex items-center justify-center gap-2 py-3 px-4 bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 hover:border-indigo-500 text-zinc-700 dark:text-zinc-200 font-medium text-sm rounded-xl transition-colors"
                        >
                            <CalendarClock className="h-4 w-4" />
                            Reschedule
                        </a>
                    )}

                    {booking.cancel_token && booking.status !== 'cancelled' && (
                        <a
                            href={`/b/cancel/${booking.cancel_token}`}
                            className="w-full inline-flex items-center justify-center gap-2 py-3 px-4 bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 hover:border-rose-500 hover:text-rose-600 dark:hover:text-rose-400 text-zinc-700 dark:text-zinc-200 font-medium text-sm rounded-xl transition-colors"
                        >
                            <XCircle className="h-4 w-4" />
                            Cancel
                        </a>
                    )}

                    <p className="text-xs text-zinc-400">
                        A confirmation email with these options has been sent to you.
                    </p>
                </div>
            </div>
        </div>
    );
}

