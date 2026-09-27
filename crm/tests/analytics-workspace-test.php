<?php
declare(strict_types=1);
$index=file_get_contents(__DIR__.'/../index.php');$page=file_get_contents(__DIR__.'/../pages/analytics.php');$more=file_get_contents(__DIR__.'/../pages/more.php');$css=file_get_contents(__DIR__.'/../assets/css/analytics.css');$prospect=file_get_contents(__DIR__.'/../config/prospect.php');
if($index===false||$page===false||$more===false||$css===false||$prospect===false)throw new RuntimeException('Cannot read CRM Analytics workspace files.');
foreach(["'analytics'",'assets/css/analytics.css','assets/css/workspace-core.css'] as $x)if(!str_contains($index,$x))throw new RuntimeException("Missing Analytics integration: {$x}");
foreach(['Analytics CRM','crm-workspace-page','crm-analytics-filter','crm-analytics-kpis','crm-analytics-chart-card','crm-analytics-quality','crm-analytics-table-card','crmProspectClassifyMessage','crmGetDisqualifiedNumbers','crmGetBlockedNumbers','calon_peserta','export=csv'] as $x)if(!str_contains($page,$x))throw new RuntimeException("Missing Analytics element: {$x}");
if(!str_contains($more,'index.php?page=analytics'))throw new RuntimeException('More does not route to native Analytics.');
foreach(['crm-analytics-kpis','crm-analytics-main-grid','crm-analytics-chart-wrap','@media(max-width:560px)'] as $x)if(!str_contains($css,$x))throw new RuntimeException("Missing responsive Analytics rule: {$x}");
if(str_contains($page,'<style>'))throw new RuntimeException('Analytics contains inline style.');
if(!str_contains($prospect,'crmIsEligibleProspect'))throw new RuntimeException('Analytics is not using prospect eligibility rules.');
echo "CRM Analytics workspace test passed.\n";
