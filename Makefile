.PHONY: help install build clean test appstore docker-up docker-down docker-logs

# Default target
help:
	@echo "Nextcloud Time Bank - Available Commands:"
	@echo ""
	@echo "  make install       - Install PHP and Node.js dependencies"
	@echo "  make build         - Build frontend assets"
	@echo "  make watch         - Watch and rebuild frontend on changes"
	@echo "  make clean         - Clean build artifacts"
	@echo "  make test          - Run PHP unit tests"
	@echo "  make lint          - Run PHP linter"
	@echo "  make cs-check      - Check code style"
	@echo "  make cs-fix        - Fix code style issues"
	@echo "  make psalm         - Run static analysis"
	@echo "  make appstore      - Build app package for Nextcloud App Store"
	@echo "  make docker-up     - Start Docker development environment"
	@echo "  make docker-down   - Stop Docker development environment"
	@echo "  make docker-logs   - View Docker logs"
	@echo "  make docker-shell  - Open shell in Nextcloud container"
	@echo ""

# Install dependencies
install:
	@echo "Installing Composer dependencies..."
	composer install
	@echo "Installing Node.js dependencies..."
	npm install
	@echo "Dependencies installed successfully!"

# Build frontend
build:
	@echo "Building frontend assets..."
	npm run build
	@echo "Build complete!"

# Watch mode for development
watch:
	@echo "Starting watch mode..."
	npm run watch

# Clean build artifacts
clean:
	@echo "Cleaning build artifacts..."
	rm -rf js/
	rm -rf node_modules/
	rm -rf vendor/
	rm -rf build/
	@echo "Clean complete!"

# Run tests
test:
	@echo "Running PHPUnit tests..."
	composer test:unit

# Lint PHP files
lint:
	@echo "Linting PHP files..."
	composer lint

# Check code style
cs-check:
	@echo "Checking code style..."
	composer cs:check

# Fix code style
cs-fix:
	@echo "Fixing code style..."
	composer cs:fix

# Run Psalm static analysis
psalm:
	@echo "Running Psalm static analysis..."
	composer psalm

# Build app package for App Store
appstore:
	@echo "Building app package..."
	@mkdir -p build
	@rm -rf build/timebank
	@rsync -av \
		--exclude='.git' \
		--exclude='build' \
		--exclude='node_modules' \
		--exclude='vendor-bin' \
		--exclude='tests' \
		--exclude='src' \
		--exclude='.github' \
		--exclude='*.log' \
		--exclude='package*.json' \
		--exclude='tsconfig.json' \
		--exclude='vite.config.ts' \
		--exclude='.env*' \
		--exclude='docker-compose.yml' \
		--exclude='Dockerfile' \
		--exclude='Makefile' \
		./ build/timebank/
	@cd build && tar czf timebank.tar.gz timebank
	@echo "App package created: build/timebank.tar.gz"

# Docker commands
docker-up:
	@echo "Starting Docker environment..."
	@if [ ! -f .env ]; then \
		echo "Creating .env file from .env.example..."; \
		cp .env.example .env; \
		echo "Please update .env with your configuration!"; \
	fi
	docker-compose up -d
	@echo "Docker environment started!"
	@echo "Nextcloud will be available at http://localhost:8080"

docker-down:
	@echo "Stopping Docker environment..."
	docker-compose down

docker-logs:
	docker-compose logs -f nextcloud

docker-shell:
	docker-compose exec nextcloud bash

docker-rebuild:
	@echo "Rebuilding Docker containers..."
	docker-compose down
	docker-compose build --no-cache
	docker-compose up -d
	@echo "Docker containers rebuilt and started!"

# Install app in running Nextcloud container
docker-install-app:
	@echo "Installing Time Bank app in Nextcloud..."
	docker-compose exec -u www-data nextcloud php occ app:enable timebank
	@echo "Time Bank app enabled!"

# Development workflow
dev: install build docker-up
	@echo "Development environment ready!"
	@echo "Access Nextcloud at http://localhost:8080"
