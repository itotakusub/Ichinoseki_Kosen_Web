# **ベースイメージを digest で固定する**(2026-09-15)。
#
# 以前は `php:8.4-apache`(動くタグ)で、`docker compose build --pull` のたびに誰も決めないまま
# PHP の版が上がりえた。今動いている web(PHP 8.4.25)と同じ中身を指す。
# **タグは系列(8.4)のまま残す** —— 使われるのは digest で、タグは scripts/check-updates.sh が
# 「8.4 の修正版が出たか」を読むため。知らせが来たら digest をここで書き換えて build する。
FROM php:8.4-apache@sha256:75325cceea4f9a8200f4e9e146e8155ec1b6466b2957b87accf0286b94547cbd
# composer は実行時には使わない(ホスト側で vendor を入れるときの道具)。今の web を作った版に固定する
COPY --from=composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/local/bin/composer

# **使わない拡張を入れない**(2026-09-15。security-review-2026-09-14 §7 の 14)。
# 以前は pdo_pgsql と、そのための libpq-dev を入れていたが、PHP は Postgres を一度も使わない
# (src を grep して 0 件。Logto の DB は Logto だけが触る)。mysqli・pdo_mysql は追加のライブラリが要らないので
# apt も要らない。
# status は管理画面のタスクマネージャーが /server-status?auto を読むため(2026-10-05)。見せる範囲は apache-km.conf
RUN docker-php-ext-install mysqli pdo_mysql \
    && a2enmod rewrite status

WORKDIR /var/www/html
