<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use JournalingPostServer\Analysis\AnalysisRequestParser;
use JournalingPostServer\Analysis\OpenAi\CurlResponsesTransport;
use JournalingPostServer\Analysis\OpenAi\OpenAiAnalyzer;

require_once __DIR__ . '/../vendor/autoload.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php bin/analyze-local.php <analysis-request.json>\n");
    exit(2);
}

$inputFile = $argv[1];
if (!is_file($inputFile) || !is_readable($inputFile)) {
    fwrite(STDERR, "The analysis request file is missing or unreadable.\n");
    exit(2);
}

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$apiKey = $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? null;
$timeout = $_ENV['OPENAI_TIMEOUT_SECONDS']
    ?? $_SERVER['OPENAI_TIMEOUT_SECONDS']
    ?? null;

if (!is_string($apiKey) || trim($apiKey) === '') {
    fwrite(STDERR, "OPENAI_API_KEY must be configured.\n");
    exit(2);
}
if (!is_string($timeout) || preg_match('/\A[1-9][0-9]*\z/', $timeout) !== 1) {
    fwrite(STDERR, "OPENAI_TIMEOUT_SECONDS must be a positive integer.\n");
    exit(2);
}

$instructionFile = $_ENV['ANALYSIS_INSTRUCTION_FILE']
    ?? $_SERVER['ANALYSIS_INSTRUCTION_FILE']
    ?? dirname(__DIR__) . '/config/analysis-instruction.txt';
if (!is_string($instructionFile) || !is_file($instructionFile) || !is_readable($instructionFile)) {
    fwrite(STDERR, "The analysis instruction file is missing or unreadable.\n");
    exit(2);
}

$instruction = file_get_contents($instructionFile);
$input = file_get_contents($inputFile);
if ($instruction === false || $input === false) {
    fwrite(STDERR, "A local analysis input file could not be read.\n");
    exit(2);
}

$instruction = str_replace("\r\n", "\n", $instruction);
$newline = strpos($instruction, "\n");
$systemPrompt = $newline === false ? trim($instruction) : trim(substr($instruction, 0, $newline));
$analysisRules = $newline === false ? '' : trim(substr($instruction, $newline + 1));
if ($systemPrompt === '' || $analysisRules === '') {
    fwrite(STDERR, "The analysis instruction is incomplete.\n");
    exit(2);
}

try {
    $payload = json_decode($input, flags: JSON_THROW_ON_ERROR);
    if (!$payload instanceof stdClass) {
        throw new RuntimeException('The analysis request must be a JSON object.');
    }

    $request = AnalysisRequestParser::parse($payload);
    $analyzer = new OpenAiAnalyzer(
        new CurlResponsesTransport((int) $timeout),
        $apiKey,
        $systemPrompt,
        $analysisRules,
    );
    $analysis = $analyzer->analyze($request);
    fwrite(STDOUT, $analysis->text . "\n");
} catch (Throwable $exception) {
    // JournalEntry・prompt・provider responseを誤って表示しない。比較用の正常な
    // 解析結果だけを標準出力へ出す。
    fwrite(STDERR, "Local analysis failed (" . $exception::class . ").\n");
    exit(1);
}
