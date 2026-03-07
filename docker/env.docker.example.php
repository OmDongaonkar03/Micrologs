<?php
    define("DB_HOST", "mysql");        // Docker service name, not localhost
    define("DB_USER", "micrologs");
    define("DB_PASS", "micrologs");
    define("DB_NAME", "micrologs");

    define("IS_PRODUCTION", false);

    define("VALKEY_HOST",     "valkey"); // Docker service name
    define("VALKEY_PORT",     6379);
    define("VALKEY_PASSWORD", "");

    if (!defined("RUNNING_AS_WORKER") && php_sapi_name() === "cli") {
        define("RUNNING_AS_WORKER", true);
    }

    define("APP_TIMEZONE", "+05:30");
    define("TIMEZONE", "Asia/Kolkata");

    define("IP_HASH_SALT", "your_ip_hash_salt_here");

    define("GEOIP_PATH", __DIR__ . "/../utils/geoip/GeoLite2-City.mmdb");
    define("LOG_PATH",   __DIR__ . "/../logs/micrologs.log");

    define("ADMIN_KEY", "your_admin_key_here");

    define("APP_URL", "http://localhost:8080");

    define("ALLOWED_ORIGINS", "http://localhost:8080");

    define("TRUSTED_PROXIES", "");

    if (!defined("MICROLOGS_TEST") && getenv("ML_TEST_MODE") === "true") {
        define("MICROLOGS_TEST", true);
    }
?>