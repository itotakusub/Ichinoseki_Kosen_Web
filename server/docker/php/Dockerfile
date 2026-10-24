# **ベースイメージを digest で固定する**(2026-09-15)。
#
# 以前は `php:8.4-apache`(動くタグ)で、`docker compose build --pull` のたびに誰も決めないまま
# PHP の版が上がりえた。今動いている web(PHP 8.4.25)と同じ中身を指す。
# **タグは系列(8.4)のまま残す** —— 使われるのは digest で、タグは scripts/check-updates.sh が
# 「8.4 の修正版が出たか」を読むため。知らせが来たら digest をここで書き換えて build する。
# 2026-10-12: PHP 8.4.26 のまま土台(Debian)だけ作り直された版(2026-10-06 作成)に上げた
FROM php:8.4-apache@sha256:c6527b06c772a2c3cae648ce58c23a2a12ed2fed41cdfb0619ea64be008cc601
# composer は実行時には使わない(ホスト側で vendor を入れるときの道具)。今の web を作った版に固定する
COPY --from=composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/local/bin/composer

# **使わない拡張を入れない**(2026-09-15。security-review-2026-09-14 §7 の 14)。
# 以前は pdo_pgsql と、そのための libpq-dev を入れていたが、PHP は Postgres を一度も使わない
# (src を grep して 0 件。Logto の DB は Logto だけが触る)。mysqli・pdo_mysql は追加のライブラリが要らないので
# apt も要らない。
# status は管理画面のタスクマネージャーが /server-status?auto を読むため(2026-10-05)。見せる範囲は apache-km.conf
RUN docker-php-ext-install mysqli pdo_mysql \
    && a2enmod rewrite status

WORKDIR /var/www/html
