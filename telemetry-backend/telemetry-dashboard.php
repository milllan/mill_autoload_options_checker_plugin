<?php
/**
 * Telemetry Dashboard for Autoloaded Options Optimizer
 * Modern dark-mode UI – single file, zero dependencies
 */

if (!defined('ABSPATH')) {
    if (!isset($_SERVER['HTTP_HOST'])) die('Access denied');
}

define('TELEMETRY_LOG_FILE', __DIR__.'/telemetry-data.jsonl');

/* ---------- env ---------- */
foreach (file(__DIR__.'/.env') as $l) {
    if (strpos(trim($l),'#')!==0 && strpos($l,'=')) putenv(trim($l));
}
define('TELEMETRY_PASSWORD', getenv('DASHBOARD_PASSWORD') ?: die('Set DASHBOARD_PASSWORD in .env'));

/* ---------- auth ---------- */
$auth = false;
if (isset($_POST['password']) && $_POST['password'] === TELEMETRY_PASSWORD) {
    $auth = true; setcookie('telemetry_auth', hash('sha256', TELEMETRY_PASSWORD), time() + 3600);
} elseif (($_COOKIE['telemetry_auth'] ?? '') === hash('sha256', TELEMETRY_PASSWORD)) {
    $auth = true;
}
if (!$auth) { loginUI(); exit; }

/* ---------- data ---------- */
$telemetryData = loadTelemetryData();
$analysis      = analyzeTelemetryData($telemetryData);

/* ---------- html ---------- */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Telemetry Dashboard</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{
  --bg:#0d1117;--surface:#161b22;--elevate:#21262d;
  --primary:#58a6ff;--danger:#f85149;--text:#c9d1d9;--mute:#8b949e;
  --font:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;
  --radius:8px;--shadow:0 8px 24px rgba(0,0,0,.45);
}
*{box-sizing:border-box;margin:0;padding:0;font-family:var(--font);}
body{background:var(--bg);color:var(--text);display:flex;flex-direction:column;min-height:100vh;}
a{color:var(--primary);text-decoration:none;}
a:hover{text-decoration:underline;}
header{position:sticky;top:0;z-index:10;background:var(--surface);border-bottom:1px solid var(--elevate);padding:1rem 2rem;display:flex;align-items:center;justify-content:space-between;}
header h1{font-size:1.25rem;font-weight:600;}
.btn{padding:.5rem 1rem;border-radius:var(--radius);border:none;font-weight:500;cursor:pointer;}
.btn-danger{background:var(--danger);color:#fff;}
main{padding:2rem;display:grid;gap:2rem;grid-template-columns:1fr;max-width:1400px;margin:auto;width:100%;}
.grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));}
.card{background:var(--surface);border:1px solid var(--elevate);border-radius:var(--radius);padding:1.25rem;box-shadow:var(--shadow);}
.card h2{margin-bottom:.75rem;font-size:1.1rem;font-weight:600;display:flex;align-items:center;gap:.5rem;}
.number{font-size:2rem;font-weight:700;color:var(--primary);}
.mute{font-size:.875rem;color:var(--mute);}
table{width:100%;border-collapse:collapse;font-size:.925rem;}
th,td{padding:.75rem 1rem;text-align:left;border-bottom:1px solid var(--elevate);}
th{position:sticky;top:0;background:var(--elevate);color:var(--primary);font-weight:600;}
tr:hover{background:rgba(88,166,255,.05);}
.mono{font-family:monospace;font-size:.85rem;}
.ellip{max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;}
.search{width:100%;max-width:320px;margin-bottom:1rem;padding:.6rem 1rem;border:1px solid var(--elevate);border-radius:var(--radius);background:var(--bg);color:var(--text);}
.spark{height:40px;width:100%;}
.login{display:flex;align-items:center;justify-content:center;height:100vh;}
.login-box{background:var(--surface);padding:2rem;border-radius:var(--radius);box-shadow:var(--shadow);width:100%;max-width:360px;}
.login-box h2{margin-bottom:1.2rem;text-align:center;}
.login-box input{width:100%;padding:.7rem 1rem;margin-bottom:1rem;border:1px solid var(--elevate);border-radius:var(--radius);background:var(--bg);color:var(--text);}
.login-box button{width:100%;padding:.7rem;border:none;border-radius:var(--radius);background:var(--primary);color:#0d1117;font-weight:600;cursor:pointer;}
.login-box button:hover{opacity:.9;}
@media(max-width:600px){header{padding:1rem;}main{padding:1rem;}}
</style>
</head>
<body>

<header>
  <h1>Telemetry Dashboard</h1>
  <a class="btn btn-danger" href="?logout=1">Logout</a>
</header>

<main>
  <div class="card">
    <h2>Overview</h2>
    <div class="grid">
      <div><div class="number"><?=number_format($analysis['total_submissions'])?></div><div class="mute">Unique Sites</div></div>
      <div><div class="number"><?=number_format($analysis['total_all_submissions'])?></div><div class="mute">Total Submissions</div></div>
      <div><div class="number"><?=number_format($analysis['unique_options'])?></div><div class="mute">Unknown Options</div></div>
      <div><div class="number"><?=number_format($analysis['unique_plugins'])?></div><div class="mute">Known Plugins</div></div>
      <div><div class="number"><?=number_format($analysis['unique_themes'])?></div><div class="mute">Known Themes</div></div>
      <div><div class="number"><?=round($analysis['avg_options_per_submission'],1)?></div><div class="mute">Avg Options / Site</div></div>
    </div>
  </div>

  <div class="card">
    <h2>New Unique Options / Week&nbsp;(last 12)</h2>
    <canvas id="spark" class="spark"></canvas>
  </div>

  <div class="card">
    <h2>Top Unknown Options</h2>
    <input id="search" class="search" placeholder="Filter option names…" autocomplete="off">
    <div style="overflow-x:auto;">
      <table>
        <thead><tr><th>Option</th><th>Sites</th><th>Frequency</th><th>Avg Size</th></tr></thead>
        <tbody id="opts">
        <?php foreach($analysis['top_options'] as $o): ?>
          <tr>
            <td class="mono ellip" title="Click to copy"><?=htmlspecialchars($o['name'])?></td>
            <td><?=$o['count']?></td>
            <td><?=round(($o['count']/$analysis['total_submissions'])*100,1)?>%</td>
            <td><?=size_format($o['avg_size'])?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <h2>Top Plugins</h2>
      <div style="overflow-x:auto;">
        <table><thead><tr><th>Plugin</th><th>Sites</th><th>Avg Options</th><th>Avg Size</th></tr></thead>
        <tbody>
        <?php foreach($analysis['top_plugins'] as $n=>$s): ?>
          <tr>
            <td><?=htmlspecialchars($n)?></td>
            <td><?=$s['count']?></td>
            <td><?=round($s['avg_options'],1)?></td>
            <td><?=size_format($s['avg_size'])?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>

    <div class="card">
      <h2>Top Themes</h2>
      <div style="overflow-x:auto;">
        <table><thead><tr><th>Theme</th><th>Sites</th><th>Versions</th></tr></thead>
        <tbody>
        <?php foreach($analysis['top_themes'] as $n=>$s): ?>
          <tr>
            <td><?=htmlspecialchars($n)?></td>
            <td><?=$s['count']?></td>
            <td><?=count(array_unique($s['versions']))?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Recent Submissions</h2>
    <div style="overflow-x:auto;">
      <table>
        <thead><tr><th>Time</th><th>Site</th><th>WP</th><th>PHP</th><th>Unknown</th><th>Plugins</th></tr></thead>
        <tbody>
        <?php
        foreach(array_slice(array_reverse($telemetryData),0,15) as $sub):
        ?>
          <tr>
            <td><?=date('Y-m-d H:i',strtotime($sub['received_at']))?></td>
            <td><a href="<?=htmlspecialchars($sub['site_url']??'#')?>" target="_blank" rel="noopener"><?=htmlspecialchars(parse_url($sub['site_url']??'',PHP_URL_HOST))?></a></td>
            <td><?=htmlspecialchars($sub['wp_version']??'–')?></td>
            <td><?=htmlspecialchars($sub['php_version']??'–')?></td>
            <td><?=count($sub['unknown_options']??[])?></td>
            <td><?=count($sub['known_plugins']??[])?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<script>
/* sparkline */
const d = <?=json_encode($analysis['sparkline'])?>;
const c = document.getElementById('spark'), x=c.getContext('2d');
c.width=c.offsetWidth;c.height=40;
const m=Math.max(...d,1);
x.strokeStyle=getComputedStyle(document.documentElement).getPropertyValue('--primary');
x.beginPath();
d.forEach((v,i)=>{ const px=i*c.width/d.length, py=c.height-(v/m)*c.height; i?x.lineTo(px,py):x.moveTo(px,py); });
x.stroke();

/* live filter + copy */
document.getElementById('search').addEventListener('input',e=>{
  const q=e.target.value.toLowerCase();
  document.querySelectorAll('#opts tr').forEach(tr=>{
    tr.style.display=tr.querySelector('.ellip').textContent.toLowerCase().includes(q)?'':'none';
  });
});
document.querySelectorAll('.ellip').forEach(el=>{
  el.addEventListener('click',()=>{
    navigator.clipboard.writeText(el.textContent);
    el.style.color='var(--primary)'; setTimeout(()=>el.style.color='',800);
  });
});
</script>
</body>
</html>

<?php
/* ---------- helpers ---------- */
function loginUI(){ ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Login | Telemetry</title>
<style>
:root{
  --bg:#0d1117;--surface:#161b22;--elevate:#21262d;
  --primary:#58a6ff;--danger:#f85149;--text:#c9d1d9;--mute:#8b949e;
  --font:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;
  --radius:8px;--shadow:0 8px 24px rgba(0,0,0,.45);
}
*{box-sizing:border-box;margin:0;padding:0;font-family:var(--font);}
body{background:var(--bg);color:var(--text);display:flex;align-items:center;justify-content:center;height:100vh;}
.login-box{background:var(--surface);border:1px solid var(--elevate);padding:2rem;border-radius:var(--radius);box-shadow:var(--shadow);width:100%;max-width:360px;}
.login-box h2{margin-bottom:1.2rem;text-align:center;font-weight:600;}
.login-box input{width:100%;padding:.7rem 1rem;margin-bottom:1rem;border:1px solid var(--elevate);border-radius:var(--radius);background:var(--bg);color:var(--text);}
.login-box button{width:100%;padding:.7rem;border:none;border-radius:var(--radius);background:var(--primary);color:#0d1117;font-weight:600;cursor:pointer;}
.login-box button:hover{opacity:.9;}
</style>
</head>
<body>
  <div class="login-box">
    <h2>Telemetry Dashboard</h2>
    <form method="post">
      <input type="password" name="password" placeholder="Password" required>
      <button>Login</button>
    </form>
  </div>
</body>
</html>
<?php }

function loadTelemetryData(){
  if(!file_exists(TELEMETRY_LOG_FILE)) return [];
  $d=[]; $h=fopen(TELEMETRY_LOG_FILE,'r');
  while(($l=fgets($h))!==false){ $j=json_decode(trim($l),true); if($j) $d[]=$j; }
  fclose($h); return $d;
}

function analyzeTelemetryData($data){
  $all=count($data);
  $latest=[]; $opts=[]; $plugs=[]; $themes=[]; $sites=[];
  foreach($data as $sub){
    $hash=$sub['site_hash']??''; if(!$hash) continue;
    if(!isset($latest[$hash])||strtotime($sub['received_at'])>strtotime($latest[$hash]['received_at'])) $latest[$hash]=$sub;
  }
  $data=array_values($latest); $uniq=count($data);
  foreach($data as $sub){
    $sites[$sub['site_hash']]=true;
    foreach($sub['unknown_options']??[] as $o){
      $n=$o['name'];
      if(!isset($opts[$n])) $opts[$n]=['count'=>0,'total_size'=>0,'sizes'=>[]];
      $opts[$n]['count']++; $opts[$n]['total_size']+=$o['size']; $opts[$n]['sizes'][]=$o['size'];
    }
    foreach($sub['known_plugins']??[] as $p){
      $n=$p['name'];
      if(!isset($plugs[$n])) $plugs[$n]=['count'=>0,'total_size'=>0,'total_options'=>0];
      $plugs[$n]['count']++; $plugs[$n]['total_size']+=$p['total_size']??0; $plugs[$n]['total_options']+=$p['option_count']??0;
    }
    foreach($sub['known_themes']??[] as $t){
      $n=$t['name'];
      if(!isset($themes[$n])) $themes[$n]=['count'=>0,'versions'=>[]];
      $themes[$n]['count']++; if(isset($t['version'])) $themes[$n]['versions'][]=$t['version'];
    }
  }
  foreach($opts as $name => &$o){
    $o['name']   = $name;
    $o['avg_size']=count($o['sizes'])?$o['total_size']/count($o['sizes']):0;
  }
  foreach($plugs as &$p){ $p['avg_size']=$p['count']?$p['total_size']/$p['count']:0; $p['avg_options']=$p['count']?$p['total_options']/$p['count']:0; }
  uasort($opts,fn($a,$b)=>$b['count']<=>$a['count']); uasort($plugs,fn($a,$b)=>$b['count']<=>$a['count']); uasort($themes,fn($a,$b)=>$b['count']<=>$a['count']);
  $spark=function($data){
    $first=[]; $week=[];
    foreach($data as $sub){
      $w=date('Y-W',strtotime($sub['received_at']));
      foreach($sub['unknown_options']??[] as $o){
        $n=$o['name']; if(!isset($first[$n])){ $first[$n]=$w; $week[$w]=($week[$w]??0)+1; }
      }
    }
    $out=[]; for($i=11;$i>=0;$i--) $out[]=$week[date('Y-W',strtotime("-$i weeks"))]??0; return $out;
  };
  return [
    'total_submissions'=>$uniq,'total_all_submissions'=>$all,'unique_sites'=>count($sites),'unique_options'=>count($opts),'unique_plugins'=>count($plugs),'unique_themes'=>count($themes),
    'avg_options_per_submission'=>$uniq?array_sum(array_column($opts,'count'))/$uniq:0,
    'sparkline'=>$spark($data),
    'top_options'=>array_slice($opts,0,50),'top_plugins'=>array_slice($plugs,0,30),'top_themes'=>array_slice($themes,0,20)
  ];
}

function size_format($b){
  return $b>=1048576?round($b/1048576,1).'MB':($b>=1024?round($b/1024,1).'KB':$b.'B');
}