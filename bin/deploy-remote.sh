#!/usr/bin/env bash
#
# 本番更新デプロイ。GitHub Actions（.github/workflows/deploy.yaml）から SSH の
# 標準入力経由で XServer 本番ホスト上で実行される。GitHub Actions runner 上で
# 直接実行することは想定しない。
#
# 呼び出し側が実行前に export する環境変数（値は argv から取らないため
# リモートホストの `ps` に現れない）:
#   DEPLOY_PATH               アプリ本体 checkout の絶対パス。
#   DEPLOY_COMPOSER_PATH      本番へ配置済みの専用 composer.phar の絶対パス。
#   DEPLOY_PUBLIC_PATH        公開ディレクトリ（public_html）の絶対パス。
#   DEPLOY_INSTRUCTION_PATH   解析指示本文を書き戻す実行時ファイルの絶対パス
#                             （既定運用では <DEPLOY_PATH>/config/analysis-instruction.txt）。
#   ANALYSIS_INSTRUCTION_B64  解析指示本文（プレーンテキスト）を base64 で
#                             1 行にしたもの。GitHub Secret の本文がそのまま入る。
#   TAG_NAME                  push されたタグ名（例: v1.0.0）。
#   EXPECTED_COMMIT           push されたタグが解決すべき commit SHA。
#
# 秘密値（ANALYSIS_INSTRUCTION_B64）と本番固有値は標準出力へ出さない。

set -euo pipefail

PHP_BIN=/opt/php-8.5.5/bin/php

: "${DEPLOY_PATH:?DEPLOY_PATH is required}"
: "${DEPLOY_COMPOSER_PATH:?DEPLOY_COMPOSER_PATH is required}"
: "${DEPLOY_PUBLIC_PATH:?DEPLOY_PUBLIC_PATH is required}"
: "${DEPLOY_INSTRUCTION_PATH:?DEPLOY_INSTRUCTION_PATH is required}"
: "${ANALYSIS_INSTRUCTION_B64:?ANALYSIS_INSTRUCTION_B64 is required}"
: "${TAG_NAME:?TAG_NAME is required}"
: "${EXPECTED_COMMIT:?EXPECTED_COMMIT is required}"

cd "$DEPLOY_PATH"

# 本番 working tree に想定外の tracked 変更があれば、上書き・reset せず中止する。
# .env と解析指示本文は Git 管理対象外（untracked）のため、この判定にも
# git checkout にも影響しない。
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Deploy aborted: production working tree has unexpected tracked changes." >&2
    exit 1
fi

# push されたタグをそのタグ名で取得する。annotated / lightweight のいずれでも
# ^{commit} で最終的な commit へ解決できる。実行時点の origin/main は使わない。
git fetch origin --force "refs/tags/${TAG_NAME}:refs/tags/${TAG_NAME}"

RESOLVED_COMMIT="$(git rev-list -n 1 "refs/tags/${TAG_NAME}^{commit}")"

if [ "$RESOLVED_COMMIT" != "$EXPECTED_COMMIT" ]; then
    echo "Deploy aborted: tag ${TAG_NAME} resolves to an unexpected commit." >&2
    exit 1
fi

git checkout --detach "$RESOLVED_COMMIT"

# 解析指示本文を GitHub Secret から実行時ファイルへ復元する。アプリは Secret も
# 運搬形式（base64）も扱わない。ここで平文へ戻して所定のパスへ置くだけ。
# 内容は標準出力・標準エラーへ出さない。
umask 077
mkdir -p "$(dirname "$DEPLOY_INSTRUCTION_PATH")"
INSTRUCTION_TMP="$(mktemp "${DEPLOY_INSTRUCTION_PATH}.XXXXXX")"
trap 'rm -f "$INSTRUCTION_TMP"' EXIT

if ! printf '%s' "$ANALYSIS_INSTRUCTION_B64" | base64 -d > "$INSTRUCTION_TMP"; then
    echo "Deploy aborted: could not decode the analysis instruction secret." >&2
    exit 1
fi

# bootstrap/config.php は「1 行目 = system prompt」「2 行目以降 = 分析ルール本文」を
# 要求し、どちらかが空なら起動を失敗させる。初回リクエストで 500 になる前に
# ここで検知する。内容そのものは出力しない。
INSTRUCTION_FIRST_LINE="$(head -n 1 "$INSTRUCTION_TMP" | tr -d '\r')"
INSTRUCTION_REST="$(tail -n +2 "$INSTRUCTION_TMP" | tr -d '[:space:]')"
if [ -z "$INSTRUCTION_FIRST_LINE" ] || [ -z "$INSTRUCTION_REST" ]; then
    echo "Deploy aborted: the analysis instruction secret must have a non-empty" \
        "first line and a non-empty analysis-rules body." >&2
    exit 1
fi

mv -f "$INSTRUCTION_TMP" "$DEPLOY_INSTRUCTION_PATH"
chmod 600 "$DEPLOY_INSTRUCTION_PATH"
trap - EXIT

# 本番依存を composer.lock どおりに、開発用パッケージ抜きで導入する。
# デプロイのたびに実行する運用と整合するよう autoloader を最適化する。
"$PHP_BIN" "$DEPLOY_COMPOSER_PATH" install --no-dev --optimize-autoloader --classmap-authoritative

# 未適用マイグレーションを適用する（このタグに含まれる database/migrations の分）。
"$PHP_BIN" bin/migrate.php

# 公開ディレクトリ側の .htaccess をこのタグの内容へ更新する。
# index.php のシンボリックリンクは初回デプロイで作成したものを再利用する。
cp "$DEPLOY_PATH/public/.htaccess" "$DEPLOY_PUBLIC_PATH/.htaccess"

echo "Deployed tag=${TAG_NAME} commit=${RESOLVED_COMMIT}"
