<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\CredentialEncryptor;
use PHPUnit\Framework\TestCase;

final class CredentialEncryptorTest extends TestCase
{
    private CredentialEncryptor $encryptor;

    protected function setUp(): void
    {
        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $this->encryptor = new CredentialEncryptor($key);
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $plaintext = 'my-secret-password';
        $encrypted = $this->encryptor->encrypt($plaintext);

        self::assertNotSame($plaintext, $encrypted);

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

    public function testDecryptWithWrongKeyFails(): void
    {
        $encrypted = $this->encryptor->encrypt('secret');

        $otherKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $otherEncryptor = new CredentialEncryptor($otherKey);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');
        $otherEncryptor->decrypt($encrypted);
    }

    public function testDecryptCorruptedDataFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');
        $this->encryptor->decrypt(base64_encode('corrupted-data-that-is-long-enough-to-pass'));
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
}
