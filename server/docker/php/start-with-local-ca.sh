#!/bin/sh
set -eu

SYSTEM_CA="/etc/ssl/certs/ca-certificates.crt"
LOCAL_CA="/etc/ssl/localca/rootCA.pem"
COMBINED_CA="/tmp/combined-ca.pem"

if [ ! -s "$SYSTEM_CA" ]; then
  echo "ERROR: System CA bundle not found: $SYSTEM_CA" >&2
  exit 1
fi

# ローカル CA は **無くてもよい**。
#
# mkcert の自己署名で動かしている校内 LAN では、PHP から Logto(https://…:3001)へ
# 出ていくときにこの CA が要る。一方 VPS + Let's Encrypt では Logto も公的な証明書になり、
# システムの CA だけで足りる — そちらでは mkcert の CA 自体が存在しない。
#
# ここで exit 1 していると **Let's Encrypt 構成では起動すらできない**ので、
# 無ければシステムの CA だけで進む(TLS 検証を緩めるわけではない)。
if [ -s "$LOCAL_CA" ]; then
  cat "$SYSTEM_CA" "$LOCAL_CA" > "$COMBINED_CA"
else
  echo "NOTE: local CA not found ($LOCAL_CA). Using the system CA bundle only." >&2
  cat "$SYSTEM_CA" > "$COMBINED_CA"
fi
chmod 0644 "$COMBINED_CA"

exec apache2-foreground
