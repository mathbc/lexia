/**
 * Os temas do STJ da revisão forense — a segunda aba da etapa 6 —, fora do
 * componente.
 *
 * O mesmo arranjo de `@/lib/forensic-review` e `@/lib/court-decisions`: o que
 * significa manter um tema na peça não depende de React, e é a parte que a tela
 * e o "Concluir" precisam enxergar igual.
 */

import type { ResearchedLegalTheme } from '@/types'

/**
 * Um tema vinculado com a decisão do advogado ao lado.
 *
 * `theme` é o que o servidor mandou, intocado; o que a tela acrescenta é
 * `keep`, e só. Desmarcar não tira o tema da lista — ele fica esmaecido,
 * reversível, até o "Concluir" transformar a decisão em desvínculo.
 *
 * `id` é o do tema, que é também a `key` do React e o `legal_theme_id` do
 * payload.
 */
export interface LegalThemeDraft {
    id: string
    theme: ResearchedLegalTheme
    keep: boolean
}

/**
 * Os temas como a aba os desenha: todos marcados.
 *
 * A seleção foi pedida e só devolve o que o agente leu e julgou aplicável: o
 * gesto do advogado é **tirar** o que não serve, como nas teses e nos julgados.
 */
export const toLegalThemeDrafts = (themes: ResearchedLegalTheme[] | null | undefined): LegalThemeDraft[] =>
    (themes ?? []).map((theme) => ({ id: theme.id, theme, keep: true }))

/**
 * Os temas mantidos, na forma que `SaveLegalCaseThemes` grava.
 *
 * A razão volta junto porque a gravação é um `sync()`: um vínculo que ficou
 * recebe de novo as colunas do pivot, e mandar a razão vazia a apagaria. O
 * tema desmarcado não vai — e é por não ir que o servidor o desvincula.
 */
export const toLegalThemePayload = (
    drafts: LegalThemeDraft[],
): { legal_theme_id: string; reason: string | null }[] =>
    keptLegalThemes(drafts).map((draft) => ({
        legal_theme_id: draft.id,
        reason: draft.theme.reason,
    }))

/** Os temas que o advogado decidiu manter na peça. */
export const keptLegalThemes = (drafts: LegalThemeDraft[]): LegalThemeDraft[] => drafts.filter((draft) => draft.keep)
