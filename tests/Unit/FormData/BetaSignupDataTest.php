<?php

declare(strict_types=1);

namespace App\Tests\Unit\FormData;

use App\FormData\BetaSignupData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class BetaSignupDataTest extends TestCase
{
    public function testValidData(): void
    {
        $data = new BetaSignupData();
        $data->email = 'test@example.com';
        $data->domainCount = 5;
        $data->painPoint = 'SPF confusion';

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $violations = $validator->validate($data);

        self::assertCount(0, $violations);
    }

    public function testEmailIsRequired(): void
    {
        $data = new BetaSignupData();

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $violations = $validator->validate($data);

        self::assertGreaterThan(0, count($violations));
    }

    public function testInvalidEmail(): void
    {
        $data = new BetaSignupData();
        $data->email = 'not-an-email';

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $violations = $validator->validate($data);

        self::assertGreaterThan(0, count($violations));
    }

    public function testPainPointMaxLength(): void
    {
        $data = new BetaSignupData();
        $data->email = 'test@example.com';
        $data->painPoint = str_repeat('a', 501);

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $violations = $validator->validate($data);

        self::assertGreaterThan(0, count($violations));
    }

    public function testPainPointAtMaxLength(): void
    {
        $data = new BetaSignupData();
        $data->email = 'test@example.com';
        $data->painPoint = str_repeat('a', 500);

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $violations = $validator->validate($data);

        self::assertCount(0, $violations);
    }

    public function testOptionalFieldsCanBeNull(): void
    {
        $data = new BetaSignupData();
        $data->email = 'test@example.com';
        $data->domainCount = null;
        $data->painPoint = null;

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $violations = $validator->validate($data);

        self::assertCount(0, $violations);
    }
}
