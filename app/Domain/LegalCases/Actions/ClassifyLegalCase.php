<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\LegalCases\Data\DefendantData;
use App\Domain\LegalCases\Data\LegalCaseClassification;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\PracticeAreas\Actions\ClassifyPracticeArea;
use App\Domain\ProceduralClasses\Actions\SelectProceduralClass;
use App\Domain\ProceduralClasses\Data\ProceduralClassSelection;
use App\Domain\Requirements\Data\RequirementListData;
use Closure;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Read the facts of a matter: frame it, describe who is on the other side, and
 * write out what is being asked of the court.
 *
 * Four inferences, **uma de cada vez**, na ordem em que estão escritas: área,
 * classe, réu, pedidos. Cada etapa é um `statement` do `handle()`, e é só isso
 * que garante a fila — a etapa seguinte não é sequer construída antes de a
 * anterior ter voltado. Já foi diferente: as duas extrações viajavam como
 * argumentos nomeados do construtor, e a ordem delas era a ordem de avaliação
 * de argumentos do PHP. Corria igual, mas era uma garantia da linguagem, não do
 * desenho — e reordenar um argumento reordenaria inferência.
 *
 * Só as duas primeiras são uma cadeia de verdade: as classes que um caso pode
 * receber são as vinculadas à sua área, então a lista que o segundo agente
 * escolhe não existe antes de o primeiro responder. Cada um dos dois restringe
 * a própria resposta com um `enum`, que o provider impõe — nem uma área nem uma
 * classe inventada é algo que o modelo consiga emitir.
 *
 * As outras duas não devem nada ao enquadramento nem uma à outra.
 * `ExtractLegalCaseDefendant` lê os mesmos fatos e responde quem está sendo
 * processado; `ExtractLegalCaseRequirements` lê os mesmos fatos e responde o
 * que o cliente quer. Poderiam correr junto, e **não correm de propósito**: são
 * quatro pedidos ao mesmo modelo em nome do mesmo advogado, e dispará-los
 * sobrepostos troca minutos de espera por um pico de carga — no provider, que
 * responde com limite de taxa, e no Ollama local, onde a inferência concorrente
 * disputa a mesma máquina. A fila é a decisão; o paralelismo é que precisaria
 * de justificativa.
 *
 * Ambas seguem chamáveis sozinhas, e é assim que a etapa 2 ou a etapa 4 de uma
 * peça já salva pede a sugestão: uma inferência, não quatro. O que as põe aqui
 * é o chamador — o preenchimento inteligente pede tudo o que um relato pode dar
 * antes de abrir o assistente, e uma ida ao servidor por etapa faria o advogado
 * esperar três vezes pelo mesmo gesto.
 *
 * ## Onde uma etapa pode faltar
 *
 * Três das quatro. A área é a única obrigatória, por duas razões que se somam:
 * a classe depende dela, e é ela o que a tela foi buscar — sem área não há
 * enquadramento nenhum a devolver, e a resposta é o 503 lá de baixo.
 *
 * Da segunda em diante, uma etapa que cai não leva as seguintes junto. O relato
 * que não identifica o réu, a extração que não voltou, o agente que caiu: nada
 * disso é motivo para descartar o que já custou minutos, nem para dispensar as
 * perguntas que ainda não foram feitas. `stage()` é onde isso mora.
 *
 * ## A casca HTTP
 *
 * `POST /pecas/classificar` devolve `LegalCaseClassification::toArray()` como
 * JSON, e não uma resposta do Inertia: quem chama é a tela de preenchimento
 * inteligente, que precisa do resultado em mãos para carregá-lo até o
 * assistente — a navegação vem depois, e é do browser.
 *
 * O preço é a latência, e a fila é o que a torna a soma das quatro: são quatro
 * chamadas a `Timeout(180)` em série, e o navegador espera pelas quatro. É uma
 * dívida conhecida e o lugar dela é aqui — quando a espera passar a ser uma
 * fila de verdade, é este `asController()` que devolve um identificador em vez
 * do resultado, e o `handle()` não muda uma linha. É também lá que as etapas
 * ganhariam progresso por etapa, que é o que o desenho sequencial já permite
 * relatar e a resposta única não tem onde pôr.
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

        // 1. A área de atuação. A única etapa cujo fracasso encerra o pipeline:
        //    a etapa 2 escolhe dentro dela, e sem ela não há o que devolver.
        $area = ClassifyPracticeArea::run($facts);

        // 2. A classe processual, entre as de ajuizamento da área. Uma área sem
        //    nenhuma devolve null por desenho — e um agente que caia aqui vira
        //    o mesmo null, porque as etapas 3 e 4 não dependem da classe e o
        //    assistente abre a lista da área para o advogado escolher.
        //
        //    Uma área errada torna a classe errada por construção, e esta etapa
        //    não tem como objetar. As duas justificativas viajam separadas para
        //    que o advogado veja de qual das duas decisões desconfiar.
        $class = $this->stage(
            static fn (): ?ProceduralClassSelection => SelectProceduralClass::run($area->practiceArea, $facts),
        );

        // 3. Os dados do réu. Um relato incompleto não é falha: são doze nulos
        //    dentro de um DefendantData, e a etapa 4 é feita do mesmo jeito.
        $defendant = $this->stage(
            static fn (): DefendantData => ExtractLegalCaseDefendant::run($facts),
        );

        // 4. Os pedidos. Última da fila, e por isso a única que nada atrasa.
        $requirements = $this->stage(
            static fn (): RequirementListData => ExtractLegalCaseRequirements::run($facts),
        );

        return new LegalCaseClassification(
            practiceArea: $area->practiceArea,
            practiceAreaJustification: $area->justification,
            proceduralClass: $class?->proceduralClass,
            proceduralClassJustification: $class?->justification,
            defendant: $defendant,
            requirements: $requirements,
        );
    }

    /**
     * Uma etapa da fila que pode faltar sem levar as seguintes junto.
     *
     * O enquadramento é o que a tela foi buscar; o réu e os pedidos são o que
     * ela ganha de brinde. Deixar uma falha numa delas derrubar a resposta
     * inteira cobraria do advogado os minutos das inferências que já deram
     * certo, para devolver a mesma tela de erro que ele veria sem nenhuma.
     *
     * O `report()` é o que separa isto de engolir o erro: a causa continua
     * chegando ao log, e o nulo diz à tela que não há sugestão — e não que o
     * relato não descreve ninguém nem que ele não pede nada. Essas duas outras
     * coisas são doze nulos dentro de um `DefendantData` e uma lista vazia
     * dentro de um `RequirementListData`.
     *
     * Note que o nulo devolvido aqui não interrompe nada: quem chama segue para
     * a etapa seguinte na linha de baixo. É o que faz da fila uma fila, e não
     * uma corrente que arrebenta no elo mais fraco.
     *
     * @template TStage
     *
     * @param  Closure(): TStage  $read
     * @return TStage|null
     */
    private function stage(Closure $read): mixed
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
