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
 * `amount` leaves as a decimal string ("50000.00"), the shape the column and
 * the cast both speak. It never travels through a float — this is money that
 * ends up in a judgment.
 *
 * It *arrives*, however, in two dialects, and that is why there are two named
 * constructors. `fromArray()` reads the screen, where the field is a mask and
 * "50.000,00" means what the lawyer typed into a money input. `fromAgent()`
 * reads a model, which was asked for a plain decimal and may still answer in
 * Brazilian notation. Merging the two would mean guessing which dialect a
 * string is in, and guessing wrong on money is an order of magnitude on a
 * pleading.
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
            amount: self::maskedAmount($row['amount'] ?? null),
        );
    }

    /**
     * The same value, read out of the requirement extraction agent's answer.
     *
     * No `id`, and not because the agent forgot one: a suggestion is not a row
     * yet. The browser mints the key when it draws the field, and the saving
     * Action mints the real one if the lawyer keeps the request.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromAgent(array $row): self
    {
        return new self(
            id: null,
            description: (string) self::nullify($row['description'] ?? null),
            amount: self::writtenAmount($row['amount'] ?? null),
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
     * The same request with its amount dropped, unless that amount is one of
     * the figures given.
     *
     * The rule lives in `RequirementListData::fromAgent()`, which knows what
     * the narrative said; what belongs here is only the knowledge that dropping
     * an amount leaves the sentence alone. The sentence usually spells the
     * figure out too, and it should: it is the text of the pleading, and the
     * lawyer reading a blank money field next to "no importe de R$ 25.200,00"
     * knows exactly what to type.
     *
     * @param  list<string>  $figures  decimal strings, as `amount` is written
     */
    public function withoutAmountUnless(array $figures): self
    {
        if ($this->amount === null || in_array($this->amount, $figures, true)) {
            return $this;
        }

        return new self($this->id, $this->description, null);
    }

    /**
     * A figure as the `amount` column writes it, or null if it is not one.
     *
     * Public for the narrative guard: the only way to compare what a model
     * answered with what the client wrote is to put both through the same
     * parser.
     */
    public static function amountWrittenAs(mixed $written): ?string
    {
        return self::writtenAmount($written);
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

    /**
     * The screen's dialect: every digit is a cent, and the mask is decoration.
     *
     * "50.000,00" and "50000,00" are the same money because the field builds
     * the string from the right — see `formatCurrency` in `@/lib/format`.
     */
    private static function maskedAmount(mixed $masked): ?string
    {
        $digits = preg_replace('/\D/', '', (string) self::nullify($masked));

        if ($digits === '' || $digits === null) {
            return null;
        }

        $cents = (int) $digits;

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * The agent's dialect: a number somebody wrote, in whichever notation.
     *
     * The instructions ask for "25200.00" and that is what usually arrives, but
     * a model trained on Brazilian text writes "25.200,00" often enough that
     * reading only the digits would claim R$ 252,00 — or R$ 2.520.000,00 — with
     * no sign of anything having gone wrong. So the separator is found instead
     * of assumed, and a value of zero is treated as no value: a request for
     * nothing is a request with no cifra.
     */
    private static function writtenAmount(mixed $written): ?string
    {
        $raw = preg_replace('/[^\d.,]/', '', (string) self::nullify($written));

        if ($raw === '' || $raw === null) {
            return null;
        }

        [$units, $cents] = self::splitAmount($raw);

        return $units === '0' && $cents === '00' ? null : $units.'.'.$cents;
    }

    /**
     * The whole reais and the cents, told apart by where the separator falls.
     *
     * A "." or a "," followed by one or two digits **at the end** is a decimal
     * comma, because Brazilian thousands always group in threes: "1.500" is
     * fifteen hundred reais and "1.50" is one and a half. Everything else is a
     * separator to discard.
     *
     * @return array{string, string}
     */
    private static function splitAmount(string $raw): array
    {
        if (preg_match('/^(.*)[.,](\d{1,2})$/', $raw, $matches) === 1) {
            return [self::units($matches[1]), str_pad($matches[2], 2, '0')];
        }

        return [self::units($raw), '00'];
    }

    private static function units(string $value): string
    {
        $digits = ltrim((string) preg_replace('/\D/', '', $value), '0');

        return $digits === '' ? '0' : $digits;
    }

    private static function nullify(mixed $value): ?string
    {
        $trimmed = trim(is_string($value) ? $value : '');

        return $trimmed === '' ? null : $trimmed;
    }
}
