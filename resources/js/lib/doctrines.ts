/**
 * A doutrina de uma peça — as obras que a pesquisa confirmou no acervo do LexML
 * e a decisão do advogado sobre cada uma —, fora do componente.
 *
 * O gêmeo de `@/lib/court-decisions`, e pelo mesmo motivo: o que significa
 * manter uma obra na peça não depende de React, e é a parte que a aba e o
 * "Concluir" precisam enxergar igual.
 */

import type { ResearchedDoctrine } from "@/types";

/**
 * Uma obra com a decisão do advogado ao lado.
 *
 * `doctrine` é o que o servidor mandou, intocado; `keep` é o que a tela
 * acrescenta. Desmarcar não tira a obra da lista — ela fica esmaecida e
 * reversível até o "Concluir" transformar a decisão em remoção.
 *
 * `id` é a chave da linha no banco: a pesquisa grava antes de a tela desenhar.
 */
export interface DoctrineDraft {
    id: string;
    doctrine: ResearchedDoctrine;
    keep: boolean;
}

/**
 * As obras como a aba as desenha: todas marcadas, na ordem do agente — a mais
 * específica para a questão primeiro.
 *
 * O gesto do advogado é **tirar** o que não serve, como nas teses, nos temas e
 * nos julgados. A peneira do `id` é precaução: o servidor só publica linhas
 * gravadas.
 */
export const toDoctrineDrafts = (
    doctrines: ResearchedDoctrine[] | null | undefined,
): DoctrineDraft[] =>
    (doctrines ?? [])
        .filter((doctrine) => doctrine.id !== null)
        .map((doctrine) => ({
            id: doctrine.id as string,
            doctrine,
            keep: true,
        }));

/**
 * As obras mantidas, na forma que `SaveLegalCaseDoctrines` grava.
 *
 * O `id` que viaja é o da linha, e é por ele que o diff do servidor reconhece o
 * que já existe; a ordem da lista é a posição gravada. O que ficou de fora é
 * apagado.
 */
export const toDoctrinePayload = (
    drafts: DoctrineDraft[],
): ResearchedDoctrine[] =>
    keptDoctrines(drafts).map((draft) => ({
        ...draft.doctrine,
        id: draft.id,
    }));

/** As obras que o advogado decidiu manter na peça. */
export const keptDoctrines = (drafts: DoctrineDraft[]): DoctrineDraft[] =>
    drafts.filter((draft) => draft.keep);

/**
 * A busca de doutrina do LexML para um termo — a URL que o portal usa na própria
 * tela, com o filtro de tipo de documento.
 *
 * É o advogado quem a abre, no navegador dele: o servidor não pode, porque
 * `/busca/` é proibido no `robots.txt` do portal e responde a um cliente com a
 * verificação de segurança do Senado — o navegador a resolve, o PHP não. Ver
 * `DoctrineSources`.
 */
export const lexmlDoctrineSearchUrl = (term: string): string => {
    const query = new URLSearchParams({
        keyword: term,
        "f1-tipoDocumento": "Doutrina",
    });

    return `https://www.lexml.gov.br/busca/search?${query.toString()}`;
};
