<?php

declare(strict_types=1);

use JournalingPostServer\Analysis\AnalysisRequestRepository;
use JournalingPostServer\Database\ConnectionFactory;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * 失効した解析requestのidempotency metadataと、解析結果の引き渡しバッファを
 * 削除する。XServer Cronから定期実行する。
 *
 * 削除は解析requestの処理中にも行うが、requestが来なくなった期間はそれだけでは
 * 動かない。解析結果本文が保持期間を越えてDBへ残り続けないようにするために、
 * このコマンドが必要である。
 *
 * 出力するのは件数だけで、JournalEntry本文・解析結果本文・installation識別子を
 * ログへ残さない。
 *
 * DB接続だけを必要とするため`bootstrap/database-config.php`を使う。analysis /
 * OpenAI設定は読み込まない。`OPENAI_API_KEY`の失効対応などでそれらが欠落・空に
 * なっても、このCronが起動不能にならず、失効した本文の削除保証を維持する。
 */

$configuration = require __DIR__ . '/../bootstrap/database-config.php';
$databaseConfiguration = $configuration['database'];
$connection = (new ConnectionFactory(
    $databaseConfiguration['host'],
    $databaseConfiguration['port'],
    $databaseConfiguration['name'],
    $databaseConfiguration['user'],
    $databaseConfiguration['password'],
))->create();

$purgedCount = (new AnalysisRequestRepository(
    static fn (): PDO => $connection,
))->purgeExpired(new DateTimeImmutable('now'));

fwrite(STDOUT, sprintf("Purged expired analysis requests: %d\n", $purgedCount));
