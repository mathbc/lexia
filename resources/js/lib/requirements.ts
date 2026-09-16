/**
 * Os pedidos que fecham a peça — "Ante o exposto, requer: …" —, fora do
 * componente.
 *
 * O que é um pedido, o que ele carrega de valor e qual é a redação de praxe
 * não depende de React, e é a parte que o formulário de hoje e a Action de
 * amanhã precisam enxergar igual.
 */

import { currencyCents } from '@/lib/format'

export interface RequirementDraft {
    /**
     * Chave local. O id do servidor só existe depois que a peça for salva, e a
     * descrição não serve de key — dois pedidos nascem vazios ao mesmo tempo.
     */
    id: string
    description: string
    /**
     * O valor em reais, já mascarado ("50.000,00"), ou vazio quando o pedido
     * não vale dinheiro. A maioria não vale: gratuidade, citação e honorários
     * por percentual não têm cifra.
     */
    amount: string
}

export interface RequirementSuggestion {
    /** O rótulo do botão: o nome pelo qual o advogado chama o pedido. */
    label: string
    /** A redação que cai no campo. */
    description: string
}

/**
 * A redação de praxe dos pedidos que aparecem em quase toda petição inicial.
 *
 * É ponto de partida, não formulário: o texto cai no campo e continua editável
 * — os dois que terminam em aberto ("para ", "de ") são assim de propósito,
 * porque o que vem depois é o caso e não a fórmula, e deixar a frase pela
 * metade põe o cursor exatamente onde está o trabalho.
 *
 * O ponto e vírgula fecha cada um porque eles são itens de uma lista só, a que
 * vem depois de "Ante o exposto, requer:".
 *
 * Valor nenhum vem junto: sugerir a cifra seria sugerir o pedido.
 */
export const SUGGESTED_REQUIREMENTS: RequirementSuggestion[] = [
    {
        label: 'Gratuidade da justiça',
        description: 'A concessão da Gratuidade da Justiça;',
    },
    {
        label: 'Tutela de urgência',
        description: 'A concessão da TUTELA DE URGÊNCIA, nos termos do art. 300 do CPC, para ',
    },
    {
        label: 'Citação do réu',
        description:
            'A citação do Réu para, querendo, contestar a ação no prazo legal, sob pena de revelia;',
    },
    {
        label: 'Produção de provas',
        description:
            'A produção de todas as provas em direito admitidas, em especial a documental, a testemunhal e a pericial;',
    },
    {
        label: 'Procedência dos pedidos',
        description: 'A procedência total dos pedidos para condenar o Réu ao pagamento de ',
    },
    {
        label: 'Custas e honorários',
        description:
            'A condenação do Réu ao pagamento de custas e honorários advocatícios, nos termos do art. 85 do CPC;',
    },
]

export const newRequirement = (description = ''): RequirementDraft => ({
    id: crypto.randomUUID(),
    description,
    amount: '',
})

/**
 * Quanto a peça pede somada, em centavos.
 *
 * Não é o valor da causa — que se calcula com outras regras e ainda não existe
 * aqui —, é só a soma do que foi escrito, para que o advogado veja num lugar
 * só o que está espalhado pela lista.
 */
export const claimedCents = (requirements: RequirementDraft[]): number =>
    requirements.reduce((total, requirement) => total + currencyCents(requirement.amount), 0)

/**
 * Um pedido em branco não é pedido: só conta o que tem texto.
 *
 * A linha vazia continua na lista — ela é o campo que acabou de ser aberto —,
 * mas não entra na contagem do cabeçalho nem, mais tarde, no que for salvo.
 */
export const writtenRequirements = (requirements: RequirementDraft[]): RequirementDraft[] =>
    requirements.filter((requirement) => requirement.description.trim() !== '')
