import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

const CONTROL =
    'w-full rounded-md border-0 bg-white px-3 py-2 text-sm text-ink-900 ring-1 ring-inset ' +
    'ring-ink-200 placeholder:text-ink-400 focus:ring-2 focus:ring-inset focus:ring-brand-500'

export function Field({
    label,
    error,
    hint,
    required,
    children,
}: {
    label: string
    error?: string
    hint?: string
    required?: boolean
    children: ReactNode
}) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-sm font-medium text-ink-700">
                {label}
                {required && <span className="ml-0.5 text-red-600">*</span>}
            </span>
            {children}
            {hint && !error && <span className="mt-1 block text-xs text-ink-400">{hint}</span>}
            {/* role=alert so the message is announced, not just shown */}
            {error && (
                <span role="alert" className="mt-1 block text-xs text-red-600">
                    {error}
                </span>
            )}
        </label>
    )
}

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
    return <input {...props} className={cn(CONTROL, className)} />
}

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
    options: { value: string; label: string }[]
    placeholder?: string
}

export function Select({ options, placeholder, className, ...props }: SelectProps) {
    return (
        <select {...props} className={cn(CONTROL, className)}>
            {placeholder && <option value="">{placeholder}</option>}
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    )
}
