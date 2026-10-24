/**
 * O que o preenchimento inteligente faz — ler os fatos, decidir a área,
 * escolher entre as classes de ajuizamento vinculadas a ela e procurar no
 * mesmo relato quem é o réu e o que o cliente está pedindo.
 *
 * São frases sobre o trabalho, não sobre o andamento, e a distinção importa:
 * as etapas correm **em paralelo** no worker, e a ordem em que estão escritas
 * aqui é a de quem lê, não a de quem executa. O job não relata por onde anda,
 * então nenhuma delas afirma que uma etapa terminou.
 *
 * A primeira frase é a triagem, e é a única cuja posição é verdade: ela roda
 * sozinha e antes de todas, e um relato que ela recusa volta ao formulário
 * antes de as outras começarem — com o motivo debaixo do campo Fatos.
 *
 * Mora aqui, e não numa das telas, porque são duas que a mostram: o formulário
 * do preenchimento, no instante do envio, e a tela da peça nova, durante a
 * espera inteira.
 */
export const ANALYSIS_STEPS = [
    "Conferindo se o texto é o relato de um caso jurídico.",
    "Lendo o relato e separando o que tem peso jurídico.",
    "Comparando os fatos com as áreas de atuação do catálogo.",
    "Reunindo as classes processuais de ajuizamento da área.",
    "Ordenando as classes candidatas pela proximidade com o caso.",
    "Pesando prazo, pressuposto e instrumento de cada candidata.",
    "Escrevendo a justificativa de cada uma das duas escolhas.",
    "Separando quem narra o caso de quem está do outro lado dele.",
    "Recolhendo o que o relato diz sobre o réu e onde encontrá-lo.",
    "Procurando no relato o que o cliente quer do juízo.",
    "Escrevendo cada pedido e conferindo os valores contra os fatos.",
] as const;
