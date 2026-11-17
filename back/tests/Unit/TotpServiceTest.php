<?php

declare(strict_types=1);

namespace SymSvel\Module\TwoFactor\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymSvel\Module\TwoFactor\Service\TotpService;

final class TotpServiceTest extends TestCase
{
    public static function rfcVectors(): iterable
    {
        yield [59, '287082'];
        yield [1111111109, '081804'];
        yield [1111111111, '050471'];
    }

    #[DataProvider('rfcVectors')]
    public function testRfc6238Sha1SixDigitVectors(int $timestamp, string $expected): void
    {
        $service = new TotpService();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        self::assertTrue($service->verify($secret, $expected, $timestamp));
        self::assertSame(intdiv($timestamp, 30), $service->matchingCounter($secret, $expected, $timestamp, 0));
    }

    public function testMalformedCodesAndSecretsAreRejected(): void
    {
        $service = new TotpService();
        self::assertFalse($service->verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '12A456', 59));
        self::assertNull($service->matchingCounter('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '123456', -1));
        $this->expectException(\InvalidArgumentException::class);
        $service->verify('!!invalid!!', '123456', 59);
    }

    public function testGeneratesValidSecretAndProvisioningUri(): void
    {
        $service = new TotpService();
        $first = $service->generateSecret();
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/D', $first);
        self::assertNotSame($first, $service->generateSecret());
        self::assertStringContainsString('issuer=SymSvel', $service->provisioningUri($first, 'bo-123'));
    }
}
