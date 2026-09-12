import { Children, cloneElement, isValidElement, useId, type ReactElement, type ReactNode } from 'react'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'

interface ControlProps {
    id?: string
    'aria-invalid'?: boolean
    'aria-describedby'?: string
}

/**
 * Rótulo, controle, dica e erro — a unidade de um formulário.
 *
 * O `id` nasce aqui e é enxertado no controle, de modo que o rótulo aponte
 * para ele mesmo quando o controle não é um `<input>` nativo (o Select do Radix
 * é um botão). O mesmo enxerto marca `aria-invalid` quando há erro, o que é o
 * que acende o anel vermelho nos componentes — a mensagem e o estilo não podem
 * discordar.
 */
export function Field({
    label,
    error,
    hint,
    required,
    className,
    children,
}: {
    label: string
    error?: string
    hint?: string
    required?: boolean
    className?: string
    children: ReactNode
}) {
    const id = useId()
    const messageId = error ? `${id}-error` : hint ? `${id}-hint` : undefined

    const child = Children.only(children)
    const control = isValidElement<ControlProps>(child)
        ? cloneElement(child as ReactElement<ControlProps>, {
              id: child.props.id ?? id,
              'aria-invalid': error ? true : child.props['aria-invalid'],
              'aria-describedby': messageId ?? child.props['aria-describedby'],
          })
        : child

    return (
        <div data-slot="form-field" className={cn('grid gap-2', className)}>
            {/* Rótulo e asterisco num só nó: o `gap-2` do Label existe para
                separar ícones, e descolaria o obrigatório da palavra. */}
            <Label htmlFor={id}>
                <span>
                    {label}
                    {required && (
                        <span aria-hidden className="ml-0.5 text-destructive">
                            *
                        </span>
                    )}
                </span>
            </Label>

            {control}

            {hint && !error && (
                <p id={messageId} className="text-xs text-muted-foreground">
                    {hint}
                </p>
            )}

            {/* role=alert so the message is announced, not just shown */}
            {error && (
                <p id={messageId} role="alert" className="text-xs font-medium text-destructive">
                    {error}
                </p>
            )}
        </div>
    )
}

export { Input } from '@/components/ui/input'
export { SelectInput as Select } from '@/components/ui/select'
