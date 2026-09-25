<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Permintaan tidak valid.'); }

$mode=(string)($_POST['mode'] ?? 'participants');
$templateId=(int)($_POST['template_id'] ?? 0);
$returnPage=$mode==='request' ? 'reminder-peserta' : 'reminder-pembayaran';
if($templateId<=0){$_SESSION['crm_flash']=['type'=>'error','message'=>'Template reminder belum dipilih.'];header('Location: ../index.php?page='.$returnPage);exit;}

$stmt=$conn->prepare("SELECT title, content FROM wa_templates WHERE id=? LIMIT 1");
$stmt->bind_param('i',$templateId);$stmt->execute();$template=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$template){$_SESSION['crm_flash']=['type'=>'error','message'=>'Template reminder tidak ditemukan.'];header('Location: ../index.php?page='.$returnPage);exit;}

$targets=[];
if($mode==='request'){
    $requestId=(int)($_POST['request_id']??0);
    $stmt=$conn->prepare("SELECT peserta_nama,peserta_nowa FROM reminder_requests WHERE id=? AND status<>'terkirim' LIMIT 1");
    $stmt->bind_param('i',$requestId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if($row)$targets[]=['name'=>(string)$row['peserta_nama'],'nowa'=>(string)$row['peserta_nowa'],'request_id'=>$requestId];
}else{
    $raw=json_decode((string)($_POST['selected']??'[]'),true);
    if(is_array($raw)){
        $ids=[];
        foreach($raw as $id){$id=(int)$id;if($id>0)$ids[$id]=true;}
        $ids=array_keys($ids);
        if($ids){
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $types=str_repeat('i',count($ids));
            $params=$ids;$refs=[];
            foreach($params as $k=>&$v)$refs[$k]=&$v;
            $stmt=$conn->prepare("SELECT id,nama_lengkap,nowa FROM peserta WHERE id IN ($marks) AND nowa IS NOT NULL AND nowa<>''");
            call_user_func_array([$stmt,'bind_param'],array_merge([$types],$refs));
            $stmt->execute();$res=$stmt->get_result();
            while($row=$res->fetch_assoc())$targets[]=['name'=>(string)$row['nama_lengkap'],'nowa'=>(string)$row['nowa'],'request_id'=>null];
            $stmt->close();
        }
    }
}
if(!$targets){$_SESSION['crm_flash']=['type'=>'error','message'=>'Tidak ada target reminder yang valid.'];header('Location: ../index.php?page='.$returnPage);exit;}

$success=0;$failed=0;$errors=[];
$logStmt=$conn->prepare("INSERT INTO log_wa (nowa,message) VALUES (?,?)");
$requestStmt=$conn->prepare("UPDATE reminder_requests SET status='terkirim' WHERE id=?");

foreach($targets as $target){
    $name=$target['name'];$number=preg_replace('/\D+/','',(string)$target['nowa']);
    if(str_starts_with($number,'0'))$number='62'.substr($number,1);
    $message=str_ireplace(['{nama}','{NAMA}','[nama]','[NAMA]'],$name,(string)$template['content']);
    $message=preg_replace('/ {2,}/',' ',$message);
    $payload=json_encode(['recipient_type'=>'individual','to'=>$number,'type'=>'text','text'=>['body'=>$message]],JSON_UNESCAPED_UNICODE);
    $ch=curl_init();
    curl_setopt_array($ch,[CURLOPT_URL=>$apiUrl,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiToken],CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>false]);
    $response=curl_exec($ch);$httpCode=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$curlError=curl_error($ch);curl_close($ch);
    if(!$curlError&&$httpCode>=200&&$httpCode<300){
        $success++;$logged='[REMINDER] [TERKIRIM] '.$message;
        if($logStmt){$logStmt->bind_param('ss',$number,$logged);$logStmt->execute();}
        if($requestStmt&&$target['request_id']){$requestStmt->bind_param('i',$target['request_id']);$requestStmt->execute();}
    }else{
        $failed++;$errors[]=$name;$logged='[REMINDER] [GAGAL] '.$message;
        if($logStmt){$logStmt->bind_param('ss',$number,$logged);$logStmt->execute();}
    }
}
if($logStmt)$logStmt->close();if($requestStmt)$requestStmt->close();
$message=$success.' reminder berhasil dikirim.';
if($failed)$message.=' '.$failed.' gagal'.($errors?': '.implode(', ',array_slice($errors,0,5)):'').'.';
$_SESSION['crm_flash']=['type'=>$failed&&!$success?'error':'success','message'=>$message];
header('Location: ../index.php?page='.$returnPage);exit;
