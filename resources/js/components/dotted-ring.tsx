import { useEffect, useRef, type ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { usePrefersReducedMotion } from '@/hooks/use-prefers-reduced-motion'

/**
 * O anel pontilhado que circunda a marca — uma membrana, não um desenho.
 *
 * São pontos avulsos, e não um `stroke-dasharray`, porque o tracejado é uma
 * linha só: aqui cada ponto tem a sua própria posição a cada quadro, e é disso
 * que sai a maleabilidade. O cursor levanta o trecho do anel mais próximo dele
 * — uma elevação em forma de sino — e de dentro dessa elevação saem ondas que
 * correm para os dois lados, perdendo altura conforme se afastam.
 *
 * O que faz parecer matéria, e não um valor seguindo o mouse, são as três
 * molas: a elevação não *está* onde o cursor está, ela **persegue** o cursor.
 * Subamortecidas de propósito (razão ~0,6), passam um pouco do ponto e voltam,
 * então um movimento rápido deixa o anel balançando por um instante depois que
 * o cursor já parou — e quando ele vai embora, o anel não desliga: relaxa.
 *
 * A força não cai com a distância ao centro, e sim à **circunferência**: quem
 * se aproxima do anel o deforma, por fora ou por dentro. Por dentro a queda é
 * mais lenta, senão passar o mouse sobre a marca — que ocupa justamente o
 * miolo — não faria nada, que é o contrário do que a mão espera.
 *
 * O giro também é daqui, e não mais uma animação de CSS: as posições já são
 * calculadas a cada quadro, então somar o ângulo do giro sai de graça — e
 * evita ter de descobrir, em JS, onde a folha de estilo deixou o anel.
 *
 * O SVG é `aria-hidden` e transparente ao clique; quem escuta o ponteiro é a
 * janela, para que o anel reaja à aproximação e não só ao hover da caixa. O
 * conteúdo vai por cima, centrado.
 */

const TAU = Math.PI * 2

/** Centro do desenho, em unidades do `viewBox`. */
const CENTER = 100

/** O raio do anel de fora — o que o cursor procura. */
const HULL = 90

/** Até onde o cursor ainda mexe com o anel, em unidades do `viewBox`. */
const REACH = 72

/** O quanto a queda é mais lenta por dentro do anel do que por fora. */
const INSIDE = 0.55

/** Meia-largura da elevação, em radianos: o sino cobre uns 35° de cada lado. */
const SIGMA = 0.6

/** Altura da elevação sob o cursor, em unidades do `viewBox` — 14% do raio. */
const LIFT = 13

/** Altura das ondas que saem dela. */
const RIPPLE = 4.6

/** Quantas cristas cabem na volta, e a que velocidade elas correm. */
const WAVES = 5
const FLOW = 4

/**
 * O quanto a onda perde a cada radiano que se afasta da elevação.
 *
 * É o que impede o anel de virar um pulsar uniforme: com queda de menos a onda
 * dá a volta inteira e encontra a si mesma do outro lado, e o desenho deixa de
 * apontar para onde está o cursor — que é a única coisa que ele precisa dizer.
 */
const SPREAD = 1.05

/** A mola: rígida o bastante para acompanhar, frouxa o bastante para balançar. */
const STIFFNESS = 90
const DAMPING = 11

/**
 * Os dois anéis, de fora para dentro.
 *
 * `turn` é o giro em radianos por segundo, e os sentidos são opostos: é o que
 * dá profundidade a um desenho de duas dimensões. `give` é o quanto o anel
 * cede à elevação, e `lag` atrasa a onda do de dentro, de modo que ela pareça
 * chegar depois — a deformação atravessa a membrana em vez de acontecer nas
 * duas ao mesmo tempo.
 */
const RINGS = [
    { radius: HULL, count: 56, dot: 1, turn: 0.1, rest: 0.45, wake: 0.35, give: 1, lag: 0 },
    { radius: 72, count: 40, dot: 0.9, turn: -0.14, rest: 0, wake: 0.6, give: 0.6, lag: 0.9 },
] as const

/** Todos os pontos, na mesma ordem em que o SVG os desenha. */
const DOTS = RINGS.flatMap((ring) =>
    Array.from({ length: ring.count }, (_, index) => ({
        ring,
        // Começa no topo, e não à direita: é onde a primeira falha apareceria.
        base: (index / ring.count) * TAU - Math.PI / 2,
    })),
)

/** O ângulo equivalente dentro de [-π, π], que é onde a distância angular vive. */
function wrap(angle: number) {
    return (((angle + Math.PI) % TAU) + TAU) % TAU - Math.PI
}

type Spring = { value: number; speed: number }

/** Um passo da mola: persegue o alvo, com inércia e sem chegar seco. */
function settle(spring: Spring, target: number, step: number) {
    spring.speed += (target - spring.value) * STIFFNESS * step
    spring.speed *= Math.exp(-DAMPING * step)
    spring.value += spring.speed * step
}

export function DottedRing({ children, className }: { children: ReactNode; className?: string }) {
    const host = useRef<HTMLDivElement>(null)
    const canvas = useRef<SVGSVGElement>(null)
    const reduced = usePrefersReducedMotion()

    useEffect(() => {
        const box = host.current
        const svg = canvas.current

        // Quem pede menos movimento fica com o anel desenhado e parado: o SVG
        // já nasce no lugar certo, e é só não mexer nele.
        if (!box || !svg || reduced) {
            return
        }

        const circles = svg.querySelectorAll<SVGCircleElement>('circle')

        // A mola persegue o cursor em unidades do `viewBox`; a terceira é a
        // força, que sobe quando ele se aproxima e relaxa quando ele sai.
        const aimX: Spring = { value: CENTER, speed: 0 }
        const aimY: Spring = { value: CENTER, speed: 0 }
        const power: Spring = { value: 0, speed: 0 }

        let pointerX = 0
        let pointerY = 0
        let seen = false

        const onPointerMove = (event: PointerEvent) => {
            pointerX = event.clientX
            pointerY = event.clientY
            seen = true
        }

        // Sem ponteiro — saiu da janela, ou nunca houve — o anel volta ao
        // repouso pela mola, e não por um corte.
        const onPointerOut = () => {
            seen = false
        }

        window.addEventListener('pointermove', onPointerMove, { passive: true })

        // Na raiz do documento, e não na janela: `pointerleave` não borbulha,
        // então um ouvinte na `window` nunca chegaria a ser chamado — e o anel
        // ficaria estufado para sempre na direção onde o cursor saiu da tela.
        const root = document.documentElement

        root.addEventListener('pointerleave', onPointerOut)

        let frame = 0
        let start = 0
        let last = 0

        const tick = (now: number) => {
            frame = requestAnimationFrame(tick)

            if (!start) {
                start = now
                last = now
            }

            const t = (now - start) / 1000
            // Preso em 50 ms: voltar de outra aba daria um passo enorme, e uma
            // mola integrada com passo enorme explode.
            const step = Math.min(0.05, (now - last) / 1000)
            last = now

            const rect = box.getBoundingClientRect()

            let targetX = CENTER
            let targetY = CENTER
            let targetPower = 0

            if (seen && rect.width > 0) {
                targetX = ((pointerX - rect.left) / rect.width) * 200
                targetY = ((pointerY - rect.top) / rect.height) * 200

                const distance = Math.hypot(targetX - CENTER, targetY - CENTER)
                const gap =
                    distance > HULL ? distance - HULL : (HULL - distance) * INSIDE

                targetPower = Math.max(0, 1 - gap / REACH)
            }

            settle(aimX, targetX, step)
            settle(aimY, targetY, step)
            settle(power, targetPower, step)

            const aim = Math.atan2(aimY.value - CENTER, aimX.value - CENTER)

            // A mola é subamortecida, então na volta ela passa do repouso e a
            // força fica negativa por um instante. Isso a altura aproveita — o
            // anel afunda de leve antes de assentar, que é o que um material
            // faz e um valor interpolado não faz. O brilho, não: piscar abaixo
            // do repouso seria falha, não matéria.
            const force = power.value
            const shine = Math.max(0, force)

            circles.forEach((circle, index) => {
                const dot = DOTS[index]

                if (!dot) {
                    return
                }

                const { ring } = dot
                const angle = dot.base + ring.turn * t
                const delta = wrap(angle - aim)

                // O sino da elevação, e as ondas que saem dela — estas com
                // queda exponencial, para morrerem antes de dar a volta e
                // encontrarem a si mesmas do outro lado.
                const swell = Math.exp(-((delta / SIGMA) ** 2))
                const wave =
                    Math.cos(delta * WAVES - t * FLOW + ring.lag) *
                    Math.exp(-Math.abs(delta) * SPREAD)

                const lift = force * ring.give * (swell * LIFT + wave * RIPPLE)
                const radius = ring.radius + lift
                const crest = Math.max(0, lift)

                circle.setAttribute('cx', (CENTER + Math.cos(angle) * radius).toFixed(2))
                circle.setAttribute('cy', (CENTER + Math.sin(angle) * radius).toFixed(2))
                circle.setAttribute('r', (ring.dot * (1 + crest * 0.085)).toFixed(2))
                circle.setAttribute(
                    'opacity',
                    Math.min(1, ring.rest + shine * ring.wake + crest * 0.03).toFixed(3),
                )
            })
        }

        frame = requestAnimationFrame(tick)

        return () => {
            cancelAnimationFrame(frame)
            window.removeEventListener('pointermove', onPointerMove)
            root.removeEventListener('pointerleave', onPointerOut)
        }
    }, [reduced])

    return (
        <div ref={host} className={cn('relative flex items-center justify-center', className)}>
            {/* O SVG nasce no estado de repouso: sem JS, ou com menos movimento
                pedido, o que se vê é o anel parado — e não uma caixa vazia. */}
            <svg
                ref={canvas}
                viewBox="0 0 200 200"
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 size-full text-muted-foreground"
            >
                {DOTS.map((dot, index) => (
                    <circle
                        key={index}
                        cx={(CENTER + Math.cos(dot.base) * dot.ring.radius).toFixed(2)}
                        cy={(CENTER + Math.sin(dot.base) * dot.ring.radius).toFixed(2)}
                        r={dot.ring.dot}
                        opacity={dot.ring.rest}
                        fill="currentColor"
                    />
                ))}
            </svg>

            {children}
        </div>
    )
}
