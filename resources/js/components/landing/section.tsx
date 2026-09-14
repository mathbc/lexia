import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

/**
 * A moldura de uma seção da landing: a âncora, o título e a descrição.
 *
 * O `id` é o alvo do link do topo, e `scroll-mt-16` desconta a altura da barra
 * fixa — sem ele a âncora para com o título escondido atrás dela.
 */
export function Section({
    id,
    title,
    description,
    children,
    className,
}: {
    id: string
    title: string
    description?: string
    children?: ReactNode
    className?: string
}) {
    return (
        <section id={id} className={cn('scroll-mt-16 border-t border-border px-6 py-24', className)}>
            <div className="mx-auto flex w-full max-w-5xl flex-col items-center text-center">
                <h2 className="font-serif text-3xl font-semibold text-foreground sm:text-4xl">{title}</h2>

                {description && <p className="mt-4 max-w-xl text-muted-foreground">{description}</p>}

                {children && <div className="mt-12 w-full">{children}</div>}
            </div>
        </section>
    )
}
