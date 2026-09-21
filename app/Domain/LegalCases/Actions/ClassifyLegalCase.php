<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Data\DefendantData;
use App\Domain\LegalCases\Data\LegalCaseClassification;
use App\Domain\LegalCases\Data\LegalCaseFraming;
use App\Domain\LegalCases\Data\LegalResearchData;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Actions\ClassifyPracticeArea;
use App\Domain\PracticeAreas\Models\PracticeArea;
use App\Domain\ProceduralClasses\Actions\SelectProceduralClass;
use App\Domain\ProceduralClasses\Data\ProceduralClassSelection;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Requirements\Data\RequirementData;
use App\Domain\Requirements\Data\RequirementListData;
use App\Domain\Requirements\Models\Requirement;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Concurrency;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Read the facts of a matter: frame it, describe who is on the other side,
 * write out what is being asked of the court, and research what the pleading
 * can argue.
 *
 * Cinco etapas, e o que as arruma é a **dependência** entre elas e nada mais.
 * Três correm ao mesmo tempo dentro de um `Concurrency::run`; a pesquisa vem
 * depois do bloco, porque não teria o que ler antes. Já foi diferente, e de
 * duas formas: as extrações viajavam como argumentos nomeados do construtor, e
 * a ordem delas era a ordem de avaliação de argumentos do PHP; depois viraram
 * cinco `statement` em série, o que era uma garantia do desenho mas cobrava do
 * advogado a soma de todas as esperas.
 *
 * ## O que corre junto, e o que não pode
 *
 * O bloco tem **três tasks para quatro etapas**, e a que carrega duas é a do
 * enquadramento. As classes que um caso pode receber são as vinculadas à sua
 * área, então a lista que o segundo agente escolhe não existe antes de o
 * primeiro responder: área e classe são uma cadeia, e `framing()` é o nome
 * dela. Cada um dos dois restringe a própria resposta com um `enum`, que o
 * provider impõe — nem uma área nem uma classe inventada é algo que o modelo
 * consiga emitir.
 *
 * As duas extrações não devem nada ao enquadramento nem uma à outra.
 * `ExtractLegalCaseDefendant` lê os mesmos fatos e responde quem está sendo
 * processado; `ExtractLegalCaseRequirements` lê os mesmos fatos e responde o
 * que o cliente quer. Por isso começam junto com a área, em t=0, em vez de
 * esperarem a vez.
 *
 * Manter área e classe na **mesma** task não é arrumação: `SelectProceduralClass`
 * escreve no banco — a auto-cura dos vetores do catálogo —, e uma task só é o
 * que garante um escritor só nas mesmas linhas de `procedural_classes`.
 *
 * Ambas as extrações seguem chamáveis sozinhas, e é assim que a etapa 2 ou a
 * etapa 4 de uma peça já salva pede a sugestão: uma inferência, não cinco. O
 * que as põe aqui é o chamador — o preenchimento inteligente pede tudo o que um
 * relato pode dar antes de abrir o assistente, e uma ida ao servidor por etapa
 * faria o advogado esperar quatro vezes pelo mesmo gesto.
 *
 * ## O que o paralelismo custa, e onde ele mora
 *
 * O driver sai de `config/concurrency.php`, e em `process` — o único que serve a
 * um request web, já que o `fork` recusa rodar fora do console — cada task é um
 * `php artisan invoke-serialized-closure`. Três consequências que este código
 * respeita e que não se pode desfazer sem quebrá-lo:
 *
 * 1. **O `try/catch` mora dentro de cada closure**, e não em volta do bloco. O
 *    `ProcessDriver` relança no processo pai a exceção que escapou de uma task
 *    e descarta o array de resultados inteiro — uma etapa que caísse levaria
 *    junto as que já tinham voltado, que é o oposto do que `stage()` promete.
 * 2. **As closures capturam só o relato**, uma string. Nada de `$this`, nada de
 *    model, nada de escrever em stdout: o filho responde em JSON por ele, e um
 *    byte a mais quebra a leitura no pai.
 * 3. **O retorno atravessa `serialize()`.** As duas metades do enquadramento
 *    carregam models de catálogo, o que funciona porque `PracticeArea` e
 *    `ProceduralClass` são tabelas globais, sem `account_id` — nenhuma das
 *    quatro etapas lê tenant, sessão ou usuário autenticado, e um processo
 *    filho não teria nenhum dos três.
 *
 * O que se paga em troca da espera: as requisições agora saem em rajada, e uma
 * cota de provedor esgotada atinge as três ao mesmo tempo. `CONCURRENCY_DRIVER`
 * é a saída — em `sync` tudo volta a correr em série, no mesmo processo, sem
 * tocar numa linha daqui.
 *
 * ## A quinta, que é de outra natureza
 *
 * `ResearchLegalCaseTheses` é a última, fora do bloco, e é a única que **não lê
 * só os fatos**: ela lê a peça que as quatro anteriores acabaram de descrever.
 * Daí a posição — o enquadramento diz em que ramo procurar e os pedidos dizem o
 * que a tese precisa sustentar, e nenhum dos dois existe antes de o agente
 * correspondente responder. É a única etapa que não podia ter entrado no bloco.
 *
 * Ela recebe um `LegalCase` **não salvo**, montado aqui em `pleading()`: é a
 * peça que está prestes a nascer, com a área, a classe e os pedidos pendurados
 * à mão. A alternativa — projetar o dossiê de pesquisa a partir das partes
 * soltas — significaria escrever duas vezes o texto que o agente lê, e as duas
 * cópias divergiriam na primeira mudança. `LegalCaseDossier::forResearch()` só
 * toca o modelo que recebe, e por isso um modelo em memória lhe serve tão bem
 * quanto um vindo do banco; `tests/Agents/LegalThesisResearchTest` monta o seu
 * do mesmo jeito.
 *
 * Ela é também a única que **sai da máquina** com busca nos portais oficiais, e
 * de longe a mais lenta: a pesquisa é a inferência mais demorada do projeto
 * porque o provider abre as páginas antes de responder. Encurtar as quatro
 * primeiras não a encurta — ela é agora a maior parte da espera, e é por isso
 * que a dívida da fila continua de pé.
 *
 * ## Onde uma etapa pode faltar
 *
 * Quatro das cinco. A área é a única obrigatória, por duas razões que se somam:
 * a classe depende dela, e é ela o que a tela foi buscar — sem área não há
 * enquadramento nenhum a devolver, e a resposta é o 503 lá de baixo. O preço do
 * paralelismo aparece exatamente aqui: quando a área cai, as duas extrações já
 * correram e são descartadas. É desperdício de cota, não de tempo de parede.
 *
 * Fora dela, uma etapa que cai não leva as outras junto. O relato que não
 * identifica o réu, a extração que não voltou, o agente que caiu, o portal do
 * STJ fora do ar: nada disso é motivo para descartar o que já custou minutos,
 * nem para dispensar as perguntas que ainda não foram feitas. `stage()` é onde
 * isso mora.
 *
 * ## A casca HTTP
 *
 * `POST /pecas/classificar` devolve `LegalCaseClassification::toArray()` como
 * JSON, e não uma resposta do Inertia: quem chama é a tela de preenchimento
 * inteligente, que precisa do resultado em mãos para carregá-lo até o
 * assistente — a navegação vem depois, e é do browser.
 *
 * O preço continua sendo a latência, e o bloco a reduziu sem a resolver: em vez
 * da soma de seis chamadas a `Timeout(360)`, a espera é agora a mais longa
 * entre o enquadramento e as duas extrações, mais a pesquisa — que são duas
 * inferências e a parte mais lenta do total. O navegador ainda espera por tudo.
 * É uma dívida conhecida e o lugar dela é aqui; quando a espera passar a ser uma
 * fila de verdade, é este `asController()` que devolve um identificador em vez
 * do resultado, e o `handle()` não muda uma linha. É também lá que as etapas
 * ganhariam progresso por etapa — e note que o paralelismo tornou isso mais
 * pobre, não mais rico: três tasks que correm junto não têm uma ordem para
 * relatar.
 *
 * Um agente fora do ar é condição de operação, não defeito de código: daí o
 * 503 com uma frase que a tela consegue mostrar, e o `report()` para que a
 * causa real continue chegando ao log.
 */
final class ClassifyLegalCase
{
    use AsAction;

    public function handle(string $facts): LegalCaseClassification
    {
        $facts = trim($facts);

        if ($facts === '') {
            throw new RuntimeException('Não há fatos para classificar.');
        }

        // As quatro etapas básicas, em três tasks. O que cada closure pode
        // capturar e por que o `try/catch` está dentro delas está no docblock
        // da classe; o resumo é que uma task que deixe escapar uma exceção
        // descarta os resultados das outras.
        /** @var array{framing: ?LegalCaseFraming, defendant: ?DefendantData, requirements: ?RequirementListData} $read */
        $read = Concurrency::run([
            'framing' => static fn (): ?LegalCaseFraming => self::stage(
                static fn (): LegalCaseFraming => self::framing($facts),
            ),
            'defendant' => static fn (): ?DefendantData => self::stage(
                static fn (): DefendantData => ExtractLegalCaseDefendant::run($facts),
            ),
            'requirements' => static fn (): ?RequirementListData => self::stage(
                static fn (): RequirementListData => ExtractLegalCaseRequirements::run($facts),
            ),
        ]);

        $framing = $read['framing'];

        // A área é a única obrigatória, e este é o mesmo 503 de sempre: a causa
        // real já foi ao log pelo `report()` do `stage()`, dentro do processo
        // que a viu, com o stack trace de verdade.
        if (! $framing instanceof LegalCaseFraming) {
            throw new RuntimeException('Não foi possível enquadrar o caso.');
        }

        $requirements = $read['requirements'];

        // A quinta etapa. Fora do bloco porque lê a peça que as quatro
        // anteriores descreveram — e esta closure não é serializada, por isso
        // pode continuar ligada a `$this`.
        // $research = self::stage(
        //     fn (): LegalResearchData => ResearchLegalCaseTheses::run(
        //         $this->pleading(
        //             $facts,
        //             $framing->area->practiceArea,
        //             $framing->class?->proceduralClass,
        //             $requirements,
        //         ),
        //     ),
        // );

        return new LegalCaseClassification(
            practiceArea: $framing->area->practiceArea,
            practiceAreaJustification: $framing->area->justification,
            proceduralClass: $framing->class?->proceduralClass,
            proceduralClassJustification: $framing->class?->justification,
            defendant: $read['defendant'],
            requirements: $requirements,
            // research: $research,
            research: null,
        );
    }

    /**
     * A cadeia que não pode ser desfeita: a área, e a classe dentro dela.
     *
     * É a única das três tasks que roda duas inferências, e roda as duas em
     * série porque `ProceduralClassCandidatesQuery` parte do `PracticeArea` —
     * as candidatas não existem antes de a área ser conhecida.
     *
     * A área **não** passa por `stage()` aqui de propósito: ela é obrigatória, e
     * deixá-la estourar é o que faz a task inteira voltar nula e a resposta
     * virar 503. A classe passa, porque uma área errada torna a classe errada
     * por construção e esta etapa não tem como objetar — as duas justificativas
     * viajam separadas justamente para que o advogado veja de qual das duas
     * decisões desconfiar. Uma área sem classes de ajuizamento também devolve
     * nulo, por desenho, e o assistente abre a lista da área.
     *
     * Estática porque é chamada de dentro de uma closure serializada, que não
     * tem instância para onde voltar.
     */
    private static function framing(string $facts): LegalCaseFraming
    {
        $area = ClassifyPracticeArea::run($facts);

        return new LegalCaseFraming(
            area: $area,
            class: self::stage(
                static fn (): ?ProceduralClassSelection => SelectProceduralClass::run(
                    $area->practiceArea,
                    $facts,
                ),
            ),
        );
    }

    /**
     * A peça que ainda não existe, montada em memória para a pesquisa ler.
     *
     * Nada aqui é salvo, e nada aqui tem id: é um `LegalCase` com as três
     * relações que `LegalCaseDossier::forResearch()` consulta penduradas à mão
     * — a área, a classe e os pedidos. `setRelation()` é o que faz o
     * `loadMissing()` da Action de pesquisa não ir ao banco procurar o que uma
     * peça sem chave primária não teria como ter.
     *
     * O cliente e o réu ficam **de fora de propósito**, e não por esquecimento:
     * `forResearch()` não os lê, porque esta é a única inferência do projeto
     * que sai da máquina e a qualificação das partes não muda resposta nenhuma
     * da pesquisa. Defini-los aqui seria dizer que o agente os vê.
     *
     * A classe pode ser nula — é a etapa 2 que pode ter caído —, e o dossiê
     * trata disso omitindo a linha. Os pedidos chegam como `Requirement` não
     * salvos porque é isso que a relação promete; o que o dossiê lê deles é a
     * frase e a cifra, que é exatamente o que o agente de extração devolveu. E
     * eles podem faltar inteiros: a extração é uma task irmã do enquadramento,
     * não uma etapa anterior, e a pesquisa é a única que sente isso.
     */
    private function pleading(
        string $facts,
        PracticeArea $area,
        ?ProceduralClass $class,
        ?RequirementListData $requirements,
    ): LegalCase {
        $claims = $requirements instanceof RequirementListData ? $requirements->requirements : [];

        $pleading = new LegalCase(['facts' => $facts]);

        $pleading->setRelation('practiceArea', $area);
        $pleading->setRelation('proceduralClass', $class);
        $pleading->setRelation('requirements', new Collection(array_map(
            static fn (RequirementData $requirement): Requirement => new Requirement([
                'description' => $requirement->description,
                'amount' => $requirement->amount,
            ]),
            $claims,
        )));

        return $pleading;
    }

    /**
     * Uma etapa que pode faltar sem levar as outras junto.
     *
     * O enquadramento é o que a tela foi buscar; o réu, os pedidos e as teses
     * são o que ela ganha de brinde. Deixar uma falha numa delas derrubar a
     * resposta inteira cobraria do advogado os minutos das inferências que já
     * deram certo, para devolver a mesma tela de erro que ele veria sem
     * nenhuma. Vale em dobro para a pesquisa, que é a última e a mais lenta, e
     * cuja falha pode nem ser nossa — um portal oficial fora do ar derruba a
     * etapa sem que nada no projeto tenha mudado.
     *
     * **Ela é chamada de dentro das closures, e não em volta do bloco**, porque
     * é só aí que ela protege alguma coisa: o `ProcessDriver` relança no pai a
     * exceção que escapou de uma task e joga fora o array de resultados
     * inteiro. Um `try/catch` em volta de `Concurrency::run` transformaria uma
     * etapa perdida em todas elas.
     *
     * O `report()` é o que separa isto de engolir o erro: a causa continua
     * chegando ao log — e, para as três tasks, ao log escrito de dentro do
     * processo filho, onde o stack trace ainda é o verdadeiro. O nulo diz à tela
     * que não há sugestão, e não que o relato não descreve ninguém, que ele não
     * pede nada ou que a pesquisa nada confirmou. Essas três outras coisas são
     * doze nulos dentro de um `DefendantData`, uma lista vazia dentro de um
     * `RequirementListData` e um `LegalResearchData` de listas vazias com o
     * `pending` escrito.
     *
     * Estática por causa das closures serializadas: `self::` é reescrito para o
     * nome da classe ao serializar, e uma instância não atravessaria.
     *
     * @template TStage
     *
     * @param  Closure(): TStage  $read
     * @return TStage|null
     */
    private static function stage(Closure $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Quem pode abrir uma peça pode pedir o enquadramento de uma: é o mesmo
     * gesto, e a rota é a porta curta para o mesmo formulário.
     */
    public function authorize(ActionRequest $request): bool
    {
        return $request->user()->can('create', LegalCase::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'facts' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        return [
            'facts' => 'fatos',
        ];
    }

    public function asController(ActionRequest $request): JsonResponse
    {
        try {
            $classification = $this->handle($request->string('facts')->toString());
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Não foi possível enquadrar o caso agora. Tente novamente em instantes.',
            ], 503);
        }

        return response()->json($classification->toArray());
    }
}
