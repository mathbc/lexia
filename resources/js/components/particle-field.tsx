import { useEffect, useId, useRef } from 'react'
import { tsParticles, type Container, type ISourceOptions } from '@tsparticles/engine'
import { loadBasic } from '@tsparticles/basic'
import { loadInteractivityPlugin } from '@tsparticles/plugin-interactivity'
import { loadParticlesLinksInteraction } from '@tsparticles/interaction-particles-links'
import { cn } from '@/lib/utils'
import { usePrefersReducedMotion } from '@/hooks/use-prefers-reduced-motion'

/**
 * O campo de pontos ligados por linhas, atrás da balança.
 *
 * É o tsParticles fazendo o que ele já traz pronto — a ligação entre partículas
 * vizinhas —, e nada além disso. Por isso os pacotes são só três: o `basic`, o
 * subsistema de interatividade e a interação de links. O bundle `slim` traria
 * junto todas as interações de mouse, que é exatamente o que não queremos.
 * (A ligação entre partículas é uma "interação" na taxonomia do tsParticles,
 * daí depender do `plugin-interactivity` mesmo sem nada responder ao cursor;
 * sem ele o engine lança "Interactivity Plugin is not loaded".)
 *
 * As duas cores saem de `--hero-particle` e `--hero-link`, lidas do próprio
 * elemento: o tsParticles quer uma string em JS, não uma classe, e ler a
 * variável mantém a paleta num arquivo só.
 *
 * Decorativo: `aria-hidden` aqui, e `pointer-events-none` embutido, para que a
 * tela do canvas nunca fique entre o usuário e um botão.
 */

/** Os plugins são globais ao engine: registrados uma vez por página. */
let engine: Promise<void> | null = null

const engineReady = () => {
    // Em série, e nesta ordem: a interação de links se registra no subsistema
    // de interatividade, que precisa existir antes dela.
    engine ??= (async () => {
        await loadBasic(tsParticles)
        await loadInteractivityPlugin(tsParticles)
        await loadParticlesLinksInteraction(tsParticles)
    })()

    return engine
}

export function ParticleField({ className }: { className?: string }) {
    const host = useRef<HTMLDivElement>(null)
    const reduced = usePrefersReducedMotion()

    // O engine indexa os containers por id, então dois campos na mesma tela
    // não podem dividir o mesmo; `useId` traz pontuação que não sobrevive a um
    // seletor, e sobra só o que é seguro.
    const id = `particle-field-${useId().replace(/[^a-zA-Z0-9]/g, '')}`

    useEffect(() => {
        const element = host.current

        if (!element) {
            return
        }

        const styles = getComputedStyle(element)
        const token = (name: string) => styles.getPropertyValue(name).trim()

        const options: ISourceOptions = {
            fullScreen: { enable: false },
            detectRetina: true,
            fpsLimit: 60,
            interactivity: {
                // Nada responde ao cursor: isto é fundo, não brinquedo.
                events: {
                    onClick: { enable: false },
                    onHover: { enable: false },
                    resize: { enable: true },
                },
            },
            particles: {
                number: { value: 70, density: { enable: true, width: 1400, height: 900 } },
                color: { value: token('--hero-particle') },
                links: {
                    enable: true,
                    color: token('--hero-link'),
                    distance: 150,
                    opacity: 1,
                    width: 1,
                },
                // Parado quando se pede menos movimento: as ligações continuam
                // desenhadas, o campo é que não deriva.
                move: {
                    enable: !reduced,
                    speed: 0.3,
                    direction: 'none',
                    outModes: { default: 'out' },
                    straight: false,
                },
                opacity: { value: { min: 0.35, max: 0.8 } },
                size: { value: { min: 1, max: 2 } },
                shape: { type: 'circle' },
            },
        }

        let container: Container | undefined
        let cancelled = false

        void engineReady().then(async () => {
            if (cancelled) {
                return
            }

            container = await tsParticles.load({ id, element, options })

            // Desmontou enquanto o engine carregava.
            if (cancelled) {
                container?.destroy()
                container = undefined
            }
        })

        return () => {
            cancelled = true
            container?.destroy()
        }
    }, [id, reduced])

    return <div ref={host} aria-hidden="true" className={cn('pointer-events-none', className)} />
}
