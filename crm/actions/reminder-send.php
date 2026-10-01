<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Permintaan tidak valid.'); }

$mode=(string)($_POST['mode'] ?? 'participants');
$templateId=(int)($_POST['template_id'] ?? 0);
$csvImportId=max(0,(int)($_POST['csv_import_id'] ?? 0));
$bulan=trim((string)($_POST['bulan'] ?? ''));
$statusBayar=(string)($_POST['status_bayar'] ?? 'belum_lunas');
$statusPeserta=(string)($_POST['status_peserta'] ?? 'proses');

$redirectParams = ['page' => 'reminder-pembayaran'];
foreach (['q', 'bulan', 'csv_import_id', 'halaqoh', 'status_peserta', 'status_bayar'] as $filterKey) {
    if (isset($_POST[$filterKey]) && trim((string)$_POST[$filterKey]) !== '') {
        $redirectParams[$filterKey] = trim((string)$_POST[$filterKey]);
    }
}
$redirectUrl = '../index.php?' . http_build_query($redirectParams);

if($templateId<=0){
    $_SESSION['crm_flash']=['type'=>'error','message'=>'Template reminder belum dipilih.'];
    header('Location: '.$redirectUrl);
    exit;
}

$stmt=$conn->prepare("SELECT title, content FROM wa_templates WHERE id=? LIMIT 1");
$stmt->bind_param('i',$templateId);$stmt->execute();$template=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$template){
    $_SESSION['crm_flash']=['type'=>'error','message'=>'Template reminder tidak ditemukan.'];
    header('Location: '.$redirectUrl);
    exit;
}

$targets=[];
if($mode==='request'){
    $requestId=(int)($_POST['request_id']??0);
    $stmt=$conn->prepare("SELECT peserta_nama,peserta_nowa FROM reminder_requests WHERE id=? AND status<>'terkirim' LIMIT 1");
    $stmt->bind_param('i',$requestId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if($row)$targets[]=['name'=>(string)$row['peserta_nama'],'nowa'=>(string)$row['peserta_nowa'],'request_id'=>$requestId];
}else{
    $raw=json_decode((string)($_POST['selected']??'[]'),true);
    if(is_array($raw)){
        $selectedIds=[];
        foreach($raw as $item){ $id=(int)($item['id']??0); if($id>0)$selectedIds[$id]=true; }
        if($csvImportId>0 && $selectedIds){
            $conditions=[
                "p.id = ?",
                "EXISTS (SELECT 1 FROM crm_csv_participants cp WHERE cp.peserta_id=p.id AND cp.import_id={$csvImportId} AND cp.match_status='matched' AND UPPER(TRIM(cp.status_siswa))='ON' AND TRIM(cp.whatsapp_wali)<>'' )",
                "p.nowa IS NOT NULL","p.nowa <> ''"
            ];
            if($csvImportId <= 0 && $statusPeserta!=='' && $statusPeserta!=='semua') $conditions[]="p.status = ?";
            if($bulan!==''){
                if($statusBayar==='lunas') $conditions[]="EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id=p.id AND px.bulan_pembayaran=?)";
                elseif($statusBayar==='belum_lunas') $conditions[]="NOT EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id=p.id AND px.bulan_pembayaran=?)";
            } elseif($statusBayar==='lunas') $conditions[]="EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id=p.id)";
            elseif($statusBayar==='belum_lunas') $conditions[]="NOT EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id=p.id)";
            $sql="SELECT p.id,p.nama_lengkap,p.nowa,
                (SELECT cp.whatsapp_wali FROM crm_csv_participants cp
                 WHERE cp.peserta_id=p.id AND cp.import_id={$csvImportId}
                   AND cp.match_status='matched'
                   AND UPPER(TRIM(cp.status_siswa))='ON'
                   AND TRIM(cp.whatsapp_wali)<>''
                 LIMIT 1) AS target_nowa
                FROM peserta p WHERE ".implode(' AND ',$conditions)." LIMIT 1";
            $valid=$conn->prepare($sql);
            foreach(array_keys($selectedIds) as $id){
                $params=[$id]; $types='i';
                if($csvImportId <= 0 && $statusPeserta!=='' && $statusPeserta!=='semua'){ $params[]=$statusPeserta;$types.='s'; }
                if($bulan!=='' && ($statusBayar==='lunas'||$statusBayar==='belum_lunas')){$params[]=$bulan;$types.='s';}
                $refs=[];foreach($params as $k=>$v)$refs[$k]=&$params[$k];
                if($valid){call_user_func_array([$valid,'bind_param'],array_merge([$types],$refs));$valid->execute();$row=$valid->get_result()->fetch_assoc();$valid->reset();
                    if($row)$targets[]=['name'=>(string)$row['nama_lengkap'],'nowa'=>(string)($row['target_nowa'] ?? ''),'request_id'=>null];
                }
            }
            if($valid)$valid->close();
        } else {
            foreach($raw as $item){
                $nowa=preg_replace('/\D+/','',(string)($item['nowa']??''));
                if($nowa==='')continue;
                if(str_starts_with($nowa,'0'))$nowa='62'.substr($nowa,1);
                $targets[]=['name'=>trim((string)($item['name']??'Kak'))?:'Kak','nowa'=>$nowa,'request_id'=>null];
            }
        }
    }
}
if(!$targets){
    $_SESSION['crm_flash']=['type'=>'error','message'=>'Tidak ada target reminder yang valid.'];
    header('Location: '.$redirectUrl);
    exit;
}

$success=0;$failed=0;$errors=[];
$logStmt=$conn->prepare("INSERT INTO log_wa (nowa,nama,message,created_at) VALUES (?,?,?,NOW())");
$requestStmt=$conn->prepare("UPDATE reminder_requests SET status='terkirim' WHERE id=?");

foreach($targets as $target){
    $name=$target['name'];$number=$target['nowa'];
    $message=str_ireplace(['{nama}','{NAMA}','[nama]','[NAMA]'],$name,(string)$template['content']);
    $message=preg_replace('/ {2,}/',' ',$message);
    $payload=json_encode(['recipient_type'=>'individual','to'=>$number,'type'=>'text','text'=>['body'=>$message]],JSON_UNESCAPED_UNICODE);
    $ch=curl_init();
    curl_setopt_array($ch,[CURLOPT_URL=>$apiUrl,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiToken],CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>false]);
    $response=curl_exec($ch);$httpCode=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$curlError=curl_error($ch);curl_close($ch);
    if(!$curlError&&$httpCode>=200&&$httpCode<300){
        $success++;$logged='[REMINDER] [TERKIRIM] '.$message;
        if($logStmt){$logStmt->bind_param('sss',$number,$name,$logged);$logStmt->execute();}
        if($requestStmt&&$target['request_id']){$requestStmt->bind_param('i',$target['request_id']);$requestStmt->execute();}
    }else{
        $failed++;$errors[]=$name;$logged='[REMINDER] [GAGAL] '.$message;
        if($logStmt){$logStmt->bind_param('sss',$number,$name,$logged);$logStmt->execute();}
    }
}
if($logStmt)$logStmt->close();if($requestStmt)$requestStmt->close();
$message=$success.' reminder berhasil dikirim.';
if($failed)$message.=' '.$failed.' gagal'.($errors?': '.implode(', ',array_slice($errors,0,5)):'').'.';
$_SESSION['crm_flash']=['type'=>$failed&&!$success?'error':'success','message'=>$message];
header('Location: '.$redirectUrl);exit;
