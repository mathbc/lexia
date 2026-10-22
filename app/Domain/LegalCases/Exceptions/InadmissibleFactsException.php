<?php

declare(strict_types=1);

namespace App\Domain\LegalCases\Exceptions;

use App\Domain\LegalCases\Data\FactsScreeningData;
use RuntimeException;

/**
 * The narrative was screened and refused, so nothing past the screening ran.
 *
 * It is not a failure, and that is why it has a class of its own: every other
 * exception out of ClassifyLegalCase is an agent or a provider that broke, and
 * becomes the 503. This one is an answer about the input, and becomes a 422 on
 * the facts — `handle()` does not know HTTP, so it says what happened and the
 * controller says how.
 */
final class InadmissibleFactsException extends RuntimeException
{
    public function __construct(public readonly FactsScreeningData $screening)
    {
        parent::__construct($screening->message());
    }
}
