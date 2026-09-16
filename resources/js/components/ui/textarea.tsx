import * as React from 'react'
import { cn } from '@/lib/utils'

/**
 * O irmão multilinha do `Input`: mesma borda, mesmo anel de foco e o mesmo
 * `aria-invalid` que o `Field` enxerta, para que os dois controles nunca
 * discordem sobre o que é um campo com erro.
 *
 * `field-sizing-content` deixa a caixa crescer com o texto a partir do
 * `min-h`, em vez de fixar uma altura que ora sobra ora falta.
 */
function Textarea({ className, ...props }: React.ComponentProps<'textarea'>) {
    return (
        <textarea
            data-slot="textarea"
            className={cn(
                'flex field-sizing-content min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none md:text-sm',
                'placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground',
                'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                'aria-invalid:border-destructive aria-invalid:ring-destructive/20',
                'disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
            {...props}
        />
    )
}

export { Textarea }
