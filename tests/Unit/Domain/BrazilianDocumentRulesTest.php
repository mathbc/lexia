<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Shared\Rules\Cnpj;
use App\Domain\Shared\Rules\Cpf;
use App\Domain\Shared\Rules\OabNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BrazilianDocumentRulesTest extends TestCase
{
    private function fails(ValidationRule $rule, string $value): bool
    {
        $failed = false;

        $rule->validate('doc', $value, function () use (&$failed): object {
            $failed = true;

            return new class
            {
                public function translate(): void {}
            };
        });

        return $failed;
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function cnpjProvider(): array
    {
        return [
            'valid, masked' => ['11.222.333/0001-81', true],
            'valid, digits' => ['11222333000181', true],
            'valid, other' => ['19.131.243/0001-97', true],
            'wrong check digit' => ['11.222.333/0001-82', false],
            'all zeros' => ['00.000.000/0000-00', false],
            'repeated digits' => ['11.111.111/1111-11', false],
            'too short' => ['1122233300018', false],
            'empty' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('cnpjProvider')]
    public function it_validates_cnpj(string $value, bool $expected): void
    {
        $this->assertSame($expected, ! $this->fails(new Cnpj, $value));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function cpfProvider(): array
    {
        return [
            'valid, masked' => ['529.982.247-25', true],
            'valid, digits' => ['52998224725', true],
            'valid, other' => ['111.444.777-35', true],
            'wrong check digit' => ['529.982.247-26', false],
            'repeated digits' => ['111.111.111-11', false],
            'too short' => ['123', false],
        ];
    }

    #[Test]
    #[DataProvider('cpfProvider')]
    public function it_validates_cpf(string $value, bool $expected): void
    {
        $this->assertSame($expected, ! $this->fails(new Cpf, $value));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function oabProvider(): array
    {
        return [
            'six digits' => ['123456', true],
            'one digit' => ['1', true],
            'supplementary letter' => ['123456N', true],
            'lowercase letter' => ['123456n', true],
            'seven digits' => ['1234567', false],
            'letters only' => ['ABCDEF', false],
            'empty' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('oabProvider')]
    public function it_validates_oab_numbers(string $value, bool $expected): void
    {
        $this->assertSame($expected, ! $this->fails(new OabNumber, $value));
    }
}
