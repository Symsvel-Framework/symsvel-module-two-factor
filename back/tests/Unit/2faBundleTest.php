<?php

declare(strict_types=1);

namespace SymSvel\Module\2fa\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymSvel\Module\2fa\2faBundle;

final class 2faBundleTest extends TestCase
{
    public function testBundleCanBeInstantiated(): void
    {
        self::assertInstanceOf(2faBundle::class, new 2faBundle());
    }
}
