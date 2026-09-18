/**
 * O que o preenchimento inteligente entrega ao assistente.
 *
 * A área vai no slug porque é a moeda do formulário e da query string; a classe
 * vai no uuid porque é ele que será gravado. São as mesmas duas formas do
 * `LegalCaseClassification::toArray()`, e por isso nada é traduzido no caminho.
 */
export interface LegalCaseHandoff {
    practice_area: string
    customer_id: string
    procedural_class_id: string
    facts: string
}

const KEY = 'lexia:legal-case-handoff'

/**
 * Por que armazenamento de navegador, num projeto que guarda estado na URL.
 *
 * O relato é a razão: são milhares de caracteres, e query string não os carrega
 * — e o servidor não tem onde guardá-los, porque a peça ainda não existe. O
 * `sessionStorage` é a aba, e não o navegador: duas abas classificando casos
 * diferentes não se misturam, e nada sobrevive ao fechamento.
 *
 * A entrega dura até a peça nascer. Quem lê passa a área que a URL está
 * mostrando, e o rascunho só vale se for dele que aquela área veio: o
 * `/pecas/nova` aberto pelo caminho manual não traz `?area`, não casa, e o
 * rascunho antigo é descartado ali mesmo. É o que permite recarregar a tela de
 * destino sem perder o que se esperou minutos para obter.
 */
export const stashHandoff = (handoff: LegalCaseHandoff): void => {
    try {
        sessionStorage.setItem(KEY, JSON.stringify(handoff))
    } catch {
        // Aba anônima, cota estourada: a tela de destino abre em branco, que é
        // ruim, mas travar a geração por causa do armazenamento seria pior.
    }
}

/**
 * O rascunho guardado, se ele pertence à área que a tela está mostrando.
 *
 * Qualquer outra resposta descarta: uma entrega que não casa é lixo de uma
 * geração abandonada, e reaproveitá-la preencheria uma peça nova com os fatos
 * de outra.
 */
export const readHandoff = (area: string): LegalCaseHandoff | null => {
    const handoff = parse(raw())

    if (handoff && area !== '' && handoff.practice_area === area) {
        return handoff
    }

    discardHandoff()

    return null
}

export const discardHandoff = (): void => {
    try {
        sessionStorage.removeItem(KEY)
    } catch {
        // Ver `stashHandoff`.
    }
}

const raw = (): string | null => {
    try {
        return sessionStorage.getItem(KEY)
    } catch {
        return null
    }
}

/**
 * Nada aqui é confiável: o texto veio do armazenamento e pode ser de uma versão
 * anterior do formato. Um campo fora de forma invalida a entrega inteira, em
 * vez de preencher meio formulário.
 */
const parse = (value: string | null): LegalCaseHandoff | null => {
    if (value === null) {
        return null
    }

    try {
        const data = JSON.parse(value) as Partial<LegalCaseHandoff>

        return typeof data?.practice_area === 'string' &&
            typeof data.customer_id === 'string' &&
            typeof data.procedural_class_id === 'string' &&
            typeof data.facts === 'string'
            ? (data as LegalCaseHandoff)
            : null
    } catch {
        return null
    }
}
