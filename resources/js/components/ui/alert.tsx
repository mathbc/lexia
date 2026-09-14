import * as React from 'react'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

const alertVariants = cva(
    // A primeira coluna é `auto`, não `0`: sem ícone ela colapsa sozinha, e
    // texto passado solto — sem AlertTitle/AlertDescription — ainda ocupa a
    // largura toda em vez de quebrar palavra a palavra numa coluna de zero.
    'relative grid w-full grid-cols-[auto_1fr] items-start gap-y-0.5 rounded-lg border px-4 py-3 text-sm has-[>svg]:grid-cols-[calc(var(--spacing)*4)_1fr] has-[>svg]:gap-x-3 [&>svg]:size-4 [&>svg]:shrink-0 [&>svg]:translate-y-0.5 [&>svg]:text-current',
    {
        variants: {
            variant: {
                default: 'bg-card text-card-foreground',
                destructive: 'border-destructive/20 bg-destructive/10 text-destructive *:data-[slot=alert-description]:text-destructive/90',
                success: 'border-success/20 bg-success/10 text-success *:data-[slot=alert-description]:text-success/90',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
)

function Alert({ className, variant, ...props }: React.ComponentProps<'div'> & VariantProps<typeof alertVariants>) {
    return <div data-slot="alert" role="alert" className={cn(alertVariants({ variant }), className)} {...props} />
}

function AlertTitle({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="alert-title"
            className={cn('col-start-2 min-w-0 min-h-4 font-medium tracking-tight', className)}
            {...props}
        />
    )
}

function AlertDescription({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="alert-description"
            className={cn(
                'col-start-2 min-w-0 text-sm text-muted-foreground [&>p+p]:mt-1 [&_p]:leading-relaxed',
                className,
            )}
            {...props}
        />
    )
}

export { Alert, AlertTitle, AlertDescription }
