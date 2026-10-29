<?php
declare(strict_types=1);

/**
 * Lightweight automated log rotation utility.
 * Prevents log files from growing unbounded by truncating to the most recent tail.
 */

if (!function_exists('crmRotateLogFile')) {
    /**
     * Rotates/truncates a log file if it exceeds $maxBytes.
     * Retains the last $keepBytes plus a rotation notice header.
     *
     * @param string $filePath Absolute or relative path to log file.
     * @param int $maxBytes File size threshold in bytes (default 2 MB = 2,097,152 bytes).
     * @param int $keepBytes Bytes of recent log history to preserve (default 200 KB = 204,800 bytes).
     * @return bool True if file was rotated, false if not rotated or file does not exist.
     */
    function crmRotateLogFile(string $filePath, int $maxBytes = 2097152, int $keepBytes = 204800): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        clearstatcache(true, $filePath);
        $size = @filesize($filePath);
        if ($size === false || $size < $maxBytes) {
            return false;
        }

        $fp = @fopen($filePath, 'c+');
        if (!$fp) {
            return false;
        }

        $rotated = false;
        if (flock($fp, LOCK_EX)) {
            $curSize = fstat($fp)['size'] ?? $size;
            if ($curSize >= $maxBytes) {
                $offset = max(0, $curSize - $keepBytes);
                fseek($fp, $offset);
                $tail = stream_get_contents($fp);
                if ($tail === false) {
                    $tail = '';
                }

                // If offset > 0, drop the first partial line so log starts on a clean line
                if ($offset > 0) {
                    $firstNl = strpos($tail, "\n");
                    if ($firstNl !== false) {
                        $tail = substr($tail, $firstNl + 1);
                    }
                }

                $header = sprintf(
                    "[LOG ROTATED %s - Previous size: %s MB, trimmed to %s KB]\n",
                    date('Y-m-d H:i:s'),
                    number_format($curSize / 1048576, 2),
                    number_format(strlen($tail) / 1024, 1)
                );

                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, $header . $tail);
                fflush($fp);
                $rotated = true;
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return $rotated;
    }
}

if (!function_exists('crmAppendLog')) {
    /**
     * Appends text to a log file, rotating it first if it exceeds max size.
     *
     * @param string $filePath Path to log file.
     * @param string $content Content to append.
     * @param int $maxBytes Size threshold for rotation (default 2 MB).
     * @param int $keepBytes Recent history bytes to keep upon rotation (default 200 KB).
     * @return bool
     */
    function crmAppendLog(string $filePath, string $content, int $maxBytes = 2097152, int $keepBytes = 204800): bool
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        crmRotateLogFile($filePath, $maxBytes, $keepBytes);
        return @file_put_contents($filePath, $content, FILE_APPEND | LOCK_EX) !== false;
    }
}
