<?php

declare(strict_types=1);

namespace SymSvel\Module\TwoFactor;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use SymSvel\Module\TwoFactor\Service\TwoFactorManager;
use SymSvel\Module\TwoFactor\Service\TwoFactorSettingDefinitionProvider;
use SymSvel\Module\TwoFactor\Service\TotpService;
use SymSvel\Module\TwoFactor\Service\SecretCipher;
use SymSvel\Module\TwoFactor\Service\RecoveryCodeService;

/**
 * Optional second factor: Auth issues the tokens, this module verifies proofs.
 */
final class TwoFactorBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->register(TwoFactorSettingDefinitionProvider::class, TwoFactorSettingDefinitionProvider::class)
            ->addTag('symsvel.settings.definition_provider');

        $builder->register(TotpService::class, TotpService::class);
        $builder->register(RecoveryCodeService::class, RecoveryCodeService::class);
        $builder->register(SecretCipher::class, SecretCipher::class)
            ->setArgument('$kernelSecret', '%kernel.secret%');

        $builder->register(TwoFactorManager::class, TwoFactorManager::class)
            ->setAutowired(true)
            ->setArgument('$connection', new Reference('doctrine.dbal.default_connection'))
            ->addTag('symsvel.auth.second_factor');
    }
}
