<?php
    define("DB_HOST", "your_db_host");
    define("DB_USER", "your_db_user");
    define("DB_PASS", "your_db_pass");
    define("DB_NAME", "your_db_name");

    # Timezone
    define("APP_TIMEZONE", "+05:30");
    define("TIMEZONE", "Asia/Kolkata");

    define(
        "IP_HASH_SALT",
        "your_random_salt_for_ip_hashing"
    );

    define("Geo_IP2_LICENSE_KEY", "your_maxmind_license_key");
    define("GEOIP_PATH", __DIR__ . "/../utils/geoip/GeoLite2-City.mmdb");

    define("ADMIN_KEY", "your_secure_admin_key");

    define("APP_URL", "https://your_domain_url");
?>