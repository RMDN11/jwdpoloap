<?php
declare(strict_types=1);

$index = file_get_contents(__DIR__ . '/../index.php');
$page = file_get_contents(__DIR__ . '/../pages/templates.php');
$core = file_get_contents(__DIR__ . '/../assets/css/workspace-core.css');
$css = file_get_contents(__DIR__ . '/../assets/css/templates.css');

if ($index === false || $page === false || $core === false || $css === false) {
    throw new RuntimeException('Cannot read CRM template workspace files.');
}

if (!str_contains($index, "'templates'")) {
    throw new RuntimeException('Template Pesan route is missing from CRM allowed pages.');
}

foreach (['Template Pesan', 'Tambah Template', 'Library', 'template-copy', 'template-edit', 'crm-workspace-page', 'crm-workspace-grid', 'crm-workspace-card'] as $required) {
    if (!str_contains($page, $required)) {
        throw new RuntimeException("Missing template workspace element: {$required}");
    }
}

foreach (['poloap_templates', 'index.php?page=templates', 'components/topbar.php', 'components/bottom-nav.php'] as $required) {
    if (!str_contains($page . $index, $required)) {
        throw new RuntimeException("Missing CRM template integration: {$required}");
    }
}

foreach (['workspace-core.css', 'templates.css', 'max-width:1280px', '--crm-green:#168044', 'padding-bottom:104px'] as $required) {
    if (!str_contains($core . $css . $index, $required)) {
        throw new RuntimeException("Missing shared workspace core element: {$required}");
    }
}

if (str_contains($page, '<style>')) {
    throw new RuntimeException('Template Pesan still contains inline style blocks.');
}

echo "CRM Template workspace test passed.\n";
