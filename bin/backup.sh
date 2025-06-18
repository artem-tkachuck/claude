#!/bin/bash
set -e

BACKUP_DIR="/backup/mysql"
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="${BACKUP_DIR}/backup_${DATE}.sql.gz"

echo "Starting backup..."

# Create backup directory if not exists
mkdir -p ${BACKUP_DIR}

# Perform backup
docker-compose exec -T mysql mysqldump \
    -u${DB_USER} \
    -p${DB_PASS} \
    --single-transaction \
    --routines \
    --triggers \
    --add-drop-database \
    --databases ${DB_NAME} | gzip > ${BACKUP_FILE}

# Keep only last 7 days of backups
find ${BACKUP_DIR} -name "backup_*.sql.gz" -mtime +7 -delete

echo "Backup completed: ${BACKUP_FILE}"

# Encrypt and upload to remote storage
openssl enc -aes-256-cbc -salt -in ${BACKUP_FILE} -out ${BACKUP_FILE}.enc -k ${BACKUP_ENCRYPTION_KEY}

# Upload to S3/B2/etc
# aws s3 cp ${BACKUP_FILE}.enc s3://your-backup-bucket/