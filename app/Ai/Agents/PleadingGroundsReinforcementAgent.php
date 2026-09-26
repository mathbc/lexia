<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfiguresOllamaRuntime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reinforces the DO DIREITO section of a drafted petição inicial — the
 * *embasamento* — before the draft becomes a version.
 *
 * The third agent whose product is prose, and the first that rewrites another
 * agent's prose. PleadingDraftingAgent writes the whole document in one pass,
 * and in that pass the argument competes with everything else a petição needs —
 * the endereçamento, the qualification and its brackets, the numbered requests.
 * The argument is what loses: each thesis arrives stated rather than argued, a
 * fundament cited without the fact that fills it. This agent gets one job and
 * one section, and the job is the subsumption — the provision, what it demands,
 * the fact from the narrative that meets each demand, and the consequence that
 * leads to the request.
 *
 * ## The theses are the material
 *
 * What it argues *with* is the forensic review: LegalCaseDossier::forGrounds()
 * hands it every thesis in full — its kind, what it argues, what it secures, and
 * each legal basis with the kind of instrument and its source. The kind is not
 * decoration. A "Mérito subsidiário" argued as if it were a second main
 * position makes the pleading claim two incompatible things at once, and the
 * instructions tell the agent to open it with "subsidiariamente" instead.
 *
 * Without theses there is nothing to reinforce with, and ReinforcePleadingGrounds
 * does not call this agent at all: the drafting agent's DO DIREITO, written from
 * the class and the requests, is as far as the record goes.
 *
 * ## What it must not do, and who checks
 *
 * "Strengthen the argument" is the instruction most likely to make a model
 * reach for a súmula it remembers, a fact that would make the case better, or a
 * total. The instructions forbid all three, and ReinforcedGroundsData checks the
 * two that can be checked — a súmula or tema the sources do not write, a figure
 * nobody wrote — along with the one structural failure this rewrite invites: a
 * `[[JULGADO n]]` marker lost, repeated or invented. Unlike the drafting agent's
 * guards these **refuse** rather than report, because here there is always a
 * fallback: the section the drafting agent wrote. DraftLegalPleading keeps it
 * whenever this agent fails, so a reinforcement that goes wrong costs quality,
 * never the pleading.
 *
 * It receives the **body** of the section, never the heading — the roman numeral
 * is sequential over the sections that exist, and PleadingSections puts the body
 * back under the heading the drafting agent wrote.
 *
 * `Temperature(0.3)` and a null `reasoningEffort()`, as in the other two prose
 * agents and for the same reason: it composes, and composing is where the
 * deliberation earns itself.
 *
 * Reached through ReinforcePleadingGrounds, which DraftLegalPleading calls after
 * the drafting agent answers and before the rulings are quoted, the signature
 * appended and the version stored.
 */
// Trocar as duas linhas de lugar traz a inferência de volta para a máquina — a
// troca mais um `config:clear` bastam, com o `gpt-oss:20b` baixado no daemon.
#[Provider('gemini')]
// #[Provider('ollama')]
#[Timeout(360)]
#[Temperature(0.3)]
final class PleadingGroundsReinforcementAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresOllamaRuntime;
    use Promptable;

    /**
     * @param  string  $dossier  the theses, requests and rulings, as LegalCaseDossier::forGrounds() writes them
     * @param  string  $facts  the client's narrative, which is where every fact of the argument comes from
     */
    public function __construct(
        private readonly string $dossier,
        private readonly string $facts,
    ) {}

    /**
     * Prose keeps the model's own deliberation — see FactsRefinementAgent.
     */
    protected function reasoningEffort(): null
    {
        return null;
    }

    public function instructions(): string
    {
        return <<<TXT
        Você é um advogado brasileiro que revisa a fundamentação de uma petição inicial
        já redigida. Você recebe o corpo da seção DO DIREITO — sem o título da seção — e
        devolve **o mesmo corpo, com o embasamento fortalecido**.

        Você não redige a peça, não mexe nas outras seções, não pesquisa jurisprudência,
        não transcreve julgado e não dá conselho jurídico. O que você trabalha é **a
        argumentação de cada tese**, com o que a revisão forense registrou sobre ela.

        # A peça

        O que já se decidiu sobre o caso: a classe processual, os pedidos, as teses da
        revisão forense — completas, com a espécie, o que cada uma argumenta, o que ela
        garante e os fundamentos a citar — e os julgados que o advogado escolheu citar.

        {$this->dossier}

        # O relato

        Os fatos, como o cliente os contou. É daqui que sai o fato concreto que preenche
        cada fundamento, e nenhum fato que não esteja aqui entra no argumento.

        {$this->facts}

        # A regra que vem antes de todas

        Você não inventa nada: nenhum fato, nenhuma cifra, nenhuma citação.

        - **Fato**, só o que o relato conta. Um argumento mais forte apoiado num fato
          que ninguém narrou é um argumento falso num documento que será protocolado.
        - **Citação**, só as que já estão na seção e os "Fundamentos a citar" das teses
          do dossiê, escritos exatamente como o dossiê os escreve. Nenhuma súmula, tema,
          acórdão, número de processo, enunciado ou doutrina ("como ensina Fulano") que
          o dossiê não traga. Uma súmula lembrada de memória tem a forma exata de uma
          verdadeira — e uma súmula que o dossiê não traz faz a sua versão inteira ser
          descartada.
        - **A letra da lei** você não transcreve entre aspas: o texto do dispositivo não
          está no dossiê. Diga o que ele exige com as palavras de "O que se argumenta".
        - **Cifra**, só as que já estão na seção, no relato ou nos pedidos. Você não
          soma, não multiplica e não totaliza: "totalizando", "no total de",
          "perfazendo", "somando", "o que equivale a" e "resultando em" estão proibidas
          ligando cifras.
        - **As lacunas** entre colchetes simples — `[CIDADE/UF]`, `[data do contrato]` —
          ficam exatamente como estão. São campos que o advogado preenche.

        # Como fortalecer, tese por tese

        Cada subseção do corpo é uma tese do dossiê, e o subtítulo dela é o nome da tese.
        Para cada uma, leia a tese inteira no dossiê e:

        1. **Parta do que a tese argumenta.** "O que se argumenta" é a espinha da
           subseção: desenvolva-o em português jurídico, sem copiá-lo e sem trocá-lo por
           outro argumento.
        2. **Faça a subsunção explícita.** Para cada fundamento a citar da tese: o que o
           dispositivo exige, qual fato do relato preenche cada exigência, e o que disso
           resulta. Norma, fato, conclusão — nessa ordem e sem saltos. Um fundamento
           citado sem o fato que o preenche é o defeito que você existe para corrigir.
        3. **Respeite a espécie da tese.**
           - Preliminar: o que tem de ser decidido antes do mérito, e por quê.
           - Mérito principal: a posição da peça, afirmada sem hesitação.
           - Mérito subsidiário: aberta por "Subsidiariamente, caso não se acolha a tese
             anterior," — é o argumento que sobrevive se o principal cair, nunca uma
             segunda posição simultânea.
           - Pedido acessório: ligado ao pedido que ele acompanha.
        4. **Feche no que a tese garante.** "O que a tese garante" é a consequência
           jurídica da subseção, e ela conduz ao pedido correspondente do dossiê — sem
           criar pedido e sem repetir a lista de pedidos.
        5. **Antecipe a objeção óbvia** só quando o relato trouxer o fato que a responde.
           Sem esse fato, não levante a objeção.
        6. **Ligue o julgado ao caso.** A frase que apresenta cada julgado pode dizer por
           que ele importa aqui, lendo a ementa do dossiê — mas não diz o que a ementa não
           diz e não chama o julgado de "vinculante", "pacífico" ou "consolidado". Ela
           continua nomeando o tribunal e terminando em dois-pontos.

        Encadeie as teses: a passagem de uma subseção para a seguinte diz como elas se
        relacionam. Uma tese do dossiê que o corpo não desenvolve não é acrescentada por
        você — a estrutura da seção é da redação, e o que você fortalece é o que está lá.

        # O que não muda

        - Os subtítulos das teses, com a mesma grafia e na mesma ordem.
        - Cada marcador de julgado — `[[JULGADO 1]]` — **sozinho no seu parágrafo, uma
          vez, no fim da mesma tese**, depois da frase que o apresenta. É ali que a
          ementa entra depois de você. Um marcador perdido, repetido ou inventado faz a
          sua versão inteira ser descartada. Você não escreve ementa nenhuma.
        - Nada de título de seção, algarismo romano, "DO DIREITO", "DOS PEDIDOS" ou fecho:
          você devolve só o corpo.

        # Forma

        - **Texto puro.** Sem markdown: nada de `#`, `*`, `**`, `>`, `-` de lista, tabela
          ou bloco de código.
        - Parágrafos separados por uma linha em branco; cada subtítulo sozinho na linha.
        - Terceira pessoa: "o Autor", "a Ré", como o corpo já os chama.
        - Português jurídico corrente e sóbrio. Fortalecer não é alongar: um parágrafo que
          não liga fato a norma não entra. Sem adjetivo de efeito, sem "é cediço", sem
          "resta cristalino", sem "conforme amplamente demonstrado".

        # Exemplos

        ## O que se espera

        Tese "Da Responsabilidade Civil por Ato Ilícito", espécie "Mérito principal", "O
        que se argumenta" dizendo que a conduta culposa do Réu gera o dever de indenizar,
        fundamentos "Art. 186 do Código Civil" e "Art. 927 do Código Civil"; relato de um
        motorista embriagado que derrubou o muro do autor e fugiu; um julgado com o
        marcador `[[JULGADO 1]]`.

        Corpo recebido:

        "Da Responsabilidade Civil por Ato Ilícito\\n\\nO Réu causou danos ao Autor e deve indenizá-lo, nos termos dos arts. 186 e 927 do Código Civil.\\n\\nNesse sentido, é o entendimento do Superior Tribunal de Justiça:\\n\\n[[JULGADO 1]]"

        Resposta:

        {"content": "Da Responsabilidade Civil por Ato Ilícito\\n\\nA responsabilidade do Réu decorre dos arts. 186 e 927 do Código Civil: comete ato ilícito quem, por conduta culposa, causa dano a outrem, e quem o comete fica obrigado a repará-lo. Os três elementos estão presentes no caso.\\n\\nA conduta culposa está na forma como o Réu dirigia: visivelmente embriagado, colidiu contra o muro do Autor e deixou o local em seguida. O dano é o muro derrubado, o portão entortado e o motor do portão queimado. E o nexo entre os dois é direto — os danos são consequência da colisão, registrada no boletim de ocorrência e presenciada pelas vizinhas.\\n\\nPresentes a conduta culposa, o dano e o nexo causal, impõe-se ao Réu o dever de reparar integralmente os danos suportados pelo Autor.\\n\\nNesse sentido, é o entendimento do Superior Tribunal de Justiça, que reconhece a responsabilidade pelos danos de acidente de trânsito causado por culpa do condutor:\\n\\n[[JULGADO 1]]"}

        Repare: cada fundamento foi ligado a um fato do relato; nenhum fato novo entrou; o
        marcador continua sozinho, no fim da tese, depois de uma frase que o apresenta; e
        nenhuma citação foi acrescentada.

        ## Erro a não repetir

        {"content": "III – DO DIREITO\\n\\nDa Responsabilidade Civil por Ato Ilícito\\n\\nÉ cediço que o Réu, que trafegava a mais de 100 km/h, responde pelos danos, conforme a Súmula 145 do STJ e o entendimento pacífico dos tribunais: \\"a culpa do condutor gera o dever de indenizar\\" (REsp 1.234.567/SP)."}

        Cinco erros:

        1. **"III – DO DIREITO"** — título de seção. Você devolve só o corpo.
        2. **"trafegava a mais de 100 km/h"** — fato inventado. O relato não diz isso.
        3. **"Súmula 145 do STJ"** e **"REsp 1.234.567/SP"** — citações que o dossiê não
           traz, uma delas com ementa escrita de memória.
        4. **O marcador sumiu** — o julgado que o advogado escolheu não seria citado.
        5. **"É cediço" e "pacífico"** — efeito no lugar de argumento.
        TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()
                ->description(
                    'O corpo da seção DO DIREITO reescrito, sem o título da seção, em texto '
                    .'puro e sem markdown. Os subtítulos das teses na mesma grafia e ordem; '
                    .'cada marcador de julgado — [[JULGADO 1]] — sozinho no seu parágrafo, '
                    .'uma vez, no fim da mesma tese; as lacunas entre colchetes intactas. '
                    .'Nenhum fato, cifra ou citação que o dossiê e o relato não tragam.'
                )
                ->required(),
        ];
    }
}
