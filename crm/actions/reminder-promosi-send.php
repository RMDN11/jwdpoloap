<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function promosiJson(array $p,int $s=200):never{http_response_code($s);echo json_encode($p,JSON_UNESCAPED_UNICODE);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'||!crmVerifyCsrf($_POST['csrf']??null))promosiJson(['status'=>'error','message'=>'Permintaan tidak valid.'],403);
$id=(int)($_POST['participant_id']??0);$caption=trim((string)($_POST['caption']??''));$link=trim((string)($_POST['registration_link']??''));
if($id<=0||$caption==='')promosiJson(['status'=>'error','message'=>'Data campaign tidak lengkap.'],422);
if(mb_strlen($caption)>2000)promosiJson(['status'=>'error','message'=>'Caption maksimal 2000 karakter.'],422);
if($link!==''&&(!filter_var($link,FILTER_VALIDATE_URL)||mb_strlen($link)>500))promosiJson(['status'=>'error','message'=>'Tautan tidak valid.'],422);
if(!isset($_FILES['promo_image'])||$_FILES['promo_image']['error']!==UPLOAD_ERR_OK||!is_uploaded_file($_FILES['promo_image']['tmp_name'])||$_FILES['promo_image']['size']>5242880)promosiJson(['status'=>'error','message'=>'Gambar wajib dan maksimal 5 MB.'],422);
$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($_FILES['promo_image']['tmp_name']);$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif'];if(!isset($allowed[$mime]))promosiJson(['status'=>'error','message'=>'Format gambar tidak didukung.'],422);
$stmt=$conn->prepare("SELECT id,nama_lengkap,nowa FROM peserta WHERE id=? AND nowa IS NOT NULL AND nowa<>'' LIMIT 1");if(!$stmt)promosiJson(['status'=>'error','message'=>'Gagal menyiapkan peserta.'],500);$stmt->bind_param('i',$id);$stmt->execute();$target=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$target)promosiJson(['status'=>'error','message'=>'Peserta tidak ditemukan.'],404);
$number=crmNormalizeNumber((string)$target['nowa']);$name=trim((string)$target['nama_lengkap']);if($number==='')promosiJson(['status'=>'error','message'=>'Nomor WhatsApp tidak valid.'],422);\n$token=trim((string)($_POST['image_token']??''));$upload=$_SESSION['crm_promosi_uploads'][$token]??null;if($token===''||!is_array($upload)||empty($upload['path'])||!is_file($upload['path']))promosiJson(['status'=>'error','message'=>'File gambar campaign sudah tidak tersedia. Upload ulang gambar.'],422);if(time()-(int)($upload['created_at']??0)>3600){@unlink($upload['path']);unset($_SESSION['crm_promosi_uploads'][$token]);promosiJson(['status'=>'error','message'=>'File gambar sudah kedaluwarsa. Upload ulang gambar.'],422);}$mime=(string)$upload['mime'];$ext=(string)$upload['ext'];
$final=str_ireplace(['{nama}','[nama]'],[$name,$name],$caption);if($link!=='')$final.="\n".$link;
$url=strpos($apiUrl,'/v1/messages')!==false?str_replace('/v1/messages','/message',$apiUrl):rtrim($apiUrl,'/').'/message';
$post=['type'=>'image','phone'=>$number,'message'=>$final,'attachment'=>new CURLFile($upload['path'],$mime,'promosi.'.$ext)];
$ch=curl_init();curl_setopt_array($ch,[CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiToken],CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>10]);$response=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
if($err!==''){$status='GAGAL';$message='cURL Error: '.$err;}elseif($code>=200&&$code<300){$status='TERKIRIM';$message='Berhasil dikirim.';}else{$data=json_decode((string)$response,true);$status='GAGAL';$message=is_array($data)&&isset($data['message'])?(string)$data['message']:'Pengiriman tidak berhasil.';}
$log='['.$status.'] [PROMOSI] '.$message;$ls=$conn->prepare("INSERT INTO log_wa (nowa,nama,message,created_at) VALUES (?,?,?,NOW())");if($ls){$ls->bind_param('sss',$number,$name,$log);$ls->execute();$ls->close();}
$hs=$conn->prepare("INSERT INTO crm_message_history (nowa,nama,template_id,template_name,message,sent_at,status) VALUES (?,?,NULL,'Promosi',?,NOW(),?)");if($hs){$hsStatus=$status==='TERKIRIM'?'sent':'failed';$hs->bind_param('ssss',$number,$name,$final,$hsStatus);$hs->execute();$hs->close();}
if(($_POST['final_send']??'0')==='1'){@unlink($upload['path']);unset($_SESSION['crm_promosi_uploads'][$token]);}\npromosiJson(['status'=>$status==='TERKIRIM'?'success':'error','message'=>$message,'name'=>$name]);
