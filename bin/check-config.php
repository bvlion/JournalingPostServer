<?php

declare(strict_types=1);

// リリースがHTTPアプリとして起動できることを、アプリ本体と同じ設定読み込みで
// 検証する。`bootstrap/config.php` をそのまま評価するため、解析指示本文の判定
// （1行目 = system prompt、2行目以降 = 分析ルール本文、いずれも trim() 後に非空）
// は実行時と完全に同じになる。DBへは接続しない（`bootstrap/database-config.php`
// は接続設定を読むだけ）。
//
// デプロイ（`bin/deploy-remote.sh`）が公開先を新リリースへ切り替える前のゲート
// として実行する。ローカルからも `php bin/check-config.php` で同じ検証ができる。
//
// 失敗時は例外メッセージだけを stderr へ出して非ゼロ終了する。スタックトレース
// の引数（解析指示本文の一部が入り得る）は出さない。

ini_set('zend.exception_ignore_args', '1');
ini_set('zend.exception_string_param_max_len', '0');

require_once __DIR__ . '/../vendor/autoload.php';

try {
    require __DIR__ . '/../bootstrap/config.php';
} catch (\Throwable $exception) {
    fwrite(
        STDERR,
        'Configuration invalid: ' . $exception->getMessage() . "\n",
    );

    exit(1);
}

fwrite(STDOUT, "Configuration OK.\n");
