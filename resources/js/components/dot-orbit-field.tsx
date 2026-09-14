import { useEffect, useRef } from 'react'
import { cn } from '@/lib/utils'
import { usePrefersReducedMotion } from '@/hooks/use-prefers-reduced-motion'

/**
 * O fundo da home: um anel de pontos que orbita o conteúdo.
 *
 * Não é uma grade nem uma constelação ligada por linhas. Os pontos moram na
 * superfície de um **toro** — um anel de seção circular —, inclinado o bastante
 * para virar uma elipse larga na tela. O buraco do toro é o que abre o vazio do
 * meio: a marca não fica sobre um fundo apagado à força, fica dentro do anel. É
 * por isso que a faixa é grossa nas beiradas e some no centro sem nenhuma
 * máscara.
 *
 * O movimento de onda é a soma de três coisas, e nenhuma delas é o giro: uma
 * senoide percorre o anel engrossando e afinando a faixa, cada ponto gira
 * devagar dentro da seção do tubo, e a inclinação balança de leve. O giro
 * sozinho seria um carrossel; são as três juntas que fazem a faixa ondular.
 *
 * A profundidade é o que separa isto de um círculo de pontos. A perspectiva dá
 * o tamanho — perto é maior —, e o desfoque dá o resto: cada ponto é um dos
 * cinco *sprites* pré-desenhados, do nítido ao borrão, escolhido pela soma da
 * distância ao plano de foco com um pouco de sorte própria. A sorte é o que
 * importa: só com a profundidade, a borda de cima ficaria inteira borrada e a
 * lateral inteira nítida, e o que se vê num campo assim é nítido e borrado
 * misturados.
 *
 * `<canvas>`, e não SVG: são centenas de pontos redesenhados 60 vezes por
 * segundo, e um sprite escalado por `drawImage` custa uma fração de um nó de
 * DOM mudando de atributo. Compor em `lighter` é o que deixa dois borrões
 * sobrepostos somarem brilho, como num bokeh de verdade.
 *
 * A cor sai de `--hero-particle`, lida do próprio elemento: o canvas quer uma
 * string em JS, não uma classe, e o `fillStyle` não lê `oklch`. Fica em
 * hexadecimal no `app.css`, e é a transparência que faz o resto da escala —
 * cinza claro a 15% sobre fundo quase preto já é o cinza escuro.
 *
 * Decorativo: `aria-hidden` e `pointer-events-none`, para que a tela do canvas
 * nunca fique entre o usuário e um botão.
 */

/** Inclinação do anel, em radianos: ~67°, que achata a elipse a 39% da largura. */
const TILT = 1.17

/** O giro do anel em radianos por segundo — uma volta a cada ~2 minutos. */
const SPIN = 0.048

/** Distância da câmera ao centro do anel, na escala do toro (raio maior = 1). */
const FOCAL = 3

/** Diâmetro do ponto nítido em px, antes da perspectiva. */
const DOT_SIZE = 3.4

/** A meia-grossura da faixa, na escala do toro. */
const TUBE = 0.26

/** Quantos sprites, do nítido ao mais fora de foco. */
const BLUR_STEPS = 5

/** Lado do sprite em px: folgado, porque ele é sempre escalado para baixo. */
const SPRITE = 48

/** Fração da altura que esmaece na borda de baixo. */
const HEM = 0.12

type Dot = {
    /** Onde o ponto está em volta do anel. */
    ring: number
    /** Onde ele está na seção do tubo. */
    tube: number
    /** A que distância do eixo do anel este ponto ficou. */
    reach: number
    /** O quanto ele mesmo gira dentro da seção, por segundo. */
    drift: number
    /** O desfoque próprio, antes da profundidade. */
    haze: number
    /** O brilho que lhe coube. */
    glow: number
}

function makeDot(): Dot {
    return {
        ring: Math.random() * Math.PI * 2,
        tube: Math.random() * Math.PI * 2,
        // Uniforme no *raio*, e começando em zero — o que não é o mesmo que
        // espalhar por igual. A seção do tubo é um círculo, e o anel de raio r
        // tem circunferência proporcional a r: repartir os pontos igualmente
        // entre os raios deixa os de fora esticados num aro maior. A faixa sai
        // densa na linha do anel e rareando até a beirada, que é o que se quer.
        // Um mínimo aqui furaria esse miolo e deixaria duas bordas soltas.
        reach: Math.random() * TUBE,
        drift: (Math.random() - 0.5) * 0.5,
        // Elevado ao cubo e um pouco mais: no sorteio direto metade do campo
        // sairia borrada, e o borrão tem que ser a exceção que dá profundidade.
        haze: Math.random() ** 3.2,
        glow: Math.random(),
    }
}

/** Os cinco pontos pré-desenhados, do núcleo duro ao borrão sem núcleo. */
function makeSprites(red: number, green: number, blue: number) {
    return Array.from({ length: BLUR_STEPS }, (_, step) => {
        const softness = step / (BLUR_STEPS - 1)
        const sprite = document.createElement('canvas')

        sprite.width = SPRITE
        sprite.height = SPRITE

        const ctx = sprite.getContext('2d')

        if (!ctx) {
            return sprite
        }

        const half = SPRITE / 2
        // O núcleo opaco encolhe depressa: no primeiro passo o ponto ainda tem
        // miolo, no último é só queda até a transparência.
        const core = 0.55 * (1 - softness) ** 1.6
        const fill = ctx.createRadialGradient(half, half, 0, half, half, half)

        fill.addColorStop(0, `rgb(${red} ${green} ${blue} / 1)`)
        fill.addColorStop(core, `rgb(${red} ${green} ${blue} / 1)`)
        fill.addColorStop(1, `rgb(${red} ${green} ${blue} / 0)`)

        ctx.fillStyle = fill
        ctx.fillRect(0, 0, SPRITE, SPRITE)

        return sprite
    })
}

export function DotOrbitField({ className }: { className?: string }) {
    const canvas = useRef<HTMLCanvasElement>(null)
    const reduced = usePrefersReducedMotion()

    useEffect(() => {
        const element = canvas.current
        const ctx = element?.getContext('2d')

        if (!element || !ctx) {
            return
        }

        const hex = getComputedStyle(element).getPropertyValue('--hero-particle').trim()
        const value = Number.parseInt(hex.replace('#', ''), 16)
        const sprites = makeSprites((value >> 16) & 255, (value >> 8) & 255, value & 255)

        // A quantidade é escolhida uma vez, na montagem: refazer o campo a cada
        // redimensionamento trocaria todos os pontos de lugar de uma vez.
        //
        // O celular leva *mais* pontos, e não menos, por mais contraintuitivo
        // que pareça: numa tela de retrato a elipse é bem mais larga que a
        // janela, e só a quinta parte dela aparece. Os outros quatro quintos
        // não são desperdício — são o estoque que entra em cena conforme o anel
        // gira —, mas sem eles as duas faixas visíveis ficam ralas. Quem custa
        // caro é o `drawImage`, e esse o corte de tela já evita.
        const dots = Array.from({ length: window.innerWidth < 640 ? 900 : 1100 }, makeDot)

        let width = 0
        let height = 0

        const resize = () => {
            // Acima de 2 o ganho some e o custo por quadro dobra de novo.
            const ratio = Math.min(window.devicePixelRatio || 1, 2)

            width = element.clientWidth
            height = element.clientHeight
            element.width = Math.round(width * ratio)
            element.height = Math.round(height * ratio)

            // `setTransform` substitui; `scale` acumularia a cada chamada. O
            // modo de composição também se perde no redimensionamento.
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0)
            ctx.globalCompositeOperation = 'lighter'
        }

        const draw = (t: number) => {
            ctx.clearRect(0, 0, width, height)

            const centerX = width / 2
            const centerY = height / 2

            // O raio do anel na tela. O `max` é o que salva o celular: numa tela
            // estreita a elipse fica bem mais larga que a janela e sobram só as
            // duas faixas, em cima e embaixo — que é o enquadramento certo para
            // uma tela de retrato.
            const spread = Math.max(width * 0.44, height * 0.75)

            const tilt = TILT + Math.sin(t * 0.11) * 0.05
            const sinTilt = Math.sin(tilt)
            const cosTilt = Math.cos(tilt)
            const spin = t * SPIN

            for (const dot of dots) {
                const ring = dot.ring + spin

                // A onda: uma senoide de três cristas dá a volta no anel,
                // engrossando a faixa à frente dela e afinando atrás.
                const reach = dot.reach * (1 + Math.sin(ring * 3 + t * 0.55) * 0.35)
                const tube = dot.tube + t * dot.drift

                const radius = 1 + reach * Math.cos(tube)
                const x = radius * Math.cos(ring)
                const y = radius * Math.sin(ring)
                const z = reach * Math.sin(tube)

                // Inclinação em torno do eixo horizontal, e só ela: o anel não
                // precisa de mais nenhuma rotação para parecer um anel.
                const flat = y * cosTilt - z * sinTilt
                const depth = y * sinTilt + z * cosTilt

                const scale = FOCAL / (FOCAL - depth)
                const screenX = centerX + x * spread * scale
                const screenY = centerY + flat * spread * scale

                // Fora da tela, com folga para o maior dos borrões.
                if (
                    screenX < -40 ||
                    screenX > width + 40 ||
                    screenY < -40 ||
                    screenY > height + 40
                ) {
                    continue
                }

                // Fora de foco por dois motivos que se somam: estar longe do
                // plano do meio, e o desfoque que o ponto já trazia.
                const softness = Math.min(1, dot.haze * 0.9 + Math.abs(depth) * 0.25)

                // O índice sai de um valor já preso em [0, 1]; o teste existe só
                // para o `noUncheckedIndexedAccess` do TypeScript.
                const sprite = sprites[Math.round(softness * (BLUR_STEPS - 1))]

                if (!sprite) {
                    continue
                }

                // O borrão espalha a mesma luz por mais área: cresce e apaga.
                const size = DOT_SIZE * scale * (1 + softness * 2.6)
                // A borda de baixo esmaece, e só ela: é onde a faixa mais
                // grossa do anel — a que vem para a frente — encontraria o link
                // que leva à próxima seção. No topo não é preciso, porque ali a
                // faixa é a de trás, que a perspectiva já entregou pequena.
                const hem = Math.min(1, (height - screenY) / (height * HEM))

                const alpha =
                    (0.26 + dot.glow * 0.66) * (1 - softness * 0.55) * (scale - 0.3) * hem

                ctx.globalAlpha = Math.min(0.95, alpha)
                ctx.drawImage(sprite, screenX - size / 2, screenY - size / 2, size, size)
            }

            ctx.globalAlpha = 1
        }

        let frame = 0
        let start = 0

        const tick = (now: number) => {
            start ||= now
            draw((now - start) / 1000)
            frame = requestAnimationFrame(tick)
        }

        resize()

        // Quem pede menos movimento fica com o anel parado, e não sem anel: um
        // quadro só, do instante zero.
        if (reduced) {
            draw(0)
        } else {
            frame = requestAnimationFrame(tick)
        }

        const observer = new ResizeObserver(() => {
            resize()

            if (reduced) {
                draw(0)
            }
        })

        observer.observe(element)

        return () => {
            cancelAnimationFrame(frame)
            observer.disconnect()
        }
    }, [reduced])

    return (
        <canvas
            ref={canvas}
            aria-hidden="true"
            className={cn('pointer-events-none block size-full', className)}
        />
    )
}
