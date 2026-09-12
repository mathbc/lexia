import { cn } from '@/lib/cn'

export function Badge({ children, tone = 'neutral' }: { children: string; tone?: 'neutral' | 'success' | 'muted' }) {
    const tones = {
        neutral: 'bg-brand-50 text-brand-700 ring-brand-100',
        success: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        muted: 'bg-ink-100 text-ink-500 ring-ink-200',
    }

    return (
        <span className={cn('inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tones[tone])}>
            {children}
        </span>
    )
}
