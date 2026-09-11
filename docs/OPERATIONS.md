# 当社の運用メモ（配布キットには入れない）

- 本番: https://exbridge.jp/crm/
- Webhook: https://exbridge.jp/crm/webhook.php
- 対象アカウント: エクスブリッジ @271hokhu（チャネルID 2011558795 / プロバイダー 2005529884）
- デモ: https://proto.exbridge.jp/klcrm/ （LINEトークンを入れない設定。送信は無効）

## デプロイ

```
set -a; . /home/kojima/work/aixec/.env; set +a
bash scripts/deploy.sh                 # コードだけ（本番の設定は触らない）
bash scripts/deploy.sh --with-config   # 初回のみ
```

`klcrm_config.php` は git で追跡しない（トークンを含むため）。本番の設定は本番にだけ置く。

## heteml で必ず要るもの（迷いやすい）

- **`.htaccess` に `AddHandler php-script .php`**。これが無いと既定の **PHP 5.6** で動き、
  `??` や `declare(strict_types)` が構文エラーになる。書式は
  `php-script`(=PHP8系) / `php7.4-script` / `php5.6-script`。
  `x-httpd-phpX.Y` は無効で、PHPのソースがそのまま配信されるので使わない。
- SQLite と `klcrm_config.php` は `.htaccess` で遮断する（403になることを毎回実測する）。
- `klcrm_data/` は書き込み権限 777 が要る（FTPの SITE CHMOD 777）。
  権限が無いと `unable to open database file` で 500 になる。

## データ構造で気をつけていること

- **`display_name`（LINEの表示名）と `person_name`（本名）を必ず分ける。**
  LINEの表示名は「たろう」「🌸」でもよく、本名とは限らない。同じ列に混ぜると名寄せできなくなる。
- **ノートは1人1枚**（`contacts.note`）。細切れに分けるより、1枚に書き足すほうが実務に合う。
  以前 `notes` テーブルへ分けたが、初回起動時に古い順で連結して `note` へ戻す移行を入れてある。
- 列の追加は `PRAGMA table_info` を見てから `ALTER TABLE`。既存DBを壊さずに育てられる。
- **「未対応」は列に持たない。** `handled_msg_id`（どこまで対応したか）と、届いている最後の
  受信メッセージのidを比べて毎回計算する（`KLCRM_OPEN_SQL`）。
  フラグで持つと、新しいメッセージが来ても未対応に戻らない。時刻の比較も同じ秒で取りこぼす。
- DBのファイル名はチャネルシークレットから導出する（推測してURLで開けないようにするため）。
  **改名すると過去のデータを見失う**ので、導出の式は変えない。
