<?php
declare(strict_types=1);

function crmChatDirectoryTables(mysqli $conn): array {
    static $tables = null;
    if (is_array($tables)) return $tables;
    $tables = ['peserta', 'pengampu', 'pengajar'];
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
                  directory.nowa = {$alias}.nowa
                  OR directory.nowa = CONCAT('0', SUBSTRING({$alias}.nowa, 3))
                  OR directory.nowa = CONCAT('+', {$alias}.nowa)
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
