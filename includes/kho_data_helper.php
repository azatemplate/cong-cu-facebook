<?php
// includes/kho_data_helper.php

if (!function_exists('get_url_from_kho_data')) {
    /**
     * Lấy 1 URL từ Kho Data theo nhóm và chế độ (random / dedup)
     *
     * @param PDO $pdo
     * @param int $account_id
     * @param int $group_id
     * @param string $mode 'dedup' | 'random'
     * @return string|null URL tìm thấy hoặc null nếu rỗng
     */
    function get_url_from_kho_data($pdo, $account_id, $group_id, $mode = 'dedup') {
        if (!$group_id || !$account_id || !$pdo) {
            return null;
        }

        // Verify group exists
        $stmt_g = $pdo->prepare("SELECT id FROM media_data_groups WHERE id = ?");
        $stmt_g->execute([$group_id]);
        if (!$stmt_g->fetch()) {
            return null;
        }

        if ($mode === 'dedup') {
            // Đăng chống trùng: Lấy 1 URL và XÓA NGAY KHỎI KHO
            $stmt_item = $pdo->prepare("SELECT id, url FROM media_data_items WHERE group_id = ? ORDER BY id ASC LIMIT 1 FOR UPDATE");
            $stmt_item->execute([$group_id]);
            $item = $stmt_item->fetch(PDO::FETCH_ASSOC);
            if ($item) {
                $stmt_del = $pdo->prepare("DELETE FROM media_data_items WHERE id = ?");
                $stmt_del->execute([$item['id']]);
                return $item['url'];
            }
            return null;
        } else {
            // Random: Lấy 1 URL ngẫu nhiên, KHÔNG xóa
            $stmt_item = $pdo->prepare("SELECT url FROM media_data_items WHERE group_id = ? ORDER BY RAND() LIMIT 1");
            $stmt_item->execute([$group_id]);
            $item = $stmt_item->fetch(PDO::FETCH_ASSOC);
            return $item ? $item['url'] : null;
        }
    }
}
