#!/bin/bash
set -e

echo "🚀 Starting deployment..."

# Pull latest code
git pull origin main

# Build containers
docker-compose -f docker-compose.prod.yml build

# Stop services
docker-compose -f docker-compose.prod.yml down

# Start database and redis first
docker-compose -f docker-compose.prod.yml up -d mysql redis

# Wait for database
echo "Waiting for database..."
sleep 10

# Run migrations
docker-compose -f docker-compose.prod.yml run --rm php bin/console doctrine:migrations:migrate --no-interaction

# Clear cache
docker-compose -f docker-compose.prod.yml run --rm php bin/console cache:clear --env=prod

# Start all services
docker-compose -f docker-compose.prod.yml up -d

# Set up cron jobs
echo "Setting up cron jobs..."
(crontab -l 2>/dev/null; echo "0 0 * * * cd /var/www && docker-compose -f docker-compose.prod.yml run --rm php bin/console app:process-bonuses \$(cat /var/www/daily_profit.txt)") | crontab -

echo "✅ Deployment complete!"