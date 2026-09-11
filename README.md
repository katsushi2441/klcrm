# Kurage CRM (kcrm)

LINE公式アカウントに届いた相談を、送信者(userId)ごとに記録して、履歴を見ながら返す窓口。

- 本番: https://exbridge.jp/crm/
- Webhook: https://exbridge.jp/crm/webhook.php
- 対象アカウント: エクスブリッジ @271hokhu（チャネルID 2011558795）

## 設置

1. `public/kcrm_config.php.example` を `kcrm_config.php` にコピーして、
   パスワードハッシュ・チャネルシークレット・アクセストークンを入れる
2. `set -a; . /home/kojima/work/aixec/.env; set +a` のあと `bash scripts/deploy.sh --with-config`
3. LINE Developers の「Webhook URL」に webhook.php のURLを入れ、**「Webhookの利用」をオンにする**

## heteml で必ず要るもの（迷いやすい）

- **`.htaccess` に `AddHandler php-script .php`**。これが無いと既定の **PHP 5.6** で動き、
  `??` や `declare(strict_types)` が構文エラーになる。書式は
  `php-script`(=PHP8系) / `php7.4-script` / `php5.6-script`。
- SQLite と `kcrm_config.php` は `.htaccess` で遮断する（403になることを毎回実測する）。
  加えてDBのファイル名はチャネルシークレットから導出して推測不能にしてある。

## お金の話（設計の根拠）

- **Reply API（届いた発言への返信）は通数課金の対象外。** Webhook内の自動応答は無料。
- **Push API（画面からの返信）は通数課金。** コミュニケーションプランは月200通まで。
  画面ヘッダに今月のPush数を出し、使い切り事故を防いでいる。

## 分かっていること

- `userId` はこのチャネル限定の識別子。ブロック→再追加でも同じなので顧客キーに使える。
- 氏名・メール・電話は取れない。`displayName` はLINEのニックネームで本名とは限らない。
- プロフィールは友だちでなくなると取得できない（取れたときだけ更新する実装）。
