<?php

declare(strict_types=1);

namespace SymSvel\Module\TwoFactor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use SymSvel\Module\TwoFactor\TwoFactorBundle;
use SymSvel\Module\TwoFactor\Service\TwoFactorManager;
use SymSvel\Module\TwoFactor\Service\TwoFactorSettingDefinitionProvider;

final class TwoFactorBundleTest extends TestCase
{
    public function testProviderIsTaggedAndOnlyAppPolicyIsConfigurable(): void
    {
        $builder = new ContainerBuilder();
        $configurator = (new \ReflectionClass(ContainerConfigurator::class))->newInstanceWithoutConstructor();
        (new TwoFactorBundle())->loadExtension([], $configurator, $builder);

        self::assertTrue($builder->getDefinition(TwoFactorManager::class)->hasTag('symsvel.auth.second_factor'));
        self::assertTrue($builder->getDefinition(TwoFactorSettingDefinitionProvider::class)
            ->hasTag('symsvel.settings.definition_provider'));
        $definitions = iterator_to_array((new TwoFactorSettingDefinitionProvider())->definitions());
        self::assertSame(['app_required'], array_map(static fn ($d) => $d->key, $definitions));
        self::assertSame(['boolean'], array_map(static fn ($d) => $d->type, $definitions));
    }
}
