.PHONY: help install build up down restart logs shell test security-check backup restore deploy clean cache-clear migrate

# Colors
GREEN  := $(shell tput -Txterm setaf 2)
YELLOW := $(shell tput -Txterm setaf 3)
WHITE  := $(shell tput -Txterm setaf 7)
RESET  := $(shell tput -Txterm sgr0)

# Variables
DOCKER_COMPOSE = docker-compose
DOCKER_COMPOSE_PROD = docker-compose -f docker-compose.prod.yml
PHP_CONTAINER = crypto_php
MYSQL_CONTAINER = crypto_mysql

help: ## Show this help
	@echo ''
	@echo 'Usage:'
	@echo '  ${YELLOW}make${RESET} ${GREEN}<target>${RESET}'
	@echo ''
	@echo 'Targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  ${YELLOW}%-20s${RESET} %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: ## Install dependencies and setup project
	@echo "${GREEN}Installing dependencies...${RESET}"
	$(DOCKER_COMPOSE) run --rm php composer install
	@echo "${GREEN}Running migrations...${RESET}"
	$(DOCKER_COMPOSE) run --rm php bin/console doctrine:migrations:migrate --no-interaction
	@echo "${GREEN}Creating JWT keys...${RESET}"
	$(DOCKER_COMPOSE) run --rm php bin/console lexik:jwt:generate-keypair --skip-if-exists
	@echo "${GREEN}Warming up cache...${RESET}"
	$(DOCKER_COMPOSE) run --rm php bin/console cache:warmup
	@echo "${GREEN}Installation complete!${RESET}"

build: ## Build docker containers
	@echo "${GREEN}Building containers...${RESET}"
	$(DOCKER_COMPOSE) build --no-cache

build-prod: ## Build production containers
	@echo "${GREEN}Building production containers...${RESET}"
	$(DOCKER_COMPOSE_PROD) build --no-cache

up: ## Start containers
	@echo "${GREEN}Starting containers...${RESET}"
	$(DOCKER_COMPOSE) up -d
	@echo "${GREEN}Containers started!${RESET}"
	@echo "Application: http://localhost"
	@echo "RabbitMQ: http://localhost:15672"

up-prod: ## Start production containers
	@echo "${GREEN}Starting production containers...${RESET}"
	$(DOCKER_COMPOSE_PROD) up -d

down: ## Stop containers
	@echo "${YELLOW}Stopping containers...${RESET}"
	$(DOCKER_COMPOSE) down

down-prod: ## Stop production containers
	@echo "${YELLOW}Stopping production containers...${RESET}"
	$(DOCKER_COMPOSE_PROD) down

restart: down up ## Restart containers

logs: ## View logs
	$(DOCKER_COMPOSE) logs -f

logs-prod: ## View production logs
	$(DOCKER_COMPOSE_PROD) logs -f

shell: ## Access PHP container shell
	$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) sh

mysql: ## Access MySQL shell
	$(DOCKER_COMPOSE) exec $(MYSQL_CONTAINER) mysql -u$(DB_USER) -p$(DB_PASS) $(DB_NAME)

test: ## Run tests
	@echo "${GREEN}Running tests...${RESET}"
	$(DOCKER_COMPOSE) run --rm php bin/phpunit

test-coverage: ## Run tests with coverage
	@echo "${GREEN}Running tests with coverage...${RESET}"
	$(DOCKER_COMPOSE) run --rm php bin/phpunit --coverage-html var/coverage

security-check: ## Run security checks
	@echo "${GREEN}Running security checks...${RESET}"
	$(DOCKER_COMPOSE) run --rm php composer audit
	$(DOCKER_COMPOSE) run --rm php bin/console security:check
	$(DOCKER_COMPOSE) run --rm php vendor/bin/psalm

cs-fix: ## Fix code style
	@echo "${GREEN}Fixing code style...${RESET}"
	$(DOCKER_COMPOSE) run --rm php vendor/bin/php-cs-fixer fix

backup: ## Backup database
	@echo "${GREEN}Creating backup...${RESET}"
	./bin/backup.sh

restore: ## Restore database
	@echo "${YELLOW}Restoring database...${RESET}"
	./bin/restore.sh

deploy: ## Deploy to production
	@echo "${GREEN}Deploying to production...${RESET}"
	./bin/deploy.sh

cache-clear: ## Clear cache
	@echo "${GREEN}Clearing cache...${RESET}"
	$(DOCKER_COMPOSE) run --rm php bin/console cache:clear

cache-clear-prod: ## Clear production cache
	@echo "${GREEN}Clearing production cache...${RESET}"
	$(DOCKER_COMPOSE) run --rm php bin/console cache:clear --env=prod

migrate: ## Run migrations
	@echo "${GREEN}Running migrations...${RESET}"
	$(DOCKER_COMPOSE) run --rm php bin/console doctrine:migrations:migrate --no-interaction

migrate-diff: ## Generate migration diff
	@echo "${GREEN}Generating migration...${RESET}"
	$(DOCKER_COMPOSE) run --rm php bin/console doctrine:migrations:diff

migrate-status: ## Check migration status
	$(DOCKER_COMPOSE) run --rm php bin/console doctrine:migrations:status

fixtures: ## Load fixtures
	@echo "${GREEN}Loading fixtures...${RESET}"
	$(DOCKER_COMPOSE) run --rm php bin/console doctrine:fixtures:load --no-interaction

messenger-consume: ## Consume messages
	$(DOCKER_COMPOSE) run --rm php bin/console messenger:consume async blockchain notifications -vv

telegram-webhook: ## Set Telegram webhook
	@echo "${GREEN}Setting Telegram webhook...${RESET}"
	$(DOCKER_COMPOSE) run --rm php bin/console telegram:webhook:set

telegram-info: ## Get Telegram webhook info
	$(DOCKER_COMPOSE) run --rm php bin/console telegram:webhook:info

bonus-calculate: ## Calculate daily bonuses
	@echo "${GREEN}Calculating bonuses...${RESET}"
	$(DOCKER_COMPOSE) run --rm php bin/console app:process-bonuses $(PROFIT)

clean: ## Clean all generated files
	@echo "${YELLOW}Cleaning...${RESET}"
	rm -rf var/cache/* var/log/*
	rm -rf vendor/
	rm -rf node_modules/

install-prod: ## Production installation
	@echo "${GREEN}Installing production...${RESET}"
	$(DOCKER_COMPOSE_PROD) run --rm php composer install --no-dev --optimize-autoloader
	$(DOCKER_COMPOSE_PROD) run --rm php bin/console doctrine:migrations:migrate --no-interaction
	$(DOCKER_COMPOSE_PROD) run --rm php bin/console cache:warmup --env=prod

update: ## Update dependencies
	@echo "${GREEN}Updating dependencies...${RESET}"
	$(DOCKER_COMPOSE) run --rm php composer update

update-prod: ## Update production dependencies
	@echo "${GREEN}Updating production dependencies...${RESET}"
	$(DOCKER_COMPOSE_PROD) run --rm php composer update --no-dev --optimize-autoloader

validate: ## Validate project
	@echo "${GREEN}Validating project...${RESET}"
	$(DOCKER_COMPOSE) run --rm php composer validate
	$(DOCKER_COMPOSE) run --rm php bin/console lint:yaml config
	$(DOCKER_COMPOSE) run --rm php bin/console lint:twig templates
	$(DOCKER_COMPOSE) run --rm php bin/console doctrine:schema:validate

watch-logs: ## Watch specific service logs
	@read -p "Enter service name (php/nginx/mysql/redis): " service; \
	$(DOCKER_COMPOSE) logs -f $$service

ssh-prod: ## SSH to production server
	ssh -i ~/.ssh/crypto-platform.pem ubuntu@your-server.com

monitor: ## Monitor system resources
	@echo "${GREEN}Monitoring system...${RESET}"
	docker stats --format "table {{.Container}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.NetIO}}\t{{.BlockIO}}"

health-check: ## Check system health
	@echo "${GREEN}Checking system health...${RESET}"
	$(DOCKER_COMPOSE) run --rm php bin/console app:health-check