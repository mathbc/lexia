<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfiguresOllamaRuntime;
use App\Domain\CourtDecisions\Support\CourtDecisionSources;
use App\Domain\LegalCases\Data\CourtDecisionResearchData;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;

/**
 * Searches the LexML for the rulings handed down in cases like this one.
 *
 * The first half of a pair, and the second agent in this project to reach the
 * internet. It reads the framing and the narrative, searches the Senado's LexML
 * catalogue for acórdãos that decided the same question, opens each record and
 * writes down what it read — as a **labelled sheet**, not as JSON.
 * CourtDecisionTranscriptionAgent turns the sheet into the flat structure that
 * becomes CourtDecision rows.
 *
 * ## It does not repeat the forensic review
 *
 * Step six researches *theses*: what the pleading argues, with the súmula or
 * the tema that grounds it — LegalThesisResearchAgent, across the Planalto, the
 * STJ and the STF. This one researches *decisions*: the acórdão of a similar
 * case, cited to show how that court has already resolved the question. Nothing
 * here scores a ruling against these facts or attaches it to an argument; that
 * reading is the lawyer's, and it is why a CourtDecision has no `adherence` and
 * no thesis.
 *
 * ## Why it does not answer in JSON, which is the whole reason there are two
 *
 * The same measurement LegalThesisResearchAgent's docblock tabulates, and it is
 * not a preference: on Gemini, asking for `response_json_schema` switches the
 * grounding off **silently**. The request returns 200, the JSON is well formed,
 * and the model has searched nothing — composing a plausible ementa from
 * memory, which for this agent is the worst possible failure, because a
 * fabricated acórdão is shaped exactly like a real one and carries a URN that
 * looks canonical.
 *
 * So the search runs without a schema, where grounding demonstrably works, and
 * the structure is imposed afterwards by an agent that only transcribes.
 *
 * ## Why the prompt says `/urn/` and forbids `/busca/`
 *
 * Measured against the live portal rather than assumed, and it is the one thing
 * about this agent that would be impossible to guess:
 *
 * | path | robots.txt | what a client gets |
 * |---|---|---|
 * | `/busca/`, `/busca/SRU` | `Disallow` | the Senado's anti-bot challenge, in JS |
 * | `/urn/<a urn>` | `Allow` | 200, the record in full |
 *
 * The search screen is therefore in no index for a search tool to find, and a
 * fetch of it comes back as proof-of-work rather than as data — which also
 * closes the tempting door of querying the SRU endpoint from PHP and skipping
 * the model entirely. What is left is exactly what is wanted: the per-document
 * page, which Google indexes and which prints Localidade, Autoridade, Título,
 * Data, Ementa, Assuntos and the Nome Uniforme — the eight fields this sheet
 * carries, in the catalogue's own words.
 *
 * ## Why there is a search tool and no fetch tool
 *
 * A second silent Gemini failure, measured here the way the schema one was
 * measured for the theses, and just as counter-intuitive — asking the model to
 * *read* the pages it finds makes it answer nothing at all:
 *
 * | request | answer |
 * |---|---|
 * | `google_search` alone | the full sheet — ~2.9 KB, real `/urn/` addresses |
 * | `google_search` + `url_context` | **empty text**, 211 completion tokens, ~60s |
 *
 * The second row is not a refusal and not an error: the request returns 200,
 * the grounding demonstrably fires — the citation metadata comes back carrying
 * a genuine LexML record — and `text` is the empty string. Downstream that is
 * indistinguishable from a provider outage, which is why
 * ResearchLegalCaseCourtDecisions raises on a blank sheet instead of handing
 * nothing to the transcriber.
 *
 * So the search runs alone, where it demonstrably works. **The cost is
 * fidelity, and it is known rather than hoped away**: grounded on search
 * results, the model writes a real ruling at a real address, but its ementa is
 * the indexed extract rather than the page's full text — measured against
 * `AgInt no REsp 2107831/RS`, where it dropped two segments of the headnote and
 * compressed a numbered item. Hence the prompt below forbids composing and the
 * screen shows the `source_url` next to every ruling: the record is one click
 * away, and it is the record that gets cited.
 *
 * ## What the allowlist is and is not
 *
 * `WebSearch::allow()` says exactly what the prompt says. On Gemini it is
 * **discarded** — `webSearchToolOptions()` is a literal `return []` — so the
 * restriction lives in `CourtDecisionSources::isRecord()`, applied on the way
 * back by CourtDecisionResearchData. The call stays because it is the declared
 * intent and it begins working the day the provider changes.
 *
 * ## What travels
 *
 * `LegalCaseDossier::forCourtDecisions()` is the narrowest projection there is:
 * the framing alone. No client, no defendant, no requests. The narrative does
 * travel, because a ruling cannot be matched to a case without the facts that
 * raise the question — stated plainly rather than hidden, since this is one of
 * the two agents whose input leaves the office.
 *
 * **No `#[MaxSteps]`, and that absence is load-bearing**, for the reason
 * LegalThesisResearchAgent records: `WebSearch` is a *provider* tool, run
 * inside a single request, so there is no client-side loop to budget. Raising
 * the ceiling re-sends the whole request — measured at ~43s direct versus past
 * 1000s and a timeout under `#[MaxSteps(16)]`.
 *
 * `#[Temperature(0.1)]`, the copying setting. An ementa is transcribed, never
 * composed, and this is where copying matters most.
 *
 * `ConfiguresOllamaRuntime` returns `[]` for anything that is not Ollama, so
 * here it is documentation — exactly what CLAUDE.md predicted it would become
 * the day an agent pointed at the cloud. `#[Model]` stays absent: the model is
 * named in `config/ai.php` and nowhere else.
 *
 * Reached through ResearchLegalCaseCourtDecisions, which calls this one and
 * then the transcriber.
 */
#[Provider('gemini')]
#[Timeout(360)]
#[Temperature(0.1)]
final class CourtDecisionResearchAgent implements Agent, HasProviderOptions, HasTools
{
    use ConfiguresOllamaRuntime {
        providerOptions as private ollamaRuntime;
    }
    use Promptable;

    /**
     * A budget generous enough to find, small enough to end.
     *
     * Higher than the thesis research allows itself, and the asymmetry is the
     * catalogue's: finding a ruling takes a search *per ruling*, where
     * confirming a súmula takes one for the whole answer. The ceiling on how
     * many decisions come back is not here — it belongs to what a pleading
     * cites rather than to who is asked, and it lives on
     * CourtDecisionResearchData, which is also the only place it is enforced.
     */
    private const MAX_SEARCHES = 14;

    /**
     * @param  string  $dossier  the framing, as LegalCaseDossier::forCourtDecisions() writes it
     */
    public function __construct(
        private readonly string $dossier,
    ) {}

    /**
     * Search, and **only** search.
     *
     * The absence of `WebFetch` is the load-bearing part, and it is measured
     * rather than preferred — see the class docblock's second table. Adding it
     * beside the search does not enrich the answer, it erases it.
     *
     * Read the class docblock before trusting the `allow()` call too: on this
     * provider it is dropped, and CourtDecisionSources enforces the restriction
     * on the way back instead.
     *
     * @return list<WebSearch>
     */
    public function tools(): iterable
    {
        return [
            (new WebSearch)
                ->max(self::MAX_SEARCHES)
                ->allow(CourtDecisionSources::DOMAINS),
        ];
    }

    /**
     * The runtime options, and the only agent in this project that has any to
     * send to the cloud.
     *
     * `ConfiguresOllamaRuntime` answers `[]` for anything that is not Ollama,
     * which is right for its three keys and leaves this agent with nothing —
     * and nothing is what broke it. Measured twice, with `google_search` as the
     * only tool:
     *
     * | thinking | reasoning tokens | completion tokens | text |
     * |---|---|---|---|
     * | default | 6.340 | **0** | empty |
     * | `low` | ~2.400 | ~950 | the full sheet |
     *
     * Left alone, the model deliberates over a prompt this long until the turn
     * ends, and emits **nothing** — a 200 with well-formed metadata, live
     * grounding citations, and an empty string where the answer should be. It
     * is the Gemini face of the trap CLAUDE.md records for Ollama, where the
     * default effort "gastou 42.239 caracteres de raciocínio e devolveu lista
     * vazia": more reasoning bought variance, not quality.
     *
     * The key rides in `generationConfig`, which is exactly where
     * `BuildsTextRequests` merges an agent's provider options — the same
     * mechanism whose *inability* to reach `tools` is why the domain allowlist
     * has to be enforced in PHP. It reaches this, because thinking is
     * generation.
     *
     * `thinkingLevel` and not `thinkingBudget`: the budget in tokens is the
     * Gemini 2.5 spelling, and the configured model is a 3.x flash.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $lab = $provider instanceof Lab ? $provider : Lab::tryFrom($provider);

        if ($lab !== Lab::Gemini) {
            return $this->ollamaRuntime($provider);
        }

        return ['thinkingConfig' => ['thinkingLevel' => 'low']];
    }

    public function instructions(): string
    {
        $sources = CourtDecisionSources::asPromptList();
        $min = CourtDecisionResearchData::MIN_DECISIONS;
        $max = CourtDecisionResearchData::MAX_DECISIONS;

        return <<<TXT
        Você é um agente de pesquisa de jurisprudência brasileira. Leia os fatos
        e o enquadramento, **pesquise no portal LexML** e devolva os julgados que
        decidiram questão igual ou semelhante à deste caso.

        # A regra que vem antes de todas

        **Use as ferramentas e não afirme nada que não tenha aberto e lido
        agora.** Um acórdão que você "lembra" não está conferido. Responder de
        memória é o erro que este agente não pode cometer, e é indetectável por
        quem lê: uma ementa inventada tem a mesma aparência de uma verdadeira, e
        um URN inventado parece tão canônico quanto o real.

        Número de processo, súmula ou tema que apareça no relato é ponto de
        partida, nunca resultado: confira e descarte o que não se confirmar.

        Não encontrou ou não confirmou? Escreva exatamente, sem variar:

        Não localizado/confirmado em fonte oficial.

        # Onde pesquisar

        Só este domínio vale como fonte:

        {$sources}

        **Pesquise sempre com `site:lexml.gov.br/urn`** — por exemplo:
        `site:lexml.gov.br/urn embargos à execução fiscal garantia do juízo`.

        A página de um documento tem endereço na forma
        `https://www.lexml.gov.br/urn/urn:lex:br:...`, e é a única que serve: é
        ela que traz a ementa, e é o endereço que você vai registrar.

        **Nunca** use nem cite `lexml.gov.br/busca/`. Aquele caminho é a tela de
        pesquisa do portal, está bloqueado no `robots.txt` e responde com uma
        verificação de segurança em vez do conteúdo — um endereço de `/busca/`
        não abre para quem for conferir. A home `lexml.gov.br` também não serve
        como fonte: ela não contém julgado nenhum.

        **Nunca** cite Jusbrasil, blog, site de escritório, rede social ou
        trecho de resultado de buscador. Servem para formular a busca, não para
        fundamentar.

        # Como pesquisar

        1. Monte os termos a partir da **classe processual** e da **questão
           jurídica** que os fatos levantam — não a partir de nomes de pessoas
           ou empresas, que não aparecem em ementa.
        2. Busque com `site:lexml.gov.br/urn`.
        3. Faça uma busca por julgado, variando os termos, em vez de tirar todos
           de um resultado só.
        4. Registre os rótulos como o registro os traz: Localidade, Autoridade,
           Título, Data, Ementa, Assuntos e Nome Uniforme.

        Prefira julgado de tribunal superior (STF, STJ) ao de tribunal local
        quando os dois responderem à mesma questão, e prefira o mais recente
        entre julgados equivalentes. Variedade de órgão julgador vale mais que
        repetir a mesma turma. **Não repita o mesmo julgado** em dois blocos.

        # A ementa você NÃO escreve

        Esta é a regra mais importante, e ela é o contrário do que parece.

        **Não transcreva a ementa e não escreva ementa nenhuma.** Quem lê a
        ementa é o sistema, direto na página do registro, depois que você
        responder — e o texto que vale é o do tribunal, não o seu. Uma ementa
        que você escrevesse de memória ou resumisse entraria numa petição como
        se fosse a do acórdão, que é o erro que este desenho existe para tornar
        impossível.

        Seu trabalho é **achar** e **identificar** o julgado. No lugar da
        ementa, escreva em `Relevância` uma frase sua dizendo por que ele
        responde a este caso.

        O `URL` é o campo que mais importa: é por ele que o sistema vai buscar o
        texto. Copie-o exatamente como aparece, e nunca o monte a partir do URN
        nem o complete de cabeça — um endereço que não abre faz o julgado
        inteiro ser descartado.
        # O formato da resposta

        Responda **apenas** com a ficha abaixo, em texto puro — sem JSON, sem
        introdução e sem comentário final. Outro agente a transcreve campo a
        campo, e **o que não estiver escrito aqui será perdido**. Todo rótulo é
        obrigatório; sem valor, escreva `nulo`.

        ## QUESTÃO
        <a questão jurídica pesquisada, em uma ou duas frases>

        ## JULGADO 1
        Título: <o título do documento: "AgInt no REsp 2107831 / RS">
        Autoridade: <o tribunal e o órgão: "Superior Tribunal de Justiça. 1ª Turma">
        Assuntos: <os termos de indexação do registro, separados por vírgula; `nulo` se não houver>
        Nome Uniforme: <o URN: "urn:lex:br:superior.tribunal.justica;turma.6:acordao;resp:1998-04-28;143513-288332">
        URL: <o endereço do registro, começando por https://www.lexml.gov.br/urn/>
        Relevância: <uma frase sua sobre por que este julgado responde a este caso>

        ## JULGADO 2
        <mesma estrutura>

        ## FONTES
        - <cada endereço /urn/ que você abriu>

        ## PENDENTE
        - <o que não se confirmou, a divergência que não pôde resolver, a busca que não retornou nada>

        # Limites

        No mínimo **{$min}** julgados e no máximo **{$max}**, cada um num
        endereço diferente. Se depois de buscar de verdade você só achar dois,
        entregue dois e diga em PENDENTE o que faltou — dois endereços que
        abrem valem mais que três com um inventado.

        Se nada se confirmar, escreva QUESTÃO, nenhum JULGADO, e diga em
        PENDENTE o que houve. É uma resposta correta; inventar um acórdão não é.

        # O caso

        {$this->dossier}
        TXT;
    }
}
