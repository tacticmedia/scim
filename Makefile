.PHONY: test qa qa-check ci

test: ## Unit and functional tests
	@composer test

qa: ## Rector, the fixer, the linters, the dependency analyser, PHPStan and the tests; modifies files in place
	@composer qa

qa-check: ## The same gates, writing nothing; safe to run while editing
	@composer qa:check

ci: ## The GitHub Actions CI workflow through act, every job on a clean snapshot; JOB=tests runs one
	@.github/act/run.sh $(JOB)
