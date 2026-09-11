<?php
/**
 * Kurage CRM (kcrm) — 画面。
 * LINE公式アカウントに届いた相談を、送信者ごとに一覧・履歴で見て、返信する。
 *
 * 返信は Push API を使うので通数課金の対象。今月の使用数を常に画面へ出す。
 */

declare(strict_types=1);
require_once __DIR__ . '/kcrm_lib.php';

session_start();

/* ---------------- ログイン ---------------- */
$login_error = '';
if (isset($_POST['kcrm_pw'])) {
    $pw = (string)$_POST['kcrm_pw'];
    $ok = KCRM_PASSWORD_HASH !== ''
        ? password_verify($pw, KCRM_PASSWORD_HASH)
        : (KCRM_PASSWORD !== '' && hash_equals(KCRM_PASSWORD, $pw));
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['kcrm_ok'] = 1;
        header('Location: ' . strtok((string)$_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $login_error = 'パスワードが違います。';
    usleep(600000);
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: ./'); exit; }

if (empty($_SESSION['kcrm_ok'])) {
    $configured = (KCRM_PASSWORD !== '' || KCRM_PASSWORD_HASH !== '');
    ?><!doctype html><html lang="ja"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= kcrm_h(KCRM_TITLE) ?></title>
    <style>
    body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f3faf9;
      font-family:-apple-system,"Hiragino Sans","Noto Sans JP",sans-serif;color:#1d3038}
    form{background:#fff;border:1px solid #dcebe9;border-radius:16px;padding:28px 30px;width:min(360px,92vw)}
    h1{font-size:19px;margin:0 0 6px}p{color:#5f7078;font-size:13px;margin:0 0 18px}
    input{width:100%;padding:12px 14px;font-size:16px;border:1px solid #cdd8e3;border-radius:10px;margin-bottom:12px}
    button{width:100%;padding:12px;font-size:15px;font-weight:800;color:#fff;background:#0a9a8f;border:0;border-radius:10px;cursor:pointer}
    .err{color:#c0392b;font-size:13px;margin-bottom:10px}
    </style></head><body>
    <form method="post">
      <h1><?= kcrm_h(KCRM_TITLE) ?></h1>
      <p>LINEに届いた相談を見る窓口です。</p>
      <?php if ($login_error !== ''): ?><div class="err"><?= kcrm_h($login_error) ?></div><?php endif; ?>
      <?php if (!$configured): ?>
        <div class="err">kcrm_config.php にパスワードが設定されていません。</div>
      <?php endif; ?>
      <input type="password" name="kcrm_pw" placeholder="パスワード" autofocus required>
      <button type="submit">開く</button>
    </form></body></html><?php
    exit;
}

/* ---------------- 操作 ---------------- */
$db     = kcrm_db();
$notice = '';
$error  = '';

if (($_POST['action'] ?? '') === 'send') {
    $uid  = (string)($_POST['user_id'] ?? '');
    $text = trim((string)($_POST['text'] ?? ''));
    if ($uid === '' || $text === '') {
        $error = '送信先と本文が必要です。';
    } else {
        [$code, $res] = kcrm_push($uid, $text);
        if ($code === 200) {
            kcrm_add_message($uid, 'out', 'text', $text, '', 1);   // billed=1（通数を消費）
            $notice = '送信しました（今月の無料枠を1通消費しました）。';
        } else {
            $d = json_decode($res, true);
            $error = '送信できませんでした（HTTP ' . $code . '）：'
                   . kcrm_h((string)($d['message'] ?? mb_substr($res, 0, 200)));
        }
    }
}

if (($_POST['action'] ?? '') === 'note') {
    $uid = (string)($_POST['user_id'] ?? '');
    $db->prepare('UPDATE contacts SET note=? WHERE user_id=?')
       ->execute([mb_substr((string)($_POST['note'] ?? ''), 0, 2000), $uid]);
    $notice = 'メモを保存しました。';
}

$sel = (string)($_GET['u'] ?? ($_POST['user_id'] ?? ''));
$q     = trim((string)($_GET['q'] ?? ''));
$total = 0;
if ($sel !== '') {
    $db->prepare('UPDATE contacts SET unread=0 WHERE user_id=?')->execute([$sel]);
}

$contacts = $db->query('SELECT * FROM contacts ORDER BY last_seen DESC')->fetchAll();
$thread   = [];
$person   = null;
if ($sel !== '') {
    $st = $db->prepare('SELECT * FROM contacts WHERE user_id=?');
    $st->execute([$sel]);
    $person = $st->fetch() ?: null;
    // 選んでいる相手のメッセージだけを対象に検索する（q が空なら全件）
    if ($q !== '') {
        $st = $db->prepare('SELECT * FROM messages WHERE user_id=? AND body LIKE ? ORDER BY id ASC LIMIT 500');
        $st->execute([$sel, '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%']);
    } else {
        $st = $db->prepare('SELECT * FROM messages WHERE user_id=? ORDER BY id ASC LIMIT 500');
        $st->execute([$sel]);
    }
    $thread = $st->fetchAll();
    $total  = (int)$db->query('SELECT COUNT(*) FROM messages WHERE user_id=' . $db->quote($sel))->fetchColumn();
}
$used   = kcrm_push_used_this_month();
$free   = (int)KCRM_PUSH_FREE_PER_MONTH;
$ready  = (KCRM_LINE_CHANNEL_SECRET !== '' && KCRM_LINE_ACCESS_TOKEN !== '');
$hooks  = $db->query('SELECT * FROM webhook_log ORDER BY id DESC LIMIT 1')->fetch() ?: null;
?><!doctype html><html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= kcrm_h(KCRM_TITLE) ?></title>
<style>
:root{--ink:#1d3038;--muted:#5f7078;--line:#dcebe9;--teal:#0a9a8f;--teal-d:#076f67;--paper:#f3faf9}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font-size:15px;line-height:1.7;
  font-family:-apple-system,"Hiragino Sans","Noto Sans JP",sans-serif}
header{background:#fff;border-bottom:1px solid var(--line);padding:11px 18px;display:flex;
  align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
header b{font-size:16px}
.meter{font-size:12.5px;color:var(--muted)}
.meter b{color:var(--teal-d)}
.meter.warn b{color:#c0392b}
header a{font-size:13px;color:var(--teal-d)}
.wrap{display:grid;grid-template-columns:300px minmax(0,1fr);gap:0;height:calc(100vh - 52px)}
.list{border-right:1px solid var(--line);overflow-y:auto;background:#fff}
.list a{display:block;padding:11px 14px;border-bottom:1px solid var(--line);text-decoration:none;color:var(--ink)}
.list a.on{background:#e9f6f4}
.list .nm{font-weight:800;font-size:14px}
.list .lm{font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.badge{display:inline-block;background:#c0392b;color:#fff;border-radius:999px;font-size:11px;
  font-weight:800;padding:0 7px;margin-left:6px}
.blocked{color:#c0392b;font-size:11px;font-weight:800}
.pane{display:flex;flex-direction:column;min-width:0}
.searchbar{border-bottom:1px solid var(--line);background:#fff;padding:9px 18px;display:flex;
  gap:9px;align-items:center;flex-wrap:wrap}
.searchbar input{flex:1;min-width:min(100%,200px);padding:8px 12px;font:inherit;font-size:14px;
  border:1px solid #cdd8e3;border-radius:9px}
.searchbar button{padding:8px 15px;font-size:13.5px}
.searchbar .hit{font-size:12.5px;color:var(--muted)}
.searchbar .hit b{color:var(--teal-d)}
.searchbar a{font-size:12.5px;color:var(--teal-d)}
.thread{flex:1;overflow-y:auto;padding:18px}
.msg{position:relative}
.msg mark{background:#ffe9a8;padding:0 1px;border-radius:2px}
.msgtools{display:flex;gap:8px;align-items:center;margin-top:3px}
.copy{background:none;border:0;padding:2px 6px;font-size:11px;font-weight:700;color:var(--teal-d);
  cursor:pointer;border-radius:5px;font-family:inherit;opacity:.55}
.copy:hover{opacity:1;background:#e9f6f4}
.copy.done{color:#fff;background:var(--teal);opacity:1}
.msg{max-width:72%;margin-bottom:12px;padding:9px 13px;border-radius:14px;white-space:pre-wrap;word-break:break-word}
.in{background:#fff;border:1px solid var(--line)}
.out{background:#dff3ef;margin-left:auto}
.sys{background:#f1f4f6;color:var(--muted);font-size:13px;margin:0 auto 12px;text-align:center;max-width:60%}
.meta{font-size:11px;color:var(--muted);margin-top:3px}
.composer{border-top:1px solid var(--line);background:#fff;padding:12px 16px}
.composer textarea{width:100%;min-height:64px;padding:10px 12px;font:inherit;font-size:14px;
  border:1px solid #cdd8e3;border-radius:10px;resize:vertical}
.row{display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap}
button{padding:10px 20px;font-size:14px;font-weight:800;color:#fff;background:var(--teal);
  border:0;border-radius:9px;cursor:pointer;font-family:inherit}
button.sub{background:#fff;color:var(--teal-d);border:1px solid var(--teal)}
.warnline{font-size:12px;color:#8a6410;background:#fff8e6;border:1px solid #ecd8a7;
  border-radius:8px;padding:7px 11px}
.empty{padding:40px 20px;color:var(--muted)}
.notice{background:#e9f6f4;border:1px solid #bfe3de;padding:9px 14px;font-size:13.5px}
.err{background:#fdecea;border:1px solid #f0b8b1;padding:9px 14px;font-size:13.5px;color:#9d2b20}
.who{padding:12px 18px;border-bottom:1px solid var(--line);background:#fff;display:flex;
  align-items:center;gap:12px;flex-wrap:wrap}
.who img{width:38px;height:38px;border-radius:50%;object-fit:cover;background:#eee}
.who .uid{font-size:11px;color:var(--muted);word-break:break-all}
.note-form{margin-left:auto;display:flex;gap:6px;align-items:center}
.note-form input{padding:7px 10px;font-size:13px;border:1px solid #cdd8e3;border-radius:8px;width:min(260px,40vw)}
.note-form button{padding:7px 12px;font-size:12.5px}
@media(max-width:760px){.wrap{grid-template-columns:1fr;height:auto}.list{max-height:38vh}}
</style></head><body>
<header>
  <b><?= kcrm_h(KCRM_TITLE) ?></b>
  <span class="meter <?= $used >= $free ? 'warn' : '' ?>">
    今月こちらから送った数 <b><?= $used ?></b> / <?= $free ?> 通（無料枠）
    <?php if ($used >= $free): ?>— 超過分は課金されます<?php endif; ?>
  </span>
  <?php if (!$ready): ?><span class="blocked">LINEの設定が未完了（kcrm_config.php）</span><?php endif; ?>
  <?php if ($hooks): ?>
    <span class="meter">最終受信 <?= kcrm_h((string)$hooks['received_at']) ?>
      <?= ((int)$hooks['ok'] === 1) ? '' : '（署名エラー）' ?></span>
  <?php endif; ?>
  <a href="?logout=1">ログアウト</a>
</header>

<?php if ($notice !== ''): ?><div class="notice"><?= kcrm_h($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="err"><?= $error ?></div><?php endif; ?>

<div class="wrap">
  <div class="list">
    <?php if (!$contacts): ?>
      <div class="empty">まだ誰からも届いていません。<br>LINE公式アカウントを友だち追加して、メッセージを送ってみてください。</div>
    <?php endif; ?>
    <?php foreach ($contacts as $c):
      $last = $db->prepare('SELECT body FROM messages WHERE user_id=? ORDER BY id DESC LIMIT 1');
      $last->execute([$c['user_id']]);
      $lastbody = (string)($last->fetchColumn() ?: ''); ?>
      <a href="?u=<?= urlencode((string)$c['user_id']) ?>" class="<?= $sel === $c['user_id'] ? 'on' : '' ?>">
        <div class="nm"><?= kcrm_h(($c['display_name'] !== '' ? $c['display_name'] : '（名前未取得）')) ?>
          <?php if ((int)$c['unread'] > 0): ?><span class="badge"><?= (int)$c['unread'] ?></span><?php endif; ?>
          <?php if ($c['status'] === 'blocked'): ?><span class="blocked">ブロック中</span><?php endif; ?>
        </div>
        <div class="lm"><?= kcrm_h(mb_substr($lastbody, 0, 40)) ?></div>
        <div class="lm"><?= kcrm_h((string)$c['last_seen']) ?></div>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="pane">
    <?php if (!$person): ?>
      <div class="empty">左から相談者を選んでください。</div>
    <?php else: ?>
      <div class="who">
        <?php if ($person['picture_url'] !== ''): ?>
          <img src="<?= kcrm_h((string)$person['picture_url']) ?>" alt="">
        <?php endif; ?>
        <div>
          <b><?= kcrm_h(($person['display_name'] !== '' ? $person['display_name'] : '（名前未取得）')) ?></b>
          <?php if ($person['status'] === 'blocked'): ?><span class="blocked">ブロック中</span><?php endif; ?>
          <div class="uid"><?= kcrm_h((string)$person['user_id']) ?></div>
        </div>
        <form class="note-form" method="post">
          <input type="hidden" name="action" value="note">
          <input type="hidden" name="user_id" value="<?= kcrm_h((string)$person['user_id']) ?>">
          <input name="note" value="<?= kcrm_h((string)$person['note']) ?>" placeholder="メモ（相手には見えません）">
          <button class="sub" type="submit">保存</button>
        </form>
      </div>

      <form class="searchbar" method="get">
        <input type="hidden" name="u" value="<?= kcrm_h((string)$person['user_id']) ?>">
        <input name="q" value="<?= kcrm_h($q) ?>" placeholder="この相手のメッセージを検索（例: 見積 / 日程）">
        <button type="submit">検索</button>
        <?php if ($q !== ''): ?>
          <span class="hit"><b><?= count($thread) ?></b>件 / 全<?= $total ?>件</span>
          <a href="?u=<?= urlencode((string)$person['user_id']) ?>">解除</a>
        <?php else: ?>
          <span class="hit">全<?= $total ?>件</span>
        <?php endif; ?>
        <button type="button" class="sub" onclick="copyAll()">全文をコピー</button>
      </form>

      <div class="thread" id="thread">
        <?php foreach ($thread as $m):
          $cls = $m['kind'] === 'system' ? 'sys' : ($m['direction'] === 'in' ? 'in' : 'out'); ?>
          <?php
            $raw  = (string)$m['body'];
            $disp = kcrm_h($raw);
            if ($q !== '') {   // 検索語を目立たせる（HTMLエスケープ後の文字列に対して行う）
                $disp = preg_replace('/' . preg_quote(kcrm_h($q), '/') . '/iu', '<mark>$0</mark>', $disp);
            }
          ?>
          <div class="msg <?= $cls ?>"><?= $disp ?>
            <div class="msgtools">
              <span class="meta"><?= kcrm_h((string)$m['created_at']) ?><?= ((int)$m['billed'] === 1) ? ' ・通数1' : '' ?></span>
              <button type="button" class="copy" data-t="<?= kcrm_h($raw) ?>" onclick="copyOne(this)">コピー</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="composer">
        <?php if ($person['status'] === 'blocked'): ?>
          <div class="warnline">この方はブロック中のため、送信できません。</div>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="user_id" value="<?= kcrm_h((string)$person['user_id']) ?>">
            <textarea name="text" placeholder="返信を書く" required></textarea>
            <div class="row">
              <button type="submit">送信する</button>
              <span class="warnline">この送信は Push API です。<b>無料枠を1通消費します</b>（相手の発言への自動返信は無料）。</span>
            </div>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<script>
var t=document.getElementById('thread'); if(t){t.scrollTop=t.scrollHeight;}
function flash(btn, label){
  var old = btn.textContent; btn.textContent = label; btn.classList.add('done');
  setTimeout(function(){ btn.textContent = old; btn.classList.remove('done'); }, 1400);
}
function copyOne(btn){
  navigator.clipboard.writeText(btn.dataset.t).then(function(){ flash(btn, 'コピーしました'); });
}
function copyAll(){
  var out = [];
  document.querySelectorAll('#thread .msg').forEach(function(m){
    var b = m.querySelector('.copy'); if(!b) return;
    var who = m.classList.contains('in') ? '相手' : (m.classList.contains('out') ? '自分' : '記録');
    var tm  = (m.querySelector('.meta')||{}).textContent || '';
    out.push('[' + who + ' ' + tm.trim() + ']\n' + b.dataset.t);
  });
  navigator.clipboard.writeText(out.join('\n\n')).then(function(){
    var b = document.querySelector('.searchbar .sub'); if(b) flash(b, 'コピーしました');
  });
}
</script>
</body></html>
