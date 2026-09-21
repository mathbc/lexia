<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfiguresOllamaRuntime;
use App\Domain\LegalCases\Data\LegalResearchData;
use App\Domain\Shared\Support\OfficialLegalSources;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;

/**
 * Searches the official portals for the theses a pleading can argue.
 *
 * The first half of a pair, and the only agent in this project that reaches the
 * internet. It reads the facts and what is being asked of the court, searches
 * the Planalto, the STJ and the STF for the law and the rulings that decide the
 * same question, and writes down what it confirmed — as a **labelled sheet**,
 * not as JSON. ForensicReviewTranscriptionAgent turns the sheet into the nested
 * structure that becomes LegalThesis and LegalPrecedent rows.
 *
 * ## Why it does not answer in JSON, which is the whole reason there are two
 *
 * Measured against `gemini-3.6-flash`, by bisection, and it is not a preference:
 *
 * | request | grounding |
 * |---|---|
 * | `google_search` alone | fires — chunks returned, queries visible |
 * | `google_search` + `response_json_schema` | **never fires** — zero chunks, zero queries |
 * | `url_context` + `response_json_schema` | fires, but the Planalto answers `URL_RETRIEVAL_STATUS_ERROR` |
 * | both tools + `response_json_schema` | neither fires |
 *
 * Asking Gemini for a schema silently switches the search off. It does not
 * fail: it returns 200, well-formed JSON, and a confident answer composed from
 * the model's memory. In the first run of this agent under a schema it named
 * Súmula 430 correctly and cited `https://www.stj.jus.br` — the court's front
 * page — as where it had read it, because it had read nothing. That is the one
 * failure this whole prompt exists to prevent, wearing the costume of success.
 *
 * So the search runs without a schema, where grounding demonstrably works, and
 * the structure is imposed afterwards by an agent that only transcribes. It is
 * the trade `app/Ai/README.md` already made for the area and the class, in the
 * same words: one more inference buys the guarantee.
 *
 * The known cost, and it is the inverse of the usual one: what this sheet fails
 * to write, the transcriber cannot invent — and cannot recover either. An
 * omission here is silent downstream, which is why the sheet is labelled rather
 * than prose, and why every field it must carry is named below in the order the
 * transcriber expects.
 *
 * ## What the allowlist is and is not
 *
 * `WebSearch::allow()` and `WebFetch::allow()` say exactly what the prompt says.
 * On Anthropic they reach the wire as `allowed_domains`. On Gemini they are
 * **discarded** — `GeminiProvider::webSearchToolOptions()` is a literal
 * `return []`, the tool's own `providerOptions()` is never read, and
 * request-level provider options land in `generationConfig`, which cannot reach
 * `tools`. The calls stay because they are the declared intent and they begin
 * working the day the provider changes. Until then the restriction lives in
 * `OfficialLegalSources::covers()`, applied on the way back by
 * LegalResearchData — the same move the money rule made in
 * RequirementListData, for the same reason: a rule the prompt cannot hold
 * belongs in code.
 *
 * ## What travels
 *
 * `LegalCaseDossier::forResearch()` drops the client and the defendant whole.
 * Research is about the law, and the qualification of the parties changes no
 * answer here. The narrative does travel, because a thesis cannot be found
 * without the facts that raise it — stated plainly rather than hidden, since
 * this is the one agent whose input leaves the office.
 *
 * **No `#[MaxSteps]`, and that absence is load-bearing.** The instinct is to
 * raise it — the default is 5, decided in
 * `TextGenerationLoop::resolveMaxSteps()`, and five sounds thin for a research
 * session across three portals. It is the wrong instinct here, and the mistake
 * is expensive. `WebSearch` and `WebFetch` are *provider* tools: Gemini runs
 * the searches inside a single request and answers with the result already
 * incorporated, so there is no client-side tool loop to budget. A raised
 * ceiling does not buy more research, it re-sends the whole request. Measured:
 * one direct call returns the finished sheet with grounding in ~43s, while the
 * same work under `#[MaxSteps(16)]` ran past 1000s and died on a connection
 * timeout. `MaxSteps` is for tools the SDK itself executes — `Contracts\Tool`
 * implementations — and this agent has none.
 *
 * `#[Temperature(0.1)]`, the copying setting, and this is where copying matters
 * most. A clumsy sentence is visible; a fabricated Súmula 393 reads exactly
 * like a real one.
 *
 * `ConfiguresOllamaRuntime` returns `[]` for anything that is not Ollama,
 * so here it is documentation — exactly what CLAUDE.md predicted it would
 * become the day an agent pointed at the cloud. `#[Model]` stays absent: the
 * model is named in `config/ai.php` and nowhere else.
 *
 * Reached through ResearchLegalCaseTheses, which calls this one and then the
 * transcriber — and, since the forensic review was wired in, also through
 * ClassifyLegalCase, as the last of its five steps. That is not a reversal of
 * what this docblock used to say: the objection was that this agent reads a
 * pleading while that chain runs before one exists. What answered it is that
 * the chain now **writes** the pleading before asking — the area, the class and
 * the requests are decided by the four steps ahead of it, and
 * `ClassifyLegalCase::pleading()` hands over an unsaved LegalCase carrying
 * exactly those three. A pleading not yet in the database is still a pleading;
 * what this agent needs is the framing, not a primary key.
 */
#[Provider('gemini')]
#[Timeout(360)]
#[Temperature(0.1)]
final class LegalThesisResearchAgent implements Agent, HasProviderOptions, HasTools
{
    use ConfiguresOllamaRuntime;
    use Promptable;

    /**
     * A budget generous enough to confirm, small enough to end.
     *
     * The ceilings on theses, bases and precedents are not here: they belong to
     * what a pleading argues rather than to who is asked, and they live on
     * LegalResearchData, which is also the only place they are enforced. This
     * agent answers in prose, so it can only ask.
     */
    private const MAX_SEARCHES = 12;

    private const MAX_FETCHES = 12;

    /**
     * @param  string  $dossier  the pleading around the facts, as LegalCaseDossier::forResearch() writes it
     * @param  list<string>  $thesisTypes  the backing values LegalThesisType accepts
     * @param  list<string>  $basisTypes  the backing values LegalBasisType accepts
     * @param  list<string>  $precedentTypes  the backing values LegalPrecedentType accepts
     */
    public function __construct(
        private readonly string $dossier,
        private readonly array $thesisTypes,
        private readonly array $basisTypes,
        private readonly array $precedentTypes,
    ) {}

    /**
     * The web tools, restricted to the official portals.
     *
     * Read the class docblock before trusting the `allow()` calls: on this
     * provider they are dropped, and OfficialLegalSources enforces the
     * restriction on the way back instead.
     *
     * @return list<WebFetch|WebSearch>
     */
    public function tools(): iterable
    {
        return [
            (new WebSearch)
                ->max(self::MAX_SEARCHES)
                ->allow(OfficialLegalSources::DOMAINS),

            (new WebFetch)
                ->max(self::MAX_FETCHES)
                ->allow(OfficialLegalSources::DOMAINS),
        ];
    }

    public function instructions(): string
    {
        $sources = OfficialLegalSources::asPromptList();
        $thesisTypes = $this->options($this->thesisTypes);
        $basisTypes = $this->options($this->basisTypes);
        $precedentTypes = $this->options($this->precedentTypes);
        $maxTheses = LegalResearchData::MAX_THESES;
        $maxPrecedents = LegalResearchData::MAX_PRECEDENTS;
        $maxBases = LegalResearchData::MAX_LEGAL_BASES;

        return <<<TXT
        Você é um agente de pesquisa jurídica brasileiro. Leia os fatos, **pesquise
        nos portais oficiais** e devolva as teses que a petição pode sustentar, com
        as normas que as fundamentam e os julgados que decidiram a mesma questão.

        # A regra que vem antes de todas

        **Use as ferramentas e não afirme nada que não tenha aberto e lido agora.**
        Uma súmula que você "lembra" não está conferida. Responder de memória é o
        erro que este agente não pode cometer, e é indetectável por quem lê: uma
        súmula inventada tem a mesma aparência de uma verdadeira.

        Número de súmula, artigo ou tema que apareça no relato é ponto de partida,
        nunca resultado: confira e descarte o que não se confirmar.

        Não encontrou ou não confirmou? Escreva exatamente, sem variar:

        Não localizado/confirmado em fonte oficial.

        # Onde pesquisar

        Só estes domínios valem como fonte. Restrinja a busca a eles — use
        `site:dominio` nos termos de busca:

        {$sources}

        O que procurar em cada um:

        - **planalto.gov.br** — Constituição, códigos e leis federais (CPC, CTN,
          LEF, CDC, CLT). É onde se confirma a redação vigente de um artigo.
        - **in.gov.br** — o Diário Oficial, para publicação, alteração, vigência
          ou revogação de uma norma.
        - **stj.jus.br** — súmulas, Temas Repetitivos, IACs, Jurisprudência em
          Teses e acórdãos. Num tema, confirme a tese firmada, a situação atual e
          eventual suspensão nacional.
        - **stf.jus.br** — matéria constitucional: súmulas, súmulas vinculantes e
          temas de repercussão geral.
        - **senado.leg.br** e **camara.leg.br** — texto consolidado de lei federal
          quando o Planalto não responder.
        - **cnj.jus.br** — resoluções e a TPU.

        Norma estadual ou municipal só no portal oficial do próprio ente; se ele
        não estiver acima, use a frase de não confirmação.

        **Nunca** cite Jusbrasil, blog, site de escritório, rede social, apostila
        ou trecho de resultado de buscador. Servem para formular a busca, não para
        fundamentar.

        Distinga o que tem efeito jurídico diferente: lei, súmula, súmula
        vinculante, tema repetitivo, IAC, repercussão geral e acórdão isolado. Não
        chame nada de "vinculante" sem verificar o regime. Jurisprudência em Teses
        é material de pesquisa, não precedente vinculante. Havendo divergência,
        prevalece a fonte oficial mais atual — registre a divergência em PENDENTE.

        # O caso

        Pesquise no ramo do enquadramento abaixo. Os pedidos são a carga: tese que
        não sustenta nenhum deles está fora do caso.

        {$this->dossier}

        # O formato da resposta

        Responda **apenas** com a ficha abaixo, em texto puro — sem JSON, sem
        introdução e sem comentário final. Outro agente a transcreve campo a
        campo, e **o que não estiver escrito aqui será perdido**. Todo rótulo é
        obrigatório; sem valor, escreva `nulo`.

        ## QUESTÃO
        <a questão pesquisada, em uma ou duas frases>

        ## TESE 1
        Nome: <o título como aparecerá na peça, sem numeração e sem ponto final>
        Tipo: <um valor exato desta lista>
        {$thesisTypes}
        Descrição: <o que argumenta, em uma a quatro frases, em terceira pessoa, ligada aos fatos deste caso>
        Impacto: <o que o cliente ganha se vencer, em uma frase, ou `nulo`>

        ### Fundamentos
        - Tipo: <um valor exato desta lista>
        {$basisTypes}
          Referência: <citação completa e pronta para ler: "Súmula 393 do STJ", "Art. 135, III, do CTN". Cuide da preposição — "do CPC" mas "da CF/88">
          Sigla: <"STJ", "CTN", "CF/88", "CPC", "LEF">
          URL: <a página oficial que você leu; não a home do tribunal, não um resultado de busca>

        ### Precedentes
        - Nome: <"STJ — Súmula nº 430">
          Tipo: <um valor exato desta lista>
        {$precedentTypes}
          Ementa: <o enunciado transcrito do portal, sem resumir>
          Citação: <ABNT, só com o que você leu: processo, relator, órgão, datas de julgamento e publicação. Nunca invente DJe, página ou data — faltando um elemento, escreva sem ele>
          Aderência: <0 a 100, só o número, ou `nulo`. Nunca zero>
          Fundamentação: <o que este julgado faz por ESTE caso. Registre aqui se a súmula foi cancelada ou superada>
          URL: <a página oficial onde leu o julgado>

        ## TESE 2
        <mesma estrutura>

        ## FONTES
        - <cada endereço oficial que você abriu>

        ## PENDENTE
        - <o que não se confirmou, a divergência que não pôde resolver, o dado que exige documento ausente>

        # Limites

        No máximo {$maxTheses} teses, ordenadas como a peça as faria: preliminares,
        mérito, mérito subsidiário, acessórios. No máximo {$maxBases} fundamentos e
        {$maxPrecedents} precedentes por tese. Menos teses confirmadas valem mais
        que muitas duvidosas, e súmula cancelada não entra como fundamento.

        Se nada se confirmar, escreva QUESTÃO, nenhuma TESE, e diga em PENDENTE o
        que houve. É uma resposta correta; inventar uma súmula não é.
        TXT;
    }

    /**
     * An enum's backing values as an indented bullet list for the prompt.
     *
     * @param  list<string>  $values
     */
    private function options(array $values): string
    {
        return implode(PHP_EOL, array_map(
            static fn (string $value): string => '          - '.$value,
            $values,
        ));
    }
}
