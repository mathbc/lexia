import type { ButtonHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger'

const VARIANTS: Record<Variant, string> = {
    primary: 'bg-brand-600 text-white hover:bg-brand-700 focus-visible:outline-brand-600',
    secondary: 'bg-white text-ink-800 ring-1 ring-ink-200 hover:bg-ink-50',
    ghost: 'text-ink-600 hover:bg-ink-100',
    danger: 'bg-red-600 text-white hover:bg-red-700',
}

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant
}

export function Button({ variant = 'primary', className, ...props }: Props) {
    return (
        <button
            {...props}
            className={cn(
                'inline-flex items-center justify-center gap-2 rounded-md px-3.5 py-2 text-sm font-medium',
                'transition-colors disabled:cursor-not-allowed disabled:opacity-50',
                'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2',
                VARIANTS[variant],
                className,
            )}
        />
    )
}
