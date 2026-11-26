<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config/automation-followup.php';
$enginePath = __DIR__ . '/../lib/automation-engine.php';
$autoReplyPath = __DIR__ . '/../../auto_reply_engine.php';
$webhookPath = __DIR__ . '/../../webhook.php';

$cfg = (string)file_get_contents($configPath);
$eng = (string)file_get_contents($enginePath);
$ar = (string)file_get_contents($autoReplyPath);
$wh = (string)file_get_contents($webhookPath);

// 1. Sleep adjustment must happen before anti-clash checks in crmAutomationRegisterQueue
$sleepPos = strpos($cfg, '$scheduledTs = crmAutomationAdjustForSleep($conn, $scheduledTs);');
$clashPos = strpos($cfg, "SELECT COUNT(*) AS clash_count");
if ($sleepPos === false || $clashPos === false || $sleepPos > $clashPos) {
    throw new RuntimeException("crmAutomationAdjustForSleep must run BEFORE anti-clash check in crmAutomationRegisterQueue");
}

// 2. Same-contact anti-clash guard must exist
if (!str_contains($cfg, 'latest_same')) {
    throw new RuntimeException("Missing same-contact anti-clash check in crmAutomationRegisterQueue");
}

// 3. auto_webhook cadence skips steps 3 and 6 (3 clean touches: 2h -> H+1 -> H+3)
if (!str_contains($cfg, "if (\$triggerSource === 'auto_webhook' && in_array(\$num, [3, 6], true))")) {
    throw new RuntimeException("auto_webhook sequence must skip steps 3 and 6");
}

// 4. Reply summary & smart cancellation in engine
if (!str_contains($cfg, 'function crmAutomationGetReplySummary') || !str_contains($eng, 'crmAutomationGetReplySummary')) {
    throw new RuntimeException("Engine must use crmAutomationGetReplySummary to avoid killing H+1/H+3 on Day-0 Q&A");
}

// 5. Follow Up 2 includes 3 clear package choices
if (!str_contains($cfg, 'Rp135rb/bln') || !str_contains($cfg, 'Rp245rb/bln') || !str_contains($cfg, 'Rp155rb/bln')) {
    throw new RuntimeException("Follow Up 2 must present clear Normal/Intensif/Cilik options");
}

// 6. AutoReplyEngine separates opening greetings (3m schedule) from mid-chat replies
if (!str_contains($ar, '$isMidChatRule') || !str_contains($ar, '$isOpeningGreeting')) {
    throw new RuntimeException("AutoReplyEngine must separate opening greeting delay from mid-chat quick replies");
}

// 7. Webhook logs admin HP outbound messages via crm_lid_map / @s.whatsapp.net
if (!str_contains($wh, 'crm_lid_map') || !str_contains($wh, "'wa_hp'")) {
    throw new RuntimeException("webhook.php must log outbound admin HP replies into crm_messages");
}

echo "automation-followup-conversion-opt-test: OK\n";
