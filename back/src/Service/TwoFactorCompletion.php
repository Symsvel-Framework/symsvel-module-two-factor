<?php

declare(strict_types=1);

namespace SymSvel\Module\TwoFactor\Service;

use SymSvel\Module\Auth\Token\TokenPair;

/** Recovery codes are returned once, only on first successful enrollment. */
final readonly class TwoFactorCompletion
{
    /** @param list<string> $recoveryCodes */
    public function __construct(public TokenPair $tokens, public array $recoveryCodes = [])
    {
    }
}
