import { useId, useLayoutEffect, useRef } from 'react'
import gsap from 'gsap'
import { cn } from '@/lib/utils'

/**
 * A balança da justiça: linhas finas e pontos luminosos ligando as conexões,
 * desenhada em SVG e animada com GSAP. É peça decorativa de fundo.
 *
 * O SVG é escrito no estado *final*: linhas inteiras, pontos acesos. Quem
 * esconde e redesenha é o GSAP, e só quando o sistema operacional não pede
 * menos movimento — assim `prefers-reduced-motion` (e um erro de JS) caem no
 * desenho estático em vez de numa tela vazia.
 *
 * O brilho não é cor nova: é `currentColor` borrado por um filtro, de modo que
 * o ponto continua obedecendo ao token semântico de quem o pinta e o tema
 * escuro troca sozinho.
 *
 * O componente não fala com o leitor de tela (`aria-hidden`) e cabe a quem o
 * posiciona deixá-lo transparente ao clique (`pointer-events-none`), para que
 * o conteúdo acima dele não perca um toque.
 */

/** Ordem do desenho: do chão para cima, terminando nos pratos. */
const DRAW_SEQUENCE = ['base', 'column', 'beam', 'chain', 'pan'] as const

/** O eixo, em coordenadas do viewBox — é em torno dele que a travessa gira. */
const PIVOT = '200 64'

/** Graus. Balanço de balança parada, não de gangorra. */
const SWAY = 2.2

/** Segundos de meia oscilação; o ciclo inteiro é o dobro (ida e volta). */
const SWAY_DURATION = 3.4

/** O atraso dos pratos em relação à travessa: é ele que faz o balanço. */
const PAN_LAG = 0.25

export function JusticeScales({ className }: { className?: string }) {
    const root = useRef<SVGSVGElement>(null)

    // `useId` traz pontuação que não sobrevive bem a um `url(#…)`; sobra só o
    // que é seguro num id, e o prefixo mantém a leitura no inspetor.
    const glowId = `justice-scales-glow-${useId().replace(/[^a-zA-Z0-9]/g, '')}`

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

            const beam = svg.querySelector<SVGGElement>('[data-sway="beam"]')
            const pans = select<SVGGElement>('[data-sway="pan"]')

            // O movimento contínuo nasce pausado, mas dentro deste callback: é
            // o que o deixa registrado no contexto e, portanto, desfeito no
            // revert. Quem repete é cada tween, não a linha do tempo — assim a
            // defasagem do prato é uma fase constante, e não um ciclo torto.
            // `immediateRender: false` impede que o `fromTo` aplique o ângulo
            // inicial antes da hora.
            const idle = gsap.timeline({
                paused: true,
                defaults: { ease: 'sine.inOut', repeat: -1, yoyo: true, immediateRender: false },
            })

            idle.fromTo(
                beam,
                { rotation: -SWAY },
                { rotation: SWAY, svgOrigin: PIVOT, duration: SWAY_DURATION },
                0,
            )

            pans.forEach((pan) => {
                // O prato desfaz o giro da travessa para continuar na
                // horizontal; o atraso é o que sobra disso, e é o balanço.
                idle.fromTo(
                    pan,
                    { rotation: SWAY },
                    { rotation: -SWAY, svgOrigin: pan.dataset.origin ?? PIVOT, duration: SWAY_DURATION },
                    PAN_LAG,
                )
            })

            // O respiro dos pontos: a luz não fica parada, mas também não
            // pisca junto — daí a ordem embaralhada.
            idle.to(
                dots,
                {
                    opacity: 0.55,
                    duration: 1.9,
                    stagger: { each: 0.22, from: 'random' },
                },
                0,
            )

            const entry = gsap.timeline({
                delay: 0.15,
                defaults: { ease: 'power2.out' },
                onComplete: () => idle.play(),
            })

            DRAW_SEQUENCE.forEach((part, index) => {
                entry.to(
                    strokes.filter((stroke) => stroke.dataset.part === part),
                    { strokeDashoffset: 0, duration: 0.8, stagger: 0.05 },
                    index === 0 ? 0 : '-=0.55',
                )
            })

            entry
                .to(dots, { opacity: 1, scale: 1, duration: 0.4, stagger: 0.06, ease: 'back.out(2)' }, '-=0.8')
                // O ângulo em que a oscilação começa, para que ela pegue o
                // movimento em vez de estalar.
                .to(beam, { rotation: -SWAY, svgOrigin: PIVOT, duration: 1, ease: 'sine.inOut' }, '-=0.3')

            pans.forEach((pan) => {
                entry.to(
                    pan,
                    { rotation: SWAY, svgOrigin: pan.dataset.origin ?? PIVOT, duration: 1, ease: 'sine.inOut' },
                    '<',
                )
            })
        })

        return () => media.revert()
    }, [])

    /** Ponto luminoso: núcleo sólido e halo, ambos em `currentColor`. */
    const dot = (order: number, cx: number, cy: number, r: number) => (
        <circle
            key={`${cx}-${cy}`}
            data-dot={order}
            cx={cx}
            cy={cy}
            r={r}
            fill="currentColor"
            stroke="none"
            filter={`url(#${glowId})`}
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
            strokeLinejoin="round"
            className={cn('h-auto w-full text-muted-foreground', className)}
        >
            <defs>
                {/* O halo. A região é folgada porque o borrão sai muito além da
                    caixa do círculo, e o `feMerge` empilha o borrão três vezes
                    para que a luz tenha corpo antes de o núcleo entrar. */}
                <filter id={glowId} x="-400%" y="-400%" width="900%" height="900%">
                    <feGaussianBlur stdDeviation="3" result="glow" />
                    <feMerge>
                        <feMergeNode in="glow" />
                        <feMergeNode in="glow" />
                        <feMergeNode in="glow" />
                        <feMergeNode in="SourceGraphic" />
                    </feMerge>
                </filter>
            </defs>

            {/* Toda linha horizontal está partida no eixo e toda diagonal tem a
                sua espelhada: como o traço é desenhado a partir do primeiro
                ponto, a figura nasce do centro para as bordas, e a simetria
                aparece também durante a entrada — não só no fim dela. */}

            {/* A coluna e o pedestal não giram. */}
            <g>
                <line data-part="base" x1="200" y1="264" x2="124" y2="264" />
                <line data-part="base" x1="200" y1="264" x2="276" y2="264" />
                <line data-part="base" x1="150" y1="244" x2="124" y2="264" />
                <line data-part="base" x1="250" y1="244" x2="276" y2="264" />
                <line data-part="base" x1="200" y1="244" x2="150" y2="244" />
                <line data-part="base" x1="200" y1="244" x2="250" y2="244" />
                <line data-part="base" x1="176" y1="226" x2="150" y2="244" />
                <line data-part="base" x1="224" y1="226" x2="250" y2="244" />
                <line data-part="base" x1="200" y1="226" x2="176" y2="226" />
                <line data-part="base" x1="200" y1="226" x2="224" y2="226" />

                <line data-part="column" x1="200" y1="226" x2="200" y2="64" />

                {dot(0, 200, 226, 2.5)}
            </g>

            {/* Travessa, correntes e pratos giram juntos em torno do eixo. */}
            <g data-sway="beam">
                <path data-part="beam" d="M200 44 L188 64" />
                <path data-part="beam" d="M200 44 L212 64" />
                <line data-part="beam" x1="200" y1="64" x2="84" y2="64" />
                <line data-part="beam" x1="200" y1="64" x2="316" y2="64" />

                {dot(1, 200, 64, 3.5)}
                {dot(2, 84, 64, 2.75)}
                {dot(2, 316, 64, 2.75)}

                {/* Prato esquerdo. */}
                <g data-sway="pan" data-origin="84 64">
                    <line data-part="chain" x1="84" y1="64" x2="46" y2="146" />
                    <line data-part="chain" x1="84" y1="64" x2="122" y2="146" />
                    <line data-part="pan" x1="84" y1="146" x2="40" y2="146" />
                    <line data-part="pan" x1="84" y1="146" x2="128" y2="146" />
                    <path data-part="pan" d="M84 171 Q 62 171 40 146" />
                    <path data-part="pan" d="M84 171 Q 106 171 128 146" />

                    {dot(3, 65, 105, 2)}
                    {dot(3, 103, 105, 2)}
                    {dot(4, 46, 146, 2)}
                    {dot(4, 122, 146, 2)}
                </g>

                {/* Prato direito, espelhado em x = 200. */}
                <g data-sway="pan" data-origin="316 64">
                    <line data-part="chain" x1="316" y1="64" x2="278" y2="146" />
                    <line data-part="chain" x1="316" y1="64" x2="354" y2="146" />
                    <line data-part="pan" x1="316" y1="146" x2="272" y2="146" />
                    <line data-part="pan" x1="316" y1="146" x2="360" y2="146" />
                    <path data-part="pan" d="M316 171 Q 294 171 272 146" />
                    <path data-part="pan" d="M316 171 Q 338 171 360 146" />

                    {dot(3, 297, 105, 2)}
                    {dot(3, 335, 105, 2)}
                    {dot(4, 278, 146, 2)}
                    {dot(4, 354, 146, 2)}
                </g>
            </g>
        </svg>
    )
}
