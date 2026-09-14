import { Section } from './section'

/**
 * Sobre nós.
 *
 * Um texto só, por enquanto: quando houver história para contar, ela entra
 * aqui — o `children` da `Section` já aceita o que vier.
 */
export function AboutSection() {
    return (
        <Section
            id="sobre"
            title="Sobre nós"
            description="A LexIA aplica inteligência artificial ao trabalho jurídico do dia a dia, com ferramentas que o escritório usa sem sair do navegador."
        />
    )
}
