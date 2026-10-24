<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Ai\Agents\LegalQuestionFormulationAgent;
use App\Ai\Agents\LegalThesisResearchAgent;
use App\Ai\Exceptions\AgentResponseException;
use App\Domain\AgentsExecutionLogs\Data\AgentFailureData;
use App\Domain\LegalCases\Actions\ResearchLegalCaseTheses;
use Closure;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * What the execution log keeps of a failure, read out of the chain the SDK
 * builds around it.
 *
 * The first case goes through the SDK for real — a real agent, its real
 * gateway, an HTTP client faked to answer as the Gemini answers when it is
 * overloaded —, because the wrapping is the SDK's and is the thing that would
 * change under us: the 503 and the provider's sentence are a `previous` below
 * the exception that reaches the catch.
 */
final class AgentFailureDataTest extends TestCase
{
    #[Test]
    public function a_provider_error_is_read_through_the_sdk_wrapper_and_names_the_agent_on_the_stack(): void
    {
        Http::fake(['*' => Http::response(
            ['error' => ['code' => 503, 'message' => 'The model is overloaded. Please try again later.', 'status' => 'UNAVAILABLE']],
            503,
        )]);

        $data = AgentFailureData::from(
            $this->failureOf(static fn () => (new LegalQuestionFormulationAgent('## O enquadramento'))->prompt('O vizinho derrubou o muro.')),
            ResearchLegalCaseTheses::class,
        );

        $this->assertSame('LegalQuestionFormulationAgent', $data->agent);
        $this->assertSame(503, $data->httpStatus);
        $this->assertSame('The model is overloaded. Please try again later.', $data->message);
        $this->assertStringContainsString('ProviderOverloadedException', $data->error);
    }

    /**
     * O timeout: ninguém respondeu, então não há status nem mensagem do outro
     * lado — e nenhum agente no stack de uma exceção montada aqui, então vale
     * quem a Action indicou.
     */
    #[Test]
    public function a_timeout_has_no_status_and_falls_back_to_the_stage(): void
    {
        $data = AgentFailureData::from(
            ProviderConnectionException::forProvider('gemini', 0, new ConnectionException('cURL error 28: Operation timed out after 360001 milliseconds')),
            ResearchLegalCaseTheses::class,
        );

        $this->assertSame('ResearchLegalCaseTheses', $data->agent);
        $this->assertNull($data->httpStatus);
        $this->assertNull($data->message);
        $this->assertStringContainsString('Could not connect to AI provider [gemini].', $data->error);
        $this->assertStringContainsString('cURL error 28', $data->error);
    }

    /**
     * A resposta que a Action recusou: o agente já saiu do stack, e é a
     * exceção que o nomeia, junto do que o modelo disse.
     */
    #[Test]
    public function a_refused_answer_names_its_agent_and_what_the_model_said(): void
    {
        $data = AgentFailureData::from(
            new AgentResponseException(LegalThesisResearchAgent::class, 'O agente de pesquisa não devolveu nada.', 'finishReason: RECITATION'),
            ResearchLegalCaseTheses::class,
        );

        $this->assertSame('LegalThesisResearchAgent', $data->agent);
        $this->assertNull($data->httpStatus);
        $this->assertSame('finishReason: RECITATION', $data->message);
    }

    /**
     * O gateway que responde com uma página HTML: não há `error.message`, e o
     * corpo cru vai cortado — a página inteira não cabe em linha nenhuma.
     */
    #[Test]
    public function a_body_without_an_error_message_is_kept_raw_and_cut(): void
    {
        $page = '<html><body>'.str_repeat('Bad gateway. ', 400).'</body></html>';

        $data = AgentFailureData::from(
            new RequestException(new Response(new Psr7Response(502, ['Content-Type' => 'text/html'], $page))),
            ResearchLegalCaseTheses::class,
        );

        $this->assertSame(502, $data->httpStatus);
        $this->assertStringStartsWith('<html><body>Bad gateway.', (string) $data->message);
        $this->assertLessThanOrEqual(2003, mb_strlen((string) $data->message));
    }

    private function failureOf(Closure $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $e) {
            return $e;
        }

        $this->fail('A chamada deveria ter falhado.');
    }
}
