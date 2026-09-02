#!/usr/bin/env bash
#
# 副作用のないデプロイ後の疎通確認。
#
# 次の 2 つを確認する。いずれも DB 書き込み・OpenAI 呼び出しなどの副作用を
# 起こさない。
#   1. Authorization ヘッダー無しの POST /v1/analyses が HTTP 401 を返す
#      （ルーティングが届き、Bearer 認証が未認証リクエストを拒否している）。
#   2. 未定義パスへの GET が HTTP 404 を返す（ルーティングと JSON エラー応答）。
#
# 確認できるのはここまで。Authorization ヘッダーが .htaccess の Rewrite で PHP
# まで転送されているか、実 OpenAI 解析が通るかは、正しい Bearer API key を使う
# 認証済み確認（README「疎通確認」節）で別途行う。
#
# 使い方:
#   bin/check-deploy-connectivity.sh <base-url>
#   DEPLOY_BASE_URL=<base-url> bin/check-deploy-connectivity.sh
#
# GitHub Actions・ローカルシェル・本番ホスト上の SSH セッションのいずれからも
# 再利用できる。依存は curl だけ。

set -euo pipefail

BASE_URL="${1:-${DEPLOY_BASE_URL:-}}"

if [ -z "${BASE_URL}" ]; then
    echo "Usage: $0 <base-url> (or set DEPLOY_BASE_URL)" >&2
    exit 1
fi

BASE_URL="${BASE_URL%/}"
FAILED=0

check() {
    local method="$1" path="$2" expected="$3"
    local status
    # 転送全体に上限を課す。接続後に応答が返らない場合でも有限時間で
    # 失敗として完了し、呼び出し側（deploy.yaml の後続 rollback step など）が
    # 進めるようにする。curl は失敗時に %{http_code} として 000 を出力する。
    status="$(curl -sS -o /dev/null -w '%{http_code}' \
        --connect-timeout 10 --max-time 20 \
        -X "${method}" "${BASE_URL}${path}" || true)"

    if [ "${status}" = "${expected}" ]; then
        echo "OK  ${status} ${method} ${path}"
    else
        echo "NG  ${status} (expected ${expected}) ${method} ${path}" >&2
        FAILED=1
    fi
}

check POST /v1/analyses 401
check GET /v1/does-not-exist 404

if [ "${FAILED}" -ne 0 ]; then
    echo "Unauthenticated connectivity check failed." >&2
    exit 1
fi

echo "Unauthenticated connectivity check succeeded."
