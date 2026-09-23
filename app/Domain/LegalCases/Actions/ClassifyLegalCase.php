<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Data\DefendantData;
use App\Domain\LegalCases\Data\LegalCaseClassification;
use App\Domain\LegalCases\Data\LegalCaseFraming;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Actions\ClassifyPracticeArea;
use App\Domain\ProceduralClasses\Actions\SelectProceduralClass;
use App\Domain\ProceduralClasses\Data\ProceduralClassSelection;
use App\Domain\Requirements\Data\RequirementListData;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Concurrency;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Read the facts of a matter: frame it, describe who is on the other side, and
 * write out what is being asked of the court.
 *
 * Quatro etapas em três tasks, e **todas** correm ao mesmo tempo dentro de um
 * único `Concurrency::run`. Não há mais nada em série depois do bloco: a espera
 * desta rota é a mais longa das três tasks, e não a soma de coisa nenhuma.
 *
 * Já foi diferente, e de três formas. As extrações viajaram como argumentos
 * nomeados do construtor, com a ordem sendo a de avaliação de argumentos do
 * PHP; depois viraram `statement` em série, o que era garantia do desenho mas
 * cobrava do advogado a soma de todas as esperas; e por último houve uma quinta
 * etapa aqui — a pesquisa de teses —, que saiu. Onde ela foi parar está abaixo.
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
 * cota de provedor esgotada atinge as três ao mesmo tempo. Isso deixou de ser
 * hipótese — as quatro etapas apontam para o Gemini, então a rajada é de cota de
 * verdade. `CONCURRENCY_DRIVER` é a saída — em `sync` tudo volta a correr em
 * série, no mesmo processo, sem tocar numa linha daqui.
 *
 * ## A fronteira do escritório
 *
 * Com os quatro agentes na nuvem, **o relato do cliente sai do escritório aqui**,
 * e sai inteiro: quem estreita é `LegalCaseDossier::forResearch()`, que é da
 * pesquisa de teses e não desta rota. Voltar atrás é trocar o `#[Provider]` dos
 * quatro agentes pela linha comentada logo acima de cada um, mais um
 * `config:clear`.
 *
 * ## A pesquisa de teses não está mais aqui
 *
 * Ela já foi a quinta etapa deste `handle()`, e a posição se justificava: ela é
 * a única que não lê só os fatos, e sim a peça que as quatro anteriores acabaram
 * de descrever. Para lhe dar o que ler, esta classe montava um `LegalCase` **não
 * salvo** com a área, a classe e os pedidos pendurados à mão.
 *
 * Duas coisas derrubaram esse arranjo, e a segunda é a que decide.
 *
 * A primeira é o tempo. A pesquisa abre as páginas dos portais antes de
 * responder, e é de longe a inferência mais lenta do projeto. Ficando
 * aqui, ela anulava o bloco concorrente: encurtar as quatro primeiras não
 * encurta a quinta, e o advogado esperava por ela antes de ver o primeiro campo
 * preenchido.
 *
 * A segunda é que uma peça não salva não tem onde guardar o que a pesquisa
 * acha. As teses voltavam no JSON, atravessavam o `sessionStorage` e morriam com
 * a aba — pesquisa que custa minutos e não sobrevive a um F5. Hoje a pesquisa
 * roda ao abrir a etapa 6, sobre uma peça que já tem chave primária, por
 * `ResearchLegalCaseForensicReview`, que a persiste no mesmo gesto. É por isso
 * que a etapa 1 do assistente precisa ser salva antes de qualquer navegação.
 *
 * O que sobra aqui é o que o preenchimento inteligente realmente é: tudo o que
 * um relato pode dar **sem abrir uma página e sem precisar de uma peça gravada**.
 *
 * ## Onde uma etapa pode faltar
 *
 * Três das quatro. A área é a única obrigatória, por duas razões que se somam:
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
 * O preço continua sendo a latência, mas encolheu duas vezes. O bloco trocou a
 * soma das etapas pela mais longa delas; a saída da pesquisa tirou da conta a
 * mais lenta de todas. O que resta são quatro inferências na nuvem, e o teto é a
 * mais lenta das três tasks.
 *
 * A dívida da fila continua de pé e o lugar dela é aqui: quando a espera virar
 * fila de verdade, é este `asController()` que devolve um identificador em vez
 * do resultado, e o `handle()` não muda uma linha. Note que o paralelismo tornou
 * o relato de progresso mais pobre, não mais rico — três tasks que correm junto
 * não têm uma ordem para relatar.
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

        return new LegalCaseClassification(
            practiceArea: $framing->area->practiceArea,
            practiceAreaJustification: $framing->area->justification,
            proceduralClass: $framing->class?->proceduralClass,
            proceduralClassJustification: $framing->class?->justification,
            defendant: $read['defendant'],
            requirements: $read['requirements'],
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
     * Uma etapa que pode faltar sem levar as outras junto.
     *
     * O enquadramento é o que a tela foi buscar; o réu e os pedidos são o que ela
     * ganha de brinde. Deixar uma falha numa delas derrubar a resposta inteira
     * cobraria do advogado os minutos das inferências que já deram certo, para
     * devolver a mesma tela de erro que ele veria sem nenhuma.
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
     * que não há sugestão, e não que o relato não descreve ninguém ou que ele não
     * pede nada. Essas duas outras coisas são doze nulos dentro de um
     * `DefendantData` e uma lista vazia dentro de um `RequirementListData`.
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
