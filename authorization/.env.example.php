<?php
define("DB_HOST", "your_db_host");
define("DB_USER", "your_db_user");
define("DB_PASS", "your_db_pass");
define("DB_NAME", "your_db_name");

define("IS_PRODUCTION", false); // Set to true in production to enable worker health checks

define("VALKEY_HOST", "your_valkey_host");
define("VALKEY_PORT", 6379);
define("VALKEY_PASSWORD", "your_valkey_password");

if (!defined("RUNNING_AS_WORKER") && php_sapi_name() === "cli") {
    define("RUNNING_AS_WORKER", true);
}

# Timezone
# APP_TIMEZONE — MySQL offset used for SET time_zone (accepts +HH:MM or named zone)
# TIMEZONE      — PHP IANA zone name used for date_default_timezone_set()
# Both must represent the same offset. Example pairs:
#   +05:30 / Asia/Kolkata  |  +00:00 / UTC  |  +01:00 / Europe/Paris
define("APP_TIMEZONE", "+05:30");
define("TIMEZONE", "Asia/Kolkata");

# Generate once using:
# php -r "echo bin2hex(random_bytes(32));"
define(
    "IP_HASH_SALT",
    "your_random_64_char_hex_salt"
);

define("Geo_IP2_LICENSE_KEY", "your_maxmind_license_key");
define("GEOIP_PATH", __DIR__ . "/../utils/geoip/GeoLite2-City.mmdb");

define("LOG_PATH", __DIR__ . "/../logs/micrologs.log");

# Generate once using:
# php -r "echo bin2hex(random_bytes(32));"
define("ADMIN_KEY", "your_secure_admin_key");

define("APP_URL", "https://your-domain.com");

# CORS — comma separated list of allowed origins
define(
    "ALLOWED_ORIGINS",
    "https://example.com,http://localhost:8080"
);

# Trusted reverse proxy IPs (comma-separated)
define("TRUSTED_PROXIES", "");

if (!defined("MICROLOGS_TEST") && getenv("ML_TEST_MODE") === "true") {
    define("MICROLOGS_TEST", true);
}
?>