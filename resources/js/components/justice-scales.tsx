import { useId, useLayoutEffect, useRef } from 'react'
import gsap from 'gsap'
import { cn } from '@/lib/utils'

/**
 * A balança da justiça: linhas finas e pontos luminosos ligando as conexões,
 * desenhada em SVG e animada com GSAP. É peça decorativa de fundo.
 *
 * A figura é uma constelação, não um desenho técnico: a travessa é uma reta com
 * três pontos, cada prato é um leque de dois tirantes fechado por um arco, e a
 * coluna cai num traço único até a base. Duas circunferências finas e levemente
 * desencontradas orbitam o conjunto, junto do eco de cada prato — é o que dá
 * profundidade sem acrescentar estrutura.
 *
 * O SVG é escrito no estado *final*: linhas inteiras, pontos acesos. Quem
 * esconde e redesenha é o GSAP, e só quando o sistema operacional não pede
 * menos movimento — assim `prefers-reduced-motion` (e um erro de JS) caem no
 * desenho estático em vez de numa tela vazia. A animação é só a da construção:
 * a balança se desenha uma vez e fica parada.
 *
 * O brilho não é cor nova: é `currentColor` borrado por um filtro, de modo que
 * o ponto continua obedecendo ao token semântico de quem o pinta e o tema
 * escuro troca sozinho. São dois filtros, e é deles que vem o contraste da
 * figura: o halo largo marca as juntas que sustentam a estrutura — eixo,
 * pontas da travessa, nós, pé da coluna —, e a faísca curta pontua o resto do
 * percurso sem virar borrão.
 *
 * O componente não fala com o leitor de tela (`aria-hidden`) e cabe a quem o
 * posiciona deixá-lo transparente ao clique (`pointer-events-none`), para que
 * o conteúdo acima dele não perca um toque.
 */

/** Ordem do desenho: do chão para cima, terminando nos pratos e nas órbitas. */
const DRAW_SEQUENCE = ['base', 'column', 'beam', 'cord', 'pan', 'orbit'] as const

/** Acima deste raio o ponto é junta de estrutura e ganha o halo largo. */
const ANCHOR_RADIUS = 2.25

export function JusticeScales({ className }: { className?: string }) {
    const root = useRef<SVGSVGElement>(null)

    // `useId` traz pontuação que não sobrevive bem a um `url(#…)`; sobra só o
    // que é seguro num id, e o prefixo mantém a leitura no inspetor.
    const scope = useId().replace(/[^a-zA-Z0-9]/g, '')
    const glowId = `justice-scales-glow-${scope}`
    const sparkId = `justice-scales-spark-${scope}`

    useLayoutEffect(() => {
        const svg = root.current

        if (!svg) {
            return
        }

        const media = gsap.matchMedia(svg)

        media.add('(prefers-reduced-motion: no-preference)', () => {
            const select = <T extends SVGElement>(selector: string) =>
                Array.from(svg.querySelectorAll<T>(selector))

            // Traço escondido: o pontilhado tem o tamanho exato da linha e o
            // deslocamento a empurra inteira para fora. Animar o deslocamento
            // até zero é o que "desenha".
            const strokes = select<SVGGeometryElement>('[data-part]')

            strokes.forEach((stroke) => {
                const length = stroke.getTotalLength()

                gsap.set(stroke, { strokeDasharray: length, strokeDashoffset: length })
            })

            // Os pontos acendem na ordem das conexões, do pé ao prato, e não na
            // ordem em que estão no DOM (que é a da hierarquia de grupos).
            const dots = select<SVGCircleElement>('[data-dot]').sort(
                (a, b) => Number(a.dataset.dot ?? 0) - Number(b.dataset.dot ?? 0),
            )

            gsap.set(dots, { opacity: 0, scale: 0, transformOrigin: 'center' })

            const entry = gsap.timeline({ delay: 0.15, defaults: { ease: 'power2.out' } })

            DRAW_SEQUENCE.forEach((part, index) => {
                entry.to(
                    strokes.filter((stroke) => stroke.dataset.part === part),
                    { strokeDashoffset: 0, duration: 0.8, stagger: 0.05 },
                    index === 0 ? 0 : '-=0.55',
                )
            })

            entry.to(dots, { opacity: 1, scale: 1, duration: 0.4, stagger: 0.04, ease: 'back.out(2)' }, '-=1.4')
        })

        return () => media.revert()
    }, [])

    /** Ponto luminoso: núcleo e halo em `currentColor`, na opacidade do traço —
     *  sem isso o `feMerge` empilha o borrão até estourar o ponto em branco e
     *  ele deixa de acompanhar a cor das linhas. */
    const dot = (order: number, cx: number, cy: number, r: number) => (
        <circle
            key={`${cx}-${cy}`}
            data-dot={order}
            cx={cx}
            cy={cy}
            r={r}
            fill="currentColor"
            fillOpacity={0.8}
            stroke="none"
            filter={`url(#${r >= ANCHOR_RADIUS ? glowId : sparkId})`}
        />
    )

    return (
        <svg
            ref={root}
            viewBox="0 0 400 300"
            role="presentation"
            aria-hidden="true"
            focusable="false"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.5}
            strokeOpacity={0.8}
            strokeLinecap="round"
            strokeLinejoin="round"
            className={cn('h-auto w-full text-muted-foreground', className)}
        >
            <defs>
                {/* As regiões são folgadas porque o borrão sai muito além da
                    caixa do círculo, e o `feMerge` empilha o borrão para que a
                    luz tenha corpo antes de o núcleo entrar. */}
                <filter id={glowId} x="-400%" y="-400%" width="900%" height="900%">
                    <feGaussianBlur stdDeviation="3" result="glow" />
                    <feMerge>
                        <feMergeNode in="glow" />
                        <feMergeNode in="glow" />
                        <feMergeNode in="glow" />
                        <feMergeNode in="SourceGraphic" />
                    </feMerge>
                </filter>
                <filter id={sparkId} x="-400%" y="-400%" width="900%" height="900%">
                    <feGaussianBlur stdDeviation="1.4" result="spark" />
                    <feMerge>
                        <feMergeNode in="spark" />
                        <feMergeNode in="spark" />
                        <feMergeNode in="SourceGraphic" />
                    </feMerge>
                </filter>
            </defs>

            {/* As órbitas vêm antes de tudo no DOM porque são fundo: dois anéis
                finos e desencontrados em volta da figura, e o eco de cada prato
                logo por fora do arco aceso. Não têm ponto e não tocam a
                estrutura — por isso o traço mais fino e quase apagado. */}
            <g strokeWidth={0.75} strokeOpacity={0.25}>
                <circle data-part="orbit" cx="200" cy="154" r="108" />
                <circle data-part="orbit" cx="202" cy="157" r="104" />

                <path data-part="orbit" d="M105 161 A 48 48 0 0 1 58 124" />
                <path data-part="orbit" d="M105 161 A 48 48 0 0 0 152 124" />
                <path data-part="orbit" d="M295 161 A 48 48 0 0 1 248 124" />
                <path data-part="orbit" d="M295 161 A 48 48 0 0 0 342 124" />
            </g>

            {/* Toda linha horizontal está partida no eixo e toda diagonal tem a
                sua espelhada: como o traço é desenhado a partir do primeiro
                ponto, a figura nasce do centro para as bordas, e a simetria
                aparece também durante a entrada — não só no fim dela. */}

            {/* O pé é um traço só, e a coluna sobe dele até a travessa. */}
            <line data-part="base" x1="200" y1="239" x2="140" y2="239" />
            <line data-part="base" x1="200" y1="239" x2="260" y2="239" />
            <line data-part="column" x1="200" y1="239" x2="200" y2="100" />

            {dot(0, 140, 239, 1.75)}
            {dot(0, 260, 239, 1.75)}
            {dot(1, 200, 239, 2.75)}
            {dot(2, 200, 170, 2)}

            {/* A travessa: uma reta e três pontos, o do meio sobre o eixo. */}
            <line data-part="beam" x1="200" y1="100" x2="105" y2="100" />
            <line data-part="beam" x1="200" y1="100" x2="295" y2="100" />

            {dot(3, 200, 100, 3.5)}
            {dot(4, 105, 100, 2.75)}
            {dot(4, 295, 100, 2.75)}

            {/* Prato esquerdo: dois tirantes abrindo da ponta da travessa até a
                borda, e o arco fechando por baixo — desenhado do fundo para as
                bordas, como o resto. */}
            <g>
                <line data-part="cord" x1="105" y1="100" x2="66" y2="124" />
                <line data-part="cord" x1="105" y1="100" x2="144" y2="124" />
                <path data-part="pan" d="M105 155 A 40 40 0 0 1 66 124" />
                <path data-part="pan" d="M105 155 A 40 40 0 0 0 144 124" />

                {dot(5, 66, 124, 2.25)}
                {dot(5, 144, 124, 2.25)}
                {dot(6, 105, 155, 2.25)}
            </g>

            {/* Prato direito, espelhado em x = 200. */}
            <g>
                <line data-part="cord" x1="295" y1="100" x2="334" y2="124" />
                <line data-part="cord" x1="295" y1="100" x2="256" y2="124" />
                <path data-part="pan" d="M295 155 A 40 40 0 0 1 256 124" />
                <path data-part="pan" d="M295 155 A 40 40 0 0 0 334 124" />

                {dot(5, 334, 124, 2.25)}
                {dot(5, 256, 124, 2.25)}
                {dot(6, 295, 155, 2.25)}
            </g>
        </svg>
    )
}
