import { useCallback, useEffect, useState } from 'react'

export type Appearance = 'light' | 'dark' | 'system'

const STORAGE_KEY = 'appearance'

const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches

const apply = (appearance: Appearance) => {
    document.documentElement.classList.toggle('dark', appearance === 'dark' || (appearance === 'system' && prefersDark()))
}

const stored = (): Appearance => {
    const value = localStorage.getItem(STORAGE_KEY)

    return value === 'light' || value === 'dark' ? value : 'system'
}

/**
 * Claro, escuro ou o que o sistema operacional disser.
 *
 * A classe já é escrita antes da primeira pintura por um script no
 * `app.blade.php` — sem ele a tela pisca em branco a cada navegação dura. Aqui
 * só mantemos a escolha em dia, inclusive quando o sistema muda sozinho ao
 * anoitecer.
 */
export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>(() => stored())

    useEffect(() => {
        const media = window.matchMedia('(prefers-color-scheme: dark)')
        const onChange = () => appearance === 'system' && apply('system')

        media.addEventListener('change', onChange)

        return () => media.removeEventListener('change', onChange)
    }, [appearance])

    const updateAppearance = useCallback((next: Appearance) => {
        setAppearance(next)
        localStorage.setItem(STORAGE_KEY, next)
        apply(next)
    }, [])

    return { appearance, updateAppearance }
}
