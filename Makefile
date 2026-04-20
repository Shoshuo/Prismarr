DEV_COMPOSE = docker compose --env-file symfony/.env.local -f docker-compose.yml -f docker-compose.dev.yml
COMPOSER    = docker run --rm -v "$(shell pwd)/symfony:/app" composer:2

# Dev (bind mounts live, APP_ENV=dev)
dev:
	$(DEV_COMPOSE) up -d --build

# Prod (image baked)
prod:
	docker compose --env-file symfony/.env.local up -d --build

# Force-recreate the container after env/config change (rebuild included)
restart:
	$(DEV_COMPOSE) up -d --build --force-recreate prismarr

# Full stop
stop:
	docker compose down

# Container logs (FrankenPHP + worker interleaved)
logs:
	docker logs prismarr -f

# Rebuild image without cache
build:
	$(DEV_COMPOSE) build --no-cache

# Install a Composer package
composer:
	$(COMPOSER) $(filter-out $@,$(MAKECMDGOALS))

# Symfony console command inside the container
console:
	docker exec prismarr php bin/console $(filter-out $@,$(MAKECMDGOALS))

# First-boot initialization: create the SQLite DB
init:
	docker exec prismarr mkdir -p var/data
	docker exec prismarr php bin/console doctrine:schema:create --no-interaction

# Run the PHPUnit test suite (full suite — no args)
test:
	docker exec prismarr vendor/bin/phpunit

# Lint all PHP sources (syntax errors only — fast)
lint:
	docker exec prismarr sh -c 'find src tests migrations -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors" || echo "PHP syntax OK"'

# Lint Twig templates
lint-twig:
	docker exec prismarr php bin/console lint:twig templates

# Lint Symfony container + YAML config
lint-container:
	docker exec prismarr php bin/console lint:container
	docker exec prismarr php bin/console lint:yaml config

# Full pre-commit check — run this before every `git commit`.
# Required by CONTRIBUTING.md Definition of Done.
check: lint lint-twig test
	@echo ""
	@echo "✓ make check passed — ready to commit"

# Generate a new Doctrine migration from current entities
migrations-diff:
	docker exec prismarr php bin/console doctrine:migrations:diff

# Show migrations status
migrations-status:
	docker exec prismarr php bin/console doctrine:migrations:status

%:
	@:
