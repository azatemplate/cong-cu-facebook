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
    $pdo->exec("SET NAMES '" . DB_CHARSET . "'");
    // Timezone: wrapped separately — some MariaDB servers lack tz tables
    try { $pdo->exec("SET time_zone = '+07:00'"); } catch (Exception $e) {}

    // ── Core Tables (created once, safe to run every request) ──────────────

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
            provider ENUM('OpenAI', 'Gemini') DEFAULT 'Gemini',
            endpoint VARCHAR(255) DEFAULT '',
            api_keys TEXT,
            model VARCHAR(100) DEFAULT '',
            prompt_content TEXT,
            prompt_title TEXT,
            is_active TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (account_id) REFERENCES system_accounts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

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
    $pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('retry_interval_minutes', '1'), ('max_retries', '3'), ('cleanup_retain_days', '7')");

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

    // ── Performance Indexes (safe to run every boot, IF NOT EXISTS) ─────────
    // idx_cron_dispatch: tăng tốc query của dispatcher mỗi phút
    try {
        $pdo->exec("ALTER TABLE scheduled_posts ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE scheduled_posts ADD INDEX IF NOT EXISTS idx_cron_dispatch (status, scheduled_time, page_id)");
    } catch (Exception $e) {}
    // idx_comment_queue: tăng tốc query comment worker
    try {
        $pdo->exec("ALTER TABLE scheduled_posts ADD INDEX IF NOT EXISTS idx_comment_queue (status, comment_at, comment_done)");
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
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/../uploads/app_error.log',
            date('[Y-m-d H:i:s] ') . 'conversation_labels error: ' . $e->getMessage() . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

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
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_snapshot (account_id, snapshot_date),
                INDEX (snapshot_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/../uploads/app_error.log',
            date('[Y-m-d H:i:s] ') . 'dashboard_snapshots error: ' . $e->getMessage() . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

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
        // Thêm cột fb_user_id nếu bảng đã tồn tại trước đó (SHOW COLUMNS tương thích mọi MariaDB)
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
    } catch (Exception $e) {
        // Log but don't crash — tables may already exist or environment may not support FK
        @file_put_contents(__DIR__ . '/../uploads/app_error.log',
            date('[Y-m-d H:i:s] ') . 'special_watch table error: ' . $e->getMessage() . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

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
?>
