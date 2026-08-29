# 開発用（compose.yaml）のCompose project名。固定せず、Composeが解決した値を
# そのまま読み取る（compose.yaml に name: が無いため、チェックアウト先の
# ディレクトリ名から導出される）。docker compose config はdaemonを必要としない。
DEV_PROJECT := $(shell docker compose config 2>/dev/null \
	| awk -F': *' '/^name:/{print $$2; exit}')

# チェックアウトを識別する短いhash。絶対パスをGitのhash-objectへ通して得る。
# 同じチェックアウトなら常に同じ値になるため、make check-clean でも同じ検証用
# project名を再現できる。gitはリポジトリ取得に既に使用しているため追加依存は
# 発生せず、macOS・Linuxの両方で同じ値が得られる。
CHECKOUT_ID := $(shell printf '%s' '$(CURDIR)' | git hash-object --stdin | cut -c1-8)

# 検証用（make check）のCompose project名。
#
# 開発用のproject名はチェックアウト先のディレクトリ名から導出されるため、検証用を
# 固定値や「開発用 + 接尾辞」にすると、別チェックアウトの開発用project名と一致し
# うる。例えばディレクトリ foo の検証用 foo-check は、ディレクトリ foo-check の
# 開発用project名と同じである。その場合、cleanupの down --volumes が別チェック
# アウトの開発用container・network・volumeを削除してしまう。
#
# そこで、ディレクトリ名そのものではなくチェックアウト固有のhashを名前へ含める。
# これによりディレクトリ名に依存せず、他のチェックアウトの開発用project名と
# 一致しない。
CHECK_PROJECT := journalingpostserver-check-$(CHECKOUT_ID)

# 検証専用のCompose構成（compose.check.yaml）を、検証専用のproject名・
# --env-file .env.example で実行する。Composeの変数展開にも実.envを使わない。
CHECK_COMPOSE = docker compose -f compose.check.yaml -p $(CHECK_PROJECT) --env-file .env.example

.PHONY: check check-clean guard-check-project

# 検証用projectが開発用projectと同一になり得ないことを、down --volumes を
# 実行するターゲットの前に必ず確認する。開発用project名を解決できなかった
# 場合も、意図しないproject名で操作しないようここで停止する。
guard-check-project:
	@if [ -z "$(CHECKOUT_ID)" ]; then \
		echo "[中止] チェックアウト識別子を生成できませんでした（gitが必要です）。" >&2; \
		exit 1; \
	fi
	@if [ -z "$(DEV_PROJECT)" ]; then \
		echo "[中止] 開発用Compose project名を解決できませんでした。" >&2; \
		exit 1; \
	fi
	@if [ "$(CHECK_PROJECT)" = "$(DEV_PROJECT)" ]; then \
		echo "[中止] 検証用project名が開発用project名と一致しています: $(CHECK_PROJECT)" >&2; \
		exit 1; \
	fi

# 開発用のcontainer・network・volume・host portには一切触れず、
# compose.check.yaml を検証専用のCompose project ($(CHECK_PROJECT)) で
# 実行する。成功・失敗にかかわらず、最後に検証専用projectだけをcleanupする。
check: guard-check-project
	trap '$(CHECK_COMPOSE) down --volumes --remove-orphans' EXIT; \
	$(CHECK_COMPOSE) build app && \
	$(CHECK_COMPOSE) run --rm --no-deps app composer validate && \
	$(CHECK_COMPOSE) run --rm --no-deps app composer install --prefer-dist --no-progress && \
	$(CHECK_COMPOSE) run --rm --no-deps app composer lint && \
	$(CHECK_COMPOSE) run --rm --no-deps app composer style && \
	$(CHECK_COMPOSE) run --rm app composer migrate && \
	$(CHECK_COMPOSE) run --rm app composer test

# make check が失敗などで異常終了しtrapが働かなかった場合の手動cleanup用。
# 検証専用projectだけを対象とし、開発用のcontainer・network・volumeには
# 触れない。
check-clean: guard-check-project
	$(CHECK_COMPOSE) down --volumes --remove-orphans
