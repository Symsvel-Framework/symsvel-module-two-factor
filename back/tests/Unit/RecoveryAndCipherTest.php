<?php

declare(strict_types=1);

namespace SymSvel\Module\TwoFactor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymSvel\Module\TwoFactor\Service\RecoveryCodeService;
use SymSvel\Module\TwoFactor\Service\SecretCipher;

final class RecoveryAndCipherTest extends TestCase
{
    public function testEncryptedTotpSecretNeverStoredAsPlaintext(): void
    {
        $cipher = new SecretCipher('a-secret-only-for-tests');
        $encrypted = $cipher->encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        self::assertNotSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $encrypted);
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $cipher->decrypt($encrypted));
        $this->expectException(\RuntimeException::class);
        (new SecretCipher('a-different-secret'))->decrypt($encrypted);
    }

    public function testRecoveryCodesAreHashedAndConsumedExactlyOnce(): void
    {
        $codes = (new RecoveryCodeService())->generate(8);
        self::assertCount(8, $codes['plain']);
        foreach ($codes['plain'] as $i => $plain) {
            self::assertMatchesRegularExpression('/^[A-F0-9]{5}-[A-F0-9]{5}$/D', $plain);
            self::assertNotSame($plain, $codes['hashed'][$i]);
        }
        $service = new RecoveryCodeService();
        $remaining = $service->consume(strtolower($codes['plain'][0]), $codes['hashed']);
        self::assertCount(7, $remaining);
        self::assertNull($service->consume($codes['plain'][0], $remaining));
    }

    public function testEmptyKernelSecretIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        new SecretCipher('');
    }
}
