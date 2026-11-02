import { Loader2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

const SIZES = {
    sm: 'h-3.5 w-3.5',
    md: 'h-4 w-4',
    lg: 'h-6 w-6',
};

/**
 * Accessible inline spinner. Announced to assistive tech via role="status".
 */
export default function Spinner({ size = 'md', className = '', label }) {
    const { t } = useTranslation();

    return (
        <span role="status" className={`inline-flex items-center justify-center ${className}`}>
            <Loader2 className={`${SIZES[size] ?? SIZES.md} animate-spin`} aria-hidden="true" />
            <span className="sr-only">{label ?? t('state.loading', { defaultValue: 'Loading…' })}</span>
        </span>
    );
}
