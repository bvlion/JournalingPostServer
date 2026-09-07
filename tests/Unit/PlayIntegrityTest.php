<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Unit;

use JournalingPostServer\Http\ApiException;
use JournalingPostServer\Tests\Support\IntegrityFixture;
use PHPUnit\Framework\TestCase;

final class PlayIntegrityTest extends TestCase
{
    public function testRecognizedLicensedAppDoesNotRequireDeviceIntegrity(): void
    {
        $fixture = new IntegrityFixture();
        $fixture->verifier()->verify('fake-token', $fixture->registrationId);
        self::assertSame(2, $fixture->calls);
    }

    public function testInvalidVerdictsAndReplayedVerdictsAreRejected(): void
    {
        $fixture = new IntegrityFixture();
        $original = $fixture->verdict;
        $cases = [
            ['requestDetails', 'requestPackageName', 'example.other.app'],
            ['requestDetails', 'requestHash', str_repeat('0', 64)],
            ['requestDetails', 'timestampMillis', (string) ((int) floor(microtime(true) * 1000) - 300001)],
            ['requestDetails', 'timestampMillis', (string) ((int) floor(microtime(true) * 1000) + 60000)],
            ['requestDetails', 'timestampMillis', null],
            ['appIntegrity', 'appRecognitionVerdict', 'UNEVALUATED'],
            ['appIntegrity', 'packageName', 'example.other.app'],
            ['accountDetails', 'appLicensingVerdict', 'UNLICENSED'],
            ['accountDetails', 'appLicensingVerdict', 'UNEVALUATED'],
        ];
        foreach ($cases as [$section, $field, $value]) {
            $fixture->verdict = $original;
            $fixture->verdict[$section][$field] = $value;
            try {
                $fixture->verifier()->verify('fake-token', $fixture->registrationId);
                self::fail('Invalid integrity verdict was accepted.');
            } catch (ApiException $exception) {
                self::assertSame(403, $exception->status());
            }
        }
    }

    public function testMissingCredentialsFailClosedWithoutDisclosingDetails(): void
    {
        $fixture = new IntegrityFixture();
        file_put_contents($fixture->file, 'secret-invalid-credential');
        try {
            $fixture->verifier()->verify('fake-token', $fixture->registrationId);
            self::fail('Invalid credentials were accepted.');
        } catch (ApiException $exception) {
            self::assertSame(503, $exception->status());
            self::assertStringNotContainsString('secret-invalid-credential', $exception->getMessage());
            self::assertSame(0, $fixture->calls);
        }
    }
}
