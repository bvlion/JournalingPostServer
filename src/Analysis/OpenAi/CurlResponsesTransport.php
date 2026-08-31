<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis\OpenAi;

/**
 * curl拡張だけでOpenAI Responses APIを呼ぶtransport。
 *
 * OpenAI SDKは追加せず、XServerで利用確認済みのcurlを使う（`composer.json`の
 * `ext-curl`）。TLSは必須にし、平文HTTPへのリダイレクト追従は行わない。
 *
 * 失敗は2種類に分ける。
 * - 送信前の失敗（名前解決・接続・TLS、あるいは接続前のtimeout）は
 *   `OpenAiUnreachableException`。AIは呼ばれておらず、確定失敗である。
 * - 送信後に結果を確認できない失敗（接続確立後のtimeout・受信途絶）は
 *   `OpenAiUnconfirmedException`。二重課金を避けるため確定失敗にしない。
 */
final class CurlResponsesTransport implements ResponsesTransport
{
    /** 接続確立だけに使うtimeout。全体timeoutより短くなる場合はそちらへ合わせる。 */
    private const CONNECT_TIMEOUT_SECONDS = 10;

    public function __construct(private int $timeoutSeconds)
    {
    }

    public function post(string $url, array $headers, string $body): TransportResult
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min(
                $this->timeoutSeconds,
                self::CONNECT_TIMEOUT_SECONDS,
            ),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $responseBody = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $connectTime = (float) curl_getinfo($handle, CURLINFO_CONNECT_TIME);
        // curl_close()はPHP 8.0以降で不要（8.5でdeprecated）。$handleのスコープ
        // 終了でハンドルは解放される。

        if ($errorNumber === 0 && is_string($responseBody)) {
            return new TransportResult($status, $responseBody);
        }

        // メッセージはcurlのerror番号だけにとどめる。request body・API keyは
        // curlのerror文には元々含まれないが、固定文にして念のため漏らさない。
        if (self::isUnreachable($errorNumber, $connectTime)) {
            throw new OpenAiUnreachableException(
                sprintf(
                    'The OpenAI request could not be sent (curl %d).',
                    $errorNumber,
                ),
            );
        }

        throw new OpenAiUnconfirmedException(
            sprintf(
                'The OpenAI request outcome could not be confirmed (curl %d).',
                $errorNumber,
            ),
            $errorNumber === CURLE_OPERATION_TIMEDOUT,
        );
    }

    private static function isUnreachable(
        int $errorNumber,
        float $connectTime,
    ): bool {
        $beforeSend = [
            CURLE_COULDNT_RESOLVE_PROXY,
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_CONNECT,
            CURLE_SSL_CONNECT_ERROR,
        ];

        if (in_array($errorNumber, $beforeSend, true)) {
            return true;
        }

        // 接続が確立していなければ、timeoutでもrequestは送信されていない。
        return $errorNumber === CURLE_OPERATION_TIMEDOUT && $connectTime === 0.0;
    }
}
