<?php
declare(strict_types=1);

$pagePath = __DIR__ . '/../pages/automation-followup.php';
if (!file_exists($pagePath)) {
    throw new RuntimeException("Missing page file: {$pagePath}");
}

$pageContent = file_get_contents($pagePath);

// 1. Verify $perPage and $currentPage are initialized at the top before any tab handling
$perPagePos = strpos($pageContent, '$perPage =');
$queueQueryPos = strpos($pageContent, "if (\$tab === 'queue')");

if ($perPagePos === false) {
    throw new RuntimeException("Missing \$perPage definition");
}
if ($queueQueryPos === false) {
    throw new RuntimeException("Missing queue tab query block");
}
if ($perPagePos > $queueQueryPos) {
    throw new RuntimeException("\$perPage is defined at pos {$perPagePos} AFTER queue query at pos {$queueQueryPos}, causing DivisionByZeroError on tab=queue!");
}

// 2. Verify queue pagination uses valid perPage
if (preg_match('/\$totalQueue\s*\/\s*\$perPage/', $pageContent) && $perPagePos > $queueQueryPos) {
    throw new RuntimeException("Division by undefined \$perPage detected in queue tab");
}

// 3. Verify Tab Queue layout has contact avatar and clean filter bar
if (!str_contains($pageContent, 'crm-contact-cell') || !str_contains($pageContent, 'crm-avatar')) {
    throw new RuntimeException("Tab Queue should have consistent contact cell and avatar styling");
}

echo "automation-followup-queue-bug-test: OK\n";
