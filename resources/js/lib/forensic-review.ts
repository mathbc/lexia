/**
 * A revisão forense — as teses que a peça vai sustentar e os julgados que as
 * sustentam —, fora do componente.
 *
 * O que uma tese é, quais precedentes lhe pertencem e o que significa mantê-la
 * na peça não depende de React, e é a parte que a tela de hoje e a Action que
 * salvará amanhã precisam enxergar igual — o mesmo arranjo de
 * `@/lib/requirements` e `@/lib/documents`.
 */

import type { LegalBasis, LegalResearch, ResearchedPrecedent, ResearchedThesis } from '@/types'

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
 * As duas listas, e só elas — o que `toThesisDrafts` de fato lê.
 *
 * Mais estreito do que `LegalResearch` de propósito: as teses chegam de duas
 * origens agora. Da pesquisa desta sessão, que traz também a questão, as fontes
 * e o que ficou pendente; e do banco, numa peça já concluída, onde nada disso
 * foi gravado — `LegalCaseFormProps::draft()` projeta as duas listas e mais
 * nada. Pedir o objeto inteiro obrigaria a inventar campos vazios para
 * satisfazer um tipo que esta função nunca consulta.
 */
export type ResearchedReview = Pick<LegalResearch, 'theses' | 'precedents'>

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
export const toThesisDrafts = (
    research: ResearchedReview | null | undefined,
): ThesisDraft[] =>
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

/**
 * As teses mantidas e os seus julgados, na forma que o servidor grava.
 *
 * O caminho de volta de `toThesisDrafts`. A tela aninha para ler — uma tese sem
 * os julgados dela embaixo não é leitura nenhuma — e o servidor grava achatado,
 * porque o precedente aponta para a tese por `legal_thesis_id`. Esta função
 * desfaz o aninhamento.
 *
 * O `id` que viaja é o de correlação, cunhado no PHP quando a pesquisa achatou a
 * resposta do agente, ou o id real quando a tese já é linha no banco. Os dois
 * servem: `SaveLegalCaseForensicReview` trata o id postado como **palpite**, e
 * quem decide se ele é chave é a pertinência da linha à peça, não o payload. É
 * por isso que uma tese nova e uma tese salva podem viajar na mesma lista sem
 * que nada aqui precise distinguir as duas.
 *
 * Os precedentes de uma tese desmarcada não vão junto: o que não entra na peça
 * não traz o que o fundamentaria.
 */
export const toForensicReviewPayload = (
    drafts: ThesisDraft[],
): {
    theses: ResearchedThesis[]
    precedents: ResearchedPrecedent[]
} => {
    const kept = keptTheses(drafts)

    return {
        theses: kept.map((draft) => ({ ...draft.thesis, id: draft.id })),
        precedents: kept.flatMap((draft) =>
            draft.precedents.map((precedent) => ({
                ...precedent,
                legal_thesis_id: draft.id,
            })),
        ),
    }
}

/** As teses que o advogado decidiu levar para a peça. */
export const keptTheses = (drafts: ThesisDraft[]): ThesisDraft[] =>
    drafts.filter((draft) => draft.keep)

/**
 * A decisão do advogado aplicada às teses que o servidor mandou.
 *
 * A decisão vive à parte, num mapa por id, e as teses são derivadas das props a
 * cada render. É o que deixa uma tese editada chegar com o texto novo sem
 * desfazer o que foi desmarcado nas outras: a edição não muda id nenhum, então
 * uma assinatura de ids não a veria, e reconstruir a lista inteira remarcaria
 * tudo. Uma tese que o mapa não conhece — a que a pesquisa acabou de trazer, a
 * que acabou de ser cadastrada — chega marcada, como sempre chegou.
 */
export const withDecisions = (
    drafts: ThesisDraft[],
    decisions: Record<string, boolean>,
): ThesisDraft[] =>
    drafts.map((draft) => ({ ...draft, keep: decisions[draft.id] ?? true }))

/** A tese que o advogado escreveu à mão — a única que se edita. */
export const isManualThesis = (thesis: ResearchedThesis): boolean =>
    thesis.origin === 'manual'

/**
 * Uma linha da tabela de fundamentação do modal, com todo campo em string.
 *
 * String e não nulo porque é o que os controles falam: o `Select` reserva `''`
 * para "nada escolhido", e o `Input` não tem outro vazio. `key` é só do React —
 * a referência não serve, porque duas linhas recém-abertas estão ambas em
 * branco — e não viaja: `toLegalThesisPayload` a tira.
 */
export interface LegalBasisRow {
    key: string
    type: string
    reference: string
    source: string
}

/** O que o modal de "Cadastrar tese" e "Editar tese" edita. */
export interface LegalThesisForm {
    name: string
    type: string
    description: string
    impact: string
    legal_bases: LegalBasisRow[]
}

export const newLegalBasisRow = (basis?: LegalBasis): LegalBasisRow => ({
    key: crypto.randomUUID(),
    type: basis?.type ?? '',
    reference: basis?.reference ?? '',
    source: basis?.source ?? '',
})

/**
 * O formulário vazio do cadastro, ou o preenchido da edição.
 *
 * O cadastro abre com uma linha de fundamentação em branco, porque quase toda
 * tese tem ao menos um dispositivo e o primeiro clique seria sempre o de
 * "Adicionar fundamento".
 */
export const toThesisForm = (thesis: ResearchedThesis | null): LegalThesisForm =>
    thesis === null
        ? { name: '', type: '', description: '', impact: '', legal_bases: [newLegalBasisRow()] }
        : {
              name: thesis.name,
              type: thesis.type ?? '',
              description: thesis.description,
              impact: thesis.impact ?? '',
              legal_bases: thesis.legal_bases.map(newLegalBasisRow),
          }

/**
 * O formulário na forma que `CreateLegalThesis` e `UpdateLegalThesis` validam.
 *
 * A linha de fundamentação inteira em branco — aberta e abandonada — sai aqui,
 * antes do envio. A meio preenchida não: ela vai, e o servidor recusa a
 * referência que falta, porque um tipo escolhido sem citação é engano a apontar
 * e não linha a descartar. O vazio vira `null`, que é o que a coluna guarda para
 * o que ninguém informou.
 */
export const toLegalThesisPayload = (form: LegalThesisForm) => ({
    name: form.name.trim(),
    type: form.type,
    description: form.description.trim(),
    impact: blankToNull(form.impact),
    legal_bases: form.legal_bases
        .filter((row) => !isBlankBasisRow(row))
        .map(
            (row): LegalBasis => ({
                type: blankToNull(row.type),
                reference: row.reference.trim(),
                source: blankToNull(row.source),
            }),
        ),
})

/**
 * O índice com que cada linha da tela chega ao servidor, ou nulo para a que não
 * vai.
 *
 * É por ele que o erro volta: `legal_bases.1.reference` nomeia a segunda linha
 * **postada**, e não a segunda da tela, quando uma linha em branco acima dela
 * foi descartada. Sem esta tradução a mensagem apareceria na linha errada.
 */
export const postedBasisIndexes = (rows: LegalBasisRow[]): (number | null)[] => {
    let posted = 0

    return rows.map((row) => (isBlankBasisRow(row) ? null : posted++))
}

const isBlankBasisRow = (row: LegalBasisRow): boolean =>
    [row.type, row.reference, row.source].every((value) => value.trim() === '')

const blankToNull = (value: string): string | null => (value.trim() === '' ? null : value.trim())

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
 * O domínio de uma fonte, que é como uma frase nomeia o portal — "Ler o
 * registro no lexml.gov.br".
 *
 * Numa lista de fontes ele não serve: três leis do Planalto viravam três
 * "planalto.gov.br" idênticos, com cara de link repetido, e o que distingue uma
 * da outra está no caminho. Ali vai o endereço inteiro. Um endereço que o
 * navegador não consiga interpretar volta inteiro, em vez de sumir.
 */
export const sourceLabel = (url: string): string => {
    try {
        return new URL(url).hostname.replace(/^www\./, '')
    } catch {
        return url
    }
}

/**
 * As citações recusadas que têm nome para mostrar.
 *
 * Um relato gravado antes de `LegalResearchData` contar à parte as recusas sem
 * nome as traz nesta lista, como `"nulo"` — o rótulo que a ficha escreve onde
 * não tem valor — ou como o "Citação sem identificação" que a guarda punha no
 * lugar. Hoje elas chegam em `unidentified_citations`, mas o relato antigo
 * continua no banco até alguém pesquisar de novo.
 */
export const namedCitations = (citations: string[]): string[] =>
    citations.filter(
        (citation) =>
            !['nulo', 'citação sem identificação'].includes(
                citation.trim().toLowerCase(),
            ),
    )
