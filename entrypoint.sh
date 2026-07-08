#!/bin/bash
set -e

CONFIG_FILE="/var/www/html/storage/system/config.php"
DB_SCHEMA="/cms_db_schema.sql"

# Ensure storage is writable by www-data
mkdir -p /var/www/html/storage/system
chown -R www-data:www-data /var/www/html/storage 2>/dev/null || true

# Restore default storage files hidden by bind mount (exclude system/ - managed by entrypoint)
if [ -d /var/www/html/storage.defaults ]; then
    for dir in cache2 custom import lang; do
        if [ -d /var/www/html/storage.defaults/$dir ]; then
            mkdir -p /var/www/html/storage/$dir
            cp -rn /var/www/html/storage.defaults/$dir/. /var/www/html/storage/$dir/
        fi
    done
fi

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Running first-time setup..."

    CMS_SECRETKEY=${CMS_SECRETKEY:-$(openssl rand -base64 32)}
    WEBSITE_SECRETKEY=${WEBSITE_SECRETKEY:-$(openssl rand -base64 32)}

    cat > "$CONFIG_FILE" <<EOF
<?php
define('CMS_DB_HOSTNAME', '${CMS_DB_HOST:-db}');
define('CMS_DB_USERNAME', '${CMS_DB_USER:-io200}');
define('CMS_DB_PASSWORD', '${CMS_DB_PASSWORD}');
define('CMS_DB_DATABASE', '${CMS_DB_NAME:-io200}');
define('CMS_SECRETKEY', '${CMS_SECRETKEY}');
define('WEBSITE_SECRETKEY', '${WEBSITE_SECRETKEY}');
define('WEBSITE_URL', '${CMS_WEBSITE_URL}');
EOF

    cat > /var/www/html/storage/system/service.json <<EOF2
{"service_id":null,"endpoint_url":"https://www.service.io200.com/api/v1/"}
EOF2

    ADMIN_HASH=$(php -r "echo password_hash('${CMS_ADMIN_PASSWORD}', PASSWORD_DEFAULT);")
    cat > /var/www/html/storage/system/user.json <<EOF3
{"mail":"${CMS_ADMIN_EMAIL}","passwordhash":"${ADMIN_HASH}","locked":false,"resetpasswordhash":null,"autologinhash":null,"numberauthenticationattempts":0,"login_on":null}
EOF3

    cat > /var/www/html/storage/system/sitesettings.json <<EOF4
{"WEBSITE_TITLE":"${CMS_WEBSITE_TITLE:-My IO200 Website}","WEBSITE_MAIL":"${CMS_ADMIN_EMAIL}","THEME":{"layout":"fullwidth","mode":"light","font":"karlabold","flavors":["layoutfixedheader","slideeffect"]}}
EOF4

    chown -R www-data:www-data /var/www/html/storage/system

    echo "Setup complete."
fi

# Always attempt DB migration (retry in case MariaDB isn't ready yet)
if [ -f "$DB_SCHEMA" ]; then
    echo "Waiting for database connection..."
    for i in $(seq 1 30); do
        php -r "
            \$db = @new mysqli('${CMS_DB_HOST:-db}', '${CMS_DB_USER:-io200}', '${CMS_DB_PASSWORD}', '${CMS_DB_NAME:-io200}');
            if (!\$db->connect_error) { echo 'connected'; }
            \$db->close();
        " 2>/dev/null | grep -q connected && echo "  DB ready." && break
        echo "  attempt $i/30 failed, retrying in 5s..."
        sleep 5
    done

    echo "Checking if schema already applied..."
    SCHEMA_EXISTS=$(php -r "
        \$db = @new mysqli('${CMS_DB_HOST:-db}', '${CMS_DB_USER:-io200}', '${CMS_DB_PASSWORD}', '${CMS_DB_NAME:-io200}');
        if (\$db->connect_error) { echo 'error'; exit(1); }
        \$r = \$db->query(\"SHOW TABLES LIKE 'cms_articles'\");
        echo \$r->num_rows > 0 ? 'yes' : 'no';
        \$db->close();
    " 2>/dev/null || echo "error")

    if [ "$SCHEMA_EXISTS" = "no" ]; then
        echo "Applying database schema..."
        php -r "
            \$db = new mysqli('${CMS_DB_HOST:-db}', '${CMS_DB_USER:-io200}', '${CMS_DB_PASSWORD}', '${CMS_DB_NAME:-io200}');
            if (\$db->connect_error) { echo 'DB connection failed: ' . \$db->connect_error . PHP_EOL; exit(1); }
            \$sql = file_get_contents('$DB_SCHEMA');
            \$db->multi_query(\$sql);
            while (\$db->next_result()) {;}
            \$db->close();
            echo 'Database schema applied.' . PHP_EOL;
        " || echo "WARNING: Schema apply failed."
    elif [ "$SCHEMA_EXISTS" != "yes" ]; then
        echo "WARNING: Could not check schema status (DB connection issue)."
    else
        echo "Schema already applied, skipping."
    fi
fi

# remove installer files
rm -f /var/www/html/install.php /var/www/html/dist.zip 2>/dev/null || true

exec apache2-foreground
