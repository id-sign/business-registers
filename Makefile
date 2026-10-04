PHP_VERSION ?= 8.4
DOCKER_IMAGE = php:$(PHP_VERSION)-cli
DOCKER_RUN = docker run --rm -v $(CURDIR):/app -w /app $(DOCKER_IMAGE)

DOCKER_SETUP = \
	apt-get update -qq && apt-get install -yqq unzip > /dev/null 2>&1 && \
	curl -sS https://getcomposer.org/installer | php -- --quiet && \
	php composer.phar update --no-progress --quiet

.PHONY: test test-live phpstan cs-fix test-matrix

test: ## Run unit tests (PHP_VERSION=8.4)
	$(DOCKER_RUN) bash -c '$(DOCKER_SETUP) && vendor/bin/phpunit'

test-live: ## Run live tests against production registers (PHP_VERSION=8.4)
	$(DOCKER_RUN) bash -c '$(DOCKER_SETUP) && vendor/bin/phpunit --testsuite live'

phpstan: ## Run PHPStan analysis (PHP_VERSION=8.4)
	$(DOCKER_RUN) bash -c '$(DOCKER_SETUP) && vendor/bin/phpstan analyse'

cs-fix: ## Run PHP CS Fixer, dry-run (PHP_VERSION=8.4)
	$(DOCKER_RUN) bash -c '$(DOCKER_SETUP) && vendor/bin/php-cs-fixer fix --dry-run --diff'

test-matrix: ## Run unit tests on PHP 8.4 and 8.5
	@for v in 8.4 8.5; do \
		echo ""; \
		echo "===================================="; \
		echo " PHP $$v"; \
		echo "===================================="; \
		$(MAKE) test PHP_VERSION=$$v || exit 1; \
	done
	@echo ""
	@echo "===================================="
	@echo " All versions passed!"
