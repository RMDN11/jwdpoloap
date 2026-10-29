<?php
declare(strict_types=1);

/**
 * Automated Database Log Retention & Pruning Library.
 * Keeps log tables fast and compact by safely pruning ancient records in batches.
 */

if (!function_exists('crmPruneAutoReplyLogs')) {
    /**
     * Prune auto_reply_logs older than $days in chunks to avoid table locks.
     *
     * @param mysqli $conn
     * @param int $days Number of days to retain (default 90 days)
     * @param int $batchSize Chunk size per DELETE query (default 5000)
     * @return int Total deleted rows
     */
    function crmPruneAutoReplyLogs(mysqli $conn, int $days = 90, int $batchSize = 5000): int
    {
        $tableExists = $conn->query("SHOW TABLES LIKE 'auto_reply_logs'")->num_rows > 0;
        if (!$tableExists) {
            return 0;
        }

        $stmt = $conn->prepare("SELECT MAX(id) AS cutoff_id FROM auto_reply_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $days);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        $cutoffId = (int)($row['cutoff_id'] ?? 0);
        if ($cutoffId <= 0) {
            return 0;
        }

        $totalDeleted = 0;
        while (true) {
            $delStmt = $conn->prepare("DELETE FROM auto_reply_logs WHERE id <= ? LIMIT ?");
            if (!$delStmt) {
                break;
            }
            $delStmt->bind_param('ii', $cutoffId, $batchSize);
            $delStmt->execute();
            $affected = $delStmt->affected_rows;
            $delStmt->close();

            if ($affected <= 0) {
                break;
            }
            $totalDeleted += $affected;

            if ($affected < $batchSize) {
                break;
            }
        }

        return $totalDeleted;
    }
}

if (!function_exists('crmPruneLogWaGroupBroadcasts')) {
    /**
     * Prune obsolete [GRUP] halaqoh broadcast log entries older than $days.
     *
     * @param mysqli $conn
     * @param int $days Number of days to retain group broadcast logs (default 14 days)
     * @param int $batchSize
     * @return int Total deleted rows
     */
    function crmPruneLogWaGroupBroadcasts(mysqli $conn, int $days = 14, int $batchSize = 2000): int
    {
        $tableExists = $conn->query("SHOW TABLES LIKE 'log_wa'")->num_rows > 0;
        if (!$tableExists) {
            return 0;
        }

        $totalDeleted = 0;
        while (true) {
            $stmt = $conn->prepare("DELETE FROM log_wa WHERE message LIKE '%[GRUP]%' AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT ?");
            if (!$stmt) {
                break;
            }
            $stmt->bind_param('ii', $days, $batchSize);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected <= 0) {
                break;
            }
            $totalDeleted += $affected;

            if ($affected < $batchSize) {
                break;
            }
        }

        return $totalDeleted;
    }
}

if (!function_exists('crmPruneLogWaLegacy')) {
    /**
     * Prune old general log_wa entries older than $days, preserving form submissions.
     *
     * @param mysqli $conn
     * @param int $days Number of days to retain general logs (default 90 days)
     * @param int $batchSize
     * @return int Total deleted rows
     */
    function crmPruneLogWaLegacy(mysqli $conn, int $days = 90, int $batchSize = 2000): int
    {
        $tableExists = $conn->query("SHOW TABLES LIKE 'log_wa'")->num_rows > 0;
        if (!$tableExists) {
            return 0;
        }

        // Check if is_form_sent column exists
        $colCheck = $conn->query("SHOW COLUMNS FROM log_wa LIKE 'is_form_sent'");
        $hasFormCol = ($colCheck && $colCheck->num_rows > 0);

        $totalDeleted = 0;
        while (true) {
            $sql = $hasFormCol
                ? "DELETE FROM log_wa WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND (is_form_sent IS NULL OR is_form_sent = 0) LIMIT ?"
                : "DELETE FROM log_wa WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT ?";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                break;
            }
            $stmt->bind_param('ii', $days, $batchSize);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected <= 0) {
                break;
            }
            $totalDeleted += $affected;

            if ($affected < $batchSize) {
                break;
            }
        }

        return $totalDeleted;
    }
}

if (!function_exists('crmRunAllDbRetention')) {
    /**
     * Execute full retention cycle and return summary report.
     *
     * @param mysqli $conn
     * @param array $options
     * @return array
     */
    function crmRunAllDbRetention(mysqli $conn, array $options = []): array
    {
        $autoReplyDays = (int)($options['auto_reply_days'] ?? 90);
        $groupBroadcastDays = (int)($options['group_broadcast_days'] ?? 14);
        $logWaLegacyDays = (int)($options['log_wa_legacy_days'] ?? 90);

        $deletedAutoReply = crmPruneAutoReplyLogs($conn, $autoReplyDays);
        $deletedGroups = crmPruneLogWaGroupBroadcasts($conn, $groupBroadcastDays);
        $deletedLogWa = crmPruneLogWaLegacy($conn, $logWaLegacyDays);

        // Run optimize to reclaim disk space after large delete
        if ($deletedAutoReply > 0) {
            @$conn->query("OPTIMIZE TABLE auto_reply_logs");
        }
        if ($deletedGroups > 0 || $deletedLogWa > 0) {
            @$conn->query("OPTIMIZE TABLE log_wa");
        }

        return [
            'status' => 'success',
            'timestamp' => date('Y-m-d H:i:s'),
            'auto_reply_logs_deleted' => $deletedAutoReply,
            'log_wa_group_broadcasts_deleted' => $deletedGroups,
            'log_wa_legacy_deleted' => $deletedLogWa,
            'total_deleted' => $deletedAutoReply + $deletedGroups + $deletedLogWa,
        ];
    }
}
