.DEFAULT_GOAL = help
.PHONY        : *

help:
	@grep -E '(^[a-zA-Z_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "; printed=0} /^##/ { if (printed) printf "\n"; gsub(/^##[[:space:]]*/, "", $$0); printf "\033[33m%s\033[0m\n", $$0; printed=1; next } { printf "\033[32m%-30s\033[0m %s\n", $$1, $$2 }'

## —— Quality ————————————————————————————————————————————————————————————
test: cs-check stan phpunit ## Run the full local quality gate

cs-check: ## Check code style
	@php vendor/bin/php-cs-fixer check --diff --verbose

cs-fix: ## Fix code style
	@php vendor/bin/php-cs-fixer fix --verbose

stan: ## Run PHPStan
	@php vendor/bin/phpstan analyse --configuration phpstan.neon.dist --memory-limit 512M --debug

phpunit: ## Run PHPUnit
	@php -d memory_limit=512M vendor/bin/phpunit --configuration phpunit.xml.dist

smoke: ## Install into bare skeleton, Twig-only skeleton, and webapp projects
	@./scripts/smoke-test.sh skeleton
	@./scripts/smoke-test.sh skeleton-twig
	@./scripts/smoke-test.sh webapp

preview: ## Rebuild and open a generated application preview
	@./scripts/preview.sh

audit: ## Audit locked Composer dependencies
	@composer audit --locked
