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
 * escuro troca sozinho. São dois filtros, e é deles que vem o contraste da
 * figura: o halo largo marca as juntas que sustentam a estrutura — eixo,
 * pontas da travessa, nós, pé da coluna —, e a faísca curta pontua o resto do
 * percurso sem virar borrão.
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
                .to(dots, { opacity: 1, scale: 1, duration: 0.4, stagger: 0.04, ease: 'back.out(2)' }, '-=1.2')
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

            {/* Toda linha horizontal está partida no eixo e toda diagonal tem a
                sua espelhada: como o traço é desenhado a partir do primeiro
                ponto, a figura nasce do centro para as bordas, e a simetria
                aparece também durante a entrada — não só no fim dela. */}

            {/* A coluna e o pedestal não giram. */}
            <g>
                <line data-part="base" x1="200" y1="266" x2="140" y2="266" />
                <line data-part="base" x1="200" y1="266" x2="260" y2="266" />
                <line data-part="base" x1="160" y1="244" x2="140" y2="266" />
                <line data-part="base" x1="240" y1="244" x2="260" y2="266" />
                <line data-part="base" x1="200" y1="244" x2="160" y2="244" />
                <line data-part="base" x1="200" y1="244" x2="240" y2="244" />
                <line data-part="base" x1="180" y1="224" x2="160" y2="244" />
                <line data-part="base" x1="220" y1="224" x2="240" y2="244" />
                <line data-part="base" x1="200" y1="224" x2="180" y2="224" />
                <line data-part="base" x1="200" y1="224" x2="220" y2="224" />

                <line data-part="column" x1="200" y1="224" x2="200" y2="44" />

                {dot(0, 140, 266, 1.5)}
                {dot(0, 260, 266, 1.5)}
                {dot(0, 160, 244, 1.5)}
                {dot(0, 240, 244, 1.5)}
                {dot(1, 180, 224, 1.75)}
                {dot(1, 220, 224, 1.75)}
                {dot(1, 200, 224, 2.5)}
                {dot(2, 200, 184, 1.5)}
                {dot(2, 200, 144, 1.5)}
                {dot(2, 200, 104, 1.5)}
                {dot(3, 200, 44, 2.75)}
            </g>

            {/* Travessa, tirantes e pratos giram juntos em torno do eixo. A sela
                desce da travessa até o poste: é o que a faz parecer apoiada em
                vez de flutuando — e o poste, que é estático, segue por cima
                dela até o pináculo. */}
            <g data-sway="beam">
                <path data-part="beam" d="M200 84 L180 64" />
                <path data-part="beam" d="M200 84 L220 64" />
                <line data-part="beam" x1="200" y1="64" x2="84" y2="64" />
                <line data-part="beam" x1="200" y1="64" x2="316" y2="64" />
                <line data-part="beam" x1="84" y1="64" x2="84" y2="74" />
                <line data-part="beam" x1="316" y1="64" x2="316" y2="74" />

                {dot(3, 200, 64, 3.5)}
                {dot(4, 142, 64, 1.75)}
                {dot(4, 258, 64, 1.75)}
                {dot(4, 84, 64, 2.75)}
                {dot(4, 316, 64, 2.75)}

                {/* Prato esquerdo: uma corda até o nó e três tirantes até a
                    borda — é o que o V de duas linhas não dava, e é o que faz
                    a suspensão parecer suspensão. */}
                <g data-sway="pan" data-origin="84 74">
                    <line data-part="chain" x1="84" y1="74" x2="84" y2="92" />
                    <line data-part="chain" x1="84" y1="92" x2="56" y2="158" />
                    <line data-part="chain" x1="84" y1="92" x2="84" y2="158" />
                    <line data-part="chain" x1="84" y1="92" x2="112" y2="158" />
                    <line data-part="pan" x1="84" y1="158" x2="50" y2="158" />
                    <line data-part="pan" x1="84" y1="158" x2="118" y2="158" />
                    <line data-part="pan" x1="50" y1="158" x2="46" y2="150" />
                    <line data-part="pan" x1="118" y1="158" x2="122" y2="150" />
                    <path data-part="pan" d="M84 181 Q 67 181 50 158" />
                    <path data-part="pan" d="M84 181 Q 101 181 118 158" />

                    {dot(5, 84, 92, 2.5)}
                    {dot(6, 56, 158, 1.5)}
                    {dot(6, 84, 158, 1.75)}
                    {dot(6, 112, 158, 1.5)}
                    {dot(7, 46, 150, 1.5)}
                    {dot(7, 122, 150, 1.5)}
                    {dot(7, 84, 181, 2.25)}
                </g>

                {/* Prato direito, espelhado em x = 200. */}
                <g data-sway="pan" data-origin="316 74">
                    <line data-part="chain" x1="316" y1="74" x2="316" y2="92" />
                    <line data-part="chain" x1="316" y1="92" x2="288" y2="158" />
                    <line data-part="chain" x1="316" y1="92" x2="316" y2="158" />
                    <line data-part="chain" x1="316" y1="92" x2="344" y2="158" />
                    <line data-part="pan" x1="316" y1="158" x2="282" y2="158" />
                    <line data-part="pan" x1="316" y1="158" x2="350" y2="158" />
                    <line data-part="pan" x1="282" y1="158" x2="278" y2="150" />
                    <line data-part="pan" x1="350" y1="158" x2="354" y2="150" />
                    <path data-part="pan" d="M316 181 Q 299 181 282 158" />
                    <path data-part="pan" d="M316 181 Q 333 181 350 158" />

                    {dot(5, 316, 92, 2.5)}
                    {dot(6, 344, 158, 1.5)}
                    {dot(6, 316, 158, 1.75)}
                    {dot(6, 288, 158, 1.5)}
                    {dot(7, 354, 150, 1.5)}
                    {dot(7, 278, 150, 1.5)}
                    {dot(7, 316, 181, 2.25)}
                </g>
            </g>
        </svg>
    )
}
