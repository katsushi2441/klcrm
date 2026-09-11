<?php
/**
 * Kurage LINE CRM — MCPサーバー（1ファイル・依存ライブラリなし）
 *
 * Claude Code や Claude Desktop などのAIエージェントから、LINEに届いた相談を
 * 検索・参照し、ノートや連絡先を書き込むための橋渡し。
 * 「田中工務店の相談、これまでの経緯は？」「未対応の人を教えて」
 * 「今の打ち合わせ内容をノートに足しておいて」がそのまま通る。
 *
 * 設置:
 *   1. index.php と同じフォルダにこのファイルを置く
 *   2. Claude Code に登録:
 *        claude mcp add klcrm -- php /path/to/klcrm_mcp.php
 *      Claude Desktop の場合は claude_desktop_config.json に:
 *        {"mcpServers":{"klcrm":{"command":"php","args":["/path/to/klcrm_mcp.php"]}}}
 *
 * 【安全のための既定】
 *   - お客様へLINEを送るツール（klcrm_send）は **既定で無効**。
 *     KLCRM_MCP_ALLOW_PUSH=1 を設定したときだけ tools/list に現れる。
 *     送信は取り消せないうえ、Push APIなので通数課金の枠を消費するため。
 *   - KLCRM_MCP_READONLY=1 を設定すると、書き込みツールをすべて消して参照専用になる。
 *   - 判定（未対応かどうか・今月の通数）は製品本体の式をそのまま使う。
 *     ここで別の判定を作らない（画面とMCPで食い違わせないため）。
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('KLCRM_MCP_VERSION', '1.0.0');
define('KLCRM_MCP', true);

$READONLY  = (getenv('KLCRM_MCP_READONLY') === '1');
$ALLOW_PUSH = (getenv('KLCRM_MCP_ALLOW_PUSH') === '1') && !$READONLY;

$base = __DIR__ . '/klcrm_lib.php';
if (!is_file($base)) { fwrite(STDERR, "klcrm_lib.php が同じフォルダにありません: $base\n"); exit(1); }
if (!is_file(__DIR__ . '/klcrm_config.php')) {
    fwrite(STDERR, "klcrm_config.php がありません。klcrm_config.php.example をコピーして作ってください。\n");
    exit(1);
}
require $base;
if (!function_exists('klcrm_db')) { fwrite(STDERR, "klcrm_lib.php を読み込めませんでした\n"); exit(1); }

function kmcp_json($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); }
function kmcp_err($msg) { return [false, json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE)]; }

/** 相手の呼び名。LINEの表示名は本名とは限らないので、本名・会社名があればそちらを優先する */
function kmcp_label(array $r): string
{
    $parts = array_filter([(string)($r['company'] ?? ''), (string)($r['person_name'] ?? '')]);
    if ($parts) { return implode(' ', $parts); }
    return (string)($r['display_name'] ?? '') !== '' ? (string)$r['display_name'] : (string)$r['user_id'];
}

function kmcp_brief(array $r): array
{
    return [
        'user_id'      => $r['user_id'],
        'label'        => kmcp_label($r),
        'display_name' => $r['display_name'],   // LINEのニックネーム（本名ではない）
        'company'      => $r['company'] ?? '',
        'person_name'  => $r['person_name'] ?? '',
        'open'         => (int)($r['is_open'] ?? 0) === 1,   // 未対応か
        'status'       => $r['status'],                      // friend / blocked
        'last_seen'    => $r['last_seen'],
    ];
}

function kmcp_contacts(array $a): array
{
    $db = klcrm_db();
    $where = []; $bind = [];
    if (!empty($a['only_open'])) { $where[] = KLCRM_OPEN_SQL; }
    $q = isset($a['query']) ? trim((string)$a['query']) : '';
    if ($q !== '') {
        $where[] = '(c.display_name LIKE ? OR c.company LIKE ? OR c.person_name LIKE ?
                     OR c.email LIKE ? OR c.phone LIKE ?)';
        $like = '%' . $q . '%';
        array_push($bind, $like, $like, $like, $like, $like);
    }
    $limit = isset($a['limit']) ? max(1, min(200, (int)$a['limit'])) : 30;
    $sql = 'SELECT c.*, (' . KLCRM_OPEN_SQL . ') AS is_open FROM contacts c'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY is_open DESC, c.last_seen DESC LIMIT ' . $limit;
    $st = $db->prepare($sql); $st->execute($bind);
    $rows = array_map('kmcp_brief', $st->fetchAll());
    return [true, kmcp_json([
        'ok' => true, 'count' => count($rows),
        'open_total' => klcrm_open_count(),
        'contacts' => $rows,
    ])];
}

function kmcp_get(array $a): array
{
    $uid = (string)($a['user_id'] ?? '');
    if ($uid === '') { return kmcp_err('user_id を指定してください'); }
    $db = klcrm_db();
    $st = $db->prepare('SELECT c.*, (' . KLCRM_OPEN_SQL . ') AS is_open FROM contacts c WHERE c.user_id=?');
    $st->execute([$uid]);
    $r = $st->fetch();
    if (!$r) { return kmcp_err('その相手は見つかりません: ' . $uid); }

    $n = isset($a['messages']) ? max(0, min(200, (int)$a['messages'])) : 20;
    $msgs = [];
    if ($n > 0) {
        $ms = $db->prepare('SELECT direction,kind,body,billed,created_at FROM messages
                            WHERE user_id=? ORDER BY id DESC LIMIT ' . $n);
        $ms->execute([$uid]);
        $msgs = array_reverse($ms->fetchAll());   // 古い順に並べ直す（会話として読めるように）
    }
    $d = kmcp_brief($r);
    $d['contact'] = [
        'company' => $r['company'] ?? '', 'person_name' => $r['person_name'] ?? '',
        'email' => $r['email'] ?? '', 'phone' => $r['phone'] ?? '',
        'url' => $r['url'] ?? '', 'address' => $r['address'] ?? '',
        'source' => $r['source'] ?? '',
    ];
    $d['note'] = (string)($r['note'] ?? '');
    $d['first_seen'] = $r['first_seen'];
    $d['messages'] = $msgs;
    return [true, kmcp_json(['ok' => true, 'contact_detail' => $d])];
}

function kmcp_search(array $a): array
{
    $q = isset($a['query']) ? trim((string)$a['query']) : '';
    if ($q === '') { return kmcp_err('検索語（query）を指定してください'); }
    $limit = isset($a['limit']) ? max(1, min(100, (int)$a['limit'])) : 20;
    $bind = ['%' . $q . '%'];
    $narrow = '';
    if (!empty($a['user_id'])) { $narrow = ' AND m.user_id=?'; $bind[] = (string)$a['user_id']; }
    $st = klcrm_db()->prepare(
        'SELECT m.user_id, m.direction, m.kind, m.body, m.created_at,
                c.display_name, c.company, c.person_name
         FROM messages m LEFT JOIN contacts c ON c.user_id=m.user_id
         WHERE m.body LIKE ?' . $narrow . '
         ORDER BY m.id DESC LIMIT ' . $limit);
    $st->execute($bind);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'user_id'    => $r['user_id'],
            'label'      => kmcp_label($r),
            'direction'  => $r['direction'] === 'in' ? '相手から' : 'こちらから',
            'body'       => $r['body'],
            'created_at' => $r['created_at'],
        ];
    }
    return [true, kmcp_json(['ok' => true, 'count' => count($out), 'hits' => $out])];
}

function kmcp_status(array $a): array
{
    $db = klcrm_db();
    $used = klcrm_push_used_this_month();
    return [true, kmcp_json([
        'ok' => true,
        'open_count'    => klcrm_open_count(),
        'contacts'      => (int)$db->query('SELECT COUNT(*) FROM contacts')->fetchColumn(),
        'blocked'       => (int)$db->query("SELECT COUNT(*) FROM contacts WHERE status='blocked'")->fetchColumn(),
        'messages'      => (int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn(),
        'push_used_this_month' => $used,
        'push_free_per_month'  => (int)KLCRM_PUSH_FREE_PER_MONTH,
        'push_left'     => max(0, (int)KLCRM_PUSH_FREE_PER_MONTH - $used),
        'note' => '未対応は列ではなく毎回計算している（新しいメッセージが届けば自動で未対応に戻る）',
    ])];
}

function kmcp_note_set(array $a): array
{
    $uid = (string)($a['user_id'] ?? '');
    $text = (string)($a['text'] ?? '');
    if ($uid === '') { return kmcp_err('user_id を指定してください'); }
    $mode = ((string)($a['mode'] ?? 'append')) === 'replace' ? 'replace' : 'append';
    // 追記で中身が空だと、日時だけの行がノートに積もる（replace の空は「消す」意図なので通す）
    if ($mode === 'append' && trim($text) === '') { return kmcp_err('追記する内容（text）が空です'); }
    $db = klcrm_db();
    $st = $db->prepare('SELECT note FROM contacts WHERE user_id=?');
    $st->execute([$uid]);
    $cur = $st->fetchColumn();
    if ($cur === false) { return kmcp_err('その相手は見つかりません: ' . $uid); }
    $next = $mode === 'replace'
        ? $text
        : rtrim((string)$cur) . ((string)$cur === '' ? '' : "\n\n") . '[' . klcrm_now() . "]\n" . $text;
    $db->prepare('UPDATE contacts SET note=? WHERE user_id=?')->execute([$next, $uid]);
    return [true, kmcp_json(['ok' => true, 'mode' => $mode, 'note_chars' => mb_strlen($next)])];
}

function kmcp_contact_set(array $a): array
{
    $uid = (string)($a['user_id'] ?? '');
    if ($uid === '') { return kmcp_err('user_id を指定してください'); }
    $set = []; $bind = [];
    foreach (['company', 'person_name', 'email', 'phone', 'url', 'address', 'source'] as $k) {
        if (array_key_exists($k, $a)) { $set[] = "$k=?"; $bind[] = (string)$a[$k]; }
    }
    if (!$set) { return kmcp_err('更新する項目がありません（company / person_name / email / phone / url / address / source）'); }
    $bind[] = $uid;
    $st = klcrm_db()->prepare('UPDATE contacts SET ' . implode(',', $set) . ' WHERE user_id=?');
    $st->execute($bind);
    if ($st->rowCount() === 0) { return kmcp_err('その相手は見つかりません: ' . $uid); }
    return [true, kmcp_json(['ok' => true, 'updated' => count($set)])];
}

function kmcp_handled(array $a): array
{
    $uid = (string)($a['user_id'] ?? '');
    if ($uid === '') { return kmcp_err('user_id を指定してください'); }
    $to = array_key_exists('handled', $a) ? (bool)$a['handled'] : true;
    $db = klcrm_db();
    if ($to) {
        // 画面と同じ式。時刻ではなく「その時点の最新の受信ID」まで見たことにする
        $db->prepare("UPDATE contacts SET handled_at=?, handled_msg_id =
              IFNULL((SELECT MAX(m.id) FROM messages m WHERE m.user_id=? AND m.direction='in'), 0)
            WHERE user_id=?")->execute([klcrm_now(), $uid, $uid]);
    } else {
        $db->prepare("UPDATE contacts SET handled_at='', handled_msg_id=0 WHERE user_id=?")->execute([$uid]);
    }
    return [true, kmcp_json(['ok' => true, 'handled' => $to,
        'note' => '新しいメッセージが届けば自動で未対応に戻る'])];
}

function kmcp_send(array $a): array
{
    $uid  = (string)($a['user_id'] ?? '');
    $text = trim((string)($a['text'] ?? ''));
    if ($uid === '' || $text === '') { return kmcp_err('user_id と text を指定してください'); }
    if (KLCRM_LINE_ACCESS_TOKEN === '') { return kmcp_err('アクセストークンが未設定です（klcrm_config.php）'); }
    $used = klcrm_push_used_this_month();
    if ($used >= (int)KLCRM_PUSH_FREE_PER_MONTH) {
        return kmcp_err('今月の無料枠を使い切っています（' . $used . '/' . (int)KLCRM_PUSH_FREE_PER_MONTH
            . '通）。これ以上はプランの追加料金がかかるため送りません。');
    }
    [$code, $res] = klcrm_push($uid, $text);
    if ($code !== 200) { return kmcp_err('LINEへの送信に失敗しました（HTTP ' . $code . '）: ' . mb_substr((string)$res, 0, 200)); }
    klcrm_add_message($uid, 'out', 'text', $text, '', 1);
    return [true, kmcp_json(['ok' => true, 'sent' => true,
        'push_used_this_month' => $used + 1, 'push_free_per_month' => (int)KLCRM_PUSH_FREE_PER_MONTH])];
}

/* ---- ツール定義 ---------------------------------------------------------- */

$READ_TOOLS = [
    [
        'name' => 'klcrm_contacts',
        'description' => 'LINEに届いた相談の相手を一覧する。未対応が先頭に来る。only_open=true で未対応だけに絞れる。会社名・本名・メール・電話でも探せる。中身（やり取りとノート）が要るときは klcrm_get を使う。',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'only_open' => ['type' => 'boolean', 'description' => '未対応の相手だけに絞る'],
            'query'     => ['type' => 'string', 'description' => '表示名・会社名・本名・メール・電話の部分一致（任意）'],
            'limit'     => ['type' => 'integer', 'description' => '件数の上限（既定30・最大200）'],
        ], 'required' => []],
    ],
    [
        'name' => 'klcrm_get',
        'description' => '相手1人の詳細を取得する。連絡先（会社名・本名・メール・電話・URL・住所・きっかけ）、1枚のノート、直近のやり取りが返る。「この人の経緯を教えて」に答えるときはこれを使う。',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'user_id'  => ['type' => 'string', 'description' => 'LINEのuserId（klcrm_contacts / klcrm_search で得たもの）'],
            'messages' => ['type' => 'integer', 'description' => '一緒に返すやり取りの件数（既定20・最大200・0で省略）'],
        ], 'required' => ['user_id']],
    ],
    [
        'name' => 'klcrm_search',
        'description' => 'やり取りの本文を横断検索する。user_id を渡すとその相手の中だけを探す。「見積もりの話をしたのは誰か」「このキャンペーンに反応した人」を調べるときに使う。',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'query'   => ['type' => 'string', 'description' => '検索する語句'],
            'user_id' => ['type' => 'string', 'description' => 'この相手の中だけを探す（任意）'],
            'limit'   => ['type' => 'integer', 'description' => '件数の上限（既定20・最大100）'],
        ], 'required' => ['query']],
    ],
    [
        'name' => 'klcrm_status',
        'description' => '全体の状況を返す。未対応の件数、友だちの数、ブロック数、今月こちらから送った通数と無料枠の残り。「いま何件たまってる？」に答えるときに使う。',
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
    ],
];

$WRITE_TOOLS = [
    [
        'name' => 'klcrm_note_set',
        'description' => '相手のノート（1人1枚の大きなメモ）を書く。既定は追記で、日時つきで末尾に足す。mode="replace" で全文を差し替える。打ち合わせの記録や次にやることを残すときに使う。',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'user_id' => ['type' => 'string', 'description' => 'LINEのuserId'],
            'text'    => ['type' => 'string', 'description' => '書く内容'],
            'mode'    => ['type' => 'string', 'description' => 'append（既定・日時つきで追記）または replace（全文差し替え）'],
        ], 'required' => ['user_id', 'text']],
    ],
    [
        'name' => 'klcrm_contact_set',
        'description' => '連絡先を更新する。渡した項目だけを書き換える。LINEからは氏名・メール・電話が取れないので、聞き取った内容をここに入れる。person_name（本名）と display_name（LINEのニックネーム）は別物なので混ぜないこと。',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'user_id'     => ['type' => 'string', 'description' => 'LINEのuserId'],
            'company'     => ['type' => 'string', 'description' => '会社名・団体名'],
            'person_name' => ['type' => 'string', 'description' => '本名（LINEの表示名とは別）'],
            'email'       => ['type' => 'string'],
            'phone'       => ['type' => 'string'],
            'url'         => ['type' => 'string'],
            'address'     => ['type' => 'string'],
            'source'      => ['type' => 'string', 'description' => 'きっかけ（LINE・紹介・展示会など）'],
        ], 'required' => ['user_id']],
    ],
    [
        'name' => 'klcrm_handled',
        'description' => '対応済みにする（handled=false で未対応に戻す）。新しいメッセージが届けば自動で未対応に戻るので、ここで戻す必要はふつうない。',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'user_id' => ['type' => 'string', 'description' => 'LINEのuserId'],
            'handled' => ['type' => 'boolean', 'description' => '既定 true。false で未対応に戻す'],
        ], 'required' => ['user_id']],
    ],
];

$PUSH_TOOLS = [
    [
        'name' => 'klcrm_send',
        'description' => 'お客様のLINEへメッセージを送る。【取り消せない】うえに、Push APIなので通数課金の枠を1通消費する。送る前に必ず本文を利用者に見せて確認を取ること。無料枠を使い切っている場合は送らずに断る。',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'user_id' => ['type' => 'string', 'description' => 'LINEのuserId'],
            'text'    => ['type' => 'string', 'description' => '送る本文'],
        ], 'required' => ['user_id', 'text']],
    ],
];

$TOOLS = $READ_TOOLS;
if (!$READONLY)  { $TOOLS = array_merge($TOOLS, $WRITE_TOOLS); }
if ($ALLOW_PUSH) { $TOOLS = array_merge($TOOLS, $PUSH_TOOLS); }

/* ---- JSON-RPC over stdio ------------------------------------------------- */

function kmcp_out($m) { echo json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"; flush(); }
function kmcp_result($id, $r) { kmcp_out(['jsonrpc' => '2.0', 'id' => $id, 'result' => $r]); }

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') { continue; }
    $req = json_decode($line, true);
    if (!is_array($req)) { continue; }
    $id = $req['id'] ?? null;
    $method = (string)($req['method'] ?? '');
    $params = isset($req['params']) && is_array($req['params']) ? $req['params'] : [];
    if ($id === null && strpos($method, 'notifications/') === 0) { continue; }

    switch ($method) {
        case 'initialize':
            $modes = [];
            if ($GLOBALS['READONLY'])   { $modes[] = '参照専用（書き込みツールは無効）'; }
            if (!$GLOBALS['ALLOW_PUSH']) { $modes[] = 'LINE送信は無効（KLCRM_MCP_ALLOW_PUSH=1 で解禁）'; }
            kmcp_result($id, [
                'protocolVersion' => isset($params['protocolVersion']) ? (string)$params['protocolVersion'] : '2024-11-05',
                'capabilities'    => ['tools' => new stdClass()],
                'serverInfo'      => ['name' => 'klcrm', 'version' => KLCRM_MCP_VERSION],
                'instructions'    => 'LINE公式アカウントに届いた相談の台帳です。'
                    . '相手の呼び名は display_name（LINEのニックネーム）ではなく、会社名・本名がわかっていればそちらを使ってください。'
                    . '未対応かどうかは毎回計算されており、新しいメッセージが届けば自動で未対応に戻ります。'
                    . ($modes ? ' 現在の制限: ' . implode(' / ', $modes) : ''),
            ]);
            break;
        case 'ping':
            kmcp_result($id, new stdClass());
            break;
        case 'tools/list':
            kmcp_result($id, ['tools' => $GLOBALS['TOOLS']]);
            break;
        case 'tools/call':
            $name = (string)($params['name'] ?? '');
            $a = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
            $allowed = array_column($GLOBALS['TOOLS'], 'name');
            try {
                if (!in_array($name, $allowed, true)) {
                    [$ok, $text] = kmcp_err('このツールは使えません: ' . $name
                        . '（設定で無効にされているか、存在しません）');
                } elseif ($name === 'klcrm_contacts')    { [$ok, $text] = kmcp_contacts($a); }
                elseif ($name === 'klcrm_get')           { [$ok, $text] = kmcp_get($a); }
                elseif ($name === 'klcrm_search')        { [$ok, $text] = kmcp_search($a); }
                elseif ($name === 'klcrm_status')        { [$ok, $text] = kmcp_status($a); }
                elseif ($name === 'klcrm_note_set')      { [$ok, $text] = kmcp_note_set($a); }
                elseif ($name === 'klcrm_contact_set')   { [$ok, $text] = kmcp_contact_set($a); }
                elseif ($name === 'klcrm_handled')       { [$ok, $text] = kmcp_handled($a); }
                elseif ($name === 'klcrm_send')          { [$ok, $text] = kmcp_send($a); }
                else { [$ok, $text] = kmcp_err('使えないツールです: ' . $name); }
            } catch (Throwable $e) {
                $ok = false; $text = json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
            kmcp_result($id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => !$ok]);
            break;
        default:
            if ($id !== null) {
                kmcp_out(['jsonrpc' => '2.0', 'id' => $id,
                    'error' => ['code' => -32601, 'message' => 'Method not found: ' . $method]]);
            }
    }
}
