<?php

declare(strict_types=1);

namespace SymSvel\Module\TwoFactor\Service;

/** Authenticator secrets are encrypted at rest with libsodium, never hashed. */
final readonly class SecretCipher
{
    private string $key;

    public function __construct(string $kernelSecret)
    {
        if (trim($kernelSecret) === '' || !function_exists('sodium_crypto_secretbox')) {
            throw new \LogicException('2FA requires a non-empty kernel secret and libsodium.');
        }
        $this->key = sodium_crypto_generichash($kernelSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $cipher): string
    {
        $data = base64_decode($cipher, true);
        if ($data === false || strlen($data) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Invalid encrypted 2FA secret.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if ($plain === false) {
            throw new \RuntimeException('Unable to decrypt 2FA secret.');
        }
        return $plain;
    }
}
