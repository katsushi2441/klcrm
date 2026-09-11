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

if (($_POST['action'] ?? '') === 'handled') {
    $uid = (string)($_POST['user_id'] ?? '');
    $to  = ((string)($_POST['to'] ?? '1')) === '1';
    // 対応済み＝「その時点の最新の受信ID」まで見たことにする（時刻だと同じ秒で判定できない）。
    // 未対応に戻す＝0に戻す。
    if ($to) {
        $db->prepare("UPDATE contacts SET handled_at=?, handled_msg_id =
              IFNULL((SELECT MAX(m.id) FROM messages m WHERE m.user_id=? AND m.direction='in'), 0)
            WHERE user_id=?")->execute([kcrm_now(), $uid, $uid]);
    } else {
        $db->prepare("UPDATE contacts SET handled_at='', handled_msg_id=0 WHERE user_id=?")->execute([$uid]);
    }
    $notice = $to ? '対応済みにしました。' : '未対応に戻しました。';
}

/* 連絡先（会社名・担当者名・メール・電話・URL・住所・流入元） */
if (($_POST['action'] ?? '') === 'contact') {
    $uid = (string)($_POST['user_id'] ?? '');
    $f = [];
    foreach (['company', 'person_name', 'email', 'phone', 'url', 'address', 'source'] as $k) {
        $f[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, 200);
    }
    $db->prepare('UPDATE contacts SET company=?, person_name=?, email=?, phone=?, url=?, address=?, source=?
                  WHERE user_id=?')
       ->execute([$f['company'], $f['person_name'], $f['email'], $f['phone'], $f['url'],
                  $f['address'], $f['source'], $uid]);
    $notice = '連絡先を保存しました。';
}

/* ノート（1人1枚の大きなメモ。打ち合わせ記録を書き足していく場所） */
if (($_POST['action'] ?? '') === 'note_save') {
    $uid = (string)($_POST['user_id'] ?? '');
    $db->prepare('UPDATE contacts SET note=? WHERE user_id=?')
       ->execute([mb_substr((string)($_POST['note'] ?? ''), 0, 50000), $uid]);
    $notice = 'ノートを保存しました。';
}

$sel = (string)($_GET['u'] ?? ($_POST['user_id'] ?? ''));
$q     = trim((string)($_GET['q'] ?? ''));
$total = 0;
if ($sel !== '') {
    $db->prepare('UPDATE contacts SET unread=0 WHERE user_id=?')->execute([$sel]);
}

$filter = (string)($_GET['f'] ?? '');
$open_sql = '(' . KCRM_OPEN_SQL . ') AS is_open';
$contacts = $filter === 'open'
    ? $db->query("SELECT *, $open_sql FROM contacts c WHERE " . KCRM_OPEN_SQL . " ORDER BY last_seen DESC")->fetchAll()
    : $db->query("SELECT *, $open_sql FROM contacts c ORDER BY is_open DESC, last_seen DESC")->fetchAll();
$open_n = kcrm_open_count();
$thread   = [];
$person   = null;
if ($sel !== '') {
    $st = $db->prepare("SELECT *, (" . KCRM_OPEN_SQL . ") AS is_open FROM contacts c WHERE user_id=?");
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
  font-family:-apple-system,"Hiragino Sans","Noto Sans JP",sans-serif;
  height:100vh;display:flex;flex-direction:column;overflow:hidden}
header,.notice,.err{flex:none}
header{background:#fff;border-bottom:1px solid var(--line);padding:11px 18px;display:flex;
  align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
header b{font-size:16px}
.meter{font-size:12.5px;color:var(--muted)}
.meter b{color:var(--teal-d)}
.meter.warn b{color:#c0392b}
header a{font-size:13px;color:var(--teal-d)}
.wrap{display:grid;grid-template-columns:250px minmax(320px,1fr) minmax(420px,520px);gap:0;
  flex:1;min-height:0}
.side{border-left:1px solid var(--line);background:#fff;overflow-y:auto;min-height:0;
  padding:14px 16px 40px;overscroll-behavior:contain}
.side h4{margin:0 0 9px;font-size:13px;font-weight:900;color:var(--teal-d);letter-spacing:.03em;
  border-bottom:2px solid var(--line);padding-bottom:6px}
.side h4 + h4{margin-top:24px}
.fld{margin-bottom:9px}
.fld label{display:block;font-size:11px;color:var(--muted);font-weight:700;margin-bottom:2px}
.fld input{width:100%;padding:7px 10px;font:inherit;font-size:13.5px;border:1px solid #cdd8e3;border-radius:8px}
.side form button{width:100%;padding:9px;font-size:13px;margin-top:4px}
.lineid{font-size:10.5px;color:var(--muted);word-break:break-all;background:#f4f8f9;
  border-radius:6px;padding:6px 8px;margin-bottom:12px;line-height:1.5}
.side h4 .sub2{display:block;font-size:10.5px;font-weight:600;color:var(--muted);margin-top:2px;letter-spacing:0}
.noteform{margin-bottom:26px}
.noteform textarea{width:100%;height:min(52vh,520px);padding:13px 15px;font:inherit;font-size:14px;
  line-height:1.9;border:1px solid #cdd8e3;border-radius:11px;resize:vertical;background:#fffef9}
.noteform textarea:focus{outline:2px solid var(--teal);outline-offset:-1px;border-color:var(--teal)}
.noterow{display:flex;gap:8px;align-items:center;margin-top:9px;flex-wrap:wrap}
.noterow button{width:auto;padding:9px 20px;font-size:13.5px;margin-top:0}
.saved{font-size:11.5px;color:var(--muted)}
.cform .fld{margin-bottom:7px}
.cform input{font-size:13px;padding:6px 9px}
.cform label{font-size:10.5px}
@media(max-width:1100px){.wrap{grid-template-columns:250px minmax(0,1fr)}.side{grid-column:1/-1;border-left:0;border-top:1px solid var(--line)}}
.list{border-right:1px solid var(--line);overflow-y:auto;background:#fff;min-height:0}
.list a{display:block;padding:11px 14px;border-bottom:1px solid var(--line);text-decoration:none;color:var(--ink)}
.list a.on{background:#e9f6f4}
.list .nm{font-weight:800;font-size:14px}
.list .lm{font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.badge{display:inline-block;background:#c0392b;color:#fff;border-radius:999px;font-size:11px;
  font-weight:800;padding:0 7px;margin-left:6px}
.blocked{color:#c0392b;font-size:11px;font-weight:800}
.open-n{background:#c0392b;color:#fff;border-radius:999px;padding:2px 11px;font-size:13px;font-weight:900}
.open-n.zero{background:#0a9a8f}
.filters{display:flex;gap:6px;border-bottom:1px solid var(--line);background:#fff;padding:8px 12px}
.filters a{font-size:12.5px;padding:5px 11px;border-radius:999px;text-decoration:none;
  border:1px solid var(--line);color:var(--muted);background:#fff}
.filters a.on{background:var(--teal);border-color:var(--teal);color:#fff;font-weight:800}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#c0392b;margin-right:6px;vertical-align:1px}
.done-tag{font-size:11px;color:var(--muted);font-weight:700}
.who .state{margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.who .state button{padding:8px 16px;font-size:13px}
.replybox{flex:none;border-top:1px solid var(--line);background:#fff;padding:10px 16px}
.replybox[open]{padding-bottom:14px}
.replybox summary{font-size:13px;color:var(--muted);cursor:pointer;font-weight:700}
.replybox[open] summary{margin-bottom:10px}
.hint{flex:none;border-top:1px solid var(--line);background:#f7fbfb;padding:10px 18px;font-size:12.5px;color:var(--muted)}
.hint b{color:var(--ink)}
.pane{display:flex;flex-direction:column;min-width:0;min-height:0}
.searchbar{flex:none;border-bottom:1px solid var(--line);background:#fff;padding:9px 18px;display:flex;
  gap:9px;align-items:center;flex-wrap:wrap}
.searchbar input{flex:1;min-width:min(100%,200px);padding:8px 12px;font:inherit;font-size:14px;
  border:1px solid #cdd8e3;border-radius:9px}
.searchbar button{padding:8px 15px;font-size:13.5px}
.searchbar .hit{font-size:12.5px;color:var(--muted)}
.searchbar .hit b{color:var(--teal-d)}
.searchbar a{font-size:12.5px;color:var(--teal-d)}
.thread{flex:1;min-height:0;overflow-y:auto;padding:18px;overscroll-behavior:contain}
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
.replybox textarea{width:100%;height:190px;min-height:120px;padding:12px 14px;font:inherit;font-size:14.5px;
  line-height:1.85;border:1px solid #cdd8e3;border-radius:10px;resize:vertical;background:#fff}
.replybox textarea:focus{outline:2px solid var(--teal);outline-offset:-1px;border-color:var(--teal)}
.row{display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap}
button{padding:10px 20px;font-size:14px;font-weight:800;color:#fff;background:var(--teal);
  border:0;border-radius:9px;cursor:pointer;font-family:inherit}
button.sub{background:#fff;color:var(--teal-d);border:1px solid var(--teal)}
.warnline{font-size:12px;color:#8a6410;background:#fff8e6;border:1px solid #ecd8a7;
  border-radius:8px;padding:7px 11px}
.empty{padding:40px 20px;color:var(--muted)}
.notice{background:#e9f6f4;border:1px solid #bfe3de;padding:9px 14px;font-size:13.5px}
.err{background:#fdecea;border:1px solid #f0b8b1;padding:9px 14px;font-size:13.5px;color:#9d2b20}
.who{flex:none;padding:12px 18px;border-bottom:1px solid var(--line);background:#fff;display:flex;
  align-items:center;gap:12px;flex-wrap:wrap}
.who img{width:38px;height:38px;border-radius:50%;object-fit:cover;background:#eee}
.who .uid{font-size:11px;color:var(--muted);word-break:break-all}
.note-form{margin-left:auto;display:flex;gap:6px;align-items:center}
.note-form input{padding:7px 10px;font-size:13px;border:1px solid #cdd8e3;border-radius:8px;width:min(260px,40vw)}
.note-form button{padding:7px 12px;font-size:12.5px}
@media(max-width:1100px){
  body{height:auto;display:block;overflow:visible}
  .wrap{min-height:0}
  .list,.side,.thread{overflow-y:visible;max-height:none}
}
@media(max-width:760px){.wrap{grid-template-columns:1fr}.list{max-height:38vh;overflow-y:auto}}
</style></head><body>
<header>
  <b><?= kcrm_h(KCRM_TITLE) ?></b>
  <span class="open-n <?= $open_n === 0 ? 'zero' : '' ?>">未対応 <?= $open_n ?>件</span>
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
    <div class="filters">
      <a href="?" class="<?= $filter !== 'open' ? 'on' : '' ?>">すべて</a>
      <a href="?f=open" class="<?= $filter === 'open' ? 'on' : '' ?>">未対応だけ（<?= $open_n ?>）</a>
    </div>
    <?php if (!$contacts): ?>
      <div class="empty">まだ誰からも届いていません。<br>LINE公式アカウントを友だち追加して、メッセージを送ってみてください。</div>
    <?php endif; ?>
    <?php foreach ($contacts as $c):
      $last = $db->prepare('SELECT body FROM messages WHERE user_id=? ORDER BY id DESC LIMIT 1');
      $last->execute([$c['user_id']]);
      $lastbody = (string)($last->fetchColumn() ?: ''); ?>
      <a href="?u=<?= urlencode((string)$c['user_id']) ?>" class="<?= $sel === $c['user_id'] ? 'on' : '' ?>">
        <div class="nm"><?php if ((int)($c['is_open'] ?? 0) === 1): ?><span class="dot" title="未対応"></span><?php endif; ?><?= kcrm_h(($c['display_name'] !== '' ? $c['display_name'] : '（名前未取得）')) ?>
          <?php if ((int)$c['unread'] > 0): ?><span class="badge"><?= (int)$c['unread'] ?></span><?php endif; ?>
          <?php if ((int)($c['is_open'] ?? 0) === 0): ?><span class="done-tag">済</span><?php endif; ?>
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
        <div class="state">
          <?php $isopen = ((int)($person['is_open'] ?? 0) === 1); ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="handled">
            <input type="hidden" name="user_id" value="<?= kcrm_h((string)$person['user_id']) ?>">
            <input type="hidden" name="to" value="<?= $isopen ? '1' : '0' ?>">
            <button type="submit" class="<?= $isopen ? '' : 'sub' ?>">
              <?= $isopen ? '✓ 対応済みにする' : '未対応に戻す' ?>
            </button>
          </form>
        </div>
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

      <div class="hint">
        <b>返信はLINEの画面から行ってください。</b>
        <a href="https://manager.line.biz/account/@271hokhu/chat" target="_blank" rel="noopener">LINEのチャットを開く</a>
        ／ スマホは「LINE公式アカウント」アプリ。
        <span>LINEから送った返信はここには残らないため、対応が終わったら上の<b>［対応済みにする］</b>を押してください。</span>
      </div>
      <div class="replybox">
        <?php if ($person['status'] === 'blocked'): ?>
          <div class="warnline">この方はブロック中のため、送信できません。</div>
        <?php else: ?>
          <details>
            <summary>ここから送ることもできます（記録は残りますが、無料枠を1通消費します）</summary>
            <form method="post">
              <input type="hidden" name="action" value="send">
              <input type="hidden" name="user_id" value="<?= kcrm_h((string)$person['user_id']) ?>">
              <textarea name="text" placeholder="返信を書く" required></textarea>
              <div class="row">
                <button type="submit">送信する</button>
                <span class="warnline">Push API のため<b>無料枠を1通消費</b>します（今月 <?= $used ?>/<?= $free ?>）。</span>
              </div>
            </form>
          </details>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($person): ?>
  <aside class="side">
    <h4>ノート<span class="sub2">打ち合わせ・条件・次にやること</span></h4>
    <form method="post" class="noteform">
      <input type="hidden" name="action" value="note_save">
      <input type="hidden" name="user_id" value="<?= kcrm_h((string)$person['user_id']) ?>">
      <textarea name="note" id="noteta" placeholder="ここに書き足していきます。

9/11 電話
・見積の条件を確認。予算は50万前後
・次回までに構成案を送る

9/12 訪問
・…"><?= kcrm_h((string)($person['note'] ?? '')) ?></textarea>
      <div class="noterow">
        <button type="submit">ノートを保存</button>
        <button type="button" class="sub" onclick="stampDate()">日付を入れる</button>
        <span class="saved" id="savedmark"></span>
      </div>
    </form>

    <h4>連絡先<span class="sub2">補助情報</span></h4>
    <div class="lineid">LINE表示名: <?= kcrm_h((string)$person['display_name']) ?><br>userId: <?= kcrm_h((string)$person['user_id']) ?></div>
    <form method="post" class="cform">
      <input type="hidden" name="action" value="contact">
      <input type="hidden" name="user_id" value="<?= kcrm_h((string)$person['user_id']) ?>">
      <?php foreach ([
        'company'     => ['会社名・団体名', 'text'],
        'person_name' => ['お名前（本名）', 'text'],
        'email'       => ['メール', 'email'],
        'phone'       => ['電話', 'tel'],
        'url'         => ['URL', 'url'],
        'address'     => ['住所', 'text'],
        'source'      => ['きっかけ', 'text'],
      ] as $k => $meta): ?>
        <div class="fld">
          <label for="f_<?= $k ?>"><?= kcrm_h($meta[0]) ?></label>
          <input id="f_<?= $k ?>" type="<?= $meta[1] ?>" name="<?= $k ?>" value="<?= kcrm_h((string)($person[$k] ?? '')) ?>">
        </div>
      <?php endforeach; ?>
      <button type="submit" class="sub">連絡先を保存</button>
    </form>
  </aside>
  <?php endif; ?>
</div>
<script>
// ノートの先頭に日付の見出しを入れる（毎回手で打つのが面倒なので）
function stampDate(){
  var ta=document.getElementById('noteta'); if(!ta) return;
  var d=new Date(), m=(d.getMonth()+1)+'/'+d.getDate();
  var head=m+'\n・';
  ta.value = ta.value.trim()==='' ? head : head + '\n\n' + ta.value;
  ta.focus(); ta.setSelectionRange(head.length, head.length);
}
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
