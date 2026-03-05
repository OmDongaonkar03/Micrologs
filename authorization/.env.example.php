<?php
    define("DB_HOST", "your_db_host");
    define("DB_USER", "your_db_user");
    define("DB_PASS", "your_db_pass");
    define("DB_NAME", "your_db_name");

    define("IS_PRODUCTION", false); // Set to true in production to enable worker health checks

    define("VALKEY_HOST", "your_valkey_host");
    define("VALKEY_PORT", your_valkey_port);
    define("VALKEY_PASSWORD", "your_valkey_password");

    // Tell includes that we are running from CLI, not a web request.
    // functions.php checks this to skip CORS headers and OPTIONS handling
    // which don't apply in a worker context.
    // Keep false on web-facing APIs to allow CORS and preflight handling.
    define("RUNNING_AS_WORKER", true);

    # Timezone
    define("APP_TIMEZONE", "+05:30");
    define("TIMEZONE", "Asia/Kolkata");

    # Generate a secure value by running this once in PHP: echo bin2hex(random_bytes(32));
    define(
        "IP_HASH_SALT",
        "your_random_salt_for_ip_hashing"
    );

    define("Geo_IP2_LICENSE_KEY", "your_maxmind_license_key");
    define("GEOIP_PATH", __DIR__ . "/../utils/geoip/GeoLite2-City.mmdb");
	
	define("LOG_PATH", __DIR__ . "/../logs/micrologs.log");

    # Generate a secure value by running this once in PHP: echo bin2hex(random_bytes(32));
    # Use a different output than IP_HASH_SALT above.
    define("ADMIN_KEY", "your_secure_admin_key");

    # CORS — comma-separated list of allowed origins (include scheme, e.g. https://example.com)
    define("ALLOWED_ORIGINS", "https://example.com,http://localhost:8080");

    define("APP_URL", "https://your_domain_url");

    # Trusted reverse proxy IPs (comma-separated).
    # Only set this if Nginx/Apache sits in front of PHP on the same server.
    # When empty, X-Forwarded-For is NEVER trusted — prevents IP spoofing.
    # Example for local proxy: define("TRUSTED_PROXIES", "127.0.0.1");
    define("TRUSTED_PROXIES", "");
?>