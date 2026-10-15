<?php
declare(strict_types=1);

function crmChatDirectoryTables(mysqli $conn): array {
    static $tables = null;
    if (is_array($tables)) return $tables;
    $tables = [];
    $result = $conn->query(
        "SELECT c.table_name
         FROM information_schema.columns c
         WHERE c.table_schema = DATABASE()
           AND c.table_name IN ('peserta', 'pengampu', 'pengajar')
           AND c.column_name = 'nowa'"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $table = (string)($row['table_name'] ?? '');
            if (in_array($table, ['peserta', 'pengampu', 'pengajar'], true)) {
                $tables[] = $table;
            }
        }
    }
    $tables = array_values(array_unique($tables));
    sort($tables);
    return $tables;
}

function crmChatKnownContactSql(mysqli $conn, string $alias = 'crm_conversations'): string {
    $checks = [];
    foreach (crmChatDirectoryTables($conn) as $table) {
        $checks[] = "EXISTS (
            SELECT 1 FROM {$table} directory
            WHERE directory.nowa IS NOT NULL
              AND directory.nowa != ''
              AND (
                  BINARY directory.nowa = BINARY {$alias}.nowa
                  OR BINARY directory.nowa = BINARY CONCAT('0', SUBSTRING({$alias}.nowa, 3))
                  OR BINARY directory.nowa = BINARY CONCAT('+', {$alias}.nowa)
              )
        )";
    }
    return $checks ? '(' . implode(' OR ', $checks) . ')' : '0=1';
}

function crmChatRoomSql(mysqli $conn, string $room, string $alias = 'crm_conversations'): string {
    if ($room === 'people') return crmChatKnownContactSql($conn, $alias);
    if ($room === 'other') return 'NOT ' . crmChatKnownContactSql($conn, $alias);
    return '1=1';
}

/** Return true only when a number is present in the teacher/instructor directories. */
function crmChatIsTeacherNumber(mysqli $conn, string $nowa): bool {
    $number = preg_replace('/\\D+/', '', $nowa) ?? '';
    if ($number === '') return false;
    if (str_starts_with($number, '0')) $number = '62' . substr($number, 1);
    $variants = array_values(array_unique(array_filter([
        $number,
        str_starts_with($number, '62') ? '0' . substr($number, 2) : '',
        '+' . $number,
        $nowa,
    ])));
    $result = $conn->query(
        "SELECT table_name FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name IN ('pengajar', 'pengampu')
           AND column_name = 'nowa'"
    );
    if (!$result) return false;
    $tables = [];
    while ($row = $result->fetch_assoc()) {
        $table = (string)$row['table_name'];
        if (in_array($table, ['pengajar', 'pengampu'], true)) $tables[] = $table;
    }
    foreach ($tables as $table) {
        $placeholders = implode(',', array_fill(0, count($variants), '?'));
        $stmt = $conn->prepare("SELECT 1 FROM {$table} WHERE nowa IN ({$placeholders}) LIMIT 1");
        if (!$stmt) continue;
        $types = str_repeat('s', count($variants));
        $stmt->bind_param($types, ...$variants);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($found) return true;
    }
    return false;
}
