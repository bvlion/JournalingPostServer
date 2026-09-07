<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Support;

use JournalingPostServer\Installation\PlayIntegrity;

/** 実Google・実credentialへ接続しない。署名用鍵もテスト内だけで生成する。 */
final class IntegrityFixture
{
    public string $file;
    public string $registrationId;
    /** @var array<string, mixed> */
    public array $verdict;
    public int $calls = 0;

    public function __construct()
    {
        $this->file = tempnam(sys_get_temp_dir(), 'integrity-test-');
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKey);
        file_put_contents($this->file, json_encode(
            ['client_email' => 'example@example.invalid', 'private_key' => $privateKey],
            JSON_THROW_ON_ERROR,
        ));
        $this->registrationId = str_repeat('a', 64);
        $this->verdict = ['requestDetails' => [
            'requestPackageName' => 'example.journaling.app',
            'requestHash' => hash(
                'sha256',
                "POST\n/v1/installations\nexample.journaling.app\n" . $this->registrationId,
            ),
            'timestampMillis' => (string) (int) floor(microtime(true) * 1000),
        ], 'appIntegrity' => ['appRecognitionVerdict' => 'PLAY_RECOGNIZED', 'packageName' => 'example.journaling.app'],
            'accountDetails' => ['appLicensingVerdict' => 'LICENSED'], 'deviceIntegrity' => []];
    }

    public function verifier(): PlayIntegrity
    {
        return new PlayIntegrity('example.journaling.app', $this->file, function (string $url): array {
            $this->calls++;
            return $url === 'https://oauth2.googleapis.com/token'
                ? ['access_token' => 'fake-access-token'] : ['tokenPayloadExternal' => $this->verdict];
        });
    }

    public function __destruct()
    {
        unlink($this->file);
    }
}
