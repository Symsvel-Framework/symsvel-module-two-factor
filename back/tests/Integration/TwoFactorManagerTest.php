<?php

declare(strict_types=1);

namespace SymSvel\Module\TwoFactor\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use SymSvel\Module\Auth\Identity\PrincipalStatus;
use SymSvel\Module\Auth\Token\DbalTokenStore;
use SymSvel\Module\Auth\Token\TokenFactory;
use SymSvel\Module\Auth\Token\TokenIssuer;
use SymSvel\Module\Auth\Token\TokenSubject;
use SymSvel\Module\Settings\Entity\Setting;
use SymSvel\Module\Settings\Repository\SettingRepository;
use SymSvel\Module\Settings\Service\SettingKeyQualifier;
use SymSvel\Module\Settings\Service\SettingManager;
use SymSvel\Module\Settings\Service\SettingRegistry;
use SymSvel\Module\Settings\Service\SettingSecretCipher;
use SymSvel\Module\TwoFactor\Service\RecoveryCodeService;
use SymSvel\Module\TwoFactor\Service\SecretCipher;
use SymSvel\Module\TwoFactor\Service\TotpService;
use SymSvel\Module\TwoFactor\Service\TwoFactorManager;
use SymSvel\Module\TwoFactor\Service\TwoFactorSettingDefinitionProvider;

/** Real DBAL + real Auth token issuer: no mock shortcuts around transaction semantics. */
final class TwoFactorManagerTest extends TestCase
{
    private function services(): array
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite required for 2FA integration.');
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE symsvel_2fa_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, context VARCHAR(8) NOT NULL, principal_id INTEGER NOT NULL, secret_ciphertext TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 0, enabled_at TEXT, last_counter BIGINT, recovery_hashes TEXT NOT NULL, UNIQUE(context, principal_id))',
            'CREATE TABLE symsvel_2fa_challenges (id INTEGER PRIMARY KEY AUTOINCREMENT, context VARCHAR(8) NOT NULL, principal_id INTEGER NOT NULL, token_hash VARCHAR(64) UNIQUE NOT NULL, expires_at TEXT NOT NULL, created_at TEXT NOT NULL, used_at TEXT, attempts INTEGER NOT NULL DEFAULT 0)',
            'CREATE TABLE symsvel_auth_access_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, context VARCHAR(8) NOT NULL, principal_id INTEGER NOT NULL, family_id VARCHAR(32) NOT NULL, token_hash VARCHAR(64) UNIQUE NOT NULL, expires_at TEXT NOT NULL, created_at TEXT NOT NULL, revoked_at TEXT)',
            'CREATE TABLE symsvel_auth_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, context VARCHAR(8) NOT NULL, principal_id INTEGER NOT NULL, family_id VARCHAR(32) NOT NULL, token_hash VARCHAR(64) UNIQUE NOT NULL, expires_at TEXT NOT NULL, created_at TEXT NOT NULL, revoked_at TEXT, used_at TEXT)',
        ] as $sql) {
            $connection->executeStatement($sql);
        }

        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [dirname((new \ReflectionClass(Setting::class))->getFileName())],
            isDevMode: true,
        );
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager($connection, $config);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Setting::class)]);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($em);
        $registry = new SettingRegistry([]);
        $registry->registerOwned(new TwoFactorSettingDefinitionProvider(), 'two-factor', new SettingKeyQualifier());
        $settings = new SettingManager($registry, new SettingRepository($doctrine), $em,
            new SettingSecretCipher('test-settings-crypto'));
        $status = new class implements PrincipalStatus {
            public bool $active = true;
            public function isActive(TokenSubject $subject): bool { return $this->active; }
        };
        $manager = new TwoFactorManager(
            $connection, $settings, new TotpService(), new SecretCipher('test-totp-crypto'),
            new RecoveryCodeService(), $status, new TokenIssuer(new DbalTokenStore($connection), new TokenFactory()),
        );
        return [$connection, $settings, $manager, $status, $em];
    }

    private static function otp(string $base32, int $timestamp): string
    {
        $bits = '';
        foreach (str_split($base32) as $char) {
            $bits .= str_pad(decbin((int) strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $key .= chr(bindec($chunk));
        }
        $step = intdiv($timestamp, 30);
        $hash = hash_hmac('sha1', pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function testRequiredBoEnrollmentCannotIssueTokensBeforeValidCode(): void
    {
        [$connection, $settings, $manager, , $em] = $this->services();
        try {
            $settings->set('two-factor.bo_required', '1');
            $settings->flush();
            $now = new \DateTimeImmutable('@1111111109');
            $subject = new TokenSubject('bo', 7);
            $challenge = $manager->begin($subject, $now);
            self::assertNotNull($challenge);
            self::assertTrue($challenge->enrollmentRequired);
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM symsvel_auth_access_tokens'));
            self::assertNotSame($challenge->totpSecret,
                $connection->fetchOne('SELECT secret_ciphertext FROM symsvel_2fa_accounts'));
            self::assertNull($manager->complete('bo', $challenge->challenge, '111111', $now));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM symsvel_auth_access_tokens'));

            $code = self::otp((string) $challenge->totpSecret, $now->getTimestamp());
            $result = $manager->complete('bo', $challenge->challenge, $code, $now);
            self::assertNotNull($result);
            self::assertSame('bo', $result->tokens->context);
            self::assertCount(8, $result->recoveryCodes);
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM symsvel_auth_access_tokens'));
            self::assertNull($manager->complete('bo', $challenge->challenge, $code, $now));

            $next = $manager->begin($subject, $now);
            self::assertFalse($next->enrollmentRequired);
            self::assertNull($manager->complete('bo', $next->challenge, $code, $now)); // Anti-replay.
            $later = $now->modify('+30 seconds');
            $valid = self::otp((string) $challenge->totpSecret, $later->getTimestamp());
            self::assertNotNull($manager->complete('bo', $next->challenge, $valid, $later));
        } finally {
            $em->getConnection()->close();
        }
    }

    public function testContextsIndependentAndRecoveryCodeIsSingleUse(): void
    {
        [$connection, $settings, $manager, , $em] = $this->services();
        try {
            $settings->set('two-factor.app_required', '1');
            $settings->flush();
            $time = new \DateTimeImmutable('@1111111109');
            self::assertNull($manager->begin(new TokenSubject('bo', 7), $time));
            $subject = new TokenSubject('app', 7);
            $challenge = $manager->begin($subject, $time);
            self::assertNotNull($challenge);
            self::assertNull($manager->complete('bo', $challenge->challenge, '123456', $time));
            $result = $manager->complete('app', $challenge->challenge,
                self::otp((string) $challenge->totpSecret, $time->getTimestamp()), $time);
            self::assertNotNull($result);
            $recovery = $result->recoveryCodes[0];
            $second = $manager->begin($subject, $time->modify('+30 seconds'));
            self::assertNotNull($manager->complete('app', $second->challenge, $recovery, $time->modify('+30 seconds')));
            $third = $manager->begin($subject, $time->modify('+60 seconds'));
            self::assertNull($manager->complete('app', $third->challenge, $recovery, $time->modify('+60 seconds')));
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM symsvel_auth_access_tokens'));
        } finally {
            $em->getConnection()->close();
        }
    }

    public function testExpiredChallengeAndDisabledPrincipalFailClosed(): void
    {
        [$connection, $settings, $manager, $status, $em] = $this->services();
        try {
            $settings->set('two-factor.bo_required', '1');
            $settings->flush();
            $time = new \DateTimeImmutable('@1111111109');
            $challenge = $manager->begin(new TokenSubject('bo', 11), $time);
            $code = self::otp((string) $challenge->totpSecret, $time->getTimestamp());
            self::assertNull($manager->complete('bo', $challenge->challenge, $code, $time->modify('+6 minutes')));
            $status->active = false;
            self::assertNull($manager->complete('bo', $challenge->challenge, $code, $time));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM symsvel_auth_access_tokens'));
        } finally {
            $em->getConnection()->close();
        }
    }
}
