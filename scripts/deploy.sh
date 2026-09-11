#!/usr/bin/env bash
# Kurage CRM を exbridge.jp/crm/ へ配置する。
#   set -a; . /home/kojima/work/aixec/.env; set +a
#   bash scripts/deploy.sh                 # コードだけ（本番の設定は触らない）
#   bash scripts/deploy.sh --with-config   # 初回のみ。kcrm_config.php も送る
#
# kcrm_config.php はトークンを含む「設定」なので、既定では送らない。
# 毎回送ると、本番で直した設定をローカルの内容で上書きしてしまう。
set -uo pipefail
cd "$(dirname "$0")/.."
: "${FTP_HOST:?FTP_HOST が未設定です}" "${FTP_USER:?}" "${FTP_PASS:?}"

remote="/web/exbridge_jp/crm"
files=(index.php webhook.php kcrm_lib.php .htaccess kcrm_config.php.example)
if [ "${1:-}" = "--with-config" ]; then files+=(kcrm_config.php); fi

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
put "public/kcrm_data/.htaccess" "kcrm_data/.htaccess"

echo "成功${ok} 失敗${ng} → https://exbridge.jp/crm/"
exit $(( ng > 0 ))
