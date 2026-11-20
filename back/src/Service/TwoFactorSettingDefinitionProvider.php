<?php

declare(strict_types=1);

namespace SymSvel\Module\TwoFactor\Service;

use SymSvel\Module\Settings\Service\SettingDefinition;
use SymSvel\Module\Settings\Service\SettingDefinitionProviderInterface;

/** BO 2FA is mandatory by design; only App policy is configurable. */
final class TwoFactorSettingDefinitionProvider implements SettingDefinitionProviderInterface
{
    public function definitions(): iterable
    {
        yield new SettingDefinition('app_required', '2FA obligatoire pour App',
            group: 'security', type: 'boolean',
            description: 'Exige un code TOTP ou de secours avant toute nouvelle session utilisateur.');
    }
}
