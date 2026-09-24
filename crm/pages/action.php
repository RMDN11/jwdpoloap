<?php
$crmTitle = 'Action';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!crmVerifyCsrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Permintaan tidak valid.'); }
    $actionId = (int)($_POST['action_id'] ?? 0);
    if ($actionId > 0 && isset($_POST['complete_action'])) {
        $stmt = $conn->prepare("UPDATE crm_actions SET status='completed', completed_at=NOW(), updated_at=NOW() WHERE id=? AND status='pending'");
        if ($stmt) { $stmt->bind_param('i', $actionId); $stmt->execute(); $stmt->close(); }
        header('Location: ?page=action'); exit;
    }
}
$now = date('Y-m-d H:i:s'); $todayStart = date('Y-m-d 00:00:00'); $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
$counts = ['overdue'=>0,'today'=>0,'upcoming'=>0,'completed'=>0];
$queries = [
    'overdue' => "SELECT COUNT(*) AS total FROM crm_actions WHERE status='pending' AND due_at IS NOT NULL AND due_at < ?",
    'today' => "SELECT COUNT(*) AS total FROM crm_actions WHERE status='pending' AND due_at >= ? AND due_at < ?",
    'upcoming' => "SELECT COUNT(*) AS total FROM crm_actions WHERE status='pending' AND (due_at >= ? OR due_at IS NULL)",
    'completed' => "SELECT COUNT(*) AS total FROM crm_actions WHERE status='completed'"
];
foreach ($queries as $key=>$sql) { $stmt=$conn->prepare($sql); if(!$stmt) continue; if($key==='today') $stmt->bind_param('ss',$todayStart,$tomorrowStart); elseif($key==='overdue') $stmt->bind_param('s',$now); elseif($key==='upcoming') $stmt->bind_param('s',$tomorrowStart); $stmt->execute(); $counts[$key]=(int)($stmt->get_result()->fetch_assoc()['total']??0); $stmt->close(); }
$actions=[]; $result=$conn->query("SELECT id,contact_nowa,contact_name,title,description,type,priority,status,due_at,created_at FROM crm_actions ORDER BY CASE WHEN status='pending' THEN 0 ELSE 1 END, CASE WHEN due_at IS NULL THEN 1 ELSE 0 END, due_at ASC, id DESC LIMIT 50"); if($result) while($row=$result->fetch_assoc()) $actions[]=$row;
function crmActionDueLabel(?string $dueAt): string { if(!$dueAt)return 'Tanpa deadline'; $ts=strtotime($dueAt); return $ts?date('d M · H:i',$ts):'Tanpa deadline'; }
function crmActionDueClass(?string $dueAt,string $status): string { if($status==='completed')return 'is-complete'; if(!$dueAt)return ''; return strtotime($dueAt)<time()?'is-overdue':''; }
?>
<section class="page-head action-page-head"><div><span class="eyebrow">Workspace</span><h1>Action</h1><p>Kelola pekerjaan yang perlu ditindaklanjuti dari satu tempat.</p></div><button type="button" class="action-create-btn" disabled title="Create Action akan masuk tahap berikutnya"><i class="fa-solid fa-plus"></i> Action</button></section>
<div class="action-summary-grid"><div class="action-summary-card is-overdue"><span>🔴</span><strong><?=$counts['overdue']?></strong><small>Terlambat</small></div><div class="action-summary-card is-today"><span>🟠</span><strong><?=$counts['today']?></strong><small>Hari ini</small></div><div class="action-summary-card is-upcoming"><span>🟡</span><strong><?=$counts['upcoming']?></strong><small>Mendatang</small></div><div class="action-summary-card is-completed"><span>🟢</span><strong><?=$counts['completed']?></strong><small>Selesai</small></div></div>
<section class="section action-workspace-section"><div class="section-heading"><h2>Daftar Action</h2><span><?=count($actions)?> terakhir</span></div><div class="action-workspace-list"><?php if(!$actions): ?><div class="empty-state action-empty"><i class="fa-regular fa-circle-check"></i><strong>Belum ada Action</strong><p>Action yang dibuat dari workflow CRM akan muncul di sini.</p></div><?php else: foreach($actions as $item): ?><article class="crm-action-card <?=htmlspecialchars(crmActionDueClass($item['due_at'],$item['status']))?>"><div class="crm-action-icon"><i class="fa-solid <?=$item['status']==='completed'?'fa-check':'fa-list-check'?>"></i></div><div class="crm-action-body"><div class="crm-action-top"><strong><?=htmlspecialchars($item['title'])?></strong><span class="crm-action-priority priority-<?=htmlspecialchars($item['priority'])?>"><?=htmlspecialchars(ucfirst($item['priority']))?></span></div><?php if(!empty($item['contact_name'])):?><small><?=htmlspecialchars($item['contact_name'])?><?=$item['contact_nowa']?' · '.htmlspecialchars($item['contact_nowa']):''?></small><?php endif;?><?php if(!empty($item['description'])):?><p><?=htmlspecialchars($item['description'])?></p><?php endif;?><div class="crm-action-meta"><span class="crm-action-due"><i class="fa-regular fa-clock"></i> <?=htmlspecialchars(crmActionDueLabel($item['due_at']))?></span><span><?=htmlspecialchars(ucfirst($item['type']))?></span></div></div><?php if($item['status']==='pending'): ?><form method="post" class="crm-action-complete-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(crmCsrfToken())?>"><input type="hidden" name="action_id" value="<?=(int)$item['id']?>"><button name="complete_action" value="1" aria-label="Tandai selesai"><i class="fa-solid fa-check"></i></button></form><?php endif;?></article><?php endforeach; endif;?></div></section>
