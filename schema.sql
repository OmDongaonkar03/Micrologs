-- ============================================================
-- PROJECTS (tenants)
-- ============================================================
CREATE TABLE projects (
    id             INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(100)  NOT NULL,
    secret_key     CHAR(64)      NOT NULL UNIQUE,  -- server-side only
    public_key     CHAR(32)      NOT NULL UNIQUE,  -- snippet-safe, write only
    allowed_domain VARCHAR(255)  NOT NULL,
    is_active      TINYINT(1)    NOT NULL DEFAULT 1,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_secret_key (secret_key),
    INDEX idx_public_key (public_key)
) ENGINE=InnoDB;


-- ============================================================
-- LOCATIONS (normalized — reused across pageviews + link_clicks)
-- ============================================================
CREATE TABLE locations (
    id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    project_id   INT UNSIGNED  NOT NULL,
    country      VARCHAR(100)  NOT NULL DEFAULT '',
    country_code CHAR(2)       NOT NULL DEFAULT '',
    region       VARCHAR(100)  NOT NULL DEFAULT '',
    city         VARCHAR(100)  NOT NULL DEFAULT '',
    is_vpn       TINYINT(1)    NOT NULL DEFAULT 0,

    UNIQUE KEY uq_location (project_id, country_code, region, city),
    INDEX idx_project_id (project_id),

    CONSTRAINT fk_locations_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- DEVICES (normalized — reused across pageviews + link_clicks)
-- ============================================================
CREATE TABLE devices (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    project_id      INT UNSIGNED  NOT NULL,
    device_type     ENUM('desktop','mobile','tablet','unknown') NOT NULL DEFAULT 'unknown',
    os              VARCHAR(50)   NOT NULL DEFAULT '',
    browser         VARCHAR(50)   NOT NULL DEFAULT '',
    browser_version VARCHAR(20)   NOT NULL DEFAULT '',

    UNIQUE KEY uq_device (project_id, device_type, os, browser, browser_version),
    INDEX idx_project_id (project_id),

    CONSTRAINT fk_devices_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- VISITORS (one row per unique person per project)
-- ============================================================
CREATE TABLE visitors (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id       INT UNSIGNED    NOT NULL,
    visitor_hash     CHAR(64)        NOT NULL,  -- SHA-256 of cookie UUID
    fingerprint_hash CHAR(64)        NOT NULL DEFAULT '',  -- SHA-256 of browser fingerprint
    first_seen       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_visitor (project_id, visitor_hash),
    INDEX idx_fingerprint (project_id, fingerprint_hash),
    INDEX idx_project_id (project_id),

    CONSTRAINT fk_visitors_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- SESSIONS (one row per visit/session)
-- ============================================================
CREATE TABLE sessions (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id    INT UNSIGNED    NOT NULL,
    visitor_id    BIGINT UNSIGNED NOT NULL,
    session_token CHAR(64)        NOT NULL UNIQUE,
    started_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_bounced    TINYINT(1)      NOT NULL DEFAULT 1,  -- flips to 0 on second pageview

    INDEX idx_project_id (project_id),
    INDEX idx_visitor_id (visitor_id),
    INDEX idx_session_token (session_token),
    INDEX idx_started_at (started_at),

    CONSTRAINT fk_sessions_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_sessions_visitor
        FOREIGN KEY (visitor_id) REFERENCES visitors(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- PAGEVIEWS
-- ============================================================
CREATE TABLE pageviews (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id        INT UNSIGNED    NOT NULL,
    session_id        BIGINT UNSIGNED NOT NULL,
    visitor_id        BIGINT UNSIGNED NOT NULL,
    location_id       INT UNSIGNED    NULL,
    device_id         INT UNSIGNED    NULL,
    url               VARCHAR(2048)   NOT NULL,
    page_title        VARCHAR(512)    NOT NULL DEFAULT '',
    referrer_url      VARCHAR(2048)   NOT NULL DEFAULT '',
    referrer_category ENUM('direct','organic_search','social','referral','email','unknown') NOT NULL DEFAULT 'unknown',
    utm_source        VARCHAR(255)    NOT NULL DEFAULT '',
    utm_medium        VARCHAR(255)    NOT NULL DEFAULT '',
    utm_campaign      VARCHAR(255)    NOT NULL DEFAULT '',
    utm_content       VARCHAR(255)    NOT NULL DEFAULT '',
    utm_term          VARCHAR(255)    NOT NULL DEFAULT '',
    screen_resolution VARCHAR(20)     NOT NULL DEFAULT '',
    timezone          VARCHAR(100)    NOT NULL DEFAULT '',
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_project_id (project_id),
    INDEX idx_session_id (session_id),
    INDEX idx_visitor_id (visitor_id),
    INDEX idx_created_at (created_at),
    INDEX idx_url (project_id, url(255)),
    INDEX idx_referrer_category (project_id, referrer_category)
) ENGINE=InnoDB;


-- ============================================================
-- TRACKED LINKS
-- ============================================================
CREATE TABLE tracked_links (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    project_id      INT UNSIGNED  NOT NULL,
    code            VARCHAR(12)   NOT NULL UNIQUE,
    destination_url VARCHAR(2048) NOT NULL,
    label           VARCHAR(255)  NOT NULL DEFAULT '',
    is_active       TINYINT(1)    NOT NULL DEFAULT 1,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_code (code),
    INDEX idx_project_id (project_id),

    CONSTRAINT fk_links_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- LINK CLICKS
-- ============================================================
CREATE TABLE link_clicks (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link_id           INT UNSIGNED    NOT NULL,
    project_id        INT UNSIGNED    NOT NULL,
    location_id       INT UNSIGNED    NULL,
    device_id         INT UNSIGNED    NULL,
    referrer_url      VARCHAR(2048)   NOT NULL DEFAULT '',
    referrer_category ENUM('direct','organic_search','social','referral','email','unknown') NOT NULL DEFAULT 'unknown',
    ip_hash           CHAR(64)        NOT NULL DEFAULT '',
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_link_id (link_id),
    INDEX idx_project_id (project_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;