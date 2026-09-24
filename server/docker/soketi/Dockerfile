# Soketi を **Node 24(LTS)** で動かす(2026-09-15。security-review-2026-09-14 §7 の 4)。
#
# ## なぜこの形なのか
#
# 上流の soketi 1.6.1 は uWebSockets.js 20.10 に縛られていて、**Node 14・16・18 でしか起動しない**。
# どれも EOL(18 も 2025-04 に終わった)なので、上流のまま版を上げても解決しない。
#
# aloware/soketi は 1.6.1 を Node 22/24 + uWebSockets.js 20.54 に載せ替えたフォーク
# (https://github.com/aloware/soketi 。**通信・HTTP API・環境変数は 1.6.1 と同じ**)。
# ところが**配っているイメージは起動しない**(2026-09-15 に本番のホストで実測):
# 同梱の uWebSockets.js が GLIBC 2.38 を要るのに、イメージの土台が Debian 12(GLIBC 2.36)。
# latest・2.0.0・distroless のどれも同じ。
#
# そこで**フォークのイメージから /app だけを取り出し、Debian 13(GLIBC 2.41)の node:24 に載せる。**
# npm でのビルドはしない(ホストのメモリを食わない。中身はフォークが配ったものそのまま)。
#
# 使い捨ての環境で確かめたこと(今の Soketi と同じ結果): 接続・公開と非公開チャンネルの購読・
# 署名つき配信の受信・違う鍵と署名なしの配信を断る・read_only + cap_drop ALL + node 利用者で起動。
# メモリは 70〜106MiB(1.6.1 は 62MiB)。
#
# ## 上げるとき
#
# 2 つの digest を scripts/check-updates.sh の「ビルド元」の知らせを見て書き換え、
# 検証機で `docker compose build soketi` → 管理画面のチャットと死活監視が「接続中」になるまで見る。
# フォークが直った土台のイメージを出したら、この載せ替えをやめて image: で使ってよい。

# aloware/soketi:latest(2026-09-15 時点。2.0.0 の後に作り直された版)
FROM ghcr.io/aloware/soketi:latest@sha256:effb4ce14dc53809d12020ca813c68a8782854f4d842b143bc7d8b42fe626b54 AS fork

# node:24-trixie-slim(Node 24.21 / Debian 13)
FROM node:24-trixie-slim@sha256:6950b66b4c0cb0151ce89fa75074673850763d096b044f422c6729b588dd4956

COPY --from=fork /app /app
WORKDIR /app

# soketi は起動時に pm2 の置き場を $HOME/.pm2 に作ろうとする。read_only の下では書けないので tmpfs の /tmp に向ける
ENV PM2_HOME=/tmp/.pm2

# **root で動かさない**(2026-09-14)。node イメージには uid 1000 の node 利用者が最初からいる。
# /app は root の持ち物のまま読めれば足りる。待ち受けは 6001 / 9601 で 1024 未満ではないので特権も要らない
USER node

EXPOSE 6001 9601

CMD ["node", "/app/bin/server.js", "start"]
