import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Tracks Inertia navigation so list screens can swap their body for a
 * skeleton while a request is in flight (Phase 4).
 *
 * Returns { loading } and is safe during SSR/first paint.
 */
export default function useInertiaLoading() {
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const offStart = router.on('start', () => setLoading(true));
        const offFinish = router.on('finish', () => setLoading(false));
        const offError = router.on('error', () => setLoading(false));

        return () => {
            offStart?.();
            offFinish?.();
            offError?.();
        };
    }, []);

    return loading;
}
