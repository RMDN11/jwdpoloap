<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function promosiUploadJson(array $p,int $s=200):never{http_response_code($s);echo json_encode($p,JSON_UNESCAPED_UNICODE);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'||!crmVerifyCsrf($_POST['csrf']??null))promosiUploadJson(['status'=>'error','message'=>'Permintaan tidak valid.'],403);
if(!isset($_FILES['promo_image'])||$_FILES['promo_image']['error']!==UPLOAD_ERR_OK||!is_uploaded_file($_FILES['promo_image']['tmp_name']))promosiUploadJson(['status'=>'error','message'=>'Gambar wajib diunggah.'],422);
$file=$_FILES['promo_image'];if((int)$file['size']>5242880)promosiUploadJson(['status'=>'error','message'=>'Gambar maksimal 5 MB.'],422);
$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($file['tmp_name']);$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif'];if(!isset($allowed[$mime]))promosiUploadJson(['status'=>'error','message'=>'Format gambar tidak didukung.'],422);
if(!isset($_SESSION['crm_promosi_uploads'])||!is_array($_SESSION['crm_promosi_uploads']))$_SESSION['crm_promosi_uploads']=[];
foreach($_SESSION['crm_promosi_uploads'] as $oldToken=>$old){if(is_array($old)&&time()-(int)($old['created_at']??0)>3600){if(!empty($old['path']))@unlink($old['path']);unset($_SESSION['crm_promosi_uploads'][$oldToken]);}}
$token=bin2hex(random_bytes(24));$path=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'crm_promosi_'.$token.'.'.$allowed[$mime];
if(!move_uploaded_file($file['tmp_name'],$path))promosiUploadJson(['status'=>'error','message'=>'Gagal menyimpan gambar sementara.'],500);
@chmod($path,0600);
$_SESSION['crm_promosi_uploads'][$token]=['path'=>$path,'mime'=>$mime,'ext'=>$allowed[$mime],'created_at'=>time()];
promosiUploadJson(['status'=>'success','token'=>$token]);
