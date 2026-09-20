/**
 * A revisão forense — as teses que a peça vai sustentar e os julgados que as
 * sustentam —, fora do componente.
 *
 * O que uma tese é, quais precedentes lhe pertencem e o que significa mantê-la
 * na peça não depende de React, e é a parte que a tela de hoje e a Action que
 * salvará amanhã precisam enxergar igual — o mesmo arranjo de
 * `@/lib/requirements` e `@/lib/documents`.
 */

import type { LegalResearch, ResearchedPrecedent, ResearchedThesis } from '@/types'

/**
 * Uma tese da pesquisa com os seus precedentes por perto e a decisão do
 * advogado ao lado.
 *
 * `thesis` e `precedents` são o que o servidor mandou, intocado: o que a tela
 * acrescenta é `keep`, e só. Manter a resposta do agente separada da decisão de
 * quem lê é o que permite desmarcar uma tese sem perdê-la — ela continua na
 * lista, visível e reversível, até a peça ser salva.
 *
 * `id` é a chave de correlação que o servidor cunhou, e é a `key` do React.
 */
export interface ThesisDraft {
    id: string
    thesis: ResearchedThesis
    precedents: ResearchedPrecedent[]
    keep: boolean
}

/**
 * A pesquisa como a tela a desenha: cada tese com os seus julgados de volta
 * embaixo dela.
 *
 * O servidor publica as duas listas **achatadas**, porque é essa a forma que
 * `SaveLegalCaseForensicReview` aceita — um precedente aponta para a tese pelo
 * `legal_thesis_id`. O aninhamento existiu antes disso, na gramática do agente,
 * para que o modelo não precisasse cunhar uuid de correlação; aqui ele é
 * refeito, porque ler uma tese sem os julgados dela embaixo não é ler nada.
 *
 * Toda tese chega marcada. A pesquisa foi pedida, custou minutos e só devolve o
 * que confirmou em fonte oficial: o gesto que o advogado faz é **tirar** o que
 * não serve, não recolher uma por uma o que ele mesmo mandou procurar.
 *
 * Duas peneiras, e as duas por precaução e não por expectativa. A tese sem nome
 * é descartada — o servidor já as filtra em `ForensicReviewData` —, e a tese sem
 * id não recebe precedente nenhum: `legal_thesis_id` nulo casaria com ela por
 * acidente, e dois nulos juntariam os julgados de uma na outra. Precedente que
 * não ache a sua tese fica de fora, que é o que o achatamento do servidor já
 * promete nunca produzir.
 */
export const toThesisDrafts = (research: LegalResearch | null | undefined): ThesisDraft[] =>
    (research?.theses ?? [])
        .filter((thesis) => thesis.name.trim() !== '' && thesis.id !== null)
        .map((thesis) => ({
            id: thesis.id as string,
            thesis,
            precedents: (research?.precedents ?? []).filter(
                (precedent) => precedent.legal_thesis_id === thesis.id,
            ),
            keep: true,
        }))

/** As teses que o advogado decidiu levar para a peça. */
export const keptTheses = (drafts: ThesisDraft[]): ThesisDraft[] =>
    drafts.filter((draft) => draft.keep)

/**
 * "95.00" como o advogado lê.
 *
 * Nulo é não medido, e não zero — a mesma distinção que o valor de um pedido
 * faz —, e por isso vira ausência de rótulo em vez de "0%". As casas decimais
 * só aparecem quando existem: "95%" e não "95,00%".
 */
export const adherenceLabel = (adherence: string | null): string | null => {
    if (adherence === null) {
        return null
    }

    const measured = Number.parseFloat(adherence)

    if (Number.isNaN(measured)) {
        return null
    }

    return `${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 }).format(measured)}%`
}

/**
 * O domínio de uma fonte, que é o que identifica o portal numa lista de links.
 *
 * O endereço inteiro de um acórdão do STJ não cabe na linha e não diz nada a
 * mais do que "stj.jus.br" já diz — e é a procedência que interessa, porque é
 * ela que a guarda de `OfficialLegalSources` conferiu. Um endereço que o
 * navegador não consiga interpretar volta inteiro, em vez de sumir.
 */
export const sourceLabel = (url: string): string => {
    try {
        return new URL(url).hostname.replace(/^www\./, '')
    } catch {
        return url
    }
}
