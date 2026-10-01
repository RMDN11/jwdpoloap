<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crmVerifyCsrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Permintaan tidak valid.'); }

$label = trim((string)($_POST['label'] ?? ''));
$file = $_FILES['csv'] ?? null;
$redirect = '../index.php?page=reminder-csv';

if ($label === '' || mb_strlen($label) > 100 || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
 $_SESSION['crm_flash']=['type'=>'error','message'=>'Label dan file CSV wajib diisi.']; header('Location: '.$redirect); exit;
}
if (($file['size'] ?? 0) > 5*1024*1024) {
 $_SESSION['crm_flash']=['type'=>'error','message'=>'Ukuran CSV maksimal 5 MB.']; header('Location: '.$redirect); exit;
}

$handle=fopen((string)$file['tmp_name'],'rb');
if (!$handle) { $_SESSION['crm_flash']=['type'=>'error','message'=>'File CSV tidak dapat dibaca.']; header('Location: '.$redirect); exit; }

$normalizeHeader=static function(string $v): string {
 $v=preg_replace('/^\xEF\xBB\xBF/','',$v) ?? $v; return mb_strtolower(trim($v));
};
$normalizeWa=static function(string $v): string {
 $digits=preg_replace('/\D+/','',$v) ?? ''; if ($digits!=='' && str_starts_with($digits,'0')) $digits='62'.substr($digits,1); return $digits;
};
$required=['id','program','periode / level','kelas / grup','tutor pengajar','nama murid','jenis kelamin','nama wali','whatsapp wali','email wali','status siswa','jatah per minggu','jatah per hari','sesi selesai','total sesi program','progress (%)'];
$header=fgetcsv($handle);
if (!$header) { fclose($handle); $_SESSION['crm_flash']=['type'=>'error','message'=>'CSV kosong.']; header('Location: '.$redirect); exit; }
$headers=array_map($normalizeHeader,$header); $positions=[];
foreach($required as $name){$idx=array_search($name,$headers,true); if($idx===false){fclose($handle);$_SESSION['crm_flash']=['type'=>'error','message'=>'Header CSV tidak sesuai template. Kolom yang hilang: '.$name];header('Location: '.$redirect);exit;}$positions[$name]=$idx;}

$rows=[];$seen=[];$duplicateCount=0;$rowNo=1;
while(($row=fgetcsv($handle))!==false){
 $rowNo++; if(count($row)===1 && trim((string)$row[0])==='') continue;
 $get=static function(string $key) use($row,$positions):string{return trim((string)($row[$positions[$key]]??''));};
 $sourceId=$get('id');$name=$get('nama murid');$wa=$normalizeWa($get('whatsapp wali'));
 if($name==='') continue;
 $dupKey=$sourceId!==''?'id:'.$sourceId:($wa!==''?'wa:'.$wa:'row:'.$rowNo);
 if(isset($seen[$dupKey])){$duplicateCount++;continue;} $seen[$dupKey]=true;
 $rows[]=['source_row'=>$rowNo,'source_id'=>$sourceId,'program'=>$get('program'),'periode_level'=>$get('periode / level'),'kelas_grup'=>$get('kelas / grup'),'tutor_pengajar'=>$get('tutor pengajar'),'nama_murid'=>$name,'jenis_kelamin'=>$get('jenis kelamin'),'nama_wali'=>$get('nama wali'),'whatsapp_wali'=>$get('whatsapp wali'),'email_wali'=>$get('email wali'),'status_siswa'=>$get('status siswa'),'jatah_per_minggu'=>$get('jatah per minggu'),'jatah_per_hari'=>$get('jatah per hari'),'sesi_selesai'=>$get('sesi selesai'),'total_sesi_program'=>$get('total sesi program'),'progress'=>$get('progress (%)'),'normalized_wa'=>$wa];
 if(count($rows)>5000){fclose($handle);$_SESSION['crm_flash']=['type'=>'error','message'=>'CSV melebihi batas 5.000 peserta.'];header('Location: '.$redirect);exit;}
}
fclose($handle);
if(!$rows){$_SESSION['crm_flash']=['type'=>'error','message'=>'Tidak ada baris peserta yang valid.'];header('Location: '.$redirect);exit;}

try{
 $conn->begin_transaction();
 $filename=basename((string)$file['name']);
 $stmt=$conn->prepare("INSERT INTO crm_csv_imports (label,source_filename) VALUES (?,?)");
 if(!$stmt) throw new RuntimeException('Tabel import belum tersedia. Jalankan migration terlebih dahulu.');
 $stmt->bind_param('ss',$label,$filename);$stmt->execute();$importId=(int)$conn->insert_id;$stmt->close();

 $waMap=[];$r=$conn->query("SELECT id,nowa FROM peserta WHERE nowa IS NOT NULL AND nowa<>''");
 while($r && ($p=$r->fetch_assoc())){ $n=$normalizeWa((string)$p['nowa']); if($n==='')continue; if(array_key_exists($n,$waMap))$waMap[$n]=0;else$waMap[$n]=(int)$p['id']; }

 $insert=$conn->prepare("INSERT INTO crm_csv_participants (import_id,source_row,source_id,program,periode_level,kelas_grup,tutor_pengajar,nama_murid,jenis_kelamin,nama_wali,whatsapp_wali,email_wali,status_siswa,jatah_per_minggu,jatah_per_hari,sesi_selesai,total_sesi_program,progress,normalized_wa,peserta_id,match_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
 if(!$insert) throw new RuntimeException('Gagal menyiapkan penyimpanan CSV.');

 $matched=0;$unmatched=0;$ambiguous=0;
 foreach($rows as $item){
  $pesertaId=null;$matchStatus='unmatched';
  if($item['normalized_wa']!=='' && array_key_exists($item['normalized_wa'],$waMap)){
   if($waMap[$item['normalized_wa']]===0){$matchStatus='ambiguous';$ambiguous++;}else{$pesertaId=$waMap[$item['normalized_wa']];$matchStatus='matched';$matched++;}
  }else{$unmatched++;}
  $insert->bind_param('iisssssssssssssssssis',$importId,$item['source_row'],$item['source_id'],$item['program'],$item['periode_level'],$item['kelas_grup'],$item['tutor_pengajar'],$item['nama_murid'],$item['jenis_kelamin'],$item['nama_wali'],$item['whatsapp_wali'],$item['email_wali'],$item['status_siswa'],$item['jatah_per_minggu'],$item['jatah_per_hari'],$item['sesi_selesai'],$item['total_sesi_program'],$item['progress'],$item['normalized_wa'],$pesertaId,$matchStatus);
  if(!$insert->execute()) throw new RuntimeException('Gagal menyimpan baris CSV.');
 }
 $insert->close();

 $rowCount=count($rows);$unmatchedTotal=$unmatched+$ambiguous;
 $update=$conn->prepare("UPDATE crm_csv_imports SET row_count=?,matched_count=?,unmatched_count=?,duplicate_count=? WHERE id=?");
 $update->bind_param('iiiii',$rowCount,$matched,$unmatchedTotal,$duplicateCount,$importId);$update->execute();$update->close();
 $conn->commit();
 $_SESSION['crm_flash']=['type'=>'success','message'=>"Import {$label} selesai: {$rowCount} baris, {$matched} cocok, {$unmatchedTotal} belum cocok, {$duplicateCount} duplikat."];
}catch(Throwable $e){
 $conn->rollback();error_log('CRM CSV import failed: '.$e->getMessage());
 $_SESSION['crm_flash']=['type'=>'error','message'=>'Import dibatalkan. Tidak ada data CSV yang disimpan. Periksa migration dan format CSV.'];
}
header('Location: '.$redirect); exit;