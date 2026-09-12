import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

/**
 * Junta classes condicionais e resolve conflitos do Tailwind — a última vence.
 * É o utilitário que o `npx shadcn@latest add` espera encontrar em `@/lib/utils`.
 */
export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs))
}
