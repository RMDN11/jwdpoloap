<?php
declare(strict_types=1);

/**
 * Shared helpers for the Plotting workspace.
 * CSV rows remain filesystem-only; these helpers only calculate derived values.
 */

function plottingNormalize(string $value): string
{
    $value = preg_replace('/\x{FEFF}/u', '', $value) ?? $value;
    $value = trim($value);
    return strtolower(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function plottingRetentionPercent(array $previousNames, array $currentNames): float
{
    $previous = [];
    foreach ($previousNames as $name) {
        $key = plottingNormalize((string)$name);
        if ($key !== '') {
            $previous[$key] = true;
        }
    }

    if (!$previous) {
        return 0.0;
    }

    $current = [];
    foreach ($currentNames as $name) {
        $key = plottingNormalize((string)$name);
        if ($key !== '') {
            $current[$key] = true;
        }
    }

    $continued = count(array_intersect_key($previous, $current));
    return round(($continued / count($previous)) * 100, 2);
}
