# **ベースイメージを digest で固定する**(2026-09-15)。
#
# 以前は `php:8.4-apache`(動くタグ)で、`docker compose build --pull` のたびに誰も決めないまま
# PHP の版が上がりえた。今動いている web(PHP 8.4.25)と同じ中身を指す。
# **タグは系列(8.4)のまま残す** —— 使われるのは digest で、タグは scripts/check-updates.sh が
# 「8.4 の修正版が出たか」を読むため。知らせが来たら digest をここで書き換えて build する。
# 2026-10-12: PHP 8.4.26 のまま土台(Debian)だけ作り直された版(2026-10-06 作成)に上げた
FROM php:8.4-apache@sha256:be1326658a7d36dba6be79a71212d6ff9c5f854335b8ba77d714da1852c1b2ea
# composer は実行時には使わない(ホスト側で vendor を入れるときの道具)。今の web を作った版に固定する
COPY --from=composer:2@sha256:c7f68260503e3c1f0ece10bdbe84519fd98d48ba091f2c02f16ea94a7faae337 /usr/bin/composer /usr/local/bin/composer

# **使わない拡張を入れない**(2026-09-15。security-review-2026-09-14 §7 の 14)。
# 以前は pdo_pgsql と、そのための libpq-dev を入れていたが、PHP は Postgres を一度も使わない
# (src を grep して 0 件。Logto の DB は Logto だけが触る)。mysqli・pdo_mysql は追加のライブラリが要らないので
# apt も要らない。
# status は管理画面のタスクマネージャーが /server-status?auto を読むため(2026-10-05)。見せる範囲は apache-km.conf
RUN docker-php-ext-install mysqli pdo_mysql \
    && a2enmod rewrite status

# GD(PNG・JPEG・WebP)。2026-10-30、利用者の指示(振り返りのフェーズ 2「軽くする」)。
# ポスターの縮小版(lib/image-thumb.php)と、管理画面で差し替えた床の WebP の写し(lib/floor-webp.php)を作る。
# 作るときだけ要る -dev は最後に外し、gd.so が使う共有ライブラリ(libpng・libjpeg・libwebp)だけを残す
# (公式の php イメージの拡張と同じ手順。apt-mark で残すものを決めてから purge する)
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    ldd "$(php -r 'echo ini_get("extension_dir");')"/gd.so \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); print so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -rt apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -r 'exit(function_exists("imagewebp") && function_exists("imagecreatefromjpeg") ? 0 : 1);'

WORKDIR /var/www/html
