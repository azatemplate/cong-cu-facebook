<?php
// instagram.php
$current_page = 'instagram';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

$account_id = $_SESSION['account_id'];

// Auto check and add ig columns if needed
try {
    $col_ig = $pdo->query("SHOW COLUMNS FROM pages LIKE 'ig_account_id'");
    if ($col_ig->rowCount() === 0) {
        $pdo->exec("ALTER TABLE pages ADD COLUMN ig_account_id VARCHAR(100) DEFAULT NULL, ADD COLUMN ig_username VARCHAR(191) DEFAULT NULL, ADD COLUMN ig_avatar TEXT DEFAULT NULL, ADD COLUMN ig_followers_count INT DEFAULT 0");
    }
} catch (Exception $e) {}

// Fetch pages with connected Instagram Business account
$stmt = $pdo->prepare("
    SELECT p.id, p.page_id, p.name as page_name, p.avatar, p.ig_account_id, p.ig_username, p.ig_avatar, p.ig_followers_count
    FROM pages p
    JOIN users u ON p.user_id = u.id
    WHERE u.account_id = ?
    ORDER BY p.name ASC
");
$stmt->execute([$account_id]);
$all_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter pages with valid IG business ID
$ig_pages = array_filter($all_pages, function($p) {
    return !empty($p['ig_account_id']);
});
?>

<style>
.ig-container {
    max-width: 1200px;
    margin: 0 auto;
}
.ig-nav-tabs {
    display: flex;
    gap: 10px;
    border-bottom: 2px solid var(--border-color, #e2e8f0);
    margin-bottom: 25px;
    overflow-x: auto;
    padding-bottom: 5px;
}
.ig-tab-btn {
    padding: 12px 20px;
    font-weight: 600;
    font-size: 14px;
    color: var(--text-muted, #64748b);
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    white-space: nowrap;
}
.ig-tab-btn:hover {
    color: #e1306c;
}
.ig-tab-btn.active {
    color: #e1306c;
    border-bottom-color: #e1306c;
}
.ig-tab-pane {
    display: none;
}
.ig-tab-pane.active {
    display: block;
}
.ig-card {
    background: var(--card-bg, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    margin-bottom: 20px;
}
.ig-btn-primary {
    background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
    color: #ffffff;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: opacity 0.2s ease;
}
.ig-btn-primary:hover {
    opacity: 0.9;
}
.ig-btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.post-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.post-item-card {
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 10px;
    overflow: hidden;
    background: var(--card-bg, #fff);
    display: flex;
    flex-direction: column;
}
.post-media-box {
    position: relative;
    width: 100%;
    height: 220px;
    background: #000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.post-media-box img, .post-media-box video {
    max-width: 100%;
    max-height: 100%;
    object-fit: cover;
}
.post-type-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    background: rgba(0, 0, 0, 0.7);
    color: #fff;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
}
.post-info {
    padding: 15px;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.post-caption {
    font-size: 13px;
    line-height: 1.4;
    color: var(--text-main, #1e293b);
    margin-bottom: 12px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.post-stats {
    display: flex;
    gap: 15px;
    font-size: 12px;
    color: var(--text-muted, #64748b);
    padding-top: 10px;
    border-top: 1px dashed var(--border-color, #e2e8f0);
    margin-bottom: 12px;
}
.post-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.btn-delete-post {
    background: #ef4444;
    color: #fff;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    cursor: pointer;
    font-weight: 500;
}
.btn-delete-post:hover {
    background: #dc2626;
}
</style>

<div class="ig-container">
    <div class="page-title" style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px;">
        <span style="font-size: 28px;">📷</span> Quản Lý Instagram Business
    </div>

    <!-- Navigation Tabs -->
    <div class="ig-nav-tabs">
        <button class="ig-tab-btn active" onclick="switchIgTab('tab-story', this)">
            <span>📱</span> Đăng Story
        </button>
        <button class="ig-tab-btn" onclick="switchIgTab('tab-reels', this)">
            <span>🎞️</span> Đăng Reels
        </button>
        <button class="ig-tab-btn" onclick="switchIgTab('tab-scan', this)">
            <span>🔍</span> Quét Bài Đã Đăng & Thống Kê
        </button>
        <button class="ig-tab-btn" onclick="switchIgTab('tab-channels', this)">
            <span>➕</span> Thêm & Đồng Bộ Kênh
        </button>
    </div>

    <!-- Tab 1: Đăng Story -->
    <div id="tab-story" class="ig-tab-pane active">
        <div class="ig-card">
            <h3 style="margin-bottom: 10px; color: var(--text-main);">📱 Đăng Instagram Story</h3>
            <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
                Đăng bài viết câu chuyện (Story) dạng ảnh hoặc video ngắn lên tài khoản Instagram Business.
            </p>

            <form id="formStory" enctype="multipart/form-data">
                <input type="hidden" name="post_type" value="story">
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;">1. Chọn Kênh Instagram Business</label>
                    <select name="page_id" class="form-control" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--border-color);" required>
                        <option value="">-- Chọn Kênh Instagram --</option>
                        <?php foreach ($ig_pages as $p): ?>
                            <option value="<?php echo $p['page_id']; ?>">
                                @<?php echo htmlspecialchars($p['ig_username'] ?: $p['page_name']); ?> (Page: <?php echo htmlspecialchars($p['page_name']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;">2. Tải Tệp Media (Ảnh JPG/PNG hoặc Video MP4/MOV)</label>
                    <input type="file" name="media_file" class="form-control" accept="image/jpeg,image/png,video/mp4,video/quicktime" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px dashed var(--border-color); background: var(--bg-main);" required>
                </div>

                <div id="storyResultAlert" style="display: none; margin-bottom: 20px; padding: 12px 15px; border-radius: 8px;"></div>

                <button type="submit" id="btnSubmitStory" class="ig-btn-primary">
                    <span>🚀</span> Đăng Story Ngay
                </button>
            </form>
        </div>
    </div>

    <!-- Tab 2: Đăng Reels -->
    <div id="tab-reels" class="ig-tab-pane">
        <div class="ig-card">
            <h3 style="margin-bottom: 10px; color: var(--text-main);">🎞️ Đăng Instagram Reels</h3>
            <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
                Đăng thước phim ngắn (Reels) kèm Tiêu đề/Mô tả và ảnh bìa đại diện lên Instagram.
            </p>

            <form id="formReels" enctype="multipart/form-data">
                <input type="hidden" name="post_type" value="reels">

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;">1. Chọn Kênh Instagram Business</label>
                    <select name="page_id" class="form-control" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--border-color);" required>
                        <option value="">-- Chọn Kênh Instagram --</option>
                        <?php foreach ($ig_pages as $p): ?>
                            <option value="<?php echo $p['page_id']; ?>">
                                @<?php echo htmlspecialchars($p['ig_username'] ?: $p['page_name']); ?> (Page: <?php echo htmlspecialchars($p['page_name']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;">2. Nội dung Caption / Mô tả Reels</label>
                    <textarea name="caption" rows="4" class="form-control" placeholder="Nhập nội dung bài viết, hashtag..." style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--border-color);"></textarea>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;">3. Tải Video Reels (MP4 / MOV)</label>
                    <input type="file" name="media_file" class="form-control" accept="video/mp4,video/quicktime" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px dashed var(--border-color); background: var(--bg-main);" required>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;">4. Ảnh bìa Cover (Tùy chọn)</label>
                    <input type="file" name="cover_file" class="form-control" accept="image/jpeg,image/png" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--border-color);">
                </div>

                <div id="reelsResultAlert" style="display: none; margin-bottom: 20px; padding: 12px 15px; border-radius: 8px;"></div>

                <button type="submit" id="btnSubmitReels" class="ig-btn-primary">
                    <span>🎬</span> Đăng Reels Ngay
                </button>
            </form>
        </div>
    </div>

    <!-- Tab 3: Quét & Quản Lý Bài Đã Đăng -->
    <div id="tab-scan" class="ig-tab-pane">
        <div class="ig-card">
            <h3 style="margin-bottom: 10px; color: var(--text-main);">🔍 Quét Bài Đã Đăng, Xem Lượt Thích, Lượt Xem & Xóa Bài</h3>
            <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
                Tải danh sách các bài đã xuất bản trên Instagram Business để theo dõi tương tác (Likes, Comments, Views/Plays) hoặc xóa bài viết.
            </p>

            <div style="display: flex; gap: 15px; align-items: center; margin-bottom: 20px; flex-wrap: wrap;">
                <select id="scan_page_id" class="form-control" style="flex: 1; min-width: 250px; padding: 10px; border-radius: 8px; border: 1px solid var(--border-color);">
                    <option value="">-- Chọn Kênh Instagram để quét --</option>
                    <?php foreach ($ig_pages as $p): ?>
                        <option value="<?php echo $p['page_id']; ?>">
                            @<?php echo htmlspecialchars($p['ig_username'] ?: $p['page_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" onclick="fetchInstagramPosts()" class="ig-btn-primary" style="padding: 10px 20px;">
                    <span>🔄</span> Quét Bài Viết
                </button>
            </div>

            <div id="scanStatus" style="display: none; padding: 10px 15px; border-radius: 8px; margin-bottom: 15px;"></div>

            <div id="postsGrid" class="post-grid"></div>
        </div>
    </div>

    <!-- Tab 4: Thêm & Đồng Bộ Kênh -->
    <div id="tab-channels" class="ig-tab-pane">
        <div class="ig-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h3 style="margin: 0; color: var(--text-main);">➕ Danh Sách Kênh Instagram Business</h3>
                    <p style="color: var(--text-muted); font-size: 14px; margin-top: 4px; margin-bottom: 0;">
                        Tự động phát hiện các tài khoản Instagram Business được liên kết với Trang Facebook của bạn.
                    </p>
                </div>
                <button type="button" onclick="syncChannels()" class="ig-btn-primary" id="btnSync">
                    <span>🔄</span> Đồng Bộ Kênh Từ Facebook
                </button>
            </div>

            <div id="syncStatus" style="display: none; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px;"></div>

            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 14px;">
                    <thead>
                        <tr style="background: var(--bg-main, #f8fafc); border-bottom: 2px solid var(--border-color, #e2e8f0);">
                            <th style="padding: 12px;">Trang Facebook</th>
                            <th style="padding: 12px;">Tài Khoản Instagram</th>
                            <th style="padding: 12px;">Instagram ID</th>
                            <th style="padding: 12px;">Followers</th>
                            <th style="padding: 12px;">Trạng Thái Connection</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_pages)): ?>
                            <tr>
                                <td colspan="5" style="padding: 20px; text-align: center; color: var(--text-muted);">
                                    Chưa có Trang Facebook nào trong hệ thống. Hãy thêm Token Facebook tại menu <a href="token_management.php">Quản lý Token</a>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_pages as $page): ?>
                                <tr style="border-bottom: 1px solid var(--border-color, #e2e8f0);">
                                    <td style="padding: 12px; font-weight: 600;">
                                        <?php echo htmlspecialchars($page['page_name']); ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <?php if (!empty($page['ig_username'])): ?>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <?php if (!empty($page['ig_avatar'])): ?>
                                                    <img src="<?php echo htmlspecialchars($page['ig_avatar']); ?>" style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover;">
                                                <?php endif; ?>
                                                <a href="https://instagram.com/<?php echo htmlspecialchars($page['ig_username']); ?>" target="_blank" style="color: #e1306c; font-weight: bold; text-decoration: none;">
                                                    @<?php echo htmlspecialchars($page['ig_username']); ?>
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-style: italic;">Chưa kết nối IG</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px; font-family: monospace;">
                                        <?php echo htmlspecialchars($page['ig_account_id'] ?: '---'); ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <?php echo number_format($page['ig_followers_count']); ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <?php if (!empty($page['ig_account_id'])): ?>
                                            <span style="background: #dcfce7; color: #15803d; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;">✅ Đã liên kết</span>
                                        <?php else: ?>
                                            <span style="background: #fef3c7; color: #b45309; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;">⚠️ Chưa liên kết IG Business</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 25px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 15px; font-size: 13px; color: #1e40af;">
                <strong style="font-size: 14px;">💡 Hướng dẫn kết nối Instagram Business với Fanpage Facebook:</strong>
                <ol style="margin: 8px 0 0 20px; padding: 0; line-height: 1.6;">
                    <li>Chuyển đổi tài khoản Instagram của bạn sang loại <strong>Tài khoản Chuyên nghiệp / Business (Doanh nghiệp)</strong>.</li>
                    <li>Vào cài đặt Fanpage trên Facebook hoặc Meta Business Suite ➔ chọn <strong>Tài khoản đã liên kết (Linked Accounts)</strong> ➔ Kết nối với tài khoản Instagram.</li>
                    <li>Đăng nhập lại Facebook qua <a href="token_management.php" style="color: #1d4ed8; text-decoration: underline; font-weight: bold;">Quản lý Token</a> để cấp đủ các quyền Instagram mới.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<script>
function switchIgTab(tabId, btn) {
    document.querySelectorAll('.ig-tab-pane').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.ig-tab-btn').forEach(el => el.classList.remove('active'));
    
    document.getElementById(tabId).classList.add('active');
    btn.classList.add('active');
}

// Handle Form Story Submit
document.getElementById('formStory').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitStory');
    const alertBox = document.getElementById('storyResultAlert');

    btn.disabled = true;
    btn.innerHTML = '<span>⏳</span> Đang tải tệp & xử lý xuất bản...';
    alertBox.style.display = 'none';

    const formData = new FormData(this);

    fetch('actions/save_instagram_post.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alertBox.style.display = 'block';
        if (data.success) {
            alertBox.style.background = '#dcfce7';
            alertBox.style.color = '#166534';
            alertBox.innerHTML = '✅ ' + data.message;
            this.reset();
        } else {
            alertBox.style.background = '#fee2e2';
            alertBox.style.color = '#991b1b';
            alertBox.innerHTML = '❌ ' + data.message;
        }
        btn.disabled = false;
        btn.innerHTML = '<span>🚀</span> Đăng Story Ngay';
    })
    .catch(err => {
        alertBox.style.display = 'block';
        alertBox.style.background = '#fee2e2';
        alertBox.style.color = '#991b1b';
        alertBox.innerHTML = '❌ Lỗi kết nối máy chủ: ' + err.message;
        btn.disabled = false;
        btn.innerHTML = '<span>🚀</span> Đăng Story Ngay';
    });
});

// Handle Form Reels Submit
document.getElementById('formReels').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitReels');
    const alertBox = document.getElementById('reelsResultAlert');

    btn.disabled = true;
    btn.innerHTML = '<span>⏳</span> Đang xử lý tải video & đăng Reels (có thể mất 15-30 giây)...';
    alertBox.style.display = 'none';

    const formData = new FormData(this);

    fetch('actions/save_instagram_post.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alertBox.style.display = 'block';
        if (data.success) {
            alertBox.style.background = '#dcfce7';
            alertBox.style.color = '#166534';
            alertBox.innerHTML = '✅ ' + data.message;
            this.reset();
        } else {
            alertBox.style.background = '#fee2e2';
            alertBox.style.color = '#991b1b';
            alertBox.innerHTML = '❌ ' + data.message;
        }
        btn.disabled = false;
        btn.innerHTML = '<span>🎬</span> Đăng Reels Ngay';
    })
    .catch(err => {
        alertBox.style.display = 'block';
        alertBox.style.background = '#fee2e2';
        alertBox.style.color = '#991b1b';
        alertBox.innerHTML = '❌ Lỗi kết nối máy chủ: ' + err.message;
        btn.disabled = false;
        btn.innerHTML = '<span>🎬</span> Đăng Reels Ngay';
    });
});

// Fetch Instagram posts for scan tab
function fetchInstagramPosts() {
    const pageId = document.getElementById('scan_page_id').value;
    const statusBox = document.getElementById('scanStatus');
    const postsGrid = document.getElementById('postsGrid');

    if (!pageId) {
        alert('Vui lòng chọn Trang Instagram cần quét.');
        return;
    }

    statusBox.style.display = 'block';
    statusBox.style.background = '#e0f2fe';
    statusBox.style.color = '#0369a1';
    statusBox.innerHTML = '⏳ Đang quét danh sách bài viết từ Instagram API...';
    postsGrid.innerHTML = '';

    fetch('actions/instagram_actions.php?action=fetch_posts&page_id=' + encodeURIComponent(pageId))
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            statusBox.style.background = '#fee2e2';
            statusBox.style.color = '#991b1b';
            statusBox.innerHTML = '❌ ' + data.message;
            return;
        }

        statusBox.style.display = 'none';
        const posts = data.data;

        if (posts.length === 0) {
            postsGrid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; color: var(--text-muted); padding: 30px;">Không tìm thấy bài viết nào trên tài khoản Instagram này.</div>';
            return;
        }

        posts.forEach(p => {
            const card = document.createElement('div');
            card.className = 'post-item-card';

            const mediaType = (p.media_type || 'IMAGE').toUpperCase();
            const mediaUrl = p.media_url || p.thumbnail_url || '';
            const likes = p.like_count || 0;
            const comments = p.comments_count || 0;
            const views = p.views || 0;
            const caption = p.caption || '(Không có mô tả)';
            const permalink = p.permalink || '#';

            let mediaPreview = '';
            if (mediaType === 'VIDEO' || mediaType === 'REELS') {
                mediaPreview = `<video src="${mediaUrl}" controls poster="${p.thumbnail_url || ''}"></video>`;
            } else {
                mediaPreview = `<img src="${mediaUrl}" alt="Instagram Media">`;
            }

            card.innerHTML = `
                <div class="post-media-box">
                    ${mediaPreview}
                    <span class="post-type-badge">${mediaType}</span>
                </div>
                <div class="post-info">
                    <div>
                        <div class="post-caption" title="${caption.replace(/"/g, '&quot;')}">${caption}</div>
                        <div class="post-stats">
                            <span>❤️ ${likes} Thích</span>
                            <span>💬 ${comments} Bình luận</span>
                            <span>👁️ ${views} Lượt xem</span>
                        </div>
                    </div>
                    <div class="post-actions">
                        <a href="${permalink}" target="_blank" style="font-size: 12px; color: #2563eb; text-decoration: none; font-weight: 500;">🔗 Xem trên IG</a>
                        <button class="btn-delete-post" onclick="deleteIgPost('${pageId}', '${p.id}', this)">🗑️ Xóa bài</button>
                    </div>
                </div>
            `;
            postsGrid.appendChild(card);
        });
    })
    .catch(err => {
        statusBox.style.background = '#fee2e2';
        statusBox.style.color = '#991b1b';
        statusBox.innerHTML = '❌ Lỗi quét bài: ' + err.message;
    });
}

// Delete Instagram Post
function deleteIgPost(pageId, mediaId, btn) {
    if (!confirm('Bạn có chắc chắn muốn xóa bài viết này khỏi Instagram không? Hành động này không thể hoàn tác.')) {
        return;
    }

    btn.disabled = true;
    btn.innerText = 'Đang xóa...';

    const formData = new FormData();
    formData.append('action', 'delete_post');
    formData.append('page_id', pageId);
    formData.append('media_id', mediaId);

    fetch('actions/instagram_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            const card = btn.closest('.post-item-card');
            if (card) card.remove();
        } else {
            alert('❌ ' + data.message);
            btn.disabled = false;
            btn.innerText = '🗑️ Xóa bài';
        }
    })
    .catch(err => {
        alert('❌ Lỗi kết nối: ' + err.message);
        btn.disabled = false;
        btn.innerText = '🗑️ Xóa bài';
    });
}

// Sync Channels from FB API
function syncChannels() {
    const btn = document.getElementById('btnSync');
    const statusBox = document.getElementById('syncStatus');

    btn.disabled = true;
    btn.innerHTML = '<span>⏳</span> Đang đồng bộ...';
    statusBox.style.display = 'block';
    statusBox.style.background = '#e0f2fe';
    statusBox.style.color = '#0369a1';
    statusBox.innerHTML = '⏳ Đang gọi API Facebook để cập nhật thông tin Kênh Instagram Business...';

    fetch('actions/instagram_actions.php?action=sync_channels')
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            statusBox.style.background = '#dcfce7';
            statusBox.style.color = '#166534';
            statusBox.innerHTML = '✅ ' + data.message;
            setTimeout(() => { location.reload(); }, 1200);
        } else {
            statusBox.style.background = '#fee2e2';
            statusBox.style.color = '#991b1b';
            statusBox.innerHTML = '❌ ' + data.message;
            btn.disabled = false;
            btn.innerHTML = '<span>🔄</span> Đồng Bộ Kênh Từ Facebook';
        }
    })
    .catch(err => {
        statusBox.style.background = '#fee2e2';
        statusBox.style.color = '#991b1b';
        statusBox.innerHTML = '❌ Lỗi đồng bộ: ' + err.message;
        btn.disabled = false;
        btn.innerHTML = '<span>🔄</span> Đồng Bộ Kênh Từ Facebook';
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
