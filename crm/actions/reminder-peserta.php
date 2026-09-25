<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}
$action = trim((string)($_POST['action'] ?? ''));

function rpRedirect(string $message, string $type='success'): never {
    $_SESSION['crm_flash'] = ['type'=>$type,'message'=>$message];
    header('Location: ../index.php?page=reminder-peserta');
    exit;
}
function rpSend(string $number, string $message, string $apiUrl, string $apiToken): array {
    $payload = json_encode(['recipient_type'=>'individual','to'=>$number,'type'=>'text','text'=>['body'=>$message]], JSON_UNESCAPED_UNICODE);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL=>$apiUrl, CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiToken],
        CURLOPT_TIMEOUT=>20, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>false
    ]);
    curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $error=curl_error($ch);
    curl_close($ch);
    return ['ok'=>!$error && $code>=200 && $code<300,'detail'=>$error?:('HTTP '.$code)];
}

if ($action === 'delete_request') {
    $id=(int)($_POST['request_id']??0);
    if($id<=0)rpRedirect('Permintaan tidak valid.','error');
    $stmt=$conn->prepare('DELETE FROM reminder_requests WHERE id=?');
    if(!$stmt)rpRedirect('Gagal menyiapkan penghapusan.','error');
    $stmt->bind_param('i',$id); $ok=$stmt->execute(); $stmt->close();
    rpRedirect($ok?'Permintaan reminder berhasil dihapus.':'Gagal menghapus permintaan.',$ok?'success':'error');
}
if ($action === 'delete_all') {
    $ok=$conn->query('DELETE FROM reminder_requests');
    rpRedirect($ok?'Semua permintaan reminder berhasil dihapus.':'Gagal menghapus semua permintaan.',$ok?'success':'error');
}
if ($action === 'save_template') {
    $id=(int)($_POST['template_id']??0); $name=trim((string)($_POST['template_name']??'')); $content=trim((string)($_POST['template_content']??''));
    if($name===''||$content==='')rpRedirect('Nama dan isi template wajib diisi.','error');
    if(mb_strlen($name)>120||mb_strlen($content)>4000)rpRedirect('Template terlalu panjang.','error');
    if($id>0){
        $stmt=$conn->prepare('UPDATE reminder_templates SET name=?, content=? WHERE id=?');
        if(!$stmt)rpRedirect('Gagal menyiapkan update template.','error');
        $stmt->bind_param('ssi',$name,$content,$id);$ok=$stmt->execute();$stmt->close();
        rpRedirect($ok?'Template berhasil diperbarui.':'Gagal memperbarui template.',$ok?'success':'error');
    }
    $stmt=$conn->prepare('INSERT INTO reminder_templates (name,content) VALUES (?,?)');
    if(!$stmt)rpRedirect('Gagal menyiapkan template baru.','error');
    $stmt->bind_param('ss',$name,$content);$ok=$stmt->execute();$stmt->close();
    rpRedirect($ok?'Template baru berhasil disimpan.':'Gagal menyimpan template.',$ok?'success':'error');
}
if ($action === 'delete_template') {
    $id=(int)($_POST['template_id']??0);
    if($id<=0)rpRedirect('Template tidak valid.','error');
    $stmt=$conn->prepare('DELETE FROM reminder_templates WHERE id=?');
    if(!$stmt)rpRedirect('Gagal menyiapkan penghapusan template.','error');
    $stmt->bind_param('i',$id);$ok=$stmt->execute();$stmt->close();
    rpRedirect($ok?'Template berhasil dihapus.':'Gagal menghapus template.',$ok?'success':'error');
}
if ($action !== 'send_one' && $action !== 'send_all') rpRedirect('Aksi reminder tidak dikenali.','error');

$templateId=(int)($_POST['template_id']??0);
$stmt=$conn->prepare('SELECT id,name,content FROM reminder_templates WHERE id=? LIMIT 1');
if(!$stmt)rpRedirect('Gagal mengambil template.','error');
$stmt->bind_param('i',$templateId);$stmt->execute();$template=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$template)rpRedirect('Template tidak ditemukan.','error');

$requests=[];
if($action==='send_one'){
    $requestId=(int)($_POST['request_id']??0);
    $stmt=$conn->prepare("SELECT id,peserta_id,peserta_nama,peserta_nowa,halaqoh,status FROM reminder_requests WHERE id=? LIMIT 1");
    if(!$stmt)rpRedirect('Gagal mengambil permintaan peserta.','error');
    $stmt->bind_param('i',$requestId);$stmt->execute();$request=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$request)rpRedirect('Permintaan peserta tidak ditemukan.','error');
    if(($request['status']??'')==='terkirim')rpRedirect('Permintaan ini sudah terkirim.','error');
    $requests[]=$request;
}else{
    $result=$conn->query("SELECT id,peserta_id,peserta_nama,peserta_nowa,halaqoh,status FROM reminder_requests WHERE status='menunggu' ORDER BY created_at ASC LIMIT 300");
    if($result)$requests=$result->fetch_all(MYSQLI_ASSOC);
    if(!$requests)rpRedirect('Tidak ada permintaan yang masih menunggu.','error');
}

$logStmt=$conn->prepare('INSERT INTO log_wa (nowa,nama,message,created_at) VALUES (?,?,?,NOW())');
$historyStmt=$conn->prepare("INSERT INTO crm_message_history (nowa,nama,template_id,template_name,message,sent_at,status) VALUES (?,?,?,?,?,NOW(),'sent')");
$updateStmt=$conn->prepare("UPDATE reminder_requests SET status='terkirim',updated_at=NOW() WHERE id=?");
$success=0;$failed=0;$failedNames=[];

foreach($requests as $request){
    $name=trim((string)$request['peserta_nama'])?:'Peserta';
    $number=crmNormalizeNumber((string)$request['peserta_nowa']);
    if($number===''){ $failed++;$failedNames[]=$name;continue; }
    $message=str_ireplace(['{peserta_nama}','{peserta_nowa}','{nama}','[nama]'],[$name,$number,$name,$name],(string)$template['content']);
    $send=rpSend($number,$message,$apiUrl,$apiToken);
    if($send['ok']){
        $success++;
        $logged='[REMINDER] [PESERTA] [TERKIRIM] '.$message;
        if($logStmt){$logStmt->bind_param('sss',$number,$name,$logged);$logStmt->execute();}
        if($historyStmt){$tid=(int)$template['id'];$tname=(string)$template['name'];$historyStmt->bind_param('ssiss',$number,$name,$tid,$tname,$message);$historyStmt->execute();}
        if($updateStmt){$rid=(int)$request['id'];$updateStmt->bind_param('i',$rid);$updateStmt->execute();}
    }else{
        $failed++;$failedNames[]=$name;$logged='[REMINDER] [PESERTA] [GAGAL] '.$message.' | '.$send['detail'];
        if($logStmt){$logStmt->bind_param('sss',$number,$name,$logged);$logStmt->execute();}
    }
}
if($logStmt)$logStmt->close();if($historyStmt)$historyStmt->close();if($updateStmt)$updateStmt->close();
$msg=($action==='send_all'?'':'Peserta: ').$success.' berhasil dikirimi reminder.';
if($failed){$msg.=' '.$failed.' gagal'.($failedNames?': '.implode(', ',array_slice($failedNames,0,5)):'').'.';}
rpRedirect($msg,$failed&&$success===0?'error':'success');
