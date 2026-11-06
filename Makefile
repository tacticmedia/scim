.PHONY: test qa qa-check ci demo

test: ## Unit and functional tests
	@composer test

qa: ## Rector, the fixer, the linters, the dependency analyser, PHPStan and the tests; modifies files in place
	@composer qa

qa-check: ## The same gates, writing nothing; safe to run while editing
	@composer qa:check

ci: ## The GitHub Actions CI workflow through act, every job on a clean snapshot; JOB=tests runs one
	@.github/act/run.sh $(JOB)

demo: ## The demo apps with new databases: the service provider on port 8001, the identity provider on port 8000; Ctrl-C stops both
	@rm -f demo/service-provider/var/service-provider.sqlite demo/identity-provider/var/identity-provider.sqlite
	@php demo/service-provider/bin/console doctrine:schema:create --quiet
	@php demo/identity-provider/bin/console doctrine:schema:create --quiet
	@php demo/identity-provider/bin/console idp:seed --quiet
	@# Ctrl-C stops the servers directly. The trap stops them when only the shell gets a signal, such as the SIGTERM that make forwards.
	@trap 'kill $$sp $$idp 2>/dev/null' INT TERM EXIT; \
	php -S 127.0.0.1:8001 -t demo/service-provider/public & sp=$$!; \
	php -S 127.0.0.1:8000 -t demo/identity-provider/public & idp=$$!; \
	echo 'Service provider:  http://127.0.0.1:8001 (SCIM base URL http://127.0.0.1:8001/scim/v2, token demo-token)'; \
	echo 'Identity provider: http://127.0.0.1:8000'; \
	echo 'Ctrl-C stops both.'; \
	wait
