<?php

declare(strict_types=1);

namespace App\Domain\Requirements\Data;

/**
 * One request a pleading makes: the sentence, and the money it claims.
 *
 * `id` is what the browser sent, and is a hint rather than a key. The form
 * mints its own uuids with `crypto.randomUUID()` for the rows it has just
 * opened, and those are indistinguishable from the server's — both are v4 — so
 * nothing here may treat the id as proof of anything. It is carried only so the
 * saving Action can look it up among the rows the pleading actually owns, and
 * `toArray()` deliberately leaves it out: the key of a new row is minted by
 * HasUuids, never pasted from the payload.
 *
 * `amount` arrives masked from the screen ("50.000,00") and leaves as a decimal
 * string ("50000.00"), the shape the column and the cast both speak. It goes
 * through the digits and back out as integer cents, never through a float —
 * this is money that ends up in a judgment.
 */
final readonly class RequirementData
{
    public function __construct(
        public ?string $id,
        public string $description,
        public ?string $amount,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: self::nullify($row['id'] ?? null),
            description: (string) self::nullify($row['description'] ?? null),
            amount: self::amountFrom($row['amount'] ?? null),
        );
    }

    /**
     * What gets written to the row — the id is not part of it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'amount' => $this->amount,
        ];
    }

    /**
     * A request with no text is not a request.
     *
     * The mirror of `writtenRequirements()` on the screen: the empty line is
     * the field that was just opened, and it must neither become a row nor
     * block the save.
     */
    public function isWritten(): bool
    {
        return $this->description !== '';
    }

    private static function amountFrom(mixed $masked): ?string
    {
        $digits = preg_replace('/\D/', '', (string) self::nullify($masked));

        if ($digits === '' || $digits === null) {
            return null;
        }

        $cents = (int) $digits;

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private static function nullify(mixed $value): ?string
    {
        $trimmed = trim(is_string($value) ? $value : '');

        return $trimmed === '' ? null : $trimmed;
    }
}
