<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\AgentsExecutionLogs\Actions\RecordAgentFailure;
use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Data\CourtAddressingSuggestionData;
use App\Domain\LegalCases\Data\DefendantData;
use App\Domain\LegalCases\Data\FactsScreeningData;
use App\Domain\LegalCases\Data\InjunctiveReliefSuggestionData;
use App\Domain\LegalCases\Data\LegalCaseClassification;
use App\Domain\LegalCases\Data\LegalCaseFraming;
use App\Domain\LegalCases\Exceptions\InadmissibleFactsException;
use App\Domain\PracticeAreas\Actions\ClassifyPracticeArea;
use App\Domain\ProceduralClasses\Actions\SelectProceduralClass;
use App\Domain\ProceduralClasses\Data\ProceduralClassSelection;
use App\Domain\Requirements\Data\RequirementListData;
use Closure;
use Illuminate\Support\Facades\Concurrency;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Read the facts of a matter: frame it, describe who is on the other side,
 * write out what is being asked of the court, say whether any of it cannot
 * wait, and say to whom it is addressed.
 *
 * Cinco etapas em três tasks, e **todas** correm ao mesmo tempo dentro de um
 * único `Concurrency::run`. Depois do bloco há uma sexta, em série — o
 * endereçamento —, e o motivo de ela não caber lá dentro está abaixo, em "O
 * endereçamento vem depois".
 *
 * Já foi diferente, e de três formas. As extrações viajaram como argumentos
 * nomeados do construtor, com a ordem sendo a de avaliação de argumentos do
 * PHP; depois viraram `statement` em série, o que era garantia do desenho mas
 * cobrava do advogado a soma de todas as esperas; e por último houve uma quinta
 * etapa aqui — a pesquisa de teses —, que saiu. Onde ela foi parar está abaixo.
 *
 * ## A triagem vem antes
 *
 * Antes do bloco, e sozinha, `ScreenLegalCaseFacts` diz se o texto é o relato
 * de um caso jurídico. Ela nasceu de uma cantiga infantil que foi enquadrada,
 * ganhou classe e virou rascunho: até ali a única guarda era o `required`, e
 * cada texto sem sentido custava as cinco inferências — e um texto que dá
 * ordens ao modelo, em vez de narrar fatos, chegava como prompt de todos eles.
 *
 * Ela corre **em série** porque o ponto é exatamente não acionar o bloco: ao
 * lado dele, a recusa chegaria depois de as três tasks já terem gastado a cota.
 * O preço é uma inferência curta a mais na espera de todo preenchimento.
 *
 * Duas saídas, e a diferença entre elas é o desenho. A recusa é uma resposta
 * sobre a entrada: `InadmissibleFactsException`, que o job (`RunAssistedFill`) grava como
 * recusa sob `facts`, com a frase que `FactsScreeningData::message()` compôs. A
 * triagem que **falha** — o provider fora, a resposta sem estrutura — não passa
 * por `stage()` e vira a falha de sempre: uma guarda que abre quando cai não é
 * guarda, e o agente da área, que viria em seguida, mora no mesmo provider.
 *
 * Antes dela há um piso que não custa nada, o `min:20` das regras, para que
 * "teste" e "asdf" nem cheguem a ser perguntados.
 *
 * ## O que corre junto, e o que não pode
 *
 * O bloco tem **três tasks para cinco etapas**, e a que carrega três é a do
 * enquadramento. As classes que um caso pode receber são as vinculadas à sua
 * área, então a lista que o segundo agente escolhe não existe antes de o
 * primeiro responder: área e classe são uma cadeia, e `framing()` é o nome
 * dela. Cada um dos dois restringe a própria resposta com um `enum`, que o
 * provider impõe — nem uma área nem uma classe inventada é algo que o modelo
 * consiga emitir.
 *
 * A tutela de urgência é o terceiro elo da mesma cadeia, e pelo mesmo motivo:
 * ela precisa da classe. Uma possessória de força nova, um despejo, uma ação de
 * alimentos ou um mandado de segurança trazem liminar própria, com o seu
 * artigo, e é a classe que diz isso. O preço é explícito: a task mais longa do
 * bloco ganha uma inferência, e com ela a espera do preenchimento. Correr a tutela
 * em t=0 só com os fatos a tiraria da conta, mas mandaria o agente julgar a
 * urgência sem saber em que processo ela seria pedida.
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
 * etapa 4 de uma peça já salva pede a sugestão: uma inferência, não cinco. A
 * tutela também, e é a única das três que já tem rota própria — o "Consultar
 * IA" da etapa 1 chama `SuggestInjunctiveRelief` direto. O
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
 * 1. **O `try/catch` mora dentro de cada closure**, e não em volta do bloco.
 *    Em `sync` a exceção que escapasse de uma task sairia do `Concurrency::run`
 *    e levaria junto as que já tinham voltado, que é o oposto do que `stage()`
 *    promete; em `process` é ele que relata o stack trace de verdade, de dentro
 *    do filho. O que nenhuma closure alcança — o filho abatido pelo relógio,
 *    um erro fatal — é do `IsolatedProcessDriver`, que devolve o mesmo nulo
 *    naquela chave e deixa as outras voltarem.
 * 2. **As closures capturam só o relato**, uma string. Nada de `$this`, nada de
 *    model, nada de escrever em stdout: o filho responde em JSON por ele, e um
 *    byte a mais quebra a leitura no pai.
 * 3. **O retorno atravessa `serialize()`.** As duas metades do enquadramento
 *    carregam models de catálogo, o que funciona porque `PracticeArea` e
 *    `ProceduralClass` são tabelas globais, sem `account_id` — nenhuma das
 *    cinco etapas lê tenant, sessão ou usuário autenticado, e um processo
 *    filho não teria nenhum dos três. A sugestão de tutela só carrega
 *    escalares e um enum.
 *
 * O que se paga em troca da espera: as requisições agora saem em rajada, e uma
 * cota de provedor esgotada atinge as três ao mesmo tempo. Isso deixou de ser
 * hipótese — as cinco etapas apontam para o Gemini, então a rajada é de cota de
 * verdade. `CONCURRENCY_DRIVER` é a saída — em `sync` tudo volta a correr em
 * série, no mesmo processo, sem tocar numa linha daqui.
 *
 * ## A fronteira do escritório
 *
 * Com os cinco agentes na nuvem, **o relato do cliente sai do escritório aqui**,
 * e sai inteiro: quem estreita é `LegalCaseDossier::forResearch()`, que é da
 * pesquisa de teses e não desta rota. Voltar atrás é trocar o `#[Provider]` dos
 * cinco agentes pela linha comentada logo acima de cada um, mais um
 * `config:clear`.
 *
 * ## O endereçamento vem depois
 *
 * `SuggestCourtAddressing` é a única etapa que precisa de duas tasks do bloco
 * ao mesmo tempo: a classe, que diz em que justiça e em que grau a peça pode
 * tramitar, e o réu, cujo endereço é o foro da regra geral (CPC, art. 46) e
 * cujo nome revela o INSS, a Caixa ou o Município que mudam a justiça. Nenhuma
 * task tem as duas, então ela espera o bloco e roda no processo pai — o que a
 * dispensa das três regras acima: pode receber o `Customer`, que é da conta, e
 * o tenant está de pé.
 *
 * O preço é explícito e é o maior do preenchimento: a espera passa a ser a task mais
 * longa **mais** uma inferência, e mais duas no foro de um tribunal que usa
 * dois sistemas (SP, RN, RR, AP), porque aí o segundo agente escolhe entre
 * eles. Sem cliente no pedido a etapa não roda, e a chave volta nula — a tela
 * do preenchimento inteligente sempre manda o cliente; o nulo é para quem
 * chamar a rota só com os fatos.
 *
 * Ela passa por `stage()` como as outras: o endereçamento que falha não leva o
 * enquadramento junto, e a etapa 1 abre com o "Consultar IA" à mão.
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
 * Quatro das cinco. A área é a única obrigatória, por duas razões que se somam:
 * a classe depende dela, e é ela o que a tela foi buscar — sem área não há
 * enquadramento nenhum a devolver, e o preenchimento termina em falha. O preço do
 * paralelismo aparece exatamente aqui: quando a área cai, as duas extrações já
 * correram e são descartadas. É desperdício de cota, não de tempo de parede.
 * A triagem, que vem antes de todas, também não pode faltar — mas ela não é
 * uma das cinco: é a porta, e a falha dela custa só a ela mesma.
 *
 * Fora dela, uma etapa que cai não leva as outras junto. O relato que não
 * identifica o réu, a extração que não voltou, o agente que caiu, o portal do
 * STJ fora do ar: nada disso é motivo para descartar o que já custou minutos,
 * nem para dispensar as perguntas que ainda não foram feitas. `stage()` é onde
 * isso mora.
 *
 * ## Quem chama, e onde
 *
 * Ninguém pela rota. `POST /pecas/classificar` aponta para `RunAssistedFill`,
 * que grava uma `AssistedFill` e enfileira o job na fila `inference`; é o job
 * que chama este `handle()`, num worker, e grava a peça depois dele por
 * `CreateAssistedLegalCase`. A tela da peça nova espera travada e consulta a
 * linha até ela terminar.
 *
 * A dívida da fila foi paga aqui, e o motivo foi o gateway, não o PHP: atrás
 * do Cloudflare de produção a requisição era cortada com 504 muito antes de o
 * `AllowLongInference` ter opinião. A fila já tinha sido tentada uma vez e
 * saíra, porque sem um worker de pé a tela girava até o teto. A diferença hoje
 * é de operação, e está escrita no código: a fila é própria, o worker é
 * próprio, e uma rodada que ninguém pega aparece como falha em minutos — ver
 * `RunsOnInferenceQueue` e `TracksInferenceRun`.
 *
 * O `handle()` não mudou uma linha com isso, e é de propósito: ele é o que os
 * testes de agente exercitam, e "tudo o que um relato pode dar sem uma peça
 * gravada" segue sendo a descrição exata dele. Ele não toca no banco, não lê
 * sessão e lança em vez de responder — a recusa da triagem como
 * `InadmissibleFactsException`, a área que caiu como `RuntimeException` —, e é
 * o job que traduz cada uma num estado que a tela lê: `refused`, com a frase
 * debaixo do campo Fatos, e `failed`, com a frase do antigo 503.
 *
 * Um agente fora do ar é condição de operação, não defeito de código: daí a
 * frase que a tela consegue mostrar, o `report()` para que a causa real
 * continue chegando ao log, e o `RecordAgentFailure` que a deixa no registro de
 * execução dos agentes.
 *
 * ## O rascunho
 *
 * A gravação vem depois da inferência e nunca no lugar dela. Sem cliente no
 * pedido, sem classe escolhida — as duas colunas são NOT NULL — ou com a
 * gravação falhando, a `AssistedFill` termina sem peça e com o enquadramento em
 * `result`: a tela o leva até `/pecas/nova` pela entrega do `sessionStorage`,
 * que deixou de ser o caminho e virou a reserva.
 */
final class ClassifyLegalCase
{
    use AsAction;

    public function handle(string $facts, ?Customer $customer = null): LegalCaseClassification
    {
        $facts = trim($facts);

        if ($facts === '') {
            throw new RuntimeException('Não há fatos para classificar.');
        }

        // A porta, fora de `stage()` — ver "A triagem vem antes" no docblock.
        $screening = self::screen($facts);

        if (! $screening->admissible()) {
            throw new InadmissibleFactsException($screening);
        }

        // As cinco etapas básicas, em três tasks. O que cada closure pode
        // capturar e por que o `try/catch` está dentro delas está no docblock
        // da classe; o resumo é que, em `sync`, uma task que deixe escapar uma
        // exceção descarta os resultados das outras.
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

        // A área é a única obrigatória, e esta é a falha de sempre: a causa
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
            injunctiveRelief: $framing->injunctiveRelief,
            courtAddressing: $customer === null ? null : self::addressing($framing, $read['defendant'], $customer, $facts),
        );
    }

    /**
     * The sixth step, after the block because it reads two of its tasks — see
     * "O endereçamento vem depois" in the class docblock.
     *
     * A defendant extraction that failed is not a reason to skip it: the agent
     * reads the facts too, and an empty defendant only means the forum cannot
     * be copied from an address that was never read.
     */
    private static function addressing(
        LegalCaseFraming $framing,
        ?DefendantData $defendant,
        Customer $customer,
        string $facts,
    ): ?CourtAddressingSuggestionData {
        return self::stage(static fn (): CourtAddressingSuggestionData => SuggestCourtAddressing::run(
            $framing->area->practiceArea,
            $framing->class?->proceduralClass,
            $facts,
            $customer,
            $defendant ?? DefendantData::fromArray([]),
        ));
    }

    /**
     * A cadeia que não pode ser desfeita: a área, a classe dentro dela, e a
     * tutela que a classe permite.
     *
     * É a única das três tasks que roda mais de uma inferência, e roda as três
     * em série porque cada uma parte da anterior: `ProceduralClassCandidatesQuery`
     * parte do `PracticeArea`, e a tutela lê a classe para saber se ela traz
     * liminar própria.
     *
     * A área **não** passa por `stage()` aqui de propósito: ela é obrigatória, e
     * deixá-la estourar é o que faz a task inteira voltar nula e a resposta
     * virar falha. A classe passa, porque uma área errada torna a classe errada
     * por construção e esta etapa não tem como objetar — as duas justificativas
     * viajam separadas justamente para que o advogado veja de qual das duas
     * decisões desconfiar. Uma área sem classes de ajuizamento também devolve
     * nulo, por desenho, e o assistente abre a lista da área.
     *
     * A tutela passa por `stage()` pelo mesmo motivo que a classe, e roda mesmo
     * sem ela: a área e os fatos já bastam para uma resposta, só sem o
     * fundamento de um rito próprio.
     *
     * Estática porque é chamada de dentro de uma closure serializada, que não
     * tem instância para onde voltar.
     */
    private static function framing(string $facts): LegalCaseFraming
    {
        $area = ClassifyPracticeArea::run($facts);

        $class = self::stage(
            static fn (): ?ProceduralClassSelection => SelectProceduralClass::run(
                $area->practiceArea,
                $facts,
            ),
        );

        return new LegalCaseFraming(
            area: $area,
            class: $class,
            injunctiveRelief: self::stage(
                static fn (): InjunctiveReliefSuggestionData => SuggestInjunctiveRelief::run(
                    $area->practiceArea,
                    $class?->proceduralClass,
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
     * é só aí que ela protege alguma coisa: em `sync`, a exceção que escapa de
     * uma task sai do `Concurrency::run` e joga fora o array de resultados
     * inteiro. Um `try/catch` em volta dele transformaria uma etapa perdida em
     * todas elas. Em `process` o `IsolatedProcessDriver` já não descartaria as
     * outras, mas só o `stage()` relata de dentro do filho.
     *
     * O `report()` é o que separa isto de engolir o erro: a causa continua
     * chegando ao log — e, para as três tasks, ao log escrito de dentro do
     * processo filho, onde o stack trace ainda é o verdadeiro —, e o
     * `RecordAgentFailure` a deixa no registro de execução dos agentes, com o
     * status e a mensagem do provedor. O nulo diz à tela
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
            // Sem conta: as tasks do bloco não leem tenant, e um filho não tem
            // nenhum. O agente sai do stack trace da própria falha.
            RecordAgentFailure::run('assisted_fill', $e, self::class);

            return null;
        }
    }

    /**
     * The gate, written to the execution log when it falls.
     *
     * Ela fica fora de `stage()` — guarda que abre quando cai não é guarda —,
     * então a queda dela continua escapando para a falha do job. Mas o
     * `stage()` é o outro lugar que anota uma queda, e a porta nunca passa por
     * ele: sem isto, a cota do Gemini esgotada, que é o que derruba a primeira
     * inferência do preenchimento, não deixaria linha nenhuma.
     */
    private static function screen(string $facts): FactsScreeningData
    {
        try {
            return ScreenLegalCaseFacts::run($facts);
        } catch (Throwable $e) {
            RecordAgentFailure::run('assisted_fill', $e, ScreenLegalCaseFacts::class);

            throw $e;
        }
    }
}
