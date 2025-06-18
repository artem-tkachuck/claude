# 🚀 Crypto Investment Platform

Enterprise-grade cryptocurrency investment platform with Telegram bot integration, built with maximum security and
scalability in mind.

## 📋 Table of Contents

- [Features](#-features)
- [Architecture](#-architecture)
- [Requirements](#-requirements)
- [Installation](#-installation)
- [Configuration](#-configuration)
- [Usage](#-usage)
- [Security](#-security)
- [API Documentation](#-api-documentation)
- [Testing](#-testing)
- [Deployment](#-deployment)
- [Monitoring](#-monitoring)
- [Troubleshooting](#-troubleshooting)
- [Contributing](#-contributing)
- [License](#-license)

## 🎯 Features

### Core Functionality

- **💰 Cryptocurrency Deposits**: Secure USDT (TRC-20) deposits with automatic tracking
- **📊 Daily Bonus Distribution**: Automated profit sharing based on deposit amounts
- **🤝 Referral System**: Multi-level referral program with configurable rewards
- **🔒 Secure Withdrawals**: Multi-signature approval system for withdrawals
- **🤖 Telegram Bot**: Full-featured bot for account management
- **🌐 Web Interface**: Admin panel and user dashboard
- **🌍 Multi-language**: Support for English, Ukrainian, and Russian

### Security Features

- **🔐 2FA Authentication**: Two-factor authentication for admins and withdrawals
- **🛡️ Cold/Hot Wallet Separation**: Automatic fund management
- **🚫 Anti-fraud System**: Real-time suspicious activity detection
- **🌏 Geo-blocking**: Country-based access control
- **📝 Complete Audit Trail**: All actions logged and traceable
- **🔑 Encrypted Sensitive Data**: AES-256-GCM encryption at rest

## 🏗️ Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        Load Balancer                            │
└─────────────────────────────────────────────────────────────────┘
                                 │
┌─────────────────────────────────────────────────────────────────┐
│                          Nginx (SSL)                            │
└─────────────────────────────────────────────────────────────────┘
                                 │
┌─────────────────────────────────────────────────────────────────┐
│                      PHP-FPM (Symfony 7.3)                      │
└─────────────────────────────────────────────────────────────────┘
         │                       │                      │
┌──────────────┐      ┌──────────────┐      ┌──────────────────┐
│    MySQL     │      │    Redis     │      │    RabbitMQ      │
└──────────────┘      └──────────────┘      └──────────────────┘
```

### Technology Stack

- **Backend**: PHP 8.2, Symfony 7.3
- **Database**: MySQL 8.0
- **Cache**: Redis 7
- **Queue**: RabbitMQ 3
- **Blockchain**: TRON (TRC-20)
- **Bot**: Telegram Bot API
- **Containerization**: Docker & Docker Compose

## 📋 Requirements

### System Requirements

- Ubuntu 22.04 LTS (recommended)
- Docker 20.10+
- Docker Compose 2.0+
- 4GB RAM minimum (8GB recommended)
- 20GB free disk space
- SSL certificate (Let's Encrypt)

### External Services

- Telegram Bot Token
- TRON API Key
- SMTP Server (for emails)
- Domain name with SSL

## 🚀 Installation

### 1. Clone Repository

```bash
git clone https://github.com/your-repo/crypto-investment.git
cd crypto-investment
```

### 2. Configure Environment

```bash
cp .env.example .env
# Edit .env with your configuration
nano .env
```

### 3. Generate Security Keys

```bash
# Encryption key
openssl rand -base64 32 > encryption.key

# JWT keys
mkdir -p config/jwt
openssl genrsa -out config/jwt/private.pem 4096
openssl rsa -in config/jwt/private.pem -pubout -out config/jwt/public.pem
```

### 4. Build and Start Services

```bash
make build
make up
make install
```

### 5. Set Up Telegram Webhook

```bash
make telegram-webhook
```

### 6. Create Admin User

```bash
docker-compose exec php bin/console app:create-admin
```

## ⚙️ Configuration

### Essential Environment Variables

| Variable              | Description      | Example                           |
|-----------------------|------------------|-----------------------------------|
| `APP_ENV`             | Environment      | `prod`                            |
| `APP_SECRET`          | Symfony secret   | 32-char random string             |
| `DATABASE_URL`        | MySQL connection | `mysql://user:pass@mysql:3306/db` |
| `TELEGRAM_BOT_TOKEN`  | Bot token        | `123456:ABC-DEF...`               |
| `TRON_API_KEY`        | TRON API key     | Your API key                      |
| `HOT_WALLET_ADDRESS`  | Hot wallet       | `TYour...`                        |
| `COLD_WALLET_ADDRESS` | Cold wallet      | `TYour...`                        |

### Security Configuration

```yaml
# config/packages/security.yaml
security:
  firewalls:
    api:
      pattern: ^/api
      stateless: true
      custom_authenticators:
        - App\Security\TelegramAuthenticator
```

## 📱 Usage

### Telegram Bot Commands

#### User Commands

- `/start` - Start bot and register
- `/balance` - Check balance
- `/deposit` - Get deposit instructions
- `/withdraw` - Request withdrawal
- `/referral` - Get referral link
- `/language` - Change language
- `/help` - Show help

#### Admin Commands

- `/stats` - System statistics
- `/users` - User management
- `/broadcast` - Send announcement
- `/maintenance` - Toggle maintenance mode

### Web Interface

#### Admin Panel

Access at: `https://your-domain.com/admin`

Features:

- Dashboard with real-time statistics
- User management
- Transaction monitoring
- Withdrawal approvals
- System settings
- Security logs

#### API Endpoints

```bash
# Authentication
POST /api/auth/telegram
POST /api/auth/refresh

# User Operations
GET  /api/balance
POST /api/deposit
POST /api/withdrawal
GET  /api/transactions

# Admin Operations
GET  /api/admin/users
POST /api/admin/withdrawal/{id}/approve
GET  /api/admin/stats
```

## 🔒 Security

### Security Measures

1. **Infrastructure Security**
    - SSL/TLS encryption
    - Firewall rules
    - DDoS protection
    - Rate limiting

2. **Application Security**
    - Input validation
    - SQL injection prevention
    - XSS protection
    - CSRF tokens

3. **Blockchain Security**
    - Address validation
    - Transaction verification
    - Multi-signature withdrawals
    - Cold storage

### Security Checklist

- [ ] Change all default passwords
- [ ] Configure firewall rules
- [ ] Enable 2FA for admins
- [ ] Set up SSL certificates
- [ ] Configure backup system
- [ ] Enable monitoring alerts
- [ ] Review security logs regularly

## 🧪 Testing

### Run Tests

```bash
# All tests
make test

# Unit tests only
docker-compose exec php bin/phpunit --testsuite=Unit

# With coverage
make test-coverage
```

### Code Quality

```bash
# PHPStan analysis
docker-compose exec php vendor/bin/phpstan analyse

# Psalm analysis
docker-compose exec php vendor/bin/psalm

# Code style
make cs-fix
```

## 🚀 Deployment

### Production Deployment

1. **Prepare Server**

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install Docker
curl -fsSL https://get.docker.com | sh

# Configure firewall
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

2. **Deploy Application**

```bash
# Clone and configure
git clone <repository>
cd crypto-investment
cp .env.example .env.prod

# Build and start
make build-prod
make up-prod
make install-prod
```

3. **Configure SSL**

```bash
# Install Certbot
sudo apt install certbot python3-certbot-nginx

# Get certificate
sudo certbot --nginx -d your-domain.com
```

### Scaling

For high load, consider:

- Load balancer (HAProxy/Nginx)
- Multiple PHP-FPM instances
- MySQL replication
- Redis cluster
- Kubernetes deployment

## 📊 Monitoring

### Health Checks

```bash
# System health
make health-check

# Service status
docker-compose ps

# Resource usage
make monitor
```

### Logging

Logs are stored in:

- Application: `var/log/prod.log`
- Nginx: `var/log/nginx/`
- Security: `var/log/security.log`
- Transactions: `var/log/transactions.log`

### Metrics

Monitor these key metrics:

- Transaction success rate
- API response time
- Queue length
- Database performance
- Blockchain sync status

## 🔧 Troubleshooting

### Common Issues

#### Bot Not Responding

```bash
# Check webhook
make telegram-info

# Check logs
docker-compose logs php | grep telegram

# Re-set webhook
make telegram-webhook
```

#### Database Connection Error

```bash
# Check MySQL
docker-compose exec mysql mysql -uroot -p

# Check connection
docker-compose exec php bin/console doctrine:database:create
```

#### Transactions Not Processing

```bash
# Check messenger consumers
docker-compose ps | grep messenger

# Restart consumers
docker-compose restart messenger-consume
```

### Debug Mode

Enable debug mode (development only):

```bash
# .env
APP_ENV=dev
APP_DEBUG=1
```

## 🤝 Contributing

1. Fork the repository
2. Create feature branch (`git checkout -b feature/amazing-feature`)
3. Commit changes (`git commit -m 'Add amazing feature'`)
4. Push to branch (`git push origin feature/amazing-feature`)
5. Open Pull Request

### Code Standards

- PSR-12 coding style
- PHPDoc for all methods
- Unit tests for new features
- Security review required

## 📄 License

This project is proprietary software. All rights reserved.

---

## 📞 Support

- **Technical Support**: tech@your-domain.com
- **Business Inquiries**: business@your-domain.com
- **Security Issues**: security@your-domain.com
- **Telegram**: @your_support_bot

## 🙏 Acknowledgments

Built with:

- [Symfony](https://symfony.com)
- [Doctrine](https://www.doctrine-project.org)
- [Telegram Bot API](https://core.telegram.org/bots)
- [TRON](https://tron.network)

---

**⚠️ Disclaimer**: This platform handles cryptocurrency. Always ensure compliance with local regulations and implement
proper security measures.