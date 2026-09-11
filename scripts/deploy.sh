#!/usr/bin/env bash
# Kurage CRM を exbridge.jp/crm/ へ配置する。
#   set -a; . /home/kojima/work/aixec/.env; set +a
#   bash scripts/deploy.sh                 # コードだけ（本番の設定は触らない）
#   bash scripts/deploy.sh --with-config   # 初回のみ。klcrm_config.php も送る
#
# klcrm_config.php はトークンを含む「設定」なので、既定では送らない。
# 毎回送ると、本番で直した設定をローカルの内容で上書きしてしまう。
set -uo pipefail
cd "$(dirname "$0")/.."
: "${FTP_HOST:?FTP_HOST が未設定です}" "${FTP_USER:?}" "${FTP_PASS:?}"

remote="/web/exbridge_jp/crm"
files=(index.php webhook.php klcrm_lib.php .htaccess klcrm_config.php.example)
if [ "${1:-}" = "--with-config" ]; then files+=(klcrm_config.php); fi

ok=0; ng=0
put() {  # $1=ローカルパス $2=リモート相対パス
  if curl -sS --fail --ftp-create-dirs -T "$1" \
       "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}" --max-time 60 -o /dev/null; then
    echo "  up: $2"; ok=$((ok+1))
  else
    echo "  失敗: $2"; ng=$((ng+1))
  fi
}

for f in "${files[@]}"; do
  [ -f "public/$f" ] || continue
  put "public/$f" "$f"
done
put "public/klcrm_data/.htaccess" "klcrm_data/.htaccess"
# 画像（Kurageキャラ・エクスブリッジのロゴ）。外部URLを参照せず同梱するので、
# 買い手の環境でも当社のサーバーに依存せず表示できる
for a in public/assets/*; do put "$a" "assets/$(basename "$a")"; done

echo "成功${ok} 失敗${ng} → https://exbridge.jp/crm/"
exit $(( ng > 0 ))
