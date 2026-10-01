<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Permintaan tidak valid.'); }

$mode=(string)($_POST['mode'] ?? 'participants');
$templateId=(int)($_POST['template_id'] ?? 0);
$csvFile=basename(trim((string)($_POST['csv_file'] ?? '')));
$bulan=trim((string)($_POST['bulan'] ?? ''));
$statusBayar=(string)($_POST['status_bayar'] ?? 'belum_lunas');
$statusPeserta=(string)($_POST['status_peserta'] ?? 'proses');

$redirectParams = ['page' => 'reminder-pembayaran'];
foreach (['q', 'bulan', 'csv_file', 'halaqoh', 'status_peserta', 'status_bayar'] as $filterKey) {
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

        if($csvFile!=='' && $selectedIds){
            require_once __DIR__ . '/../lib/reminder-csv.php';

            try {
                $csvRows=crmReminderCsvRead($csvFile);
                $waMap=[];
                $result=$conn->query("SELECT id,nowa FROM peserta WHERE nowa IS NOT NULL AND nowa<>''");
                if($result){
                    while($participant=$result->fetch_assoc()){
                        $normalized=crmReminderCsvNormalizeWa((string)$participant['nowa']);
                        if($normalized==='')continue;
                        if(array_key_exists($normalized,$waMap))$waMap[$normalized]=0;
                        else $waMap[$normalized]=(int)$participant['id'];
                    }
                }

                $csvTargetByPesertaId=[];
                foreach($csvRows as $csvRow){
                    if(crmReminderCsvNormalizeStatus((string)$csvRow['status_siswa'])!=='ON')continue;
                    $normalized=(string)$csvRow['normalized_wa'];
                    if($normalized===''||!array_key_exists($normalized,$waMap)||$waMap[$normalized]===0)continue;
                    $csvTargetByPesertaId[(int)$waMap[$normalized]]=(string)$csvRow['whatsapp_wali'];
                }

                foreach(array_keys($selectedIds) as $id){
                    if(!isset($csvTargetByPesertaId[$id])||trim((string)$csvTargetByPesertaId[$id])==='')continue;

                    $conditions=["p.id=?"];
                    $params=[$id];
                    $types='i';

                    if($bulan!==''){
                        if($statusBayar==='lunas'){
                            $conditions[]="EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id=p.id AND px.bulan_pembayaran=?)";
                            $params[]=$bulan;$types.='s';
                        }elseif($statusBayar==='belum_lunas'){
                            $conditions[]="NOT EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id=p.id AND px.bulan_pembayaran=?)";
                            $params[]=$bulan;$types.='s';
                        }
                    }elseif($statusBayar==='lunas'){
                        $conditions[]="EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id=p.id)";
                    }elseif($statusBayar==='belum_lunas'){
                        $conditions[]="NOT EXISTS (SELECT 1 FROM pembayaran px WHERE px.peserta_id=p.id)";
                    }

                    $sql="SELECT p.id,p.nama_lengkap FROM peserta p WHERE ".implode(' AND ',$conditions)." LIMIT 1";
                    $valid=$conn->prepare($sql);
                    if(!$valid)continue;

                    $refs=[];
                    foreach($params as $k=>$v)$refs[$k]=&$params[$k];
                    call_user_func_array([$valid,'bind_param'],array_merge([$types],$refs));
                    $valid->execute();
                    $row=$valid->get_result()->fetch_assoc();
                    $valid->close();

                    if($row){
                        $targets[]=[
                            'name'=>(string)$row['nama_lengkap'],
                            'nowa'=>(string)$csvTargetByPesertaId[$id],
                            'request_id'=>null
                        ];
                    }
                }
            } catch(Throwable $e) {
                error_log('CRM CSV send validation failed: '.$e->getMessage());
            }
        }else{
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
