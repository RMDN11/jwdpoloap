<?php
declare(strict_types=1);

$index = file_get_contents(__DIR__ . '/../index.php');
$page = file_get_contents(__DIR__ . '/../pages/templates.php');

if ($index === false || $page === false) {
    throw new RuntimeException('Cannot read CRM template workspace files.');
}

if (!str_contains($index, "'templates'")) {
    throw new RuntimeException('Template Pesan route is missing from CRM allowed pages.');
}

foreach (['Template Pesan', 'Tambah Template', 'Library', 'template-copy', 'template-edit'] as $required) {
    if (!str_contains($page, $required)) {
        throw new RuntimeException("Missing template workspace element: {$required}");
    }
}

foreach (['poloap_templates', 'index.php?page=templates', 'components/topbar.php', 'components/bottom-nav.php'] as $required) {
    if (!str_contains($page . $index, $required)) {
        throw new RuntimeException("Missing CRM template integration: {$required}");
    }
}

echo "CRM Template workspace test passed.\n";
