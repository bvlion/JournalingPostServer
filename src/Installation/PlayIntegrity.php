<?php

declare(strict_types=1);

namespace JournalingPostServer\Installation;

use Closure;
use JournalingPostServer\Http\ApiException;
use Throwable;

/** Standard requestをGoogleで復号する。token・判定本文・鍵は永続化しない。 */
final class PlayIntegrity
{
    /** @param Closure(string, array<string, string>, string): array<string, mixed>|null $post */
    public function __construct(
        private string $packageName,
        private string $credentialsFile,
        private ?Closure $post = null,
    ) {
    }

    public function verify(string $token, string $registrationId): void
    {
        try {
            if (preg_match('/\A[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+\z/', $this->packageName) !== 1) {
                throw new \RuntimeException('Integrity configuration is missing.');
            }
            $credentials = json_decode(
                (string) @file_get_contents($this->credentialsFile),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($credentials) || !is_string($credentials['client_email'] ?? null)
                || !is_string($credentials['private_key'] ?? null)
            ) {
                throw new \RuntimeException('Integrity credentials are invalid.');
            }
            $now = time();
            $parts = [];
            foreach (
                [['alg' => 'RS256', 'typ' => 'JWT'], [
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/playintegrity',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now, 'exp' => $now + 3600,
                ]] as $part
            ) {
                $parts[] = rtrim(strtr(base64_encode(json_encode($part, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            }
            $unsigned = implode('.', $parts);
            if (!@openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('Integrity signing failed.');
            }
            $assertion = $unsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
            $access = $this->post(
                'https://oauth2.googleapis.com/token',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion,
                ]),
            );
            if (
                !is_string($access['access_token'] ?? null)
                || preg_match('/\A[\x21-\x7E]+\z/', $access['access_token']) !== 1
            ) {
                throw new \RuntimeException('Integrity authorization failed.');
            }
            $decoded = $this->post(
                'https://playintegrity.googleapis.com/v1/' . $this->packageName . ':decodeIntegrityToken',
                ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $access['access_token']],
                json_encode(['integrity_token' => $token], JSON_THROW_ON_ERROR)
            );
        } catch (Throwable $exception) {
            // Googleのエラー本文・サービスアカウント情報を外へ出さない。
            throw new ApiException(503, 'registration_unavailable', 'Registration verification is unavailable.');
        }
        $verdict = $decoded['tokenPayloadExternal'] ?? [];
        $details = $verdict['requestDetails'] ?? [];
        $timestamp = $details['timestampMillis'] ?? null;
        $requestHash = hash('sha256', "POST\n/v1/installations\n" . $this->packageName . "\n" . $registrationId);
        $nowMilliseconds = (int) floor(microtime(true) * 1000);
        if (
            ($details['requestPackageName'] ?? null) !== $this->packageName
            || ($details['requestHash'] ?? null) !== $requestHash
            || !is_string($timestamp) || preg_match('/\A[0-9]{13}\z/', $timestamp) !== 1
            || (int) $timestamp > $nowMilliseconds || $nowMilliseconds - (int) $timestamp > 300000
            || ($verdict['appIntegrity']['appRecognitionVerdict'] ?? null) !== 'PLAY_RECOGNIZED'
            || ($verdict['appIntegrity']['packageName'] ?? null) !== $this->packageName
            || ($verdict['accountDetails']['appLicensingVerdict'] ?? null) !== 'LICENSED'
        ) {
            throw new ApiException(403, 'registration_rejected', 'Registration verification was rejected.');
        }
    }

    /** @param array<string, string> $headers @return array<string, mixed> */
    private function post(string $url, array $headers, string $body): array
    {
        if ($this->post !== null) {
            return ($this->post)($url, $headers, $body);
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (!is_string($response) || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Integrity request failed.');
        }
        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Integrity response is invalid.');
        }
        return $decoded;
    }
}
