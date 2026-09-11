<?php
/**
 * Kurage LINE CRM (klcrm) — 共通処理。
 *
 * LINE公式アカウントに届いた相談を、送信者(userId)ごとに記録する窓口の土台。
 * 画面(index.php)とWebhook(webhook.php)の両方から読み込む。
 *
 * 【お金の話（設計に効くので最初に書く）】
 *   - Reply API（届いたメッセージへの返信）は通数課金の対象外。何通返しても無料。
 *   - Push API（こちらから送る）は通数課金。コミュニケーションプランは月200通まで無料。
 *   ゆえに Webhook の中で返す自動応答は無料、画面からの返信は有料枠を消費する。
 *   画面には必ず今月のPush数を出す（知らずに使い切る事故を防ぐ）。
 *
 * 【userId について】
 *   userId は「このチャネルの中だけで有効な識別子」で、他社のボットとは共通しない。
 *   ブロック→再追加でも同じ値なので、顧客キーとして使える。
 *   氏名・メール・電話は取れない。表示名(displayName)はLINEのニックネームで本名とは限らない。
 */

declare(strict_types=1);

define('KLCRM_DIR', __DIR__);
$__cfg = KLCRM_DIR . '/klcrm_config.php';
if (is_file($__cfg)) { require_once $__cfg; }

foreach ([
    'KLCRM_TITLE' => 'Kurage LINE CRM',
    'KLCRM_PASSWORD' => '', 'KLCRM_PASSWORD_HASH' => '',
    'KLCRM_LINE_CHANNEL_SECRET' => '', 'KLCRM_LINE_ACCESS_TOKEN' => '',
    'KLCRM_LINE_BASIC_ID' => '',
    'KLCRM_AUTO_REPLY' => '', 'KLCRM_FOLLOW_REPLY' => '',
    'KLCRM_PUSH_FREE_PER_MONTH' => 200,
] as $k => $v) { if (!defined($k)) { define($k, $v); } }

/** SQLite。web直下に置くので .htaccess で必ず遮断する（klcrm_data/.htaccess） */
function klcrm_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    $dir = KLCRM_DIR . '/klcrm_data';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    // .htaccess が効かない環境でもURLを当てられないよう、ファイル名を秘密から導出する。
    // （.htaccess による遮断が本命。これは二重の備え）
    $seed = KLCRM_LINE_CHANNEL_SECRET !== '' ? KLCRM_LINE_CHANNEL_SECRET : KLCRM_PASSWORD_HASH . KLCRM_PASSWORD;
    // 名前を kcrm → klcrm に変えたが、DBのファイル名は変えない。
    // ハッシュの元文字列を変えると既存のDBが見つからなくなり、記録が消えたように見えるため。
    $suffix = $seed !== '' ? substr(hash('sha256', 'kcrm-db|' . $seed), 0, 16) : 'local';
    $file = $dir . '/kcrm_' . $suffix . '.sqlite';
    // 旧フォルダ（kcrm_data）に残っていれば引き継ぐ
    $old = dirname($dir) . '/kcrm_data/kcrm_' . $suffix . '.sqlite';
    if (!is_file($file) && is_file($old)) {
        foreach (['', '-wal', '-shm'] as $ext) {
            if (is_file($old . $ext)) { @rename($old . $ext, $file . $ext); }
        }
    }
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS contacts (
        user_id TEXT PRIMARY KEY,
        display_name TEXT DEFAULT "",
        picture_url TEXT DEFAULT "",
        status TEXT DEFAULT "friend",      -- friend / blocked
        note TEXT DEFAULT "",              -- 担当者の私的メモ（相手には見えない）
        first_seen TEXT,
        last_seen TEXT,
        unread INTEGER DEFAULT 0,
        profile_checked TEXT DEFAULT ""
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        direction TEXT NOT NULL,           -- in（相手から） / out（こちらから）
        kind TEXT NOT NULL DEFAULT "text", -- text / image / sticker / other / system
        body TEXT DEFAULT "",
        line_message_id TEXT DEFAULT "",
        billed INTEGER DEFAULT 0,          -- 1 なら通数課金の対象（Push）
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_user ON messages(user_id, id)');
    // 既存DBにも後から足せるようにする（列が無ければ追加）
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(contacts)') as $c) { $cols[] = $c['name']; }
    // 顧客台帳としての項目。LINEの表示名は本名とは限らないので person_name と必ず分ける
    // （同じ列に混ぜると、あとで名寄せできなくなる）
    foreach ([
        'company'     => "TEXT DEFAULT ''",   // 会社名・団体名
        'person_name' => "TEXT DEFAULT ''",   // 本名（display_nameとは別物）
        'email'       => "TEXT DEFAULT ''",
        'phone'       => "TEXT DEFAULT ''",
        'url'         => "TEXT DEFAULT ''",
        'address'     => "TEXT DEFAULT ''",
        'source'      => "TEXT DEFAULT ''",   // どこから来たか（LINE/紹介/展示会…）
    ] as $col => $decl) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE contacts ADD COLUMN $col $decl");
        }
    }
    // ノート（1行メモでは足りないので、件数を積める形にする）
    $pdo->exec('CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        title TEXT DEFAULT "",
        body TEXT DEFAULT "",
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notes_user ON notes(user_id, id)');
    // ノートは「1人につき1枚の大きなメモ」にする（contacts.note）。
    // 以前 notes テーブルへ分けたが、細切れより1枚に書き足すほうが実務に合う。
    // 既に notes 行があれば、古い順に連結して note へ戻し、notes は空にする。
    if (!in_array('note_merged', $cols, true)) {
        $pdo->exec("ALTER TABLE contacts ADD COLUMN note_merged INTEGER DEFAULT 0");
        $has_notes = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='notes'")->fetchColumn();
        if ($has_notes) {
            foreach ($pdo->query('SELECT DISTINCT user_id FROM notes') as $r) {
                $uid = $r['user_id'];
                $parts = [];
                $q = $pdo->prepare('SELECT title, body, updated_at FROM notes WHERE user_id=? ORDER BY id ASC');
                $q->execute([$uid]);
                foreach ($q as $n) {
                    $parts[] = '【' . ($n['title'] !== '' ? $n['title'] : $n['updated_at']) . "】\n" . $n['body'];
                }
                $cur = (string)$pdo->query('SELECT note FROM contacts WHERE user_id=' . $pdo->quote($uid))->fetchColumn();
                $merged = trim(implode("\n\n", $parts) . ($cur !== '' ? "\n\n" . $cur : ''));
                $pdo->prepare('UPDATE contacts SET note=? WHERE user_id=?')->execute([$merged, $uid]);
            }
            $pdo->exec('DELETE FROM notes');
        }
        $pdo->exec('UPDATE contacts SET note_merged=1');
    }
    if (false) {
        $pdo->exec("ALTER TABLE contacts ADD COLUMN note_migrated INTEGER DEFAULT 0");
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        foreach ($pdo->query("SELECT user_id, note FROM contacts WHERE note <> ''") as $r) {
            $ins = $pdo->prepare('INSERT INTO notes(user_id,title,body,created_at,updated_at) VALUES(?,?,?,?,?)');
            $ins->execute([$r['user_id'], 'メモ', $r['note'], $now, $now]);
        }
        $pdo->exec('UPDATE contacts SET note_migrated=1');
    }
    // 未対応かどうかは「列に書いた真偽値」ではなく、そのつど計算する。
    //   未対応 = 最後に相手から届いた時刻 > 最後に「対応済み」を押した時刻
    // 真偽フラグだと、受信時の更新を一度でも書き損ねると以後ずっとズレたままになる。
    // 時刻の比較なら、受信側が何もしなくても新着で自動的に未対応へ戻る。
    if (!in_array('handled_at', $cols, true)) {
        $pdo->exec("ALTER TABLE contacts ADD COLUMN handled_at TEXT DEFAULT ''");
        if (in_array('handled', $cols, true)) {
            $pdo->exec("UPDATE contacts SET handled_at = last_seen WHERE handled = 1");
        }
    }
    // 時刻は秒単位なので、対応済みにした直後（同じ秒）に受信すると比較できない。
    // 受信メッセージの id（単調増加）で比べる。
    if (!in_array('handled_msg_id', $cols, true)) {
        $pdo->exec('ALTER TABLE contacts ADD COLUMN handled_msg_id INTEGER DEFAULT 0');
        // 旧データの引き継ぎ：対応済みだったものは、その時点の最新受信までを見たことにする
        $pdo->exec("UPDATE contacts SET handled_msg_id =
            IFNULL((SELECT MAX(m.id) FROM messages m WHERE m.user_id = contacts.user_id AND m.direction='in'), 0)
            WHERE IFNULL(handled_at,'') <> ''");
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS webhook_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        received_at TEXT NOT NULL,
        ok INTEGER NOT NULL,               -- 署名検証の結果
        note TEXT DEFAULT "",
        events INTEGER DEFAULT 0
    )');
    return $pdo;
}

function klcrm_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
}

/**
 * LINEからのWebhookか検証する。
 * チャネルシークレットでHMAC-SHA256して、X-Line-Signature と一致するかを見る。
 * これを省くと、誰でも偽のイベントを投げ込めてしまう（顧客データを汚染される）。
 */
function klcrm_verify_signature(string $body, string $signature): bool
{
    if (KLCRM_LINE_CHANNEL_SECRET === '' || $signature === '') { return false; }
    $expected = base64_encode(hash_hmac('sha256', $body, KLCRM_LINE_CHANNEL_SECRET, true));
    return hash_equals($expected, $signature);
}

/** LINE APIを叩く。失敗しても例外にせず、[HTTPコード, 本文] を返す（Webhookは必ず200で返したいため） */
function klcrm_line_api(string $method, string $path, ?array $payload = null): array
{
    if (KLCRM_LINE_ACCESS_TOKEN === '') { return [0, 'アクセストークンが未設定です']; }
    $ch = curl_init('https://api.line.me' . $path);
    $headers = ['Authorization: Bearer ' . KLCRM_LINE_ACCESS_TOKEN];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 15,
    ];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$res];
}

/** 返信（無料）。replyTokenは1回きり・発行から短時間しか使えない */
function klcrm_reply(string $reply_token, string $text): array
{
    return klcrm_line_api('POST', '/v2/bot/message/reply', [
        'replyToken' => $reply_token,
        'messages' => [['type' => 'text', 'text' => mb_substr($text, 0, 4900)]],
    ]);
}

/** こちらから送る（通数課金） */
function klcrm_push(string $user_id, string $text): array
{
    return klcrm_line_api('POST', '/v2/bot/message/push', [
        'to' => $user_id,
        'messages' => [['type' => 'text', 'text' => mb_substr($text, 0, 4900)]],
    ]);
}

/** 表示名とアイコン。友だちでなくなると取れなくなるので、取れたときだけ更新する */
function klcrm_refresh_profile(string $user_id, bool $force = false): void
{
    $db = klcrm_db();
    $row = $db->prepare('SELECT profile_checked FROM contacts WHERE user_id=?');
    $row->execute([$user_id]);
    $checked = (string)($row->fetchColumn() ?: '');
    if (!$force && $checked !== '' && strtotime($checked) > time() - 86400) { return; }
    [$code, $res] = klcrm_line_api('GET', '/v2/bot/profile/' . rawurlencode($user_id));
    if ($code !== 200) { return; }
    $d = json_decode($res, true);
    if (!is_array($d)) { return; }
    $st = $db->prepare('UPDATE contacts SET display_name=?, picture_url=?, profile_checked=? WHERE user_id=?');
    $st->execute([(string)($d['displayName'] ?? ''), (string)($d['pictureUrl'] ?? ''), klcrm_now(), $user_id]);
}

function klcrm_touch_contact(string $user_id, string $status = ''): void
{
    $db = klcrm_db();
    $now = klcrm_now();
    $st = $db->prepare('INSERT INTO contacts(user_id, first_seen, last_seen) VALUES(?,?,?)
                        ON CONFLICT(user_id) DO UPDATE SET last_seen=excluded.last_seen');
    $st->execute([$user_id, $now, $now]);
    if ($status !== '') {
        $db->prepare('UPDATE contacts SET status=? WHERE user_id=?')->execute([$status, $user_id]);
    }
}

function klcrm_add_message(string $user_id, string $direction, string $kind, string $body,
                          string $line_message_id = '', int $billed = 0): void
{
    $db = klcrm_db();
    $db->prepare('INSERT INTO messages(user_id,direction,kind,body,line_message_id,billed,created_at)
                  VALUES(?,?,?,?,?,?,?)')
       ->execute([$user_id, $direction, $kind, $body, $line_message_id, $billed, klcrm_now()]);
    if ($direction === 'in') {
        // 未読の数だけ更新する。未対応かどうかは handled_at との比較で毎回求めるので、
        // ここで状態を書く必要がない（書き損ねても壊れない）。
        $db->prepare('UPDATE contacts SET unread = unread + 1 WHERE user_id=?')->execute([$user_id]);
    }
}

/** 今月こちらから送った通数（無料枠の消費分）。画面に出して使い切り事故を防ぐ */
function klcrm_push_used_this_month(): int
{
    $db = klcrm_db();
    $m = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m');
    $st = $db->prepare("SELECT COUNT(*) FROM messages WHERE billed=1 AND substr(created_at,1,7)=?");
    $st->execute([$m]);
    return (int)$st->fetchColumn();
}

/** 未対応の判定式。ここ1か所だけに置く（画面と件数で食い違わせないため）。
 *  「最後に相手から届いた時刻」が「最後に対応済みを押した時刻」より後なら未対応。 */
define('KLCRM_OPEN_SQL',
    "IFNULL((SELECT MAX(m.id) FROM messages m WHERE m.user_id = c.user_id AND m.direction = 'in'), 0)"
    . " > IFNULL(c.handled_msg_id, 0)");

/** 未対応の件数。画面の主役はここ（返信そのものはLINE側で行う） */
function klcrm_open_count(): int
{
    return (int)klcrm_db()->query(
        "SELECT COUNT(*) FROM contacts c WHERE " . KLCRM_OPEN_SQL
    )->fetchColumn();
}

function klcrm_h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
