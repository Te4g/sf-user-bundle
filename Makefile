.DEFAULT_GOAL = help
.PHONY        : *

help:
	@grep -E '(^[a-zA-Z_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "; printed=0} /^##/ { if (printed) printf "\n"; gsub(/^##[[:space:]]*/, "", $$0); printf "\033[33m%s\033[0m\n", $$0; printed=1; next } { printf "\033[32m%-30s\033[0m %s\n", $$1, $$2 }'

## —— Quality ————————————————————————————————————————————————————————————
test: cs-check stan phpunit twig-check yaml-check assets-check ## Run the full local quality gate

cs-check: ## Check code style
	@php vendor/bin/php-cs-fixer check --diff --verbose

cs-fix: ## Fix code style
	@php vendor/bin/php-cs-fixer fix --verbose

stan: ## Run PHPStan
	@php vendor/bin/phpstan analyse --configuration phpstan.neon.dist --memory-limit 512M --debug

phpunit: ## Run PHPUnit
	@php vendor/bin/phpunit --configuration phpunit.xml.dist

twig-check: ## Verify generated Twig and Shadcn templates
	@php vendor/bin/phpunit tests/Integration/ShadcnSkeletonTest.php

yaml-check: ## Verify the generated Symfony configuration
	@php vendor/bin/phpunit tests/Integration/GeneratedSkeletonTest.php

assets-check: ## Build generated assets and verify locked icons
	@php vendor/bin/phpunit tests/Integration/GeneratedShadcnApplicationTest.php --filter testGeneratedApplicationBuildsOfflineAndRendersAccessibleAssetBackedForms

smoke: ## Install into fresh skeleton and webapp projects
	@./scripts/smoke-test.sh skeleton
	@./scripts/smoke-test.sh webapp

audit: ## Audit locked Composer dependencies
	@composer audit --locked
