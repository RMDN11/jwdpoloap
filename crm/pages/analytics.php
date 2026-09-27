<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/prospect.php';

$crmTitle='Analytics';
$from=(string)($_GET['from']??date('Y-m-d',strtotime('-29 days')));
$to=(string)($_GET['to']??date('Y-m-d'));
$group=(string)($_GET['group']??'day');
$fromDate=DateTimeImmutable::createFromFormat('!Y-m-d',$from) ?: new DateTimeImmutable('-29 days');
$toDate=DateTimeImmutable::createFromFormat('!Y-m-d',$to) ?: new DateTimeImmutable('today');
if($fromDate>$toDate)[$fromDate,$toDate]=[$toDate,$fromDate];
$from=$fromDate->format('Y-m-d');$to=$toDate->format('Y-m-d');
if(!in_array($group,['day','week','month'],true))$group='day';
$months=['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
function crmAnalyticsPeriodKey(DateTimeImmutable $d,string $group):string{
 if($group==='month')return $d->format('Y-m');
 if($group==='week')return $d->modify('monday this week')->format('Y-m-d');
 return $d->format('Y-m-d');
}
function crmAnalyticsPeriodLabel(string $key,string $group):string{
 global $months;$d=new DateTimeImmutable($key);
 if($group==='month')return $months[(int)$d->format('n')].' '.$d->format('Y');
 if($group==='week'){$e=$d->modify('+6 days');return $d->format('d').'–'.$e->format('d').' '.$months[(int)$e->format('n')].' '.$e->format('Y');}
 return $d->format('d').' '.$months[(int)$d->format('n')].' '.$d->format('Y');
}
$disqualified=crmGetDisqualifiedNumbers($conn);$blocked=crmGetBlockedNumbers($conn);
$categories=[];
foreach(crmGetProspectTriggers($conn) as $trigger){$c=trim((string)$trigger['category']);if($c!==''&&!in_array($c,$categories,true))$categories[]=$c;}
if(!$categories)$categories=['Ziyadah Pemula','Ziyadah Lanjutan',"Muroja'ah",'Tahfidz Cilik','Mode Intensif','Mode Normal','Ekspresi Minat'];
$periods=[];$cursor=$fromDate;
while($cursor<=$toDate){
 $key=crmAnalyticsPeriodKey($cursor,$group);$periods[$key]=['label'=>crmAnalyticsPeriodLabel($key,$group),'total'=>0,'categories'=>array_fill_keys($categories,0)];
 if($group==='month')$cursor=$cursor->modify('first day of next month');elseif($group==='week')$cursor=$cursor->modify('monday next week');else$cursor=$cursor->modify('+1 day');
}
$observed=[];$valid=[];$totalRows=0;
$stmt=$conn->prepare("SELECT id,nowa,message,created_at FROM log_wa WHERE created_at>=? AND created_at<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY created_at ASC,id ASC");
if($stmt){$stmt->bind_param('ss',$from,$to);$stmt->execute();$result=$stmt->get_result();
 while($row=$result->fetch_assoc()){
  $totalRows++;$raw=(string)($row['nowa']??'');$number=crmProspectNormalizeNumber($raw);
  if($number===''||str_contains($raw,'@g')||str_contains($raw,'-'))continue;
  $observed[$number]=true;if(isset($valid[$number]))continue;
  if(!crmIsEligibleProspect($row,$disqualified,$blocked,$conn))continue;
  $valid[$number]=true;$classification=crmProspectClassifyMessage((string)$row['message'],$conn);
  $key=crmAnalyticsPeriodKey(new DateTimeImmutable((string)$row['created_at']),$group);
  if(!isset($periods[$key]))$periods[$key]=['label'=>crmAnalyticsPeriodLabel($key,$group),'total'=>0,'categories'=>array_fill_keys($categories,0)];
  if(!isset($periods[$key]['categories'][$classification])){$periods[$key]['categories'][$classification]=0;if(!in_array($classification,$categories,true))$categories[]=$classification;}
  $periods[$key]['categories'][$classification]++;$periods[$key]['total']++;
 }$stmt->close();
}
$validTotal=count($valid);$observedTotal=count($observed);$excludedTotal=max(0,$observedTotal-$validTotal);
$validRate=$observedTotal>0?($validTotal/$observedTotal)*100:0;
$categoryTotals=array_fill_keys($categories,0);foreach($periods as $p)foreach($categories as $c)$categoryTotals[$c]=(int)($categoryTotals[$c]??0)+(int)($p['categories'][$c]??0);
arsort($categoryTotals);$topCategory='-';$topCategoryCount=0;foreach($categoryTotals as $c=>$n)if($n>0){$topCategory=$c;$topCategoryCount=$n;break;}
$peakPeriod='-';$peakCount=0;foreach($periods as $p)if($p['total']>$peakCount){$peakCount=$p['total'];$peakPeriod=$p['label'];}
if(($_GET['export']??'')==='csv'){header('Content-Type:text/csv;charset=utf-8');header('Content-Disposition:attachment; filename=CRM_Analytics_'.$from.'_'.$to.'.csv');$out=fopen('php://output','w');fputcsv($out,array_merge(['Periode'],$categories,['Total']));foreach($periods as $p){$row=[$p['label']];foreach($categories as $c)$row[]=(int)($p['categories'][$c]??0);$row[]=$p['total'];fputcsv($out,$row);}fclose($out);exit;}
$chartPeriods=array_values($periods);$labels=array_map(fn($p)=>$p['label'],$chartPeriods);$totals=array_map(fn($p)=>(int)$p['total'],$chartPeriods);
$palette=['#168044','#4c9a68','#79b58d','#a5cbb3','#c7dfcf','#6d8579','#9baaa3','#365b49','#b6c8be'];$datasets=[];
foreach($categories as $i=>$c){$data=array_map(fn($p)=>(int)($p['categories'][$c]??0),$chartPeriods);if(array_sum($data)>0)$datasets[]=['label'=>$c,'data'=>$data,'backgroundColor'=>$palette[$i%count($palette)],'borderWidth'=>0,'borderRadius'=>5,'stack'=>'leads','type'=>'bar'];}
$datasets[]=['label'=>'Total Lead','data'=>$totals,'type'=>'line','borderColor'=>'#10231b','backgroundColor'=>'#10231b','borderWidth'=>2,'borderDash'=>[5,5],'pointRadius'=>2.5,'tension'=>.3,'fill'=>false,'stack'=>'total'];
?>
<div class="crm-workspace-page crm-analytics-page">
 <div class="crm-workspace-back"><a href="index.php?page=more" aria-label="Kembali ke More"><i class="fa-solid fa-arrow-left"></i></a></div>
 <section class="crm-workspace-header crm-analytics-header">
  <div class="crm-workspace-header-main"><span class="crm-workspace-kicker">Data Intelligence</span><h1>Analytics CRM</h1><p>Baca pola prospek, kualitas data, dan minat program dari satu dashboard.</p></div>
  <div class="crm-analytics-header-actions"><span class="crm-analytics-range"><?=htmlspecialchars($from)?> → <?=htmlspecialchars($to)?></span><a class="crm-btn crm-btn-secondary" href="?page=analytics&from=<?=urlencode($from)?>&to=<?=urlencode($to)?>&group=<?=urlencode($group)?>&export=csv"><i class="fa-solid fa-download"></i> Export CSV</a></div>
 </section>
 <section class="crm-analytics-filter crm-workspace-card">
  <div class="crm-analytics-filter-head"><div><span class="crm-workspace-card-kicker">Control</span><h2>Rentang & tampilan data</h2></div><span class="crm-analytics-filter-meta"><?=number_format($totalRows)?> log diperiksa</span></div>
  <form method="get" class="crm-analytics-filter-form"><input type="hidden" name="page" value="analytics">
   <label class="crm-workspace-field"><span>Mulai</span><input type="date" name="from" value="<?=htmlspecialchars($from)?>"></label>
   <label class="crm-workspace-field"><span>Sampai</span><input type="date" name="to" value="<?=htmlspecialchars($to)?>"></label>
   <label class="crm-workspace-field"><span>Granularitas</span><select name="group"><option value="day" <?=$group==='day'?'selected':''?>>Harian</option><option value="week" <?=$group==='week'?'selected':''?>>Pekanan</option><option value="month" <?=$group==='month'?'selected':''?>>Bulanan</option></select></label>
   <button class="crm-btn crm-btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Terapkan</button>
  </form>
 </section>
 <section class="crm-analytics-kpis">
  <article class="crm-analytics-kpi is-primary"><span>Lead Valid</span><strong><?=number_format($validTotal)?></strong><small>Kontak unik yang lolos kriteria prospek</small></article>
  <article class="crm-analytics-kpi"><span>Rasio Valid</span><strong><?=number_format($validRate,1)?>%</strong><small>Dari kontak unik yang terobservasi</small></article>
  <article class="crm-analytics-kpi"><span>Minat Teratas</span><strong><?=htmlspecialchars($topCategory)?></strong><small><?=number_format($topCategoryCount)?> lead terklasifikasi</small></article>
  <article class="crm-analytics-kpi"><span>Puncak Periode</span><strong><?=number_format($peakCount)?></strong><small><?=htmlspecialchars($peakPeriod)?></small></article>
 </section>
 <section class="crm-analytics-main-grid">
  <article class="crm-workspace-card crm-analytics-chart-card"><div class="crm-analytics-card-head"><div><span class="crm-workspace-card-kicker">Trend</span><h2>Pergerakan Lead</h2><p>Bar menunjukkan komposisi minat. Garis menunjukkan total lead per periode.</p></div><span class="crm-analytics-total-badge"><?=number_format($validTotal)?> total</span></div><div class="crm-analytics-chart-wrap"><?php if(!$validTotal):?><div class="crm-analytics-empty"><i class="fa-solid fa-chart-column"></i><strong>Belum ada data pada rentang ini.</strong><span>Perlebar rentang tanggal untuk melihat pola.</span></div><?php else:?><canvas id="crmAnalyticsChart"></canvas><?php endif;?></div></article>
  <article class="crm-workspace-card crm-analytics-insight-card"><div class="crm-analytics-card-head"><div><span class="crm-workspace-card-kicker">Quality Check</span><h2>Kualitas Data</h2></div></div><div class="crm-analytics-quality"><div><span>Log diperiksa</span><strong><?=number_format($totalRows)?></strong></div><div><span>Kontak unik</span><strong><?=number_format($observedTotal)?></strong></div><div><span>Lolos kriteria</span><strong><?=number_format($validTotal)?></strong></div><div><span>Tidak masuk analitik</span><strong><?=number_format($excludedTotal)?></strong></div></div><div class="crm-analytics-method"><i class="fa-solid fa-circle-info"></i><p><strong>Metode:</strong> satu nomor dihitung satu kali, berdasarkan pesan prospek pertama yang memenuhi kriteria. Peserta, pengampu/pengajar, nomor diblokir, grup, dan pesan yang tidak terklasifikasi tidak masuk Lead Valid.</p></div></article>
 </section>
 <section class="crm-analytics-bottom-grid">
  <article class="crm-workspace-card"><div class="crm-analytics-card-head"><div><span class="crm-workspace-card-kicker">Interest Mix</span><h2>Distribusi Minat</h2></div></div><div class="crm-analytics-rank-list">
   <?php foreach($categoryTotals as $category=>$count): if($count<=0)continue; $width=$validTotal>0?min(100,($count/$validTotal)*100):0;?><div class="crm-analytics-rank-row"><div class="crm-analytics-rank-label"><span><?=htmlspecialchars($category)?></span><strong><?=number_format($count)?></strong></div><div class="crm-analytics-progress"><span style="width:<?=$width?>%"></span></div></div><?php endforeach;?>
   <?php if($topCategoryCount===0):?><div class="crm-analytics-empty-small">Belum ada kategori terisi.</div><?php endif;?>
  </div></article>
  <article class="crm-workspace-card crm-analytics-table-card"><div class="crm-analytics-card-head"><div><span class="crm-workspace-card-kicker">Detail</span><h2>Data Per Periode</h2></div></div><div class="crm-analytics-table-wrap"><table><thead><tr><th>Periode</th><th>Total</th><th>Top Minat</th></tr></thead><tbody>
   <?php foreach(array_reverse($chartPeriods) as $p):$pt='-';$pc=0;foreach($categories as $c){$n=(int)($p['categories'][$c]??0);if($n>$pc){$pt=$c;$pc=$n;}}?><tr><td><?=htmlspecialchars($p['label'])?></td><td><?=number_format((int)$p['total'])?></td><td><?=htmlspecialchars($pt)?></td></tr><?php endforeach;?>
  </tbody></table></div></article>
 </section>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(()=>{const c=document.getElementById('crmAnalyticsChart');if(!c||typeof Chart==='undefined')return;new Chart(c,{data:{labels:<?=json_encode($labels,JSON_UNESCAPED_UNICODE)?>,datasets:<?=json_encode($datasets,JSON_UNESCAPED_UNICODE)?>},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'bottom',labels:{usePointStyle:true,padding:16,boxWidth:7,font:{size:10,weight:'600'}}},tooltip:{backgroundColor:'#10231b',padding:10,cornerRadius:10}},scales:{x:{stacked:true,grid:{display:false},ticks:{font:{size:9}}},y:{stacked:true,beginAtZero:true,grid:{color:'#edf2ef'},ticks:{font:{size:9},precision:0}}}}});})();
</script>