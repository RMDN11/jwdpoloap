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

function plottingCurrentMatchesCategory(array $current, string $category, int $participantThreshold, int $salaryThreshold): bool
{
    $participants = (int)($current['total_peserta'] ?? 0);
    $salary = (int)($current['total_gaji'] ?? 0);
    return match ($category) {
        'belum_ada' => $participants === 0,
        'peserta_dikit' => $participants < $participantThreshold,
        'gaji_rendah' => $salary < $salaryThreshold,
        'keduanya' => $participants < $participantThreshold && $salary < $salaryThreshold,
        default => true,
    };
}

function plottingCurrentMatchesCategories(array $current, array $categories, int $participantThreshold, int $salaryThreshold): bool
{
    foreach ($categories as $category) {
        if (!plottingCurrentMatchesCategory($current, (string)$category, $participantThreshold, $salaryThreshold)) {
            return false;
        }
    }
    return true;
}
