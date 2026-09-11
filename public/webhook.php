<?php
/**
 * Kurage CRM (kcrm) — LINE Messaging API の Webhook 受け口。
 *
 * LINE Developers コンソールの「Webhook URL」にこのファイルのURLを設定する。
 *   例: https://exbridge.jp/crm/webhook.php
 *
 * 【必ず守ること】
 *  1. 署名検証（X-Line-Signature）を通らないものは捨てる。省くと誰でも偽イベントを投げ込める。
 *  2. 何があっても最後は 200 を返す。LINEは200以外だと再送を繰り返し、続くと配信を止める。
 *     そのため中の処理は try で包み、失敗はログに残して握りつぶす。
 *  3. 返信は Reply API を使う（通数課金の対象外）。Push はここでは使わない。
 */

declare(strict_types=1);
require_once __DIR__ . '/kcrm_lib.php';

$body = (string)file_get_contents('php://input');
$sig  = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

// LINEの「検証」ボタンは空ボディで来ることがある。200で返さないと検証が失敗扱いになる
if ($body === '') {
    http_response_code(200);
    echo 'ok';
    exit;
}

if (!kcrm_verify_signature($body, (string)$sig)) {
    try {
        kcrm_db()->prepare('INSERT INTO webhook_log(received_at,ok,note,events) VALUES(?,?,?,?)')
                 ->execute([kcrm_now(), 0, '署名検証に失敗（偽リクエストの可能性）', 0]);
    } catch (Throwable $e) { /* ログすら取れなくても止めない */ }
    http_response_code(400);
    echo 'bad signature';
    exit;
}

$data   = json_decode($body, true);
$events = (is_array($data) && isset($data['events']) && is_array($data['events'])) ? $data['events'] : [];

try {
    kcrm_db()->prepare('INSERT INTO webhook_log(received_at,ok,note,events) VALUES(?,?,?,?)')
             ->execute([kcrm_now(), 1, '', count($events)]);
} catch (Throwable $e) { /* 続行 */ }

foreach ($events as $ev) {
    try {
        $type    = (string)($ev['type'] ?? '');
        $user_id = (string)($ev['source']['userId'] ?? '');
        $token   = (string)($ev['replyToken'] ?? '');
        if ($user_id === '') { continue; }   // グループ・ルームは今は扱わない

        if ($type === 'follow') {
            kcrm_touch_contact($user_id, 'friend');
            kcrm_refresh_profile($user_id, true);
            kcrm_add_message($user_id, 'in', 'system', '友だち追加');
            if (KCRM_FOLLOW_REPLY !== '' && $token !== '') {
                kcrm_reply($token, KCRM_FOLLOW_REPLY);
                kcrm_add_message($user_id, 'out', 'text', KCRM_FOLLOW_REPLY, '', 0);
            }
            continue;
        }

        if ($type === 'unfollow') {
            kcrm_touch_contact($user_id, 'blocked');
            kcrm_add_message($user_id, 'in', 'system', 'ブロック（友だち解除）');
            continue;
        }

        if ($type !== 'message') { continue; }

        kcrm_touch_contact($user_id, 'friend');
        kcrm_refresh_profile($user_id);

        $m    = is_array($ev['message'] ?? null) ? $ev['message'] : [];
        $mt   = (string)($m['type'] ?? 'other');
        $mid  = (string)($m['id'] ?? '');
        if ($mt === 'text') {
            $text = (string)($m['text'] ?? '');
        } elseif ($mt === 'sticker') {
            $text = '［スタンプ］';
        } elseif (in_array($mt, ['image', 'video', 'audio', 'file'], true)) {
            $text = '［' . $mt . '］（内容はLINEアプリ側で確認してください）';
        } elseif ($mt === 'location') {
            $text = '［位置情報］' . (string)($m['address'] ?? '');
        } else {
            $text = '［' . $mt . '］';
        }
        kcrm_add_message($user_id, 'in', $mt, $text, $mid);

        // 受け取ったことだけ自動で返す（Reply APIなので無料）
        if (KCRM_AUTO_REPLY !== '' && $token !== '' && $mt === 'text') {
            kcrm_reply($token, KCRM_AUTO_REPLY);
            kcrm_add_message($user_id, 'out', 'text', KCRM_AUTO_REPLY, '', 0);
        }
    } catch (Throwable $e) {
        try {
            kcrm_db()->prepare('INSERT INTO webhook_log(received_at,ok,note,events) VALUES(?,?,?,?)')
                     ->execute([kcrm_now(), 0, '処理中のエラー: ' . mb_substr($e->getMessage(), 0, 200), 0]);
        } catch (Throwable $e2) { /* 何もしない */ }
    }
}

http_response_code(200);
echo 'ok';
