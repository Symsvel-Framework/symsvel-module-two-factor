<?php

declare(strict_types=1);

namespace SymSvel\Module\TwoFactor\Service;

use Doctrine\DBAL\Connection;
use SymSvel\Module\Auth\Identity\LoginSecondFactorInterface;
use SymSvel\Module\Auth\Identity\PrincipalStatus;
use SymSvel\Module\Auth\Identity\SecondFactorChallenge;
use SymSvel\Module\Auth\Identity\SecondFactorVerified;
use SymSvel\Module\Auth\Token\TokenIssuer;
use SymSvel\Module\Auth\Token\TokenSubject;
use SymSvel\Module\Settings\Service\SettingManager;

/**
 * Optional 2FA policy and single-use DBAL challenges, never a second token
 * system. The full SymSvel session is created only after factor verification.
 */
final readonly class TwoFactorManager implements LoginSecondFactorInterface
{
    public function __construct(
        private Connection $connection,
        private SettingManager $settings,
        private TotpService $totp,
        private SecretCipher $cipher,
        private RecoveryCodeService $recovery,
        private PrincipalStatus $principals,
        private TokenIssuer $tokens,
    ) {
    }

    public function begin(TokenSubject $subject, \DateTimeImmutable $now): ?SecondFactorChallenge
    {
        $required = $this->settings->get('two-factor.'.($subject->context === 'bo' ? 'bo_required' : 'app_required')) === '1';
        return $this->connection->transactional(function () use ($subject, $now, $required): ?SecondFactorChallenge {
            $account = $this->account($subject);
            if (!$required && (!$account || !(bool) $account['enabled'])) {
                return null;
            }
            if ($account === false) {
                $secret = $this->totp->generateSecret();
                $this->connection->insert('symsvel_2fa_accounts', [
                    'context' => $subject->context,
                    'principal_id' => $subject->id,
                    'secret_ciphertext' => $this->cipher->encrypt($secret),
                    'enabled' => 0,
                    'recovery_hashes' => '[]',
                ]);
            } else {
                $secret = $this->cipher->decrypt((string) $account['secret_ciphertext']);
            }

            $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $expires = $now->modify('+5 minutes');
            $this->connection->insert('symsvel_2fa_challenges', [
                'context' => $subject->context,
                'principal_id' => $subject->id,
                'token_hash' => hash('sha256', $raw),
                'created_at' => self::date($now),
                'expires_at' => self::date($expires),
                'attempts' => 0,
            ]);

            $needsEnrollment = $account === false || !(bool) $account['enabled'];
            return new SecondFactorChallenge(
                $raw,
                $expires,
                $needsEnrollment,
                $needsEnrollment ? $secret : null,
                $needsEnrollment ? $this->totp->provisioningUri($secret, $subject->context.'-'.$subject->id) : null,
            );
        });
    }

    public function complete(string $context, string $challenge, string $code, \DateTimeImmutable $now): ?SecondFactorVerified
    {
        if (!in_array($context, ['bo', 'app'], true)
            || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge)
            || !preg_match('/^(?:[0-9]{6}|[A-Fa-f0-9]{5}-[A-Fa-f0-9]{5})$/D', $code)) {
            return null;
        }
        $hash = hash('sha256', $challenge);
        $entry = $this->connection->fetchAssociative(
            'SELECT principal_id FROM symsvel_2fa_challenges WHERE token_hash = ? AND context = ?',
            [$hash, $context],
        );
        if ($entry === false) {
            return null;
        }

        $subject = new TokenSubject($context, (int) $entry['principal_id']);
        $codes = [];
        // Auth owns the outer transaction. This is mandatory for the existing
        // SQLite token store, which intentionally rejects nested transactions.
        $issued = $this->tokens->issueWhen($subject, $now,
            function () use ($subject, $context, $hash, $code, $now, &$codes): bool {
                // Atomic UPDATE acquires the lock before reading account state
                // on all supported databases, including SQLite.
                $affected = $this->connection->executeStatement(
                    'UPDATE symsvel_2fa_challenges SET attempts = attempts + 1
                     WHERE token_hash = ? AND context = ? AND principal_id = ?
                       AND used_at IS NULL AND expires_at > ? AND attempts < 5',
                    [$hash, $context, $subject->id, self::date($now)],
                );
                if ($affected !== 1 || !$this->principals->isActive($subject)) {
                    return false;
                }
                $account = $this->account($subject);
                if ($account === false) {
                    return false;
                }
                $secret = $this->cipher->decrypt((string) $account['secret_ciphertext']);
                $counter = $this->totp->matchingCounter($secret, $code, $now->getTimestamp());
                $isEnrollment = !(bool) $account['enabled'];
                if ($counter !== null) {
                    if ($account['last_counter'] !== null && $counter <= (int) $account['last_counter']) {
                        return false;
                    }
                    if ($isEnrollment) {
                        $generated = $this->recovery->generate();
                        $codes = $generated['plain'];
                        $this->connection->update('symsvel_2fa_accounts', [
                            'enabled' => 1,
                            'enabled_at' => self::date($now),
                            'last_counter' => $counter,
                            'recovery_hashes' => json_encode($generated['hashed'], JSON_THROW_ON_ERROR),
                        ], ['context' => $context, 'principal_id' => $subject->id]);
                    } else {
                        $this->connection->update('symsvel_2fa_accounts', [
                            'last_counter' => $counter,
                        ], ['context' => $context, 'principal_id' => $subject->id]);
                    }
                } else {
                    if ($isEnrollment) {
                        return false;
                    }
                    $hashes = json_decode((string) $account['recovery_hashes'], true, 32, JSON_THROW_ON_ERROR);
                    if (!is_array($hashes)) {
                        throw new \RuntimeException('Corrupt 2FA recovery-code data.');
                    }
                    $remaining = $this->recovery->consume($code, $hashes);
                    if ($remaining === null) {
                        return false;
                    }
                    $this->connection->update('symsvel_2fa_accounts', [
                        'recovery_hashes' => json_encode($remaining, JSON_THROW_ON_ERROR),
                    ], ['context' => $context, 'principal_id' => $subject->id]);
                }
                $this->connection->executeStatement(
                    'UPDATE symsvel_2fa_challenges SET used_at = ? WHERE token_hash = ? AND used_at IS NULL',
                    [self::date($now), $hash],
                );
                return true;
            },
        );

        return $issued === null ? null : new SecondFactorVerified($issued, $codes);
    }

    /** @return array<string, mixed>|false */
    private function account(TokenSubject $subject): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT secret_ciphertext, enabled, last_counter, recovery_hashes
             FROM symsvel_2fa_accounts WHERE context = ? AND principal_id = ?',
            [$subject->context, $subject->id],
        );
    }

    private static function date(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
