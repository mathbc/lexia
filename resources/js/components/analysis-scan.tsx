import { useEffect, useRef, type ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { usePrefersReducedMotion } from '@/hooks/use-prefers-reduced-motion'

/**
 * Dois anéis de pontos varridos por um feixe — o desenho de uma leitura em
 * curso.
 *
 * É o vocabulário de pontos do fundo da home usado para outra coisa. Lá os
 * pontos orbitam por conta própria, decorando uma tela onde não se espera por
 * nada; aqui a espera é justamente o momento em que o usuário não pode fazer
 * nada, e o que move os pontos precisa dizer que algo corre enquanto isso.
 *
 * Um giro sozinho seria um carrossel: diz "aguarde" e nada mais. O que diz
 * *análise* é o feixe. Ele dá voltas mais rápido do que os anéis, acende o
 * ponto que alcança e deixa atrás de si uma cauda que apaga devagar — a borda
 * de ataque é curta e a de trás é longa, de modo que o desenho tem sentido: dá
 * para ver por onde a leitura passou e para onde ela vai. A cada volta o feixe
 * reencontra pontos que já andaram, e nenhuma volta repete a anterior.
 *
 * Os dois anéis são os dois agentes. O de dentro roda ao contrário e recebe o
 * feixe com atraso (`lag`), porque a classe processual só pode ser escolhida
 * depois que a área de atuação é conhecida — a segunda inferência acompanha a
 * primeira, não acontece junto com ela.
 *
 * SVG e não `<canvas>`: são 74 círculos, não os mil pontos do campo da home, e
 * um punhado de atributos por quadro é mais barato do que manter um contexto
 * 2D. O desenho é `aria-hidden` — quem precisa saber o que está acontecendo lê
 * as mensagens ao lado, não o anel.
 */

const TAU = Math.PI * 2

/** Centro do desenho, em unidades do `viewBox`. */
const CENTER = 60

/** Velocidade do feixe, em radianos por segundo: uma volta a cada ~3,5 s. */
const SWEEP = 1.8

/**
 * O quanto a cauda demora a apagar, em radianos.
 *
 * Mais do que isto e o rastro dá a volta e encontra a própria cabeça, e o anel
 * vira um pulsar uniforme que não aponta para lugar nenhum.
 */
const TRAIL = 1.15

/** A borda de ataque, em radianos: curta, para o feixe ter frente. */
const LEAD = 0.16

/** O quanto o ponto aceso se afasta do anel, em unidades do `viewBox`. */
const LIFT = 3.4

/**
 * Os dois anéis, de fora para dentro.
 *
 * `turn` é o giro em radianos por segundo, e os sentidos são opostos: é o que
 * dá profundidade a um desenho de duas dimensões. `rest` é o brilho de quem o
 * feixe não alcançou — baixo, mas nunca zero, senão o anel desaparece e o que
 * se vê é uma cauda solta no escuro.
 */
const RINGS = [
    { radius: 46, count: 44, dot: 1.1, turn: 0.22, rest: 0.16, wake: 0.84, lag: 0 },
    { radius: 32, count: 30, dot: 1, turn: -0.34, rest: 0.17, wake: 0.62, lag: 1.15 },
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
    return ((((angle + Math.PI) % TAU) + TAU) % TAU) - Math.PI
}

export function AnalysisScan({ children, className }: { children?: ReactNode; className?: string }) {
    const canvas = useRef<SVGSVGElement>(null)
    const reduced = usePrefersReducedMotion()

    useEffect(() => {
        const svg = canvas.current

        // Quem pede menos movimento fica com os dois anéis desenhados e
        // parados: o SVG já nasce no estado de repouso, e é só não mexer nele.
        // O que informa o andamento, nesse caso, são as mensagens — que trocam
        // de qualquer maneira, porque são conteúdo e não enfeite.
        if (!svg || reduced) {
            return
        }

        const circles = svg.querySelectorAll<SVGCircleElement>('circle')

        let frame = 0
        let start = 0

        const tick = (now: number) => {
            frame = requestAnimationFrame(tick)
            start ||= now

            const t = (now - start) / 1000
            const sweep = t * SWEEP

            // A respiração: sem ela os anéis têm raio exato e o conjunto fica
            // mecânico, que é a diferença entre esperar e travar.
            const breath = 1 + Math.sin(t * 0.85) * 0.016

            circles.forEach((circle, index) => {
                const dot = DOTS[index]

                if (!dot) {
                    return
                }

                const { ring } = dot
                const angle = dot.base + ring.turn * t

                // Quanto o feixe já passou deste ponto: positivo atrás dele,
                // negativo à frente. As duas quedas são exponenciais e de
                // escalas bem diferentes, e é a diferença que dá direção.
                const behind = wrap(sweep - ring.lag - angle)
                const glow = behind >= 0 ? Math.exp(-behind / TRAIL) : Math.exp(behind / LEAD)

                const radius = ring.radius * breath + glow * LIFT

                circle.setAttribute('cx', (CENTER + Math.cos(angle) * radius).toFixed(2))
                circle.setAttribute('cy', (CENTER + Math.sin(angle) * radius).toFixed(2))
                circle.setAttribute('r', (ring.dot * (1 + glow * 0.7)).toFixed(2))
                circle.setAttribute('opacity', Math.min(1, ring.rest + glow * ring.wake).toFixed(3))
            })
        }

        frame = requestAnimationFrame(tick)

        return () => cancelAnimationFrame(frame)
    }, [reduced])

    return (
        <div className={cn('relative flex items-center justify-center', className)}>
            <svg
                ref={canvas}
                viewBox="0 0 120 120"
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 size-full text-foreground"
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
