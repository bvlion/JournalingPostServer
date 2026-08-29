# 開発用（compose.yaml）のCompose project名。固定せず、Composeが解決した値を
# そのまま読み取る（compose.yaml に name: が無いため、チェックアウト先の
# ディレクトリ名から導出される）。docker compose config はdaemonを必要としない。
DEV_PROJECT := $(shell docker compose config 2>/dev/null \
	| awk -F': *' '/^name:/{print $$2; exit}')

# 検証用（make check）のCompose project名。
#
# 固定値にすると、チェックアウト先のディレクトリ名がその固定値と一致したときに
# 検証用と開発用が同じprojectになり、cleanupの down --volumes が開発用の
# container・network・volumeを削除してしまう。そのため固定せず、開発用の
# project名へ接尾辞を付けて導出する。接尾辞は必ず1文字以上あるので、開発用と
# 検証用が同じ名前になることはない。
CHECK_PROJECT := $(DEV_PROJECT)-check

# 検証専用のCompose構成（compose.check.yaml）を、検証専用のproject名・
# --env-file .env.example で実行する。Composeの変数展開にも実.envを使わない。
CHECK_COMPOSE = docker compose -f compose.check.yaml -p $(CHECK_PROJECT) --env-file .env.example

.PHONY: check check-clean guard-check-project

# 検証用projectが開発用projectと同一になり得ないことを、down --volumes を
# 実行するターゲットの前に必ず確認する。開発用project名を解決できなかった
# 場合も、意図しないproject名で操作しないようここで停止する。
guard-check-project:
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
