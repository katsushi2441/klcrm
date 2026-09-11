<?php
/**
 * Kurage CRM (kcrm) — 共通処理。
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

define('KCRM_DIR', __DIR__);
$__cfg = KCRM_DIR . '/kcrm_config.php';
if (is_file($__cfg)) { require_once $__cfg; }

foreach ([
    'KCRM_TITLE' => 'Kurage CRM',
    'KCRM_PASSWORD' => '', 'KCRM_PASSWORD_HASH' => '',
    'KCRM_LINE_CHANNEL_SECRET' => '', 'KCRM_LINE_ACCESS_TOKEN' => '',
    'KCRM_AUTO_REPLY' => '', 'KCRM_FOLLOW_REPLY' => '',
    'KCRM_PUSH_FREE_PER_MONTH' => 200,
] as $k => $v) { if (!defined($k)) { define($k, $v); } }

/** SQLite。web直下に置くので .htaccess で必ず遮断する（kcrm_data/.htaccess） */
function kcrm_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    $dir = KCRM_DIR . '/kcrm_data';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    // .htaccess が効かない環境でもURLを当てられないよう、ファイル名を秘密から導出する。
    // （.htaccess による遮断が本命。これは二重の備え）
    $seed = KCRM_LINE_CHANNEL_SECRET !== '' ? KCRM_LINE_CHANNEL_SECRET : KCRM_PASSWORD_HASH . KCRM_PASSWORD;
    $suffix = $seed !== '' ? substr(hash('sha256', 'kcrm-db|' . $seed), 0, 16) : 'local';
    $pdo = new PDO('sqlite:' . $dir . '/kcrm_' . $suffix . '.sqlite');
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
    $pdo->exec('CREATE TABLE IF NOT EXISTS webhook_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        received_at TEXT NOT NULL,
        ok INTEGER NOT NULL,               -- 署名検証の結果
        note TEXT DEFAULT "",
        events INTEGER DEFAULT 0
    )');
    return $pdo;
}

function kcrm_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
}

/**
 * LINEからのWebhookか検証する。
 * チャネルシークレットでHMAC-SHA256して、X-Line-Signature と一致するかを見る。
 * これを省くと、誰でも偽のイベントを投げ込めてしまう（顧客データを汚染される）。
 */
function kcrm_verify_signature(string $body, string $signature): bool
{
    if (KCRM_LINE_CHANNEL_SECRET === '' || $signature === '') { return false; }
    $expected = base64_encode(hash_hmac('sha256', $body, KCRM_LINE_CHANNEL_SECRET, true));
    return hash_equals($expected, $signature);
}

/** LINE APIを叩く。失敗しても例外にせず、[HTTPコード, 本文] を返す（Webhookは必ず200で返したいため） */
function kcrm_line_api(string $method, string $path, ?array $payload = null): array
{
    if (KCRM_LINE_ACCESS_TOKEN === '') { return [0, 'アクセストークンが未設定です']; }
    $ch = curl_init('https://api.line.me' . $path);
    $headers = ['Authorization: Bearer ' . KCRM_LINE_ACCESS_TOKEN];
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
function kcrm_reply(string $reply_token, string $text): array
{
    return kcrm_line_api('POST', '/v2/bot/message/reply', [
        'replyToken' => $reply_token,
        'messages' => [['type' => 'text', 'text' => mb_substr($text, 0, 4900)]],
    ]);
}

/** こちらから送る（通数課金） */
function kcrm_push(string $user_id, string $text): array
{
    return kcrm_line_api('POST', '/v2/bot/message/push', [
        'to' => $user_id,
        'messages' => [['type' => 'text', 'text' => mb_substr($text, 0, 4900)]],
    ]);
}

/** 表示名とアイコン。友だちでなくなると取れなくなるので、取れたときだけ更新する */
function kcrm_refresh_profile(string $user_id, bool $force = false): void
{
    $db = kcrm_db();
    $row = $db->prepare('SELECT profile_checked FROM contacts WHERE user_id=?');
    $row->execute([$user_id]);
    $checked = (string)($row->fetchColumn() ?: '');
    if (!$force && $checked !== '' && strtotime($checked) > time() - 86400) { return; }
    [$code, $res] = kcrm_line_api('GET', '/v2/bot/profile/' . rawurlencode($user_id));
    if ($code !== 200) { return; }
    $d = json_decode($res, true);
    if (!is_array($d)) { return; }
    $st = $db->prepare('UPDATE contacts SET display_name=?, picture_url=?, profile_checked=? WHERE user_id=?');
    $st->execute([(string)($d['displayName'] ?? ''), (string)($d['pictureUrl'] ?? ''), kcrm_now(), $user_id]);
}

function kcrm_touch_contact(string $user_id, string $status = ''): void
{
    $db = kcrm_db();
    $now = kcrm_now();
    $st = $db->prepare('INSERT INTO contacts(user_id, first_seen, last_seen) VALUES(?,?,?)
                        ON CONFLICT(user_id) DO UPDATE SET last_seen=excluded.last_seen');
    $st->execute([$user_id, $now, $now]);
    if ($status !== '') {
        $db->prepare('UPDATE contacts SET status=? WHERE user_id=?')->execute([$status, $user_id]);
    }
}

function kcrm_add_message(string $user_id, string $direction, string $kind, string $body,
                          string $line_message_id = '', int $billed = 0): void
{
    $db = kcrm_db();
    $db->prepare('INSERT INTO messages(user_id,direction,kind,body,line_message_id,billed,created_at)
                  VALUES(?,?,?,?,?,?,?)')
       ->execute([$user_id, $direction, $kind, $body, $line_message_id, $billed, kcrm_now()]);
    if ($direction === 'in') {
        $db->prepare('UPDATE contacts SET unread = unread + 1 WHERE user_id=?')->execute([$user_id]);
    }
}

/** 今月こちらから送った通数（無料枠の消費分）。画面に出して使い切り事故を防ぐ */
function kcrm_push_used_this_month(): int
{
    $db = kcrm_db();
    $m = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m');
    $st = $db->prepare("SELECT COUNT(*) FROM messages WHERE billed=1 AND substr(created_at,1,7)=?");
    $st->execute([$m]);
    return (int)$st->fetchColumn();
}

function kcrm_h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
