<?php
/**
 * Kurage LINE CRM — 設置前チェック。
 * このファイルを crm/ に置いてブラウザで開くと、そのサーバーで動くかどうかが分かります。
 * 確認が済んだら削除してください（設定の状態が見えるため）。
 */

$rows = [];
$ng = 0;

function row(&$rows, &$ng, $name, $ok, $detail, $required = true)
{
    $rows[] = [$name, $ok, $detail, $required];
    if (!$ok && $required) { $ng++; }
}

row($rows, $ng, 'PHPのバージョン', version_compare(PHP_VERSION, '7.0', '>='),
    PHP_VERSION . '（7.0以上が必要。共有サーバーでは既定が古いことがあり、その場合は .htaccess で指定します）');
row($rows, $ng, 'PDO と SQLite', extension_loaded('pdo_sqlite'),
    extension_loaded('pdo_sqlite') ? '利用できます' : 'pdo_sqlite が見つかりません（データの保存に必要）');
row($rows, $ng, 'cURL', extension_loaded('curl'),
    extension_loaded('curl') ? '利用できます' : 'curl が見つかりません（LINEへの送信に必要）');
row($rows, $ng, 'mbstring', extension_loaded('mbstring'),
    extension_loaded('mbstring') ? '利用できます' : 'mbstring が見つかりません（日本語の処理に必要）');
row($rows, $ng, 'セッション', function_exists('session_start'), 'ログインの保持に使います');

$dir = __DIR__ . '/klcrm_data';
$w = is_dir($dir) ? is_writable($dir) : is_writable(__DIR__);
row($rows, $ng, 'データ保存先への書き込み', $w,
    $w ? 'klcrm_data に書き込めます' : 'klcrm_data に書き込めません。パーミッションを 777 にしてください');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
row($rows, $ng, 'HTTPS', $https,
    $https ? 'https で開けています' : 'http です。LINEのWebhookは https でないと登録できません');

// 外向きにLINEのAPIへ出られるか（ファイアウォールで塞がれていないか）
$reach = false; $reach_detail = 'cURL が無いため確認できません';
if (extension_loaded('curl')) {
    $ch = curl_init('https://api.line.me/v2/bot/info');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_NOBODY => true]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $reach = $code > 0;   // 401が返れば到達している（トークンなしのため）
    $reach_detail = $reach ? "api.line.me に到達できます（応答 $code）" : '外部への接続が塞がれています';
}
row($rows, $ng, 'LINE APIへの接続', $reach, $reach_detail);

// .htaccess が効いているか（SQLiteを外から読めると情報が漏れる）
$ht = is_file(__DIR__ . '/klcrm_data/.htaccess');
row($rows, $ng, '.htaccess の設置', $ht,
    $ht ? 'klcrm_data/.htaccess があります（Apache以外では効かないので、その場合はデータを公開領域の外へ）' : 'klcrm_data/.htaccess がありません',
    false);

$cfg = is_file(__DIR__ . '/klcrm_config.php');
row($rows, $ng, '設定ファイル', $cfg,
    $cfg ? 'klcrm_config.php があります' : 'klcrm_config.php.example をコピーして作ってください', false);
?><!doctype html><html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>設置前チェック｜Kurage LINE CRM</title>
<style>
body{margin:0;padding:28px 18px;background:#f3faf9;color:#1d3038;
  font-family:-apple-system,"Hiragino Sans","Noto Sans JP",sans-serif;line-height:1.8}
.box{max-width:720px;margin:0 auto;background:#fff;border:1px solid #dcebe9;border-radius:16px;padding:26px 28px}
h1{font-size:20px;margin:0 0 4px}
p.sub{color:#5f7078;font-size:13.5px;margin:0 0 18px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{border-bottom:1px solid #eef4f4;padding:9px 6px;text-align:left;vertical-align:top}
th{width:38%;font-weight:700}
.ok{color:#0a7a70;font-weight:800}
.no{color:#c0392b;font-weight:800}
.warn{color:#b8860b;font-weight:800}
.result{margin-top:18px;padding:13px 16px;border-radius:11px;font-weight:800}
.result.ok{background:#e9f6f4;border:1px solid #bfe3de;color:#0a726b}
.result.no{background:#fdecea;border:1px solid #f0b8b1;color:#9d2b20}
.note{font-size:12.5px;color:#5f7078;margin-top:14px}
</style></head><body>
<div class="box">
  <h1>設置前チェック</h1>
  <p class="sub">このサーバーで Kurage LINE CRM が動くかを確認します。確認できたら、このファイル（check.php）は削除してください。</p>
  <table>
    <?php foreach ($rows as [$name, $ok, $detail, $req]): ?>
      <tr>
        <th><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></th>
        <td><span class="<?= $ok ? 'ok' : ($req ? 'no' : 'warn') ?>"><?= $ok ? '○' : ($req ? '×' : '△') ?></span>
          　<?= htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <div class="result <?= $ng === 0 ? 'ok' : 'no' ?>">
    <?= $ng === 0 ? '○ このサーバーで動きます。' : "× {$ng}件、足りないものがあります。上の × を解消してください。" ?>
  </div>
  <p class="note">PHPのバージョンが古い場合は、このフォルダの <code>.htaccess</code> に
    サーバー会社が指定する記述（例: ヘテムルなら <code>AddHandler php-script .php</code>）を入れると切り替わります。
    契約中のサーバーの案内をご確認ください。</p>
</div>
</body></html>
