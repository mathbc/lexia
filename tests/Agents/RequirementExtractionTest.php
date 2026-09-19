<?php

declare(strict_types=1);

namespace Tests\Agents;

use App\Domain\LegalCases\Actions\ExtractLegalCaseRequirements;
use App\Domain\Requirements\Data\RequirementData;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The requirement agent against the real text provider, no fakes.
 *
 * No RefreshDatabase, like the defendant test next door and unlike the
 * classification one: this agent reads nothing from the catalogue and writes
 * nothing back. What it takes is a narrative and what it gives back is a
 * `RequirementListData`.
 *
 * The assertions are split by what kind of thing is being checked, because this
 * agent does two things at once and only one of them may be pinned:
 *
 * - **What it reads** is held firmly. The narrative writes R$ 25.200,00 once,
 *   as the money the client wants back, and that figure has to come back
 *   untouched. It also writes a monthly expense, which must *not* be multiplied
 *   by the months to produce a total nobody claimed — the "nunca some" rule,
 *   with an arithmetic trap laid for it.
 * - **What it writes** is held loosely. Which sentences a model composes, and
 *   how many, is a judgement in the same family as choosing a procedural class:
 *   pinning the wording would turn ordinary variation into a red build.
 *
 * The exclusion of the boilerplate is asserted, and it is the assertion most
 * likely to go red on a model swap. When it does, the prompt is what to look
 * at, not the case: the rite's requests — citação, provas, honorários — are one
 * click away on the screen (`SUGGESTED_REQUIREMENTS` in
 * `@/lib/requirements`), and an agent that writes them too hands the lawyer two
 * copies of each to reconcile by hand.
 *
 * Grouped out of the default run for the same reason as its siblings: it needs
 * Ollama up and spends seconds on inference.
 *
 *   composer test:agents
 */
#[Group('agents')]
final class RequirementExtractionTest extends TestCase
{
    #[Test]
    public function it_reads_what_the_client_is_asking_for_out_of_a_narrative(): void
    {
        $facts = <<<'TXT'
        Doutor, em 03/02/2026 contratei a Móveis Bertoldi para fabricar e instalar os armários planejados
        da minha cozinha. O contrato foi de R$ 25.200,00, que paguei à vista no ato da assinatura, com
        entrega prometida em 45 dias.
        Passados sete meses, nada foi instalado. Liguei dezenas de vezes, sempre remarcavam e nunca
        cumpriam. Em julho mandei uma notificação extrajudicial e não obtive resposta nenhuma.
        Desmontei a cozinha antiga contando com a instalação, e desde então como fora de casa — gasto
        uns R$ 600,00 por mês com isso. Minha mulher está passando muito mal com a situação, brigamos
        toda semana por causa disso.
        Quero meu dinheiro de volta, quero me ver livre desse contrato e quero ser indenizado pelo
        transtorno que isso causou.
        TXT;

        // Os três relatos abaixo foram escritos para as três respostas que não
        // são a deste: trocar o `$facts` acima por um deles é o jeito de ver,
        // no dump, se o agente respeita cada regra. As asserções desta função
        // descrevem o relato completo acima — ao trocar, o que se lê é o dump.
        //
        // 1. Proporção não é cifra: o pedido sai com o percentual escrito na
        //    frase e `amount` nulo, porque 30% do salário mínimo é uma conta
        //    que muda de valor todo ano e que o agente não deve fazer.
        //
        // $facts = <<<'TXT'
        // Me separei do pai do meu filho no ano passado. O menino tem 6 anos, mora comigo e eu banco
        // tudo sozinha. Ele nunca mais depositou nada, faz uns oito meses. Trabalha registrado numa
        // transportadora e ganha bem. Queria pelo menos 30% do salário dele por mês, e também que ele
        // arque com a metade do plano de saúde e do material escolar. Ele vê o menino quando quer,
        // sem combinar antes, e isso atrapalha a rotina da criança.
        // TXT;
        //
        // 2. Nada é pedido: a lista vazia é a resposta certa, e inventar um
        //    pedido aqui é o pior erro que este agente pode cometer, porque ele
        //    entraria na peça com aparência de ter sido escolhido.
        //
        // $facts = <<<'TXT'
        // Doutor, acabei de passar por um susto aqui na loja porque flagrei uma pessoa furtando peças
        // de roupa no meio do expediente, escondendo tudo dentro da bolsa enquanto disfarçava nas
        // araras. Preciso saber exatamente como agir e quais providências tomar agora com as
        // filmagens das câmeras de segurança, para não ter nenhum problema jurídico.
        // TXT;
        //
        // 3. Urgência no relato: a tutela deveria aparecer, e antes dos pedidos de
        //    mérito, porque a ordem da lista é a ordem da numeração na peça.
        //    É a resposta menos confiável do `qwen2.5:7b` de hoje — ele acerta
        //    a baixa da negativação e o débito inexistente, e escreve a tutela
        //    uma vez a cada tantas. Fica aqui como o caso a reconferir no dia
        //    em que o `OLLAMA_TEXT_MODEL` mudar, e é por isso que nenhuma
        //    asserção desta função depende dela.
        //
        // $facts = <<<'TXT'
        // Meu nome foi negativado pela operadora por uma linha que eu nunca contratei, no valor de
        // R$ 1.340,00. Já liguei quatro vezes e mandei dois e-mails com cópia do meu RG, e eles só
        // dizem que vão apurar. Na semana que vem tenho a assinatura do financiamento do apartamento
        // e o banco já avisou que com o nome sujo não sai. Perco o imóvel e o sinal de R$ 15.000,00
        // que já paguei se isso não for resolvido em dias.
        // TXT;

        $requirements = ExtractLegalCaseRequirements::run($facts);
        $payload = $requirements->toArray();

        // The point of this test is to look at the answer, and PHPUnit swallows
        // stdout — STDERR is what actually reaches the terminal.
        fwrite(STDERR, PHP_EOL.json_encode([
            'fatos' => $facts,
            'resposta' => $payload,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL);

        // Este relato pede três coisas em voz alta, então a lista vazia aqui é
        // falha — ela é resposta legítima para o relato 2 comentado acima, e
        // não para este.
        $this->assertNotEmpty($requirements->requirements);

        // O payload é o contrato: a frase que vai para a peça e o valor que a
        // tela soma, sem o id — que ainda não existe. Uma chave renomeada aqui
        // deixa a sugestão cair fora da etapa em vez de estourar.
        $this->assertSame(['description', 'amount'], array_keys($payload[0]));

        foreach ($requirements->requirements as $requirement) {
            $this->assertNotSame('', trim($requirement->description));

            // A numeração é da tela, que numera pela posição. Um "1." colado na
            // frase viraria "1. 1. A condenação…" no campo.
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*(\d+[.)]|[-*•])/u',
                $requirement->description,
            );
        }

        $amounts = array_column($payload, 'amount');

        // Copiado, não decidido: a única cifra do relato é o que se pagou, e é
        // ela que volta. Um valor diferente aqui é dinheiro inventado numa
        // petição.
        $this->assertContains('25200.00', $amounts);

        // A armadilha aritmética. R$ 600,00 por mês durante sete meses não é um
        // pedido de R$ 4.200,00: ninguém pediu esse total, e somar é
        // exatamente o que as instruções proíbem.
        $this->assertNotContains('4200.00', $amounts);

        // Nenhum valor veio de fora do relato. Esta é a asserção que vale por
        // todas as outras sobre dinheiro, e ela é firme porque não depende do
        // modelo: `RequirementListData::fromAgent()` recusa a cifra que os
        // fatos não escrevem, e é lá que o motivo está escrito.
        //
        // O que ela não afirma é que R$ 600,00 não aparece. O relato o escreve,
        // então ele está autorizado, e se o agente o tomar como o valor de um
        // pedido em vez de uma despesa mensal, isso é juízo — visível na frase
        // ao lado, num campo que o advogado edita.
        foreach (array_filter($amounts) as $amount) {
            $this->assertContains($amount, ['25200.00', '600.00']);
        }

        // A regra que separa este agente da fila de pedidos frequentes da tela.
        // Ela é do rito, e não deste caso; a gratuidade também não entra,
        // porque o relato não diz nada sobre não poder pagar as custas.
        $written = mb_strtolower(implode(' ', array_map(
            static fn (RequirementData $requirement): string => $requirement->description,
            $requirements->requirements,
        )));

        foreach (['citaç', 'honorári', 'sucumbên', 'gratuidade'] as $boilerplate) {
            $this->assertStringNotContainsString($boilerplate, $written);
        }

        // Fidelidade, e não redação: o relato diz que o Autor passou a comer
        // fora, e nenhuma versão deste pedido pode falar em aluguel. É o erro
        // que mais custou ajuste no prompt, justamente porque a frase trocada
        // se lê tão bem quanto a certa — nada a denuncia depois.
        $this->assertStringNotContainsString('aluguel', $written);
    }
}
