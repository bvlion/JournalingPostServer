<?php

declare(strict_types=1);

use Dotenv\Dotenv;

// HTTPアプリの入口用の完全な設定。
//
// DB接続設定は database-config.php で読み込み・検証する（CLIと共通）。この
// ファイルはそれに加えて、HTTPアプリだけが必要とするanalysis / OpenAI設定を
// 必須検証して analysis キーを足す。DBだけを必要とするCLIはこのファイルを
// 経由せず database-config.php を直接使う。

$configuration = require __DIR__ . '/database-config.php';

// analysis / OpenAI設定はHTTPアプリ専用。ここでだけ必須検証する。
Dotenv::createImmutable(dirname(__DIR__))->required([
    'ANALYSIS_FINGERPRINT_SECRET',
    'OPENAI_API_KEY',
    'OPENAI_TIMEOUT_SECONDS',
])->notEmpty();

$fingerprintSecret = $_ENV['ANALYSIS_FINGERPRINT_SECRET']
    ?? $_SERVER['ANALYSIS_FINGERPRINT_SECRET'];

// 短い値では鍵付きhashの意味がなくなるため、最低長を課す。値そのものは
// 例外メッセージへ含めない。
if (strlen($fingerprintSecret) < 32) {
    throw new \RuntimeException(
        'ANALYSIS_FINGERPRINT_SECRET must be at least 32 characters.',
    );
}

$openAiApiKey = $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'];

// OpenAI呼び出しのtimeout秒数は設定可能にする。productionで使う値は実
// provider / XServerでの実測から決定する（`docs/hosted-analysis-api.md`）。
// 正の整数以外は起動を失敗させる。値そのものは秘密ではないが、扱いを他の
// 設定値とそろえる。
$openAiTimeoutSeconds = $_ENV['OPENAI_TIMEOUT_SECONDS']
    ?? $_SERVER['OPENAI_TIMEOUT_SECONDS'];

if (preg_match('/\A[1-9][0-9]*\z/', (string) $openAiTimeoutSeconds) !== 1) {
    throw new \RuntimeException(
        'OPENAI_TIMEOUT_SECONDS must be a positive integer number of seconds.',
    );
}

// OpenAIへ送る解析指示本文（system promptと分析ルール本文）は、実行時の
// プレーンテキストファイルから読む。既定は config/analysis-instruction.txt、
// `ANALYSIS_INSTRUCTION_FILE` で別パス（例: `.env` と並べた非公開ディレクトリ）
// へ差し替えられる。ファイルはGit管理対象外で、実値はrepository・Issue・PR・
// commit message・テスト・ログへ含めない。
//
// GitHub Secretに保持するのはprompt本文だけで、Secretや運搬形式（base64等）は
// このアプリが直接扱わない。productionではdeploy時にSecretの本文をこのファイル
// へ復元する。ローカルは同じファイルを直接編集する。
//
// 本文は「1行目 = system prompt」「2行目以降 = 分析ルール本文」で読む（間の空行は
// 任意）。欠落・空・本体不足は、内容を出力せずに起動を失敗させる（Androidから
// 指定するAPIにはしない）。
$analysisInstructionFile = $_ENV['ANALYSIS_INSTRUCTION_FILE']
    ?? $_SERVER['ANALYSIS_INSTRUCTION_FILE']
    ?? null;

if (!is_string($analysisInstructionFile) || $analysisInstructionFile === '') {
    $analysisInstructionFile = dirname(__DIR__)
        . '/config/analysis-instruction.txt';
}

if (!is_file($analysisInstructionFile) || !is_readable($analysisInstructionFile)) {
    throw new \RuntimeException(
        'The analysis instruction file is missing or unreadable.',
    );
}

$analysisInstructionText = file_get_contents($analysisInstructionFile);

if ($analysisInstructionText === false) {
    throw new \RuntimeException(
        'The analysis instruction file could not be read.',
    );
}

// 1行目を system prompt、残りを分析ルール本文として取り出す。内容そのものは
// 例外メッセージへ含めない。
$analysisInstructionText = str_replace("\r\n", "\n", $analysisInstructionText);
$analysisInstructionNewline = strpos($analysisInstructionText, "\n");

$analysisSystemPrompt = $analysisInstructionNewline === false
    ? trim($analysisInstructionText)
    : trim(substr($analysisInstructionText, 0, $analysisInstructionNewline));
$analysisRules = $analysisInstructionNewline === false
    ? ''
    : trim(substr($analysisInstructionText, $analysisInstructionNewline + 1));

if ($analysisSystemPrompt === '' || $analysisRules === '') {
    throw new \RuntimeException(
        'The analysis instruction must have a non-empty system-prompt line '
            . 'and a non-empty analysis-rules body.',
    );
}

$configuration['analysis'] = [
    // 解析requestのfingerprintを鍵付きにするための秘密値。本番deployを跨いで
    // 同じ値を使う。値が変わると、保持期間内の再送が別内容と判定されて
    // `409 idempotency_key_reuse`になる。
    'fingerprintSecret' => $fingerprintSecret,
    // OpenAI Responses APIの認証情報。実値をrepository・response・通常ログ・
    // 例外メッセージへ出さない。
    'openAiApiKey' => $openAiApiKey,
    'openAiTimeoutSeconds' => (int) $openAiTimeoutSeconds,
    // OpenAIへ送る解析指示本文。実値をrepository・response・通常ログ・例外
    // メッセージへ出さない。
    'systemPrompt' => $analysisSystemPrompt,
    'analysisRules' => $analysisRules,
];

return $configuration;
