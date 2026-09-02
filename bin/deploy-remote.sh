#!/usr/bin/env bash
#
# 本番リリースデプロイ。GitHub Actions（.github/workflows/deploy.yaml）から SSH の
# 標準入力経由で XServer 本番ホスト上で実行される。GitHub Actions runner 上で
# 直接実行することは想定しない。GNU coreutils（mv -T / readlink 等）を前提とする。
#
# リリースごとに独立したディレクトリを用意し、対象タグのコード・依存関係・
# 解析指示本文・マイグレーション適用・起動検証まで完成させてから、公開先の
# symlink（current）を原子的に切り替える。切替前に失敗した場合は稼働中の
# リリースへ一切影響しない。切替後のスモークチェックに失敗した場合は直前の
# リリースへ自動で戻す。
#
# 想定するディレクトリ構成（初回セットアップで用意する。README「本番デプロイ」）:
#   <DEPLOY_ROOT>/
#     repo/                     public repository の git clone（origin = 公開URL）
#     shared/.env               恒久的な秘密設定。デプロイでは touch しない
#     releases/<tag>/           タグごとの完成リリース（git checkout + vendor 等）
#     current -> releases/<tag> 公開中リリースへの symlink（初回デプロイ前は無い）
#
# 呼び出し側が実行前に export する環境変数（値は argv から取らないため
# リモートホストの `ps` に現れない）:
#   DEPLOY_ROOT              上記 <DEPLOY_ROOT> の絶対パス。
#   DEPLOY_COMPOSER_PATH     本番へ配置済みの専用 composer.phar の絶対パス。
#   DEPLOY_PUBLIC_PATH       公開ディレクトリ（public_html）の絶対パス。
#   ANALYSIS_INSTRUCTION_B64 解析指示本文（プレーンテキスト）を base64 で 1 行に
#                            したもの。GitHub Secret の本文がそのまま入る。
#   TAG_NAME                 push されたタグ名（例: v1.0.0）。
#   EXPECTED_COMMIT          push されたタグが解決すべき commit SHA。
#   DEPLOY_BASE_URL          任意。切替後スモークチェックに使う本番URL。
#   DEPLOY_INSTRUCTION_RELPATH 任意。リリース内の解析指示本文ファイルの相対パス。
#                            既定 config/analysis-instruction.txt（アプリの既定と一致）。
#   DEPLOY_KEEP_RELEASES     任意。保持する過去リリース数。既定 5。
#   DEPLOY_PHP_BIN           任意。PHP CLI のパス。既定 /opt/php-8.5.5/bin/php
#                            （production 非接続での検証用の上書き口）。
#
# 秘密値（ANALYSIS_INSTRUCTION_B64）と本番固有値は標準出力へ出さない。

set -euo pipefail

# 本番CLIは XServer の PHP 8.5.5 を明示する。production 非接続での検証用に
# DEPLOY_PHP_BIN で上書きできる（本番では設定しない）。
PHP_BIN="${DEPLOY_PHP_BIN:-/opt/php-8.5.5/bin/php}"
INSTRUCTION_RELPATH="${DEPLOY_INSTRUCTION_RELPATH:-config/analysis-instruction.txt}"
KEEP_RELEASES="${DEPLOY_KEEP_RELEASES:-5}"

: "${DEPLOY_ROOT:?DEPLOY_ROOT is required}"
: "${DEPLOY_COMPOSER_PATH:?DEPLOY_COMPOSER_PATH is required}"
: "${DEPLOY_PUBLIC_PATH:?DEPLOY_PUBLIC_PATH is required}"
: "${ANALYSIS_INSTRUCTION_B64:?ANALYSIS_INSTRUCTION_B64 is required}"
: "${TAG_NAME:?TAG_NAME is required}"
: "${EXPECTED_COMMIT:?EXPECTED_COMMIT is required}"

REPO_DIR="$DEPLOY_ROOT/repo"
RELEASES_DIR="$DEPLOY_ROOT/releases"
SHARED_ENV="$DEPLOY_ROOT/shared/.env"
CURRENT_LINK="$DEPLOY_ROOT/current"

for required_path in "$REPO_DIR/.git" "$RELEASES_DIR" "$SHARED_ENV"; do
    if [ ! -e "$required_path" ]; then
        echo "Deploy aborted: expected path is missing: ${required_path} (run the initial setup first)." >&2
        exit 1
    fi
done

# --- タグを解決し、実行順に依存しない「最終版」を保証する --------------------
git -C "$REPO_DIR" fetch --force origin
git -C "$REPO_DIR" fetch --force origin "refs/tags/${TAG_NAME}:refs/tags/${TAG_NAME}"

NEW_COMMIT="$(git -C "$REPO_DIR" rev-list -n 1 "refs/tags/${TAG_NAME}^{commit}")"

if [ "$NEW_COMMIT" != "$EXPECTED_COMMIT" ]; then
    echo "Deploy aborted: tag ${TAG_NAME} resolves to an unexpected commit." >&2
    exit 1
fi

PREVIOUS_RELEASE=""
if [ -L "$CURRENT_LINK" ] && [ -d "$CURRENT_LINK" ]; then
    PREVIOUS_RELEASE="$(cd "$CURRENT_LINK" && pwd -P)"
    LIVE_COMMIT="$(git -C "$PREVIOUS_RELEASE" rev-parse HEAD)"

    if ! git -C "$REPO_DIR" cat-file -e "${LIVE_COMMIT}^{commit}" 2>/dev/null; then
        echo "Deploy aborted: cannot verify release ordering; the live commit is not present in ${REPO_DIR}." >&2
        exit 1
    fi

    # concurrency は同時実行を防ぐだけで FIFO 順は保証しない。稼働中より古い
    # （= 稼働中 commit の祖先。同一を含む）タグのデプロイはここで拒否し、
    # 遅れて実行された古いタグで本番が巻き戻らないようにする。直前リリースへ
    # 戻すのは bin/rollback-release.sh（公開先の symlink だけを戻す）。
    if git -C "$REPO_DIR" merge-base --is-ancestor "$NEW_COMMIT" "$LIVE_COMMIT"; then
        echo "Deploy aborted: tag ${TAG_NAME} is not newer than the currently deployed release. Use bin/rollback-release.sh to move back to an earlier release." >&2
        exit 1
    fi
fi

# --- リリースディレクトリを完成させる（公開先はまだ切り替えない）------------
RELEASE_DIR="$RELEASES_DIR/$TAG_NAME"
rm -rf "$RELEASE_DIR"
git clone --quiet --no-checkout "$REPO_DIR" "$RELEASE_DIR"
git -C "$RELEASE_DIR" -c advice.detachedHead=false checkout --detach "$NEW_COMMIT"

# 共有 .env をこのリリースへ symlink する。中身（DB_* / OPENAI_API_KEY /
# ANALYSIS_FINGERPRINT_SECRET 等）はデプロイでは作成・変更・削除しない。
ln -s "$SHARED_ENV" "$RELEASE_DIR/.env"

# 解析指示本文を GitHub Secret からこのリリースの実行時ファイルへ復元する。
# 稼働中リリースのファイルには一切触れないため、内容が不正でも稼働中には
# 影響しない（不正なら後段の検証で切替前に停止する）。内容は出力しない。
umask 077
INSTRUCTION_PATH="$RELEASE_DIR/$INSTRUCTION_RELPATH"
mkdir -p "$(dirname "$INSTRUCTION_PATH")"

if ! printf '%s' "$ANALYSIS_INSTRUCTION_B64" | base64 -d > "$INSTRUCTION_PATH"; then
    echo "Deploy aborted: could not decode the analysis instruction secret." >&2
    exit 1
fi
chmod 600 "$INSTRUCTION_PATH"

# 目視しやすい早期失敗のための軽い検査。アプリは 1 行目を trim() して判定する
# ため、ここでも空白だけの 1 行目を弾く（空白以外の文字があるかで判定）。
# 権威ある検証はこの後の bin/check-config.php。
INSTRUCTION_FIRST_LINE="$(head -n 1 "$INSTRUCTION_PATH" | tr -d '[:space:]')"
INSTRUCTION_REST="$(tail -n +2 "$INSTRUCTION_PATH" | tr -d '[:space:]')"
if [ -z "$INSTRUCTION_FIRST_LINE" ] || [ -z "$INSTRUCTION_REST" ]; then
    echo "Deploy aborted: the analysis instruction secret must have a non-empty first line (system prompt) and a non-empty analysis-rules body." >&2
    exit 1
fi

# composer.lock どおりの本番依存をこのリリースへ導入する。
"$PHP_BIN" "$DEPLOY_COMPOSER_PATH" install --no-dev --optimize-autoloader --classmap-authoritative --working-dir "$RELEASE_DIR"

# 未適用マイグレーションを本番DBへ適用する。旧リリースへ戻しても動作不能に
# しない additive-only 運用が前提（database/migrations/README.md）。
( cd "$RELEASE_DIR" && "$PHP_BIN" bin/migrate.php )

# アプリ本体と同じ設定読み込み（bootstrap/config.php）でこのリリースが起動
# 可能なことを検証する。解析指示本文の判定は実行時と完全に一致する。DBへは
# 接続しない。ここまで通ってはじめて公開先を切り替える。
( cd "$RELEASE_DIR" && "$PHP_BIN" bin/check-config.php )

# --- 公開先を原子的に切り替える --------------------------------------------
cp "$RELEASE_DIR/public/.htaccess" "$DEPLOY_PUBLIC_PATH/.htaccess"

SWITCH_TMP="$DEPLOY_ROOT/.current.$$"
ln -s "$RELEASE_DIR" "$SWITCH_TMP"
mv -T "$SWITCH_TMP" "$CURRENT_LINK"

echo "Switched current -> ${TAG_NAME} (${NEW_COMMIT})"

# --- 切替後スモークチェックと自動復帰 ------------------------------------
if [ -n "${DEPLOY_BASE_URL:-}" ]; then
    SMOKE_CODE="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 -X POST "${DEPLOY_BASE_URL%/}/v1/analyses" || true)"

    if [ "$SMOKE_CODE" = "401" ]; then
        echo "Post-switch check OK (401 on unauthenticated POST /v1/analyses)."
    elif [ -z "$SMOKE_CODE" ] || [ "$SMOKE_CODE" = "000" ]; then
        echo "Post-switch check inconclusive: could not reach ${DEPLOY_BASE_URL} from the host. The workflow's own connectivity check still runs." >&2
    else
        if [ -n "$PREVIOUS_RELEASE" ]; then
            cp "$PREVIOUS_RELEASE/public/.htaccess" "$DEPLOY_PUBLIC_PATH/.htaccess"
            ln -s "$PREVIOUS_RELEASE" "$SWITCH_TMP"
            mv -T "$SWITCH_TMP" "$CURRENT_LINK"
            echo "Deploy reverted: post-switch check returned ${SMOKE_CODE} (expected 401); restored the previous release." >&2
        else
            echo "Deploy failed: post-switch check returned ${SMOKE_CODE} (expected 401); no previous release to restore." >&2
        fi
        exit 1
    fi
fi

# --- 古いリリースを整理する（current は必ず残す）--------------------------
CURRENT_TARGET="$(cd "$CURRENT_LINK" && pwd -P)"
release_index=0
while IFS= read -r release_dir; do
    [ -n "$release_dir" ] || continue
    release_dir="${release_dir%/}"
    release_index=$((release_index + 1))
    if [ "$release_index" -le "$KEEP_RELEASES" ]; then
        continue
    fi
    if [ "$(cd "$release_dir" && pwd -P)" = "$CURRENT_TARGET" ]; then
        continue
    fi
    rm -rf "$release_dir"
done < <(ls -1dt "$RELEASES_DIR"/*/ 2>/dev/null)

echo "Deployed tag=${TAG_NAME} commit=${NEW_COMMIT}"
