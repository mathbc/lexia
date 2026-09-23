/**
 * A jurisprudência de uma peça — os julgados que a pesquisa confirmou e a
 * decisão do advogado sobre cada um —, fora do componente.
 *
 * O irmão de `@/lib/forensic-review`, e feito na mesma forma pelo mesmo motivo:
 * o que um julgado é e o que significa mantê-lo na peça não depende de React, e
 * é a parte que a tela de hoje e a gravação de amanhã precisam enxergar igual.
 *
 * O que ele **não** repete é o aninhamento. Lá uma tese carrega os seus
 * precedentes embaixo, porque ler a tese sem eles não é ler nada; aqui a
 * pesquisa devolve uma lista só — um julgado não pende de nada e não sustenta
 * nada declarado. Então não há o que desachatar, e a única coisa que a tela
 * acrescenta ao que o servidor mandou é o `keep`.
 */

import type { ResearchedCourtDecision } from "@/types";

/**
 * Um julgado com a decisão do advogado ao lado.
 *
 * `decision` é o que o servidor mandou, intocado; `keep` é o que a tela
 * acrescenta. Manter a resposta da pesquisa separada da leitura de quem decide
 * é o que permite desmarcar um julgado sem perdê-lo — ele continua na lista,
 * visível e reversível, até a peça ser concluída.
 *
 * `id` é a chave da linha no banco, e é a `key` do React. Não há id de
 * correlação nesta etapa: a pesquisa grava antes de a tela desenhar, então todo
 * julgado que chega aqui já é linha.
 */
export interface CourtDecisionDraft {
    id: string;
    decision: ResearchedCourtDecision;
    keep: boolean;
}

/**
 * Os julgados como a tela os desenha: todos marcados.
 *
 * A pesquisa foi pedida, custou minutos e só devolve o que confirmou num
 * registro do LexML — o gesto que o advogado faz é **tirar** o que não serve,
 * não recolher um por um o que ele mesmo mandou procurar. A mesma regra das
 * teses, e pela mesma razão.
 *
 * A peneira do `id` é precaução e não expectativa: um julgado sem chave não
 * seria alcançável pela caixa de seleção, e duas linhas sem chave colidiriam na
 * `key` do React. O servidor só publica linhas gravadas, então ela nunca
 * remove nada.
 */
export const toCourtDecisionDrafts = (
    decisions: ResearchedCourtDecision[] | null | undefined,
): CourtDecisionDraft[] =>
    (decisions ?? [])
        .filter((decision) => decision.id !== null)
        .map((decision) => ({
            id: decision.id as string,
            decision,
            keep: true,
        }));

/**
 * Os julgados que o advogado decidiu levar para a peça, na forma que o servidor
 * grava.
 *
 * O `id` que viaja é o da linha, e é por ele que `SaveLegalCaseCourtDecisions`
 * reconhece o que já existe — o que ficou de fora desta lista é apagado pelo
 * diff. É por isso que desmarcar só vira gravação no "Concluir": até lá a
 * decisão é do navegador, e voltar atrás não custa nada.
 */
export const toCourtDecisionPayload = (
    drafts: CourtDecisionDraft[],
): ResearchedCourtDecision[] =>
    keptCourtDecisions(drafts).map((draft) => ({
        ...draft.decision,
        id: draft.id,
    }));

/** Os julgados que o advogado decidiu levar para a peça. */
export const keptCourtDecisions = (
    drafts: CourtDecisionDraft[],
): CourtDecisionDraft[] => drafts.filter((draft) => draft.keep);

/**
 * "1998-04-28" como o advogado lê.
 *
 * Nulo é o registro que não trouxe data, e vira ausência de rótulo em vez de
 * uma data inventada — a mesma distinção que a aderência de um precedente faz.
 * Uma data que o navegador não consiga interpretar volta inteira, em vez de
 * sumir: o texto cru é pior do que a data formatada e melhor do que nada.
 */
export const decidedAtLabel = (decidedAt: string | null): string | null => {
    if (decidedAt === null) {
        return null;
    }

    // `Date` sem hora lê "1998-04-28" como UTC e o fuso do Brasil devolveria o
    // dia anterior; as partes da própria string não têm esse problema.
    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(decidedAt);

    if (parts === null) {
        return decidedAt;
    }

    return `${parts[3]}/${parts[2]}/${parts[1]}`;
};
