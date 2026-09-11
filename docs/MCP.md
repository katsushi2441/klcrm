# AIエージェントから操作する（MCP）

Kurage LINE CRM には **MCPサーバー**（`public/klcrm_mcp.php`）が同梱されています。
これを登録すると、**Claude Code や Claude Desktop から、この台帳を直接読み書き**できます。

```
あなた「田中工務店の相談、これまでの経緯を3行でまとめて」
  → AIが klcrm_get でやり取りとノートを読んで答える

あなた「いま未対応は何件？誰？」
  → AIが klcrm_contacts(only_open) で返す

あなた「いまの打ち合わせ内容を田中工務店のノートに足しておいて」
  → AIが klcrm_note_set で日時つきで追記する
```

画面を開かずに、ふだん使っているAIの対話のなかで台帳が動きます。

---

## 登録のしかた

**このMCPサーバーは、CRM本体と同じサーバー上で PHP を実行します。**
レンタルサーバーに置いている場合は、そのサーバーへSSHで入れることが前提です
（SSHが使えない共有プランでは、手元のPCに同じ一式を置いて使う形になります）。

### Claude Code

```bash
claude mcp add klcrm -- php /path/to/klcrm_mcp.php
claude mcp list          # klcrm: ... ✔ Connected と出れば成功
```

### Claude Desktop

`claude_desktop_config.json` に追記します。

```json
{
  "mcpServers": {
    "klcrm": {
      "command": "php",
      "args": ["/path/to/klcrm_mcp.php"]
    }
  }
}
```

---

## 使えるツール

### 読む（いつでも使えます）

| ツール | できること |
| --- | --- |
| `klcrm_contacts` | 相談相手の一覧。**未対応が先頭**。`only_open` で未対応だけ。会社名・本名・メール・電話でも探せる |
| `klcrm_get` | 相手1人の詳細。連絡先・**ノート**・直近のやり取りをまとめて返す |
| `klcrm_search` | やり取りの本文を横断検索。`user_id` を渡せばその人の中だけ |
| `klcrm_status` | 未対応の件数、友だち数、ブロック数、**今月のPush通数と残り** |

### 書く（`KLCRM_MCP_READONLY=1` で無効にできます）

| ツール | できること |
| --- | --- |
| `klcrm_note_set` | ノートを書く。既定は**日時つきで追記**、`mode="replace"` で全文差し替え |
| `klcrm_contact_set` | 連絡先を更新（会社名・本名・メール・電話・URL・住所・きっかけ） |
| `klcrm_handled` | 対応済みにする／未対応に戻す |

### 送る（**既定で無効**）

| ツール | できること |
| --- | --- |
| `klcrm_send` | お客様のLINEへメッセージを送る |

**`klcrm_send` は既定では出てきません。** `KLCRM_MCP_ALLOW_PUSH=1` を設定したときだけ現れます。

理由は2つです。**送信は取り消せない**こと、そして **Push APIなので通数課金の枠を1通消費する**ことです。
有効にした場合も、無料枠を使い切っていれば送らずに断ります。

---

## 安全のための決めごと

- **新しい入り口を作らない。** MCPは製品本体の関数をそのまま呼ぶ薄い橋です。
  「未対応かどうか」も「今月の通数」も、画面とまったく同じ式で求めます（食い違いません）。
- **お客様へ送る操作だけは、明示的に有効にしない限り存在しない。**
  無効のときは `tools/list` にも出ないので、AIが選ぶことすらできません。
- **参照だけにしたいとき**は `KLCRM_MCP_READONLY=1`。書き込みツールが全部消えます
  （`KLCRM_MCP_ALLOW_PUSH=1` を同時に付けても、READONLYが勝ちます）。
- **Webからは開けません。** `php_sapi_name()` が `cli` でなければ 404 を返して終了します。

### 設定の例

```bash
# 参照専用で登録する（社内の誰かに見せるだけのとき）
KLCRM_MCP_READONLY=1 claude mcp add klcrm -- php /path/to/klcrm_mcp.php

# 送信まで許す（本文を必ず確認してから送る運用が前提）
KLCRM_MCP_ALLOW_PUSH=1 claude mcp add klcrm -- php /path/to/klcrm_mcp.php
```

---

## 覚えておくこと

- 相手の**呼び名**は、LINEの表示名（`display_name`）ではなく**会社名・本名**を優先して返します。
  LINEの表示名は「たろう」「🌸」のことがあり、本名とは限らないためです。
- **未対応は状態ではなく計算**です。対応済みにした相手でも、新しいメッセージが届けば自動で未対応に戻ります。
- `user_id` はそのアカウント限定の識別子で、ブロック→再追加しても変わりません。
