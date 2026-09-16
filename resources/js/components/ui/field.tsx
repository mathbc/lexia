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
 *
 * `action` é o atalho que pertence ao campo, e não à tela: um "Novo cliente"
 * ao lado do rótulo do select de cliente, por exemplo. Fica na linha do rótulo
 * de propósito — dentro do controle atrapalharia o teclado do Radix, e abaixo
 * dele se confundiria com a dica.
 */
export function Field({
    label,
    error,
    hint,
    required,
    action,
    className,
    children,
}: {
    label: string
    error?: string
    hint?: string
    required?: boolean
    action?: ReactNode
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

    /* `min-h-5` porque o `Label` é `leading-none`: sozinho ele mede 14px, e a
       faixa com uma ação mede os 20px do botão. Sem o piso, dois campos lado a
       lado na mesma grade nasceriam com o controle em alturas diferentes — o
       que tem "Novo cliente" 6px abaixo do vizinho. Com ele, a faixa do rótulo
       tem a mesma altura com ou sem ação. */
    const labelNode = (
        <Label htmlFor={id} className="min-h-5">
            <span>
                {label}
                {required && (
                    <span aria-hidden className="ml-0.5 text-destructive">
                        *
                    </span>
                )}
            </span>
        </Label>
    )

    return (
        /* `content-start` e `grid-cols-1` seguram as duas deformações que um
           campo sofre por ser item de grade. Esticado para a altura da linha
           — porque um vizinho tem dica ou erro —, o `align-content` padrão
           distribuiria a sobra entre as faixas e o rótulo, que é `flex
           items-center`, desceria para o meio: dois campos lado a lado
           deixariam de começar na mesma altura. Com `start`, a sobra cai toda
           embaixo e vira espaço até a linha seguinte. `grid-cols-1` é
           `minmax(0,1fr)`: sem ele a faixa cresce até o min-content do
           controle — um Select com rótulo longo é `whitespace-nowrap` — e
           vaza para fora do cartão. `min-w-0` faz o mesmo um nível acima,
           pelo campo dentro da grade do formulário. */
        <div data-slot="form-field" className={cn('grid min-w-0 grid-cols-1 content-start gap-2', className)}>
            {/* Rótulo e asterisco num só nó: o `gap-2` do Label existe para
                separar ícones, e descolaria o obrigatório da palavra.

                Com uma ação, a faixa do rótulo vira uma linha com os dois
                extremos; sem ela, continua sendo o Label puro, para que campo
                nenhum mude de marcação por causa deste acréscimo. A altura
                mínima é a do rótulo sozinho: assim um botão baixo ao lado não
                desalinha este campo dos vizinhos na mesma grade. */}
            {action ? (
                <div className="flex min-h-5 items-center justify-between gap-2">
                    {labelNode}
                    {action}
                </div>
            ) : (
                labelNode
            )}

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
export { Textarea } from '@/components/ui/textarea'
