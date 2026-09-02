#!/usr/bin/env bash
#
# 公開先を既存の完成済みリリースへ戻す。XServer 本番ホスト上で利用者が手で
# 実行する（AI agent は SSH 接続しない。AGENTS.md「本番環境・SSHの安全ルール」）。
# GNU coreutils（mv -T / readlink 等）を前提とする。
#
# 使い方（環境変数で渡す。値は argv に載せない）:
#   DEPLOY_ROOT=<deploy-root> DEPLOY_PUBLIC_PATH=<public_html> \
#     bin/rollback-release.sh [<release-name>]
#
# <release-name> を省略すると、current の 1 つ前（mtime 順）のリリースへ戻す。
# 本番DBのマイグレーションは戻さない（additive-only 運用が前提。
# database/migrations/README.md）。切替は current symlink の原子的な差し替え
# だけで、リリースディレクトリ自体は変更しない。

set -euo pipefail

# 本番CLIは XServer の PHP 8.5.5 を明示する。検証用に DEPLOY_PHP_BIN で
# 上書きできる（本番では設定しない）。
PHP_BIN="${DEPLOY_PHP_BIN:-/opt/php-8.5.5/bin/php}"

: "${DEPLOY_ROOT:?DEPLOY_ROOT is required}"
: "${DEPLOY_PUBLIC_PATH:?DEPLOY_PUBLIC_PATH is required}"

RELEASES_DIR="$DEPLOY_ROOT/releases"
CURRENT_LINK="$DEPLOY_ROOT/current"

CURRENT_TARGET=""
if [ -L "$CURRENT_LINK" ] && [ -d "$CURRENT_LINK" ]; then
    CURRENT_TARGET="$(cd "$CURRENT_LINK" && pwd -P)"
fi

releases=()
while IFS= read -r dir; do
    [ -n "$dir" ] || continue
    releases+=("${dir%/}")
done < <(ls -1dt "$RELEASES_DIR"/*/ 2>/dev/null)

if [ "${#releases[@]}" -eq 0 ]; then
    echo "Rollback aborted: no releases found under ${RELEASES_DIR}." >&2
    exit 1
fi

echo "Releases (newest first):"
for dir in "${releases[@]}"; do
    mark="  "
    if [ -n "$CURRENT_TARGET" ] && [ "$(cd "$dir" && pwd -P)" = "$CURRENT_TARGET" ]; then
        mark="* "
    fi
    printf '  %s%s\n' "$mark" "$(basename "$dir")"
done

target_name="${1:-}"
if [ -n "$target_name" ]; then
    target_dir="$RELEASES_DIR/$target_name"
else
    target_dir=""
    for dir in "${releases[@]}"; do
        if [ -n "$CURRENT_TARGET" ] && [ "$(cd "$dir" && pwd -P)" = "$CURRENT_TARGET" ]; then
            continue
        fi
        target_dir="$dir"
        break
    done
fi

if [ -z "$target_dir" ] || [ ! -d "$target_dir" ]; then
    echo "Rollback aborted: target release not found." >&2
    exit 1
fi

if [ ! -d "$target_dir/vendor" ]; then
    echo "Rollback aborted: $(basename "$target_dir") has no vendor/; it is not a completed release." >&2
    exit 1
fi

if [ -n "$CURRENT_TARGET" ] && [ "$(cd "$target_dir" && pwd -P)" = "$CURRENT_TARGET" ]; then
    echo "Nothing to do: $(basename "$target_dir") is already current."
    exit 0
fi

# 戻し先が起動可能なことをアプリと同じ設定読み込みで確認する。
( cd "$target_dir" && "$PHP_BIN" bin/check-config.php )

cp "$target_dir/public/.htaccess" "$DEPLOY_PUBLIC_PATH/.htaccess"

switch_tmp="$DEPLOY_ROOT/.current.$$"
ln -s "$target_dir" "$switch_tmp"
mv -T "$switch_tmp" "$CURRENT_LINK"

echo "Rolled back current -> $(basename "$target_dir")"
echo "Note: database migrations are not rolled back (additive-only operation is assumed)."
