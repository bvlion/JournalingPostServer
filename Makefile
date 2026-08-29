# 検証用（make check）専用のCompose project名。開発用（compose.yaml の
# 既定project名 journalingpostserver）とは常に別の名前を使うことで、
# container・network・volume・host portが開発環境と混ざらないようにする。
CHECK_PROJECT = journalingpostserver-check

# 検証専用のCompose構成（compose.check.yaml）を、検証専用のproject名・
# --env-file .env.example で実行する。Composeの変数展開にも実.envを使わない。
CHECK_COMPOSE = docker compose -f compose.check.yaml -p $(CHECK_PROJECT) --env-file .env.example

.PHONY: check check-clean

# 開発用のcontainer・network・volume・host portには一切触れず、
# compose.check.yaml を検証専用のCompose project ($(CHECK_PROJECT)) で
# 実行する。成功・失敗にかかわらず、最後に検証専用projectだけをcleanupする。
check:
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
check-clean:
	$(CHECK_COMPOSE) down --volumes --remove-orphans
