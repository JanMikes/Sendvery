<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\CredentialEncryptor;
use PHPUnit\Framework\TestCase;

final class CredentialEncryptorTest extends TestCase
{
    private CredentialEncryptor $encryptor;
    private string $rawKey;

    protected function setUp(): void
    {
        $this->rawKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $this->encryptor = new CredentialEncryptor($this->rawKey);
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $plaintext = 'my-secret-password';
        $encrypted = $this->encryptor->encrypt($plaintext);

        self::assertNotSame($plaintext, $encrypted);
        self::assertStringStartsWith('halite:', $encrypted);

        $decrypted = $this->encryptor->decrypt($encrypted);

        self::assertSame($plaintext, $decrypted);
    }

    public function testDifferentNoncesEachTime(): void
    {
        $plaintext = 'same-password';
        $encrypted1 = $this->encryptor->encrypt($plaintext);
        $encrypted2 = $this->encryptor->encrypt($plaintext);

        self::assertNotSame($encrypted1, $encrypted2);

        self::assertSame($plaintext, $this->encryptor->decrypt($encrypted1));
        self::assertSame($plaintext, $this->encryptor->decrypt($encrypted2));
    }

    public function testBackwardCompatibilityWithLegacySodium(): void
    {
        // Encrypt using legacy sodium format
        $key = base64_decode($this->rawKey);
        $plaintext = 'legacy-password';
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        $legacyEncrypted = base64_encode($nonce.$ciphertext);

        // Decrypt with the new encryptor should work
        $decrypted = $this->encryptor->decrypt($legacyEncrypted);

        self::assertSame($plaintext, $decrypted);
    }

    public function testReEncryptMigratesLegacyToHalite(): void
    {
        // Create legacy-encrypted value
        $key = base64_decode($this->rawKey);
        $plaintext = 'migrate-me';
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        $legacyEncrypted = base64_encode($nonce.$ciphertext);

        $reEncrypted = $this->encryptor->reEncrypt($legacyEncrypted);

        self::assertNotNull($reEncrypted);
        self::assertStringStartsWith('halite:', $reEncrypted);
        self::assertSame($plaintext, $this->encryptor->decrypt($reEncrypted));
    }

    public function testReEncryptReturnsNullForHaliteFormat(): void
    {
        $encrypted = $this->encryptor->encrypt('already-halite');

        self::assertNull($this->encryptor->reEncrypt($encrypted));
    }

    public function testEncryptsEmptyString(): void
    {
        $encrypted = $this->encryptor->encrypt('');
        $decrypted = $this->encryptor->decrypt($encrypted);

        self::assertSame('', $decrypted);
    }

    public function testEncryptsUtf8Content(): void
    {
        $plaintext = 'heslo-česky-ěščřžýáíé';
        $encrypted = $this->encryptor->encrypt($plaintext);
        $decrypted = $this->encryptor->decrypt($encrypted);

        self::assertSame($plaintext, $decrypted);
    }

    public function testDecryptLegacyCorruptedDataFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');
        $this->encryptor->decrypt(base64_encode('corrupted-data-that-is-long-enough-to-pass'));
    }
}
