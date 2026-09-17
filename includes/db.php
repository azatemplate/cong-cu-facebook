<?php
// includes/db.php
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET NAMES '" . DB_CHARSET . "'");
    // Timezone: wrapped separately — some MariaDB servers lack tz tables
    try { $pdo->exec("SET time_zone = '+07:00'"); } catch (Exception $e) {}

    // Auto-save base_site_url when accessed via Web HTTP to ensure correct domain for API URLs
    if (!empty($_SERVER['HTTP_HOST'])) {
        $is_ssl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
        $scheme = $is_ssl ? 'https' : 'http';
        $current_domain = $scheme . '://' . $_SERVER['HTTP_HOST'];
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('base_site_url', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                ->execute([$current_domain, $current_domain]);
        } catch (Exception $e) {}
    }

function ensure_db_schema_ready($pdo) {
    static $already_checked = false;
    if ($already_checked) return;

    $flag_file = sys_get_temp_dir() . '/fb_schema_init_v14.done';
    if (file_exists($flag_file)) {
        $already_checked = true;
        return;
    }

    try {
        $chk = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'schema_init_v14_done'");
        if ($chk && $chk->fetchColumn() === '1') {
            @file_put_contents($flag_file, date('Y-m-d H:i:s'));
            $already_checked = true;
            return;
        }
    } catch (Exception $e) {}

    try {
        // ── Core Tables (created once) ──────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fb_id VARCHAR(255) UNIQUE,
                name VARCHAR(255),
                access_token TEXT,
                account_id INT DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                page_id VARCHAR(255) UNIQUE,
                page_name VARCHAR(255),
                name VARCHAR(255),
                access_token TEXT,
                category VARCHAR(255),
                followers_count INT DEFAULT 0,
                user_id INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS posts_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                page_id VARCHAR(255),
                post_type VARCHAR(50),
                content TEXT,
                fb_post_id VARCHAR(255),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                role ENUM('admin', 'user') DEFAULT 'user',
                expire_date DATETIME DEFAULT NULL,
                page_limit INT DEFAULT 500,
                fb_app_id VARCHAR(255) DEFAULT NULL,
                fb_app_secret VARCHAR(255) DEFAULT NULL,
                gg_client_id VARCHAR(255) DEFAULT NULL,
                gg_client_secret VARCHAR(255) DEFAULT NULL,
                gg_refresh_token TEXT DEFAULT NULL,
                youtube_multi_api TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Insert default admin if not exists
        $stmt = $pdo->query("SELECT id FROM system_accounts WHERE username = 'admin'");
        if (!$stmt->fetch()) {
            $default_password = password_hash('admin123', PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO system_accounts (username, password, role) VALUES ('admin', ?, 'admin')")
                ->execute([$default_password]);
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS scheduled_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                page_id VARCHAR(50) NOT NULL,
                post_type VARCHAR(50) NOT NULL,
                content TEXT,
                media_path VARCHAR(255),
                scheduled_time DATETIME NOT NULL,
                status ENUM('pending', 'processing', 'published', 'failed') DEFAULT 'pending',
                error_msg TEXT,
                retry_count INT DEFAULT 0,
                campaign_id INT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS post_campaigns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                post_type VARCHAR(50) DEFAULT 'Mixed',
                total_posts INT DEFAULT 0,
                scheduled_time DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (account_id) REFERENCES system_accounts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS saved_replies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                title VARCHAR(255) DEFAULT NULL,
                content TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (account_id) REFERENCES system_accounts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_configs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                provider ENUM('OpenAI', 'Gemini', 'Claude', 'HongVipPro AI', 'HongHub AI') DEFAULT 'Gemini',
                endpoint VARCHAR(255) DEFAULT '',
                api_keys TEXT,
                model VARCHAR(100) DEFAULT '',
                prompt_content TEXT,
                prompt_title TEXT,
                is_active TINYINT(1) DEFAULT 0,
                max_retries INT DEFAULT 2,
                timeout_seconds INT DEFAULT 120,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (account_id) REFERENCES system_accounts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS gemini_keys (
                id INT AUTO_INCREMENT PRIMARY KEY,
                api_key VARCHAR(255) UNIQUE NOT NULL,
                description VARCHAR(255) DEFAULT '',
                status TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_usage_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT DEFAULT NULL,
                username VARCHAR(50) DEFAULT NULL,
                provider VARCHAR(50) DEFAULT NULL,
                feature VARCHAR(100) DEFAULT NULL,
                prompt_length INT DEFAULT 0,
                response_length INT DEFAULT 0,
                status VARCHAR(20) DEFAULT 'success',
                error_message TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS posted_folder_files (
                id INT AUTO_INCREMENT PRIMARY KEY,
                folder_id VARCHAR(255) NOT NULL,
                file_id VARCHAR(255) NOT NULL,
                posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_folder_file (folder_id, file_id),
                UNIQUE KEY uq_folder_file (folder_id, file_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Migration: Clean duplicates and add UNIQUE key for existing tables
        try {
            $pdo->exec("
                DELETE t1 FROM posted_folder_files t1
                INNER JOIN posted_folder_files t2 
                WHERE t1.id < t2.id AND t1.folder_id = t2.folder_id AND t1.file_id = t2.file_id
            ");
            $check_idx = $pdo->query("SHOW INDEX FROM posted_folder_files WHERE Key_name = 'uq_folder_file'");
            if (!$check_idx->fetch()) {
                $pdo->exec("ALTER TABLE posted_folder_files ADD UNIQUE KEY uq_folder_file (folder_id, file_id)");
            }
        } catch (Exception $e) {}

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS page_shares (
                id INT AUTO_INCREMENT PRIMARY KEY,
                page_id VARCHAR(255) NOT NULL,
                owner_account_id INT NOT NULL,
                shared_with_account_id INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (page_id),
                INDEX (owner_account_id),
                INDEX (shared_with_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS youtube_channels (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                channel_id VARCHAR(255) NOT NULL,
                channel_title VARCHAR(255) NOT NULL,
                channel_avatar VARCHAR(500) DEFAULT NULL,
                refresh_token TEXT,
                gg_client_id VARCHAR(255) DEFAULT NULL,
                gg_client_secret VARCHAR(255) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_channel (account_id, channel_id),
                FOREIGN KEY (account_id) REFERENCES system_accounts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Seed default settings silently
        $pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('retry_interval_minutes', '1'), ('max_retries', '3'), ('cleanup_retain_days', '3')");
        $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('cleanup_retain_days', '3') ON DUPLICATE KEY UPDATE setting_value = '3'");


        $pdo->exec("
            CREATE TABLE IF NOT EXISTS data_deletion_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                confirmation_code VARCHAR(100) UNIQUE NOT NULL,
                fb_user_id VARCHAR(255) NOT NULL,
                status VARCHAR(50) DEFAULT 'completed',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS scraper_pages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                user_id INT NOT NULL,
                page_id VARCHAR(255) NOT NULL,
                page_name VARCHAR(255),
                followers_count INT DEFAULT 0,
                access_token TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_scraper_page (account_id, page_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        try {
            $col = $pdo->query("SHOW COLUMNS FROM scraper_pages LIKE 'access_token'");
            if ($col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE scraper_pages ADD COLUMN access_token TEXT");
            }
            $col = $pdo->query("SHOW COLUMNS FROM scraper_pages LIKE 'post_count'");
            if ($col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE scraper_pages ADD COLUMN post_count INT DEFAULT 0");
            }
            $col = $pdo->query("SHOW COLUMNS FROM scraper_pages LIKE 'auto_refresh_hours'");
            if ($col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE scraper_pages ADD COLUMN auto_refresh_hours INT DEFAULT 0");
            }
            $col = $pdo->query("SHOW COLUMNS FROM scraper_pages LIKE 'last_scraped_at'");
            if ($col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE scraper_pages ADD COLUMN last_scraped_at DATETIME DEFAULT NULL");
            }
            $col = $pdo->query("SHOW COLUMNS FROM scraper_pages LIKE 'only_with_content'");
            if ($col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE scraper_pages ADD COLUMN only_with_content TINYINT(1) DEFAULT 0");
            }
        } catch (Exception $e) {}

        try {
            $col = $pdo->query("SHOW COLUMNS FROM pages LIKE 'avatar'");
            if ($col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE pages ADD COLUMN avatar TEXT DEFAULT NULL");
            } else {
                $colInfo = $col->fetch(PDO::FETCH_ASSOC);
                if (stripos($colInfo['Type'], 'varchar') !== false) {
                    $pdo->exec("ALTER TABLE pages MODIFY COLUMN avatar TEXT DEFAULT NULL");
                }
            }
        } catch (Exception $e) {}

        // Add new columns to system_accounts and youtube_channels dynamically
        try {
            $col = $pdo->query("SHOW COLUMNS FROM system_accounts LIKE 'youtube_multi_api'");
            if ($col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE system_accounts ADD COLUMN youtube_multi_api TINYINT(1) DEFAULT 0");
            }
            $col = $pdo->query("SHOW COLUMNS FROM system_accounts LIKE 'drive_multi_api'");
            if ($col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE system_accounts ADD COLUMN drive_multi_api TINYINT(1) DEFAULT 0");
            }
            
            $col = $pdo->query("SHOW COLUMNS FROM youtube_channels LIKE 'gg_client_id'");
            if ($col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE youtube_channels ADD COLUMN gg_client_id VARCHAR(255) DEFAULT NULL");
            }
            
            $col = $pdo->query("SHOW COLUMNS FROM youtube_channels LIKE 'gg_client_secret'");
            if ($col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE youtube_channels ADD COLUMN gg_client_secret VARCHAR(255) DEFAULT NULL");
            }
        } catch (Exception $e) {}

        // Auto-convert old HTTP avatars to local proxy to prevent expiration
        try {
            $stmt = $pdo->query("SELECT id FROM pages WHERE avatar LIKE 'http%' LIMIT 1");
            if ($stmt && $stmt->fetch()) {
                $pdo->exec("UPDATE pages SET avatar = CONCAT('avatar.php?id=', page_id) WHERE avatar LIKE 'http%'");
            }
        } catch (Exception $e) {}

        // Migrate ai_configs to support max_retries and timeout_seconds
        try {
            $col = $pdo->query("SHOW COLUMNS FROM ai_configs LIKE 'max_retries'");
            if ($col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE ai_configs ADD COLUMN max_retries INT DEFAULT 2");
            }
            $col = $pdo->query("SHOW COLUMNS FROM ai_configs LIKE 'timeout_seconds'");
            if ($col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE ai_configs ADD COLUMN timeout_seconds INT DEFAULT 120");
            }
            
            $col = $pdo->query("SHOW COLUMNS FROM ai_configs LIKE 'provider'");
            if ($col) {
                $colInfo = $col->fetch(PDO::FETCH_ASSOC);
                if ($colInfo && strpos($colInfo['Type'], 'HongHub AI') === false) {
                    $pdo->exec("ALTER TABLE ai_configs MODIFY COLUMN provider ENUM('OpenAI', 'Gemini', 'Claude', 'HongVipPro AI', 'HongHub AI') DEFAULT 'Gemini'");
                    $pdo->exec("UPDATE ai_configs SET provider = 'HongHub AI' WHERE provider = 'HongVipPro AI'");
                    $pdo->exec("UPDATE ai_usage_logs SET provider = 'HongHub AI' WHERE provider = 'HongVipPro AI'");
                }
            }
            
            $colCookie = $pdo->query("SHOW COLUMNS FROM ai_configs LIKE 'cookie'");
            if ($colCookie && $colCookie->rowCount() === 0) {
                $pdo->exec("ALTER TABLE ai_configs ADD COLUMN cookie TEXT DEFAULT NULL");
            }
            
            $colKeyCookie = $pdo->query("SHOW COLUMNS FROM gemini_keys LIKE 'cookie_value'");
            if ($colKeyCookie && $colKeyCookie->rowCount() > 0) {
                $pdo->exec("ALTER TABLE gemini_keys DROP COLUMN cookie_value");
            }
            
            $colIP = $pdo->query("SHOW COLUMNS FROM ai_usage_logs LIKE 'ip_address'");
            if ($colIP && $colIP->rowCount() === 0) {
                $pdo->exec("ALTER TABLE ai_usage_logs ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL");
            }
            $colUA = $pdo->query("SHOW COLUMNS FROM ai_usage_logs LIKE 'user_agent'");
            if ($colUA && $colUA->rowCount() === 0) {
                $pdo->exec("ALTER TABLE ai_usage_logs ADD COLUMN user_agent TEXT DEFAULT NULL");
            }
        } catch (Exception $e) {}

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS scraper_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                page_id VARCHAR(255) NOT NULL,
                fb_post_id VARCHAR(255) UNIQUE NOT NULL,
                message TEXT,
                picture VARCHAR(500),
                shares INT DEFAULT 0,
                comments INT DEFAULT 0,
                likes INT DEFAULT 0,
                post_created_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        try {
            $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        } catch (Exception $e) {}

        $add_idx = function($p, $tbl, $name, $cols) {
            try {
                $c = $p->query("SHOW INDEX FROM `$tbl` WHERE Key_name = '$name'");
                if ($c && !$c->fetch()) {
                    $p->exec("ALTER TABLE `$tbl` ADD INDEX `$name` ($cols)");
                }
            } catch (Exception $e) {}
        };
        $add_idx($pdo, 'scheduled_posts', 'idx_camp_status', 'campaign_id, status');
        $add_idx($pdo, 'scheduled_posts', 'idx_acc_camp', 'account_id, campaign_id');
        $add_idx($pdo, 'scheduled_posts', 'idx_page_status', 'page_id, status');
        $add_idx($pdo, 'scheduled_posts', 'idx_acc_page_status', 'account_id, page_id, status');
        $add_idx($pdo, 'scheduled_posts', 'idx_camp_sched_id', 'campaign_id, scheduled_time, id');
        $add_idx($pdo, 'scheduled_posts', 'idx_camp_type_page', 'campaign_id, post_type, page_id');
        $add_idx($pdo, 'scheduled_posts', 'idx_sched_status_acc', 'scheduled_time, status, account_id');
        $add_idx($pdo, 'scheduled_posts', 'idx_acc_status_sched', 'account_id, status, scheduled_time');
        $add_idx($pdo, 'scheduled_posts', 'idx_status_sched', 'status, scheduled_time');
        $add_idx($pdo, 'scheduled_posts', 'idx_acc_sched', 'account_id, scheduled_time');
        $add_idx($pdo, 'scheduled_posts', 'idx_post_type_sched', 'post_type, scheduled_time');
        $add_idx($pdo, 'scheduled_posts', 'idx_updated_at', 'updated_at');
        $add_idx($pdo, 'scheduled_posts', 'idx_status_retry', 'status, retry_count');
        $add_idx($pdo, 'pages', 'idx_user_id', 'user_id');
        $add_idx($pdo, 'pages', 'idx_page_id', 'page_id');
        $add_idx($pdo, 'users', 'idx_account_id', 'account_id');
        $add_idx($pdo, 'post_campaigns', 'idx_acc_created', 'account_id, created_at');
        $add_idx($pdo, 'youtube_channels', 'idx_yt_channel_id', 'channel_id');
        $add_idx($pdo, 'page_shares', 'idx_ps_page_shared', 'page_id, shared_with_account_id');
        $add_idx($pdo, 'page_shares', 'idx_ps_shared_page', 'shared_with_account_id, page_id');
        $add_idx($pdo, 'buffer_channels', 'idx_buf_chan_id', 'channel_id');
        $add_idx($pdo, 'instagram_accounts', 'idx_ig_user_id', 'ig_user_id');

        try {
            $pdo->exec("ALTER TABLE scheduled_posts ADD INDEX IF NOT EXISTS idx_cron_dispatch (status, scheduled_time, page_id)");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE scheduled_posts ADD INDEX IF NOT EXISTS idx_comment_queue (status, comment_at, comment_done)");
        } catch (Exception $e) {}

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS fb_conversations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    page_id VARCHAR(100) NOT NULL,
                    sender_id VARCHAR(100) NOT NULL,
                    sender_name VARCHAR(255) NULL,
                    type VARCHAR(20) DEFAULT 'message',
                    snippet TEXT NULL,
                    unread_count INT DEFAULT 0,
                    updated_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                    conversation_id VARCHAR(100) NULL,
                    UNIQUE KEY uq_page_sender (page_id, sender_id),
                    INDEX idx_upd_time (updated_time)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (Exception $e) {}

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS conversation_labels (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    conv_id VARCHAR(100) NOT NULL,
                    page_id VARCHAR(100) NOT NULL,
                    recipient_id VARCHAR(100) NOT NULL,
                    label_name VARCHAR(100) NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_label (conv_id, page_id, label_name),
                    INDEX (conv_id),
                    INDEX (page_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (Exception $e) {}

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS dashboard_snapshots (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL COMMENT '0 = admin/global',
                    snapshot_date DATE NOT NULL,
                    total_followers BIGINT DEFAULT 0,
                    total_reach BIGINT DEFAULT 0,
                    total_views BIGINT DEFAULT 0,
                    total_pages INT DEFAULT 0,
                    total_accounts INT DEFAULT 0,
                    total_reels INT DEFAULT 0,
                    total_posts INT DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_snapshot (account_id, snapshot_date),
                    INDEX (snapshot_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            
            try {
                $col = $pdo->query("SHOW COLUMNS FROM dashboard_snapshots LIKE 'total_accounts'");
                if ($col->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE dashboard_snapshots ADD COLUMN total_accounts INT DEFAULT 0, ADD COLUMN total_reels INT DEFAULT 0, ADD COLUMN total_posts INT DEFAULT 0");
                }
            } catch (Exception $e) {}
        } catch (Exception $e) {}

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS special_watch_targets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    fb_user_id INT DEFAULT NULL,
                    page_url VARCHAR(500) NOT NULL,
                    page_name VARCHAR(255) DEFAULT '',
                    page_avatar VARCHAR(500) DEFAULT '',
                    label VARCHAR(100) DEFAULT '',
                    post_limit INT DEFAULT 20,
                    auto_refresh TINYINT(1) DEFAULT 1,
                    post_count INT DEFAULT 0,
                    last_scanned_at DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (account_id) REFERENCES system_accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            try {
                $col = $pdo->query("SHOW COLUMNS FROM special_watch_targets LIKE 'fb_user_id'");
                if ($col->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE special_watch_targets ADD COLUMN fb_user_id INT DEFAULT NULL");
                }
            } catch (Exception $e) {}

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS special_watch_posts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    target_id INT NOT NULL,
                    post_fb_id VARCHAR(255) DEFAULT '',
                    content TEXT,
                    image_url VARCHAR(500) DEFAULT '',
                    likes INT DEFAULT 0,
                    comments INT DEFAULT 0,
                    shares INT DEFAULT 0,
                    post_time DATETIME DEFAULT NULL,
                    post_url VARCHAR(500) DEFAULT '',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX (target_id),
                    FOREIGN KEY (target_id) REFERENCES special_watch_targets(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (Exception $e) {}

        // Auto Info Request columns
        $auto_req_migrations = [
            "ALTER TABLE system_accounts ADD COLUMN phone_request_enabled TINYINT DEFAULT 0",
            "ALTER TABLE system_accounts ADD COLUMN phone_request_hours INT DEFAULT 1",
            "ALTER TABLE system_accounts ADD COLUMN phone_request_text TEXT DEFAULT NULL",
            "ALTER TABLE system_accounts ADD COLUMN province_request_text TEXT DEFAULT NULL",
            "ALTER TABLE system_accounts ADD COLUMN product_request_text TEXT DEFAULT NULL",
            
            "ALTER TABLE zalo_settings ADD COLUMN phone_request_enabled TINYINT DEFAULT 0",
            "ALTER TABLE zalo_settings ADD COLUMN phone_request_hours INT DEFAULT 1",
            "ALTER TABLE zalo_settings ADD COLUMN phone_request_text TEXT DEFAULT NULL",
            "ALTER TABLE zalo_settings ADD COLUMN province_request_text TEXT DEFAULT NULL",
            "ALTER TABLE zalo_settings ADD COLUMN product_request_text TEXT DEFAULT NULL",

            "ALTER TABLE fb_customers ADD COLUMN last_sender VARCHAR(10) DEFAULT 'customer'",
            "ALTER TABLE fb_customers ADD COLUMN last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            "ALTER TABLE fb_customers ADD COLUMN info_requested_at TIMESTAMP NULL DEFAULT NULL",

            "ALTER TABLE zalo_customers ADD COLUMN last_sender VARCHAR(10) DEFAULT 'customer'",
            "ALTER TABLE zalo_customers ADD COLUMN last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            "ALTER TABLE zalo_customers ADD COLUMN info_requested_at TIMESTAMP NULL DEFAULT NULL",

            "ALTER TABLE fb_customers ADD COLUMN consulted TINYINT DEFAULT 0",
            "ALTER TABLE zalo_customers ADD COLUMN consulted TINYINT DEFAULT 0",
            "ALTER TABLE pages ADD COLUMN auto_send_capi TINYINT DEFAULT 1",
            
            "ALTER TABLE system_accounts ADD COLUMN followup_request_enabled TINYINT DEFAULT 0",
            "ALTER TABLE system_accounts ADD COLUMN followup_request_hours INT DEFAULT 12",
            "ALTER TABLE system_accounts ADD COLUMN followup_request_text TEXT DEFAULT NULL",
            
            "ALTER TABLE zalo_settings ADD COLUMN followup_request_enabled TINYINT DEFAULT 0",
            "ALTER TABLE zalo_settings ADD COLUMN followup_request_hours INT DEFAULT 12",
            "ALTER TABLE zalo_settings ADD COLUMN followup_request_text TEXT DEFAULT NULL",
            
            "ALTER TABLE fb_customers ADD COLUMN followup_requested_at TIMESTAMP NULL DEFAULT NULL",
            "ALTER TABLE zalo_customers ADD COLUMN followup_requested_at TIMESTAMP NULL DEFAULT NULL",
            "ALTER TABLE fb_customers ADD COLUMN info_request_count INT DEFAULT 0",
            "ALTER TABLE zalo_customers ADD COLUMN info_request_count INT DEFAULT 0",
            "ALTER TABLE users ADD COLUMN gg_client_id VARCHAR(255) DEFAULT NULL",
            "ALTER TABLE users ADD COLUMN gg_client_secret VARCHAR(255) DEFAULT NULL",
            "ALTER TABLE users ADD COLUMN gg_refresh_token TEXT DEFAULT NULL",
            "ALTER TABLE system_accounts ADD COLUMN phone_request_limit INT DEFAULT 3",
            "ALTER TABLE zalo_settings ADD COLUMN phone_request_limit INT DEFAULT 3",
            "ALTER TABLE scheduled_posts ADD COLUMN is_read TINYINT(1) DEFAULT 0"
        ];

        foreach ($auto_req_migrations as $query) {
            try {
                $pdo->exec($query);
            } catch (PDOException $e) {}
        }

        try {
            // Re-evaluate consulted status 4: requires ALL 4 FIELDS (name, phone, province, notes)
            $pdo->exec("UPDATE fb_customers SET consulted = 0 WHERE consulted = 4 AND (name IS NULL OR TRIM(name) = '' OR phone IS NULL OR TRIM(phone) = '' OR province IS NULL OR TRIM(province) = '' OR notes IS NULL OR TRIM(notes) = '')");
            $pdo->exec("UPDATE zalo_customers SET consulted = 0 WHERE consulted = 4 AND (name IS NULL OR TRIM(name) = '' OR name = 'Khách hàng Zalo' OR phone IS NULL OR TRIM(phone) = '' OR province IS NULL OR TRIM(province) = '' OR notes IS NULL OR TRIM(notes) = '')");

            $pdo->exec("UPDATE fb_customers SET consulted = 4 WHERE consulted = 0 AND name IS NOT NULL AND TRIM(name) != '' AND phone IS NOT NULL AND TRIM(phone) != '' AND province IS NOT NULL AND TRIM(province) != '' AND notes IS NOT NULL AND TRIM(notes) != ''");
            $pdo->exec("UPDATE zalo_customers SET consulted = 4 WHERE consulted = 0 AND name IS NOT NULL AND TRIM(name) != '' AND name != 'Khách hàng Zalo' AND phone IS NOT NULL AND TRIM(phone) != '' AND province IS NOT NULL AND TRIM(province) != '' AND notes IS NOT NULL AND TRIM(notes) != ''");
        } catch (Exception $e) {}

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS page_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                page_id VARCHAR(255) NOT NULL,
                type VARCHAR(50) DEFAULT 'message',
                sender_id VARCHAR(255) DEFAULT NULL,
                sender_name VARCHAR(255) DEFAULT NULL,
                conversation_id VARCHAR(255) DEFAULT NULL,
                post_id VARCHAR(255) DEFAULT NULL,
                comment_id VARCHAR(255) DEFAULT NULL,
                snippet TEXT DEFAULT NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_page (page_id),
                INDEX idx_read (is_read),
                INDEX idx_type (type),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS zalo_oas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                oa_id VARCHAR(100) UNIQUE NOT NULL,
                account_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                avatar VARCHAR(500) DEFAULT NULL,
                access_token TEXT,
                refresh_token TEXT,
                expires_at INT DEFAULT 0,
                refresh_expires_at INT DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_acc (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS zalo_file_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                oa_id VARCHAR(64) NOT NULL,
                message_id VARCHAR(128) NOT NULL UNIQUE,
                sender_id VARCHAR(64) NOT NULL,
                file_name VARCHAR(512) DEFAULT NULL,
                file_url TEXT DEFAULT NULL,
                file_size BIGINT DEFAULT 0,
                file_type VARCHAR(50) DEFAULT 'file',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_oa_sender (oa_id, sender_id),
                INDEX idx_message_id (message_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS buffer_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                email VARCHAR(255) DEFAULT NULL,
                access_token TEXT NOT NULL,
                organization VARCHAR(255) DEFAULT 'My Organization',
                synced_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_buf_acc (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS buffer_channels (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                buffer_account_id INT NOT NULL,
                channel_id VARCHAR(128) NOT NULL,
                channel_name VARCHAR(255) DEFAULT NULL,
                service VARCHAR(50) NOT NULL,
                service_type VARCHAR(50) DEFAULT 'profile',
                avatar VARCHAR(512) DEFAULT NULL,
                organization VARCHAR(255) DEFAULT 'My Organization',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_buf_chan (account_id, channel_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tiktok_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                open_id VARCHAR(255) NOT NULL,
                union_id VARCHAR(255) DEFAULT NULL,
                display_name VARCHAR(255) NOT NULL,
                avatar TEXT DEFAULT NULL,
                access_token TEXT NOT NULL,
                refresh_token TEXT DEFAULT NULL,
                expires_at INT NOT NULL,
                refresh_expires_at INT DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY idx_open_id (account_id, open_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS instagram_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                ig_user_id VARCHAR(255) NOT NULL,
                fb_page_id VARCHAR(255) NOT NULL,
                username VARCHAR(255) NOT NULL,
                name VARCHAR(255) DEFAULT '',
                avatar TEXT DEFAULT NULL,
                followers_count INT DEFAULT 0,
                access_token TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ig_acc (account_id, ig_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS buffer_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                text TEXT DEFAULT NULL,
                media_url TEXT DEFAULT NULL,
                media_type VARCHAR(20) DEFAULT 'none',
                selected_channels JSON DEFAULT NULL,
                post_mode VARCHAR(20) DEFAULT 'now',
                scheduled_at DATETIME DEFAULT NULL,
                status VARCHAR(20) DEFAULT 'published',
                results JSON DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_buf_post (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS proxies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                proxy_string VARCHAR(255) NOT NULL,
                ip VARCHAR(100) DEFAULT NULL,
                port VARCHAR(10) DEFAULT NULL,
                username VARCHAR(100) DEFAULT NULL,
                password VARCHAR(100) DEFAULT NULL,
                protocol VARCHAR(10) DEFAULT 'http',
                ip_type VARCHAR(10) DEFAULT 'IPv4',
                status VARCHAR(20) DEFAULT 'untested',
                latency INT DEFAULT 0,
                country VARCHAR(10) DEFAULT 'VN',
                assigned_user_id INT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (account_id),
                INDEX (assigned_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'proxy_id'");
            if ($col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN proxy_id INT DEFAULT NULL");
            }
        } catch (Exception $e) {}

        try {
            $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('schema_init_v14_done', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
        } catch (Exception $e) {}
        @file_put_contents($flag_file, date('Y-m-d H:i:s'));
        $already_checked = true;
    } catch (Exception $e) {}
}

ensure_db_schema_ready($pdo);

} catch (PDOException $e) {
    $err_msg = date('[Y-m-d H:i:s] ') . 'DB Connection Error: ' . $e->getMessage() . "\n";
    @file_put_contents(__DIR__ . '/../uploads/app_error.log', $err_msg, FILE_APPEND | LOCK_EX);
    if (defined('APP_ENV') && APP_ENV === 'development') {
        die("Lỗi kết nối CSDL: " . $e->getMessage());
    } else {
        die("Hệ thống tạm thời gặp sự cố. Vui lòng thử lại sau hoặc liên hệ Admin.");
    }
}


// ── Encryption Helpers ──────────────────────────────────────────────────────
if (!function_exists('encryptData')) {
    function encryptData($data) {
        if (empty($data)) return $data;
        $method = 'aes-256-cbc';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
        $encrypted = openssl_encrypt($data, $method, ENCRYPTION_KEY, 0, $iv);
        return 'ENC:' . base64_encode($encrypted . '::' . $iv);
    }

    function decryptData($data) {
        if (empty($data)) return $data;
        if (strpos($data, 'ENC:') === 0) {
            $method = 'aes-256-cbc';
            $payload = base64_decode(substr($data, 4));
            if ($payload && strpos($payload, '::') !== false) {
                list($encrypted_data, $iv) = explode('::', $payload, 2);
                $decrypted = openssl_decrypt($encrypted_data, $method, ENCRYPTION_KEY, 0, $iv);
                if ($decrypted !== false) return $decrypted;
            }
        }
        return $data;
    }
}

// ── Global mime_content_type Polyfill (for servers without fileinfo) ────────
if (!function_exists('mime_content_type')) {
    function mime_content_type($filename) {
        // Test if it's a valid image using getimagesize
        $img_info = @getimagesize($filename);
        if ($img_info && isset($img_info['mime'])) {
            return $img_info['mime'];
        }

        // Fallback to extension mapping if filename contains an extension
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'mp4'  => 'video/mp4',
            'mov'  => 'video/quicktime',
            'avi'  => 'video/x-msvideo',
            'mkv'  => 'video/x-matroska',
            'webm' => 'video/webm'
        ];
        if (isset($map[$ext])) {
            return $map[$ext];
        }

        return 'application/octet-stream';
    }
}
