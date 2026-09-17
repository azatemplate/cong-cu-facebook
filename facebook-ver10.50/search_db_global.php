<?php
// search_db_global.php
require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/plain; charset=utf-8');

$search = '496920799978163';
echo "Global database search for: $search\n\n";

try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $columns = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
        $where_clauses = [];
        $params = [];
        foreach ($columns as $col) {
            $col_name = $col['Field'];
            $where_clauses[] = "`$col_name` LIKE ?";
            $params[] = "%$search%";
        }
        
        if (!empty($where_clauses)) {
            $sql = "SELECT * FROM `$table` WHERE " . implode(" OR ", $where_clauses) . " LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($results)) {
                echo "FOUND IN TABLE: $table\n";
                print_r($results);
                echo "-------------------------------------\n\n";
            }
        }
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

exit;
?>
