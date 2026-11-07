.PHONY: test qa qa-check ci docs docs-build

ZENSICAL = uvx zensical@0.1.0

test: ## Unit and functional tests
	@composer test

qa: ## Rector, the fixer, the linters, the dependency analyser, PHPStan and the tests; modifies files in place
	@composer qa

qa-check: ## The same gates, writing nothing; safe to run while editing
	@composer qa:check

ci: ## The GitHub Actions CI workflow through act, every job on a clean snapshot; JOB=tests runs one
	@.github/act/run.sh $(JOB)

docs: ## The documentation site with live reload on http://127.0.0.1:8002
	@$(ZENSICAL) serve

docs-build: ## The documentation site in var/site; fails on a broken link or anchor
	@$(ZENSICAL) build --clean --strict
