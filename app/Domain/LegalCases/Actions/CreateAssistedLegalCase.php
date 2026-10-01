<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Actions;

use App\Domain\Customers\Models\Customer;
use App\Domain\LegalCases\Data\LegalCaseBasicsData;
use App\Domain\LegalCases\Data\LegalCaseClassification;
use App\Domain\LegalCases\Enums\LegalCaseStep;
use App\Domain\LegalCases\Models\LegalCase;
use App\Domain\ProceduralClasses\Models\ProceduralClass;
use App\Domain\Requirements\Data\RequirementData;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Opens a pleading out of what the smart fill read, as a draft standing on the
 * first step.
 *
 * O preenchimento inteligente custa minutos de inferência, e até aqui nada
 * dele era gravado: o resultado atravessava o `sessionStorage` até `/pecas/nova`
 * e só virava linha no "Continuar" da etapa 1 — uma aba fechada antes disso
 * jogava a espera inteira fora. Esta Action é o que `ClassifyLegalCase` chama
 * logo depois do `handle()`, e a peça passa a existir no instante em que o
 * advogado a vê.
 *
 * ## O que é gravado
 *
 * As três etapas que o relato preenche, e com as mesmas regras com que a tela
 * as preenchia a partir da entrega:
 *
 * 1. **A etapa 1** — cliente, área, classe, relato e as duas sugestões. A
 *    tutela só vem marcada, e com o texto composto, quando a IA a recomenda; o
 *    envelope é gravado nos dois casos, porque "a IA não viu urgência" também é
 *    o registro de uma resposta. O endereçamento e o sistema saem da sugestão,
 *    com o envelope ao lado — é ele que mantém o selo "Sugestão da IA".
 * 2. **O réu**, como o agente o descreveu. As regras de
 *    `UpdateLegalCaseDefendant` não rodam aqui: um valor que a etapa recusaria
 *    (um e-mail fora de forma) aparece como erro dela quando o advogado
 *    continuar, que é exatamente o que acontecia com a sugestão não salva.
 * 3. **Os pedidos**, na ordem em que o agente os escreveu, com a cifra que já
 *    passou pela guarda de `RequirementListData::fromAgent()`.
 *
 * O que falhou no enquadramento fica de fora sem impedir o resto: réu nulo é
 * etapa 2 em branco, pedidos nulos são lista vazia, tutela nula é a caixa
 * desmarcada — as mesmas telas que a entrega já abria.
 *
 * ## A marca d'água fica na etapa 1
 *
 * `current_step` é `basics`, e não a etapa seguinte como em `CreateLegalCase`.
 * Lá foi o advogado quem salvou a etapa; aqui quem preencheu foi a IA, e nada
 * foi conferido ainda. O réu e os pedidos já são linhas, mas a trilha os mantém
 * atrás do "Continuar" da etapa 1, e cada "Continuar" regrava a etapa que o
 * advogado acabou de ler — o fluxo de revisão é o mesmo de antes, só que agora
 * sobrevive ao fechamento da aba. A listagem diz "Dados básicos e fatos", que é
 * onde a peça de fato está.
 *
 * `is_draft` fica com o default da coluna, pelo mesmo motivo que em
 * `CreateLegalCase`: rascunho é o que toda peça é até `FinalizeLegalCase`.
 *
 * ## Uma transação, e uma recusa
 *
 * A peça nasce inteira ou não nasce. Uma metade gravada — a etapa 1 sem os
 * pedidos — seria uma peça que parece completa e perdeu o que a IA leu; inteira
 * ou ausente, quem chama sabe o que tem. E quem chama sempre tem o enquadramento
 * na mão: uma gravação que falha devolve o advogado ao caminho antigo, pela
 * entrega, sem custar a inferência.
 *
 * A classe é a única ausência que impede a gravação: `procedural_class_id` é
 * NOT NULL, e uma classe que a seleção não escolheu é o advogado escolhendo na
 * lista da área. Quem chama confere antes; a exceção é a guarda.
 */
final class CreateAssistedLegalCase
{
    use AsAction;

    public function handle(
        string $accountId,
        string $userId,
        Customer $customer,
        string $facts,
        LegalCaseClassification $classification,
    ): LegalCase {
        $class = $classification->proceduralClass;

        if (! $class instanceof ProceduralClass) {
            throw new RuntimeException('Uma peça sem classe processual não pode ser gravada.');
        }

        return DB::transaction(static function () use ($accountId, $userId, $customer, $facts, $classification, $class): LegalCase {
            $legalCase = new LegalCase([
                ...self::basics($customer, $class, $facts, $classification)->toArray(),
                ...($classification->defendant?->toArray() ?? []),
            ]);

            // Explícitos, como em CreateLegalCase: a conta é argumento deste
            // caso de uso, e o usuário é quem abriu a peça — o painel conta por
            // ele.
            $legalCase->account_id = $accountId;
            $legalCase->user_id = $userId;
            $legalCase->current_step = LegalCaseStep::Basics;

            $legalCase->save();

            // A conta vem da peça, e não do ator — a mesma regra do `blank()`
            // de SaveLegalCaseRequirements. A ordem do array é a da numeração.
            $legalCase->requirements()->createMany(array_map(
                static fn (RequirementData $requirement): array => [
                    'account_id' => $legalCase->account_id,
                    ...$requirement->toArray(),
                ],
                $classification->requirements->requirements ?? [],
            ));

            return $legalCase;
        });
    }

    /**
     * A etapa 1 como a tela a abria a partir da entrega — ver `basics` em
     * `pages/legal-cases/form.tsx`, que continua sendo o caminho de reserva.
     */
    private static function basics(
        Customer $customer,
        ProceduralClass $class,
        string $facts,
        LegalCaseClassification $classification,
    ): LegalCaseBasicsData {
        $relief = $classification->injunctiveRelief;
        $addressing = $classification->courtAddressing;
        $recommended = $relief?->recommended === true;

        return new LegalCaseBasicsData(
            customerId: $customer->id,
            practiceAreaId: $classification->practiceArea->id,
            proceduralClassId: $class->id,
            // O sistema do envelope saiu do mapa, então o id é de uma linha que
            // existe; a coluna é a chave, e o envelope é só a proveniência.
            judicialSystemId: $addressing?->judicialSystem['id'] ?? null,
            courtAddressing: $addressing?->courtAddressing,
            facts: trim($facts),
            injunctiveRelief: $recommended,
            injunctiveReliefDescription: $recommended ? $relief->description : null,
            injunctiveReliefSuggestion: $relief,
            courtAddressingSuggestion: $addressing,
        );
    }
}
