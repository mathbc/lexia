<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfiguresOllamaRuntime;
use App\Domain\CourtDecisions\Support\CourtDecisionSources;
use App\Domain\LegalCases\Data\DoctrineResearchData;
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
 * Searches the LexML for the legal scholarship a case like this one may lean on.
 *
 * The first half of a pair, and the third agent in this project to reach the
 * internet. It reads the framing and the narrative, condenses them into a few
 * short search terms, searches the library records the Senado publishes in the
 * LexML, and writes a **labelled sheet**. DoctrineTranscriptionAgent turns the
 * sheet into the flat structure, and DoctrineRecordReader reads each record —
 * author, title, kind, year and reference come from the page, never from here.
 *
 * ## The address is not this agent's to give
 *
 * Measured on the first live run: seven works, seven URNs, and none of them
 * existed — the kind and the year right, the catalogue number invented. Grounded
 * on Gemini, the model never sees the page's address, only a Vertex redirect,
 * and the number at the end of a library URN is opaque. So the records that get
 * read are the provider's citations (GroundingRedirect), and what this sheet
 * contributes is the **title and author** each work is matched by, the ranking,
 * and the note. The prompt says so, and asks for `nulo` rather than a number
 * composed from memory. There is no FONTES section for the same reason: the
 * provider's metadata says what was opened, and the model's list did not.
 *
 * Every measured trap of CourtDecisionResearchAgent applies unchanged, because
 * it is the same provider, the same tool and the same portal: no schema (it
 * switches the grounding off in silence), no fetch tool (with `url_context` the
 * answer comes back empty), no `#[MaxSteps]`, `thinkingLevel: low` (the default
 * deliberates until the turn ends and emits nothing), and an `allow()` that
 * Gemini discards — DoctrineSources enforces it on the way back. Read that
 * class's docblock for the tables; they are not repeated here.
 *
 * ## Why it searches and does not query the catalogue
 *
 * The catalogue has a doctrine filter — `busca/search?keyword=…
 * &f1-tipoDocumento=Doutrina` — and PHP cannot use it: `/busca/` is
 * `Disallow`ed, and after one request the Senado answers with a JavaScript
 * challenge (measured 2026-10-22; DoctrineSources has it). The `/urn/` record
 * pages are open and indexed, so a grounded search finds them.
 *
 * ## The terms are condensed, and that is the part this agent adds
 *
 * The case arrives as a framing — area, and the class with its title,
 * operational description and statutes — plus the narrative. None of it is a
 * search query. The class title alone is too wide ("Procedimento Comum Cível"
 * is every civil matter) and its description too long: the catalogue matches
 * every word, so a sentence finds nothing. The prompt asks for two to four terms
 * of two to four words on the *substantive* institute the facts raise, writes
 * them in `## TERMOS`, and the screen shows them — as the question list the
 * themes tab shows, and as links to the very search the lawyer's browser can
 * open.
 *
 * ## It never says what an author holds
 *
 * Doctrine is cited by author and work, and nothing here reads the work: the
 * catalogue carries a reference and, at best, a table of contents. So the prompt
 * forbids every sentence of the form "o autor defende…", every quotation and
 * every page number, and `Relevância` relates the *subject* of the work to the
 * case. A plausible opinion attributed to a real jurist is the doctrinal twin of
 * an invented súmula.
 *
 * `LegalCaseDossier::forDoctrines()` is what travels: the framing, no parties
 * and no requests, because this input leaves the office.
 */
#[Provider('gemini')]
#[Timeout(360)]
#[Temperature(0.1)]
final class DoctrineResearchAgent implements Agent, HasProviderOptions, HasTools
{
    use ConfiguresOllamaRuntime {
        providerOptions as private ollamaRuntime;
    }
    use Promptable;

    /**
     * A search per term and a few per work: two to four terms, seven works.
     */
    private const MAX_SEARCHES = 12;

    /**
     * @param  string  $dossier  the framing, as LegalCaseDossier::forDoctrines() writes it
     */
    public function __construct(
        private readonly string $dossier,
    ) {}

    /**
     * Search, and only search — see CourtDecisionResearchAgent::tools().
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
     * `thinkingLevel: low` on Gemini, for the measured reason
     * CourtDecisionResearchAgent::providerOptions() tabulates.
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
        $wanted = DoctrineResearchData::WANTED;
        $reserves = DoctrineResearchData::CANDIDATES - DoctrineResearchData::WANTED;

        return <<<TXT
        Você é um agente de pesquisa de doutrina jurídica brasileira. Leia os
        fatos e o enquadramento, **pesquise no acervo do LexML** e devolva as
        obras doutrinárias — livros, artigos de revista, capítulos — que tratam
        da questão de direito deste caso.

        # O que é doutrina, e para que ela serve aqui

        Doutrina é o que juristas, professores e pesquisadores escreveram sobre
        normas, princípios e institutos do Direito. Numa peça ela é fundamento
        **complementar**: explica como uma norma é lida e reforça a tese. Não é
        lei e não é jurisprudência — não confunda uma obra com um dispositivo
        legal nem com um acórdão.

        Uma obra serve quando é pertinente aos fatos, à área do Direito e à tese
        que a peça defende. Ela é citada pelo **autor, pela obra e pela
        referência bibliográfica**, e é isso que você vai localizar.

        # A regra que vem antes de todas

        **Use as ferramentas e não afirme nada que não tenha encontrado agora.**
        Um livro que você "lembra" não está conferido. Nunca invente autor,
        obra, edição, ano, página ou citação. Um autor real com uma obra que ele
        não escreveu tem a mesma aparência de uma referência verdadeira.

        Não encontrou ou não confirmou? Escreva exatamente, sem variar:

        Não localizado/confirmado em fonte oficial.

        # O que você NÃO escreve

        Você não leu as obras — só encontrou o registro delas no catálogo.
        Então:

        - **Nunca** escreva o que o autor defende, sustenta, ensina ou entende.
          Atribuir um entendimento a um jurista sem ter lido a obra é o erro que
          este agente não pode cometer.
        - **Nunca** transcreva nem resuma trecho de obra, e nunca escreva número
          de página.
        - Em `Relevância`, relacione o **assunto da obra** (o título, os
          assuntos, o sumário do registro) com a questão do caso — por exemplo:
          "Trata da responsabilidade do transportador por assalto a passageiro,
          que é a questão central do relato." Nunca "o autor afirma que…".

        # Onde pesquisar

        Só este domínio vale como fonte:

        {$sources}

        As obras do acervo têm página própria, com endereço na forma
        `https://www.lexml.gov.br/urn/urn:lex:br:rede.virtual.bibliotecas:livro:2010;000884424`
        (ou `:artigo.revista:`, `:capitulo.livro:`). **Pesquise sempre com
        `site:lexml.gov.br/urn rede.virtual.bibliotecas`** seguido dos termos —
        por exemplo:
        `site:lexml.gov.br/urn rede.virtual.bibliotecas responsabilidade civil transportador assalto`.

        **Nunca** use nem cite:

        - `lexml.gov.br/busca/` — é a tela de pesquisa do portal, bloqueada no
          `robots.txt`, e responde com uma verificação de segurança;
        - um endereço `:revista:` — é o registro do periódico inteiro, não de
          um artigo; procure o artigo (`:artigo.revista:`);
        - a home `lexml.gov.br`, Jusbrasil, blog, site de escritório, livraria
          ou trecho de resultado de buscador.

        # Como montar os termos

        O enquadramento traz o título e a descrição da classe processual. Eles
        **não** são termos de busca: o título costuma ser largo demais
        ("Procedimento Comum Cível" vale para qualquer causa cível) e a
        descrição, longa demais — o catálogo exige todas as palavras, e uma
        frase não encontra nada.

        1. Identifique o **instituto de direito material** que os fatos levantam
           — "responsabilidade civil do transportador", "dano moral",
           "alimentos gravídicos", "usucapião extraordinária".
        2. Use o título da classe só quando ele já nomeia o instituto ("Ação de
           Alimentos", "Embargos à Execução Fiscal").
        3. Escreva de **2 a 4 termos**, cada um com **2 a 4 palavras**, sem
           artigos, sem nomes de pessoas ou empresas e sem números de processo.
        4. Faça uma busca por termo, e varie os termos se a busca vier vazia.

        # Como escolher

        Entregue **{$wanted} obras** e, se encontrar, até **{$reserves} de
        reserva** — o sistema lê o registro de cada uma em ordem e mostra as
        {$wanted} primeiras que abrirem; as de reserva cobrem um registro que
        não abra.

        - Ponha primeiro a obra **mais específica** para a questão do caso; o
          manual geral do ramo vem depois.
        - Prefira obra com **autor identificado** — uma obra sem autor não pode
          ser citada e será descartada.
        - Varie os autores, e prefira a obra mais recente entre equivalentes.
        - **Não repita a mesma obra** em dois blocos.

        **`Título` e `Autor` são os campos que mais importam**: é por eles que o
        sistema reconhece a obra no registro que a busca devolveu. Copie-os
        exatamente como o resultado da busca os mostra — o título inteiro, e o
        autor como o catálogo o escreve ("Cavalieri Filho, Sergio").

        O `URL` só vale se você o viu inteiro. O número no fim do endereço
        (`;000884424`) é o código de cadastro da biblioteca e não se deduz de
        nada: se não o viu, escreva `nulo`. Um endereço montado de cabeça não
        abre, e o sistema o descarta.

        # O formato da resposta

        Responda **apenas** com a ficha abaixo, em texto puro — sem JSON, sem
        introdução e sem comentário final. Outro agente a transcreve campo a
        campo, e **o que não estiver escrito aqui será perdido**. Todo rótulo é
        obrigatório; sem valor, escreva `nulo`.

        ## TERMOS
        - <cada termo de busca que você usou, um por linha>

        ## DOUTRINA 1
        Tipo: <Livro, Artigo de revista, Capítulo de livro…>
        Autor: <o autor como o registro traz: "Tupinambá, Carolina">
        Título: <o título da obra, inteiro>
        URL: <o endereço do registro, se você o viu inteiro; senão, nulo>
        Relevância: <uma frase sua relacionando o assunto da obra à questão do caso>

        ## DOUTRINA 2
        <mesma estrutura>

        ## PENDENTE
        - <o termo que não retornou nada, o que não se confirmou>

        Se nada se confirmar, escreva TERMOS, nenhuma DOUTRINA, e diga em
        PENDENTE o que houve. É uma resposta correta; inventar uma obra não é.

        # O caso

        {$this->dossier}
        TXT;
    }
}
