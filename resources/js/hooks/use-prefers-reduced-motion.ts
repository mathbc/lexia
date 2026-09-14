import { useEffect, useState } from 'react'

const QUERY = '(prefers-reduced-motion: reduce)'

/**
 * Se o sistema operacional pede menos movimento.
 *
 * Quem precisa disso é o campo de pontos da home: ele desenha num `<canvas>`,
 * fora do alcance de qualquer media query de CSS, e precisa da resposta já na
 * primeira renderização para não montar animado e parar depois.
 */
export function usePrefersReducedMotion() {
    const [reduced, setReduced] = useState(() => window.matchMedia(QUERY).matches)

    useEffect(() => {
        const media = window.matchMedia(QUERY)
        const onChange = () => setReduced(media.matches)

        onChange()
        media.addEventListener('change', onChange)

        return () => media.removeEventListener('change', onChange)
    }, [])

    return reduced
}
