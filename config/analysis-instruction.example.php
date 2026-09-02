<?php

declare(strict_types=1);

// OpenAIへ送る解析指示本文（system prompt と 分析ルール本文）の設定。
//
// 実行環境ごとに実ファイルを用意する（`.env` と同じ扱いで Git 管理対象外）。
// このファイルはローカル開発・テスト・`make check` 用の架空値のひな形であり、
// 実データを含まない。
//
//   cp config/analysis-instruction.example.php config/analysis-instruction.php
//
// 配置先はデフォルトで config/analysis-instruction.php。別の場所へ置く場合は
// 絶対パスを `ANALYSIS_INSTRUCTION_FILE` で指定する（ドキュメントルート外の
// アプリ本体ディレクトリへ `.env` と並べて置く運用に使える）。
//
// HTTPアプリ（bootstrap/config.php）は起動時にこのファイルの存在と内容を必須
// 検証し、欠落・空・不正な場合は値を出力せずに起動を失敗させる。DBだけを使う
// CLI（bin/migrate.php・bin/prune-expired-analyses.php）はこのファイルを読み
// 込まない。

return [
    // OpenAI Responses API の system ロールへ渡す固定文。
    'systemPrompt' => 'これはローカル検証用の架空の system prompt です。実データではありません。',

    // 分析ルール本文。user プロンプトの先頭に置き、その後へ対象期間のログ
    // 文字列（`## Slackのログ` 見出し以降）が続く。
    'rules' => <<<'RULES'
これはローカル検証・テスト用のダミー指示文です。実データを含みません。
本番の指示本文は実行環境ごとに config/analysis-instruction.php で設定します。
このひな形は systemPrompt / rules が非空であることの確認だけに使います。

出力キーと形は OpenAiAnalyzer::SCHEMA が唯一の定義です。ここでは名前だけ挙げます。

- good: 文字列の配列。
- bad: 文字列の配列。
- score: 整数。
- emotion: SCHEMA の enum のいずれか1つ。
- summary: 文字列。
- advice: 文字列。
- tags: 文字列の配列。
RULES,
];
