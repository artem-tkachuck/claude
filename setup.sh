#!/bin/bash

# Crypto Investment Platform Setup Script
# This script sets up the initial environment for the platform

set -e

echo "========================================"
echo "Crypto Investment Platform Setup"
echo "========================================"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Check if running as root
if [[ $EUID -eq 0 ]]; then
   echo -e "${RED}This script should not be run as root!${NC}"
   exit 1
fi

# Check required tools
echo "Checking required tools..."
command -v docker >/dev/null 2>&1 || { echo -e "${RED}Docker is required but not installed.${NC}" >&2; exit 1; }
command -v docker-compose >/dev/null 2>&1 || { echo -e "${RED}Docker Compose is required but not installed.${NC}" >&2; exit 1; }
command -v openssl >/dev/null 2>&1 || { echo -e "${RED}OpenSSL is required but not installed.${NC}" >&2; exit 1; }

# Create necessary directories
echo "Creating directories..."
mkdir -p var/{cache,log,sessions} \
         backups \
         public/uploads \
         config/jwt \
         .docker/{nginx,php,mysql} \
         translations

# Set permissions
chmod -R 775 var
chmod -R 775 public/uploads

# Generate .env file if not exists
if [ ! -f .env ]; then
    echo "Generating .env file..."
    cp .env.example .env

    # Generate secure keys
    APP_SECRET=$(openssl rand -hex 32)
    JWT_PASSPHRASE=$(openssl rand -base64 32)
    DB_PASSWORD=$(openssl rand -base64 24)
    DB_ROOT_PASSWORD=$(openssl rand -base64 24)
    REDIS_PASSWORD=$(openssl rand -base64 24)
    RABBITMQ_PASSWORD=$(openssl rand -base64 24)
    TELEGRAM_WEBHOOK_TOKEN=$(openssl rand -hex 32)
    TELEGRAM_SECRET_TOKEN=$(openssl rand -hex 32)
    ENCRYPTION_KEY=$(openssl rand -base64 32)

    # Update .env with generated values
    sed -i "s/APP_SECRET=.*/APP_SECRET=$APP_SECRET/" .env
    sed -i "s/JWT_PASSPHRASE=.*/JWT_PASSPHRASE=$JWT_PASSPHRASE/" .env
    sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=$DB_PASSWORD/" .env
    sed -i "s/DB_ROOT_PASSWORD=.*/DB_ROOT_PASSWORD=$DB_ROOT_PASSWORD/" .env
    sed -i "s/REDIS_PASSWORD=.*/REDIS_PASSWORD=$REDIS_PASSWORD/" .env
    sed -i "s/RABBITMQ_PASSWORD=.*/RABBITMQ_PASSWORD=$RABBITMQ_PASSWORD/" .env
    sed -i "s/TELEGRAM_WEBHOOK_TOKEN=.*/TELEGRAM_WEBHOOK_TOKEN=$TELEGRAM_WEBHOOK_TOKEN/" .env
    sed -i "s/TELEGRAM_SECRET_TOKEN=.*/TELEGRAM_SECRET_TOKEN=$TELEGRAM_SECRET_TOKEN/" .env
    sed -i "s/ENCRYPTION_KEY=.*/ENCRYPTION_KEY=$ENCRYPTION_KEY/" .env

    echo -e "${GREEN}Generated secure passwords and keys${NC}"
else
    echo -e "${YELLOW}.env file already exists, skipping generation${NC}"
fi

# Generate JWT keys
if [ ! -f config/jwt/private.pem ]; then
    echo "Generating JWT keys..."
    openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096 -pass pass:$(grep JWT_PASSPHRASE .env | cut -d '=' -f2)
    openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:$(grep JWT_PASSPHRASE .env | cut -d '=' -f2)
    chmod 644 config/jwt/public.pem
    chmod 600 config/jwt/private.pem
    echo -e "${GREEN}JWT keys generated${NC}"
else
    echo -e "${YELLOW}JWT keys already exist, skipping generation${NC}"
fi

# Create admin .htpasswd for nginx
if [ ! -f .docker/nginx/.htpasswd ]; then
    echo "Creating admin authentication..."
    read -p "Enter admin username for web interface: " admin_user
    htpasswd -c .docker/nginx/.htpasswd $admin_user
    echo -e "${GREEN}Admin authentication created${NC}"
fi

# Build and start containers
echo "Building Docker containers..."
docker-compose -f docker-compose.yml build

echo "Starting services..."
docker-compose up -d

# Wait for MySQL to be ready
echo "Waiting for MySQL to be ready..."
until docker-compose exec -T mysql mysql -u root -p$(grep DB_ROOT_PASSWORD .env | cut -d '=' -f2) -e "SELECT 1" >/dev/null 2>&1; do
    echo -n "."
    sleep 2
done
echo -e "\n${GREEN}MySQL is ready${NC}"

# Install dependencies
echo "Installing PHP dependencies..."
docker-compose exec -T php composer install --no-dev --optimize-autoloader

# Run database migrations
echo "Running database migrations..."
docker-compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction

# Clear and warm up cache
echo "Clearing cache..."
docker-compose exec -T php php bin/console cache:clear --env=prod --no-debug
docker-compose exec -T php php bin/console cache:warmup --env=prod --no-debug

# Install assets
echo "Installing assets..."
docker-compose exec -T php php bin/console assets:install public --symlink

# Create first admin user
echo "Creating admin user..."
read -p "Enter admin email: " admin_email
read -p "Enter admin Telegram username (without @): " admin_telegram
docker-compose exec -T php php bin/console app:create-admin "$admin_email" "$admin_telegram"

# Setup cron jobs
echo "Setting up cron jobs..."
cat > /tmp/crypto-cron << EOF
# Process bonuses daily at 00:00
0 0 * * * cd $(pwd) && docker-compose exec -T php php bin/console app:process-bonuses --notify >> var/log/cron.log 2>&1

# Check deposits every 5 minutes
*/5 * * * * cd $(pwd) && docker-compose exec -T php php bin/console app:check-deposits >> var/log/cron.log 2>&1

# Process withdrawals every 10 minutes
*/10 * * * * cd $(pwd) && docker-compose exec -T php php bin/console app:process-withdrawals >> var/log/cron.log 2>&1

# Cleanup old logs weekly
0 3 * * 0 cd $(pwd) && docker-compose exec -T php php bin/console app:cleanup --all >> var/log/cron.log 2>&1

# Generate daily report
0 1 * * * cd $(pwd) && docker-compose exec -T php php bin/console app:generate-report daily >> var/log/cron.log 2>&1
EOF

crontab /tmp/crypto-cron
rm /tmp/crypto-cron
echo -e "${GREEN}Cron jobs installed${NC}"

# Show status
echo -e "\n${GREEN}========================================"
echo "Setup completed successfully!"
echo "========================================${NC}"
echo ""
echo "Services status:"
docker-compose ps
echo ""
echo -e "${YELLOW}Important information:${NC}"
echo "1. Update your domain in .docker/nginx/default.conf"
echo "2. Configure Telegram bot token in .env"
echo "3. Set up SSL certificate with: docker-compose run --rm certbot certonly --webroot --webroot-path=/var/www/certbot -d your-domain.com"
echo "4. Configure webhook URL: https://your-domain.com/telegram/webhook/$(grep TELEGRAM_WEBHOOK_TOKEN .env | cut -d '=' -f2)"
echo ""
echo -e "${GREEN}Platform is ready to use!${NC}"