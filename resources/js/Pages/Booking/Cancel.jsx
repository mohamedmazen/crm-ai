import { Head, useForm } from '@inertiajs/react';
import {
    XCircle, CalendarX2, Calendar as CalendarIcon, Clock, User, ArrowLeft, AlertTriangle,
} from 'lucide-react';

/**
 * Guest-facing cancellation screen, reached from the cancel link in a booking
 * email. Confirms once, then reflects the cancelled state.
 */
export default function BookingCancel({
    booking = {},
    meeting_type = {},
    host = {},
}) {
    const isCancelled = booking.status === 'cancelled';

    const form = useForm({ reason: '' });

    const formattedDate = new Date(booking.starts_at).toLocaleString('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: 'numeric',
        timeZoneName: 'short',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(`/b/cancel/${booking.cancel_token}`);
    };

    const backUrl = `/b/${meeting_type.slug}/confirmed/${booking.uuid}`;

    return (
        <div className="min-h-screen bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 flex items-center justify-center p-4">
            <Head title={isCancelled ? 'Booking cancelled' : 'Cancel booking'} />

            <div className="w-full max-w-lg bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-3xl p-8 shadow-xl space-y-6">
                <div className="text-center space-y-3">
                    <div className={`inline-flex items-center justify-center w-16 h-16 rounded-full border ${
                        isCancelled
                            ? 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 border-zinc-200 dark:border-zinc-700'
                            : 'bg-rose-50 dark:bg-rose-950/40 text-rose-500 border-rose-200 dark:border-rose-800'
                    }`}>
                        {isCancelled ? <CalendarX2 className="h-8 w-8" /> : <XCircle className="h-8 w-8" />}
                    </div>

                    <h1 className="text-xl font-bold text-zinc-900 dark:text-zinc-100">
                        {isCancelled ? 'This meeting is cancelled' : 'Cancel this meeting?'}
                    </h1>
                    <p className="text-xs text-zinc-500 dark:text-zinc-400">
                        {isCancelled
                            ? 'The meeting below has already been cancelled.'
                            : 'This releases the time slot for someone else. This cannot be undone.'}
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
                        <Clock className="h-4 w-4 text-amber-500 shrink-0" />
                        <span>{meeting_type.duration_minutes} minutes</span>
                    </div>

                    <div className="flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-300">
                        <User className="h-4 w-4 text-purple-500 shrink-0" />
                        <span>With {host.name}</span>
                    </div>
                </div>

                {!isCancelled && (
                    <form onSubmit={submit} className="space-y-4">
                        {form.errors.reason && (
                            <div className="p-3 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 rounded-xl text-xs text-rose-600 dark:text-rose-400 flex items-center gap-2">
                                <AlertTriangle className="h-4 w-4 shrink-0" />
                                <span>{form.errors.reason}</span>
                            </div>
                        )}

                        <div>
                            <label className="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                Reason (optional)
                            </label>
                            <textarea
                                rows={3}
                                value={form.data.reason}
                                onChange={(e) => form.setData('reason', e.target.value)}
                                placeholder="Let the host know why you need to cancel."
                                className="w-full px-3.5 py-2.5 bg-zinc-50 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-xl text-sm focus:ring-2 focus:ring-rose-500"
                            />
                        </div>

                        <button
                            type="submit"
                            disabled={form.processing}
                            className="w-full py-3 bg-rose-600 hover:bg-rose-700 text-white font-medium text-sm rounded-xl transition-all shadow-md shadow-rose-600/20 disabled:opacity-50"
                        >
                            {form.processing ? 'Cancelling...' : 'Confirm cancellation'}
                        </button>
                    </form>
                )}

                <div className="text-center">
                    <a
                        href={backUrl}
                        className="inline-flex items-center gap-1.5 text-xs font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100 transition-colors"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        Back to booking
                    </a>
                </div>
            </div>
        </div>
    );
}
