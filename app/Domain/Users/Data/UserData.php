<?php

declare(strict_types=1);

namespace App\Domain\Users\Data;

use App\Domain\Accounts\Enums\BrazilianState;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Enums\UserType;
use Carbon\CarbonImmutable;

/**
 * The editable attributes of a user.
 *
 * The role is nullable because two callers do not get to choose it: sign-up
 * always produces an AccountAdmin, and self-service profile edits must not
 * change it.
 */
final readonly class UserData
{
    public function __construct(
        public string $name,
        public string $email,
        public UserType $type,
        public ?UserRole $role = null,
        public ?string $oabNumber = null,
        public ?BrazilianState $oabState = null,
        public ?CarbonImmutable $birthDate = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: (string) $validated['name'],
            email: strtolower(trim((string) $validated['email'])),
            type: UserType::from((string) $validated['type']),
            role: isset($validated['role']) ? UserRole::from((string) $validated['role']) : null,
            oabNumber: self::optionalUpper($validated['oab_number'] ?? null),
            oabState: self::optionalState($validated['oab_state'] ?? null),
            birthDate: self::optionalDate($validated['birth_date'] ?? null),
        );
    }

    /**
     * Sign-up sends the owner's fields under an `owner_` prefix, to keep them
     * distinct from the account's own name and email in the same form.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function forOwner(array $validated): self
    {
        return new self(
            name: (string) $validated['owner_name'],
            email: strtolower(trim((string) $validated['owner_email'])),
            type: UserType::from((string) $validated['owner_type']),
            role: UserRole::AccountAdmin,
            oabNumber: self::optionalUpper($validated['oab_number'] ?? null),
            oabState: self::optionalState($validated['oab_state'] ?? null),
            birthDate: self::optionalDate($validated['owner_birth_date'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attributes = [
            'name' => $this->name,
            'email' => $this->email,
            'type' => $this->type,
            // Nulls are meaningful here: a lawyer promoted to the bench has
            // their OAB cleared, so these must not be filtered out.
            'oab_number' => $this->oabNumber,
            'oab_state' => $this->oabState,
            'birth_date' => $this->birthDate,
        ];

        // The role is the one attribute a caller may decline to set, so that
        // self-service profile edits cannot escalate it.
        if ($this->role instanceof UserRole) {
            $attributes['role'] = $this->role;
        }

        return $attributes;
    }

    private static function optionalUpper(mixed $value): ?string
    {
        return blank($value) ? null : strtoupper(trim((string) $value));
    }

    private static function optionalState(mixed $value): ?BrazilianState
    {
        return blank($value) ? null : BrazilianState::from((string) $value);
    }

    private static function optionalDate(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse((string) $value);
    }
}
