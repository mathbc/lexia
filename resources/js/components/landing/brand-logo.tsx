import type { SVGProps } from 'react'
import { cn } from '@/lib/utils'

/**
 * A marca da LexIA: o "L" montado em blocos — dois quadrados, a barra e o
 * traço inclinado.
 *
 * Vetor, e não o PNG do manual: a mesma forma serve à barra do topo, à entrada
 * e ao rodapé sem um arquivo por tamanho, e o `currentColor` faz da tinta o
 * token de quem a envolve — preto no claro, branco no escuro — sem uma variante
 * por tema.
 *
 * As medidas são as do arquivo original, numa grade em que o quadrado vale 27
 * e o vão entre blocos vale 10, conferidas pixel a pixel contra o PNG. O raio
 * do traço é menor que o dos blocos de propósito: num canto agudo o mesmo raio
 * pareceria o dobro. `width` e `height` estão ali só como proporção — é o que
 * deixa quem chama fixar a altura por classe e a largura acompanhar.
 */
export function BrandMark({ className, ...props }: SVGProps<SVGSVGElement>) {
    return (
        <svg
            viewBox="0 0 92.3 101"
            width="92.3"
            height="101"
            fill="currentColor"
            aria-hidden="true"
            focusable="false"
            className={cn('shrink-0', className)}
            {...props}
        >
            <rect width="27" height="27" rx="3.9" />
            <rect y="37" width="27" height="27" rx="3.9" />
            <rect y="74" width="49.8" height="27" rx="3.9" />
            <path d="M71.41 74H90.62A1.6 1.6 0 0 1 92.07 76.29L80.78 100.09A1.6 1.6 0 0 1 79.34 101H60.13A1.6 1.6 0 0 1 58.68 98.71L69.97 74.92A1.6 1.6 0 0 1 71.41 74Z" />
        </svg>
    )
}

/**
 * O logotipo: a marca e o nome, na montagem do manual.
 *
 * "Lex" em negrito e "IA" em traço leve, na mesma IBM Plex Sans do resto do
 * sistema — o contraste de peso é o desenho, e não uma segunda família. Tudo é
 * medido em `em`, para que o tamanho seja a classe de texto de quem chama: a
 * marca passa um pouco das maiúsculas em cima e embaixo, como no manual, e o
 * vão acompanha.
 *
 * O nome é texto de verdade e a marca é `aria-hidden`: o leitor de tela lê
 * "LexIA" uma vez, e não "imagem, LexIA".
 */
export function BrandLogo({ className }: { className?: string }) {
    return (
        <span className={cn('inline-flex items-center gap-[0.6em] leading-none', className)}>
            <BrandMark className="h-[0.9em] w-auto" />
            <span className="tracking-tight">
                <span className="font-bold">Lex</span>
                <span className="font-light">IA</span>
            </span>
        </span>
    )
}
