<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Domain\Shared\Concurrency\IsolatedProcessDriver;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The driver the forensic review and the smart fill run on, against real
 * child processes — the suite pins `sync`, which has no children, no pipes and
 * no clock, so nothing here would be exercised by it.
 *
 * Each child boots the framework in about a tenth of a second, which is what
 * lets the whole file run in a few seconds and live in the default suite.
 */
final class IsolatedProcessDriverTest extends TestCase
{
    #[Test]
    public function the_process_driver_is_this_one_with_the_configured_timeout(): void
    {
        $this->assertInstanceOf(IsolatedProcessDriver::class, Concurrency::driver('process'));
        $this->assertSame(840, config('concurrency.timeout'));
    }

    #[Test]
    public function every_result_crosses_back_under_its_own_key_in_the_order_asked(): void
    {
        $results = $this->driver()->run([
            'second' => static function (): array {
                usleep(300_000);

                return ['answer' => 42];
            },
            'first' => static fn (): string => 'done',
        ]);

        $this->assertSame(['second' => ['answer' => 42], 'first' => 'done'], $results);
    }

    /**
     * The case that reached the lawyer, reproduced. With the framework's pool
     * both halves timed out at the same instant: the fast one had finished its
     * work and sat blocked writing a result bigger than the pipe buffer, while
     * the parent waited on the slow one.
     */
    #[Test]
    public function a_task_past_its_clock_is_null_and_does_not_take_a_large_result_with_it(): void
    {
        Exceptions::fake();

        $results = $this->driver()->run([
            'slow' => static fn (): int => sleep(30),
            'fast' => static fn (): string => str_repeat('x', 300_000),
        ]);

        $this->assertNull($results['slow']);
        $this->assertSame(300_000, strlen($results['fast']));

        Exceptions::assertReported(static fn (RuntimeException $e): bool => str_contains($e->getMessage(), '[slow]')
            && $e->getPrevious() instanceof ProcessTimedOutException);
    }

    #[Test]
    public function a_child_that_dies_or_throws_is_null_and_the_others_still_land(): void
    {
        Exceptions::fake();

        $results = $this->driver()->run([
            'dies' => static function (): never {
                exit(3);
            },
            'throws' => static function (): never {
                throw new RuntimeException('agente fora do ar');
            },
            'lands' => static fn (): string => 'teses',
        ]);

        $this->assertSame(['dies' => null, 'throws' => null, 'lands' => 'teses'], $results);

        Exceptions::assertReported(static fn (RuntimeException $e): bool => str_contains($e->getMessage(), '[dies]'));
        Exceptions::assertReported(static fn (RuntimeException $e): bool => str_contains($e->getMessage(), '[throws]')
            && str_contains((string) $e->getPrevious()?->getMessage(), 'agente fora do ar'));
    }

    /** Two seconds: long enough for a child to boot and answer, short enough to wait for. */
    private function driver(): IsolatedProcessDriver
    {
        return new IsolatedProcessDriver(app(ProcessFactory::class), timeout: 2);
    }
}
