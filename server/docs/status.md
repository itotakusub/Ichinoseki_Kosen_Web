# 現況

## いま(2026-09-18 に本番で実測)

**§1 以降は 2026-09-02 の記録**(ドメインは旧 `ito8795.com` のまま書いてある)。**いまの値はこの節が正**。

| 項目 | 実測(2026-09-18) |
|---|---|
| 公開名 | `https://ito4.jp` / 管理画面は別オリジン `https://admin.ito4.jp`(2026-09-17 に統一。旧 `ito8795.com` は DNS も証明書も撤去。[14](14-domain-ito4.ipynb)) |
| audience(API リソース) | `https://ito4.jp/api` |
| サービス | 9 個すべて healthy(`certbot logto mailserver mariadb phpmyadmin postgres reverse-proxy soketi web`。**Mailpit は本番では使わない**) |
| 証明書 | `ito4.jp`(SAN に `admin.ito4.jp`・`mail.ito4.jp`)—— **2026-12-16 まで**。CAA は `0 issue "letsencrypt.org"` |
| メモリ / ディスク | 1.9Gi 中 1.1Gi 使用(available 806Mi)/ 99G 中 17G(18%) |
| PHP / Soketi | PHP 8.4.25 / Node v24.21.0(web から `pdo_pgsql` は外した) |
| 地点 | `km_map_nodes` 1037 行 |
| ホストに入る利用者 | **`kmops`**(配備・`%%host`・控え。docker あり・sudo なし・鍵 `km_ops`)と **`km`**(sudo のみ・docker なし・鍵 `km_vps`)。クラウド既定の `ubuntu` は退役([12](12-hardening-2026-09-15.ipynb) §7-4 B) |
| 週次の控え | この PC のタスクが `kmops` へ門番付きの鍵(`km_backup`)で入って取る(同 §7-4 A) |
| DB | `Main`・`Ito` とも `Kosen_map` だけ・TLS 必須・`172.30.2.%` から。`root@%` は無し。Logto は `logto_app`(SUPERUSER でない) |
| 認証 | Logto 1.43。MFA は admin・default とも **Mandatory**、パスワード方針は 12 文字以上・流出パスワード拒否 |

### 地図まわりの追加(2026-09-22、利用者の要望)

| 何 | どうした | どこ |
|---|---|---|
| エレベーター優先・階段優先 | 好みでない方の乗り換えを **4 倍に重くするだけ**(外さない ——片方しか無い建物で経路が消えるため)。エレベーターは**種類が階段と同じ `stairs`** なので名前で見分ける(接続 ID「エレベーター 4号棟」・`type2` が「エレベーター」。本番に 9 地点) | Website: `Main/dijkstra.js` と検索パネルの「階を移るとき」/ アプリ: `RouteSearch.kt` と設定「階を移るとき」 |
| 屋外の施設名が重なる | 大事な種類から順に置き、**重なったら上下にずらし、それでも駄目なら文字だけ出さない**(点は残るので押せば読める)。座標は触らない | Website: `Main/app.js` の `declutterLabels` / アプリ: `GeneralMapNodeRenderer.kt` の `layoutLabels` |
| 施設 → 中の階 | 屋外の建物を押すと「中へ 1F 2F …」が出て、その階の入口へ移る。**建物の階は接続 ID から求める**(「4号棟 階段」など)。建物ごとの平面図は要らない | Website: `Main/app.js` の `facilityFloorLinks` / アプリ: `BuildingFloors.kt` |

**中が地図に載っていない建物**(萩友会館(食堂・保健室)・第一/第二体育館・メディアセンター・機械実習工場・
地域共同テクノセンター・idemitsu ミライチ)では「中へ」は出ない —— 屋外側の出入口しか登録が無いため
(2026-09-22 に本番で実測)。中の地点を作れば自動的に出る。

**人数の欄(アプリ)は動いているが、「組織内」が常に 0**(2026-09-22 実測: total 2 / inside 0 / outside 2 /
organizations 1)。組織(`Kosen_Member`)に誰も居らず、`config/app-map.local.php` の
`organizationEmailDomains` も**空**のため。学校のドメインを書けば組織内として数えられる。

### 建物の平面図は「1 枚 = 1 つの階」へ(2026-09-24、利用者の指示)

これまで建物の平面図(11 枚)は**外の全体図に重ねて**いた(`km_map_overlays` と アプリの `AdMapOverlay`)。
重ねる方式では中に地点を置けず、位置合わせもずれる。**1 枚を 1 つの階として扱う**ように変えた。

| 何 | どうした |
|---|---|
| 階の定義 | `lib/building-floors.php`(Website)と `BuildingFloorCatalog.kt`(アプリ)が対。**片方だけ足すと開けない階ができる**ので、`check.php` の `building-floors` が数を突き合わせる |
| 階の id | `bldg_library_1f` など(`km_map_floors.id` は **varchar(16)**。テクノセンターと機械実習工場は短くしてある) |
| 座標系 | 画像と同じ **600×600**。取り込み(`app-map-sync`)が一律 1600×1200 を入れないよう除外した。Y の反転も階ごとの高さで行う |
| 屋外の重ね合わせ | **やめた**(同じ建物が 2 か所に出るため)。配置データ(`km_map_overlays`)は消していないので、`KM_DRAW_BUILDING_OVERLAYS` / `DRAW_BUILDING_OVERLAYS` を true に戻せば復活する |
| 入り方 | 屋外の建物を押す → 「中へ 図書館 1F …」。**中に地点が 1 つも無くても開ける**(これから作るため) |
| 経路 | 建物の階の出入口は、**同じ接続 ID** の屋外側の出入口と繋ぐ(別の建物へは出ない) |
| 階のレール | 公開ページもアプリも**キャンパスの階(外・1F〜5F)だけ**。建物の階は「中へ」から入り、アプリでは上の帯で同じ建物の階と「外へ戻る」を出す |

**本番でやること:** 配備のあと `scripts/seed-building-floors.php` を 1 回流す(`km_map_floors` に 11 行と座標系を入れる。
`ALTER` は使わないのでアプリの DB 利用者のまま通る)。中の地点はまだ 0 件で、管理画面の地図編集から作る。

### 公開ページの操作と SSH ログインの知らせ(2026-09-25、利用者の指示)

| 何 | どうした |
|---|---|
| 錠が掛かった地図 | 初期化が途中で止まり、検索・階・表示調整のボタンが**押しても効かない**まま残っていた(スマホでは検索シートが解除欄に被さった)。描けないときは `body.km-map-unavailable` で下げる |
| ルート案内 | 下から出るシート(guidance-panel)を**廃止**。左下の操作ピルの上に 1 行の帯「✕ / ◯◯に向かう / 位置の更新」。目指す所は階が変わる所と目的地だけで、「位置の更新」で次へ進む(Web は現在地を測れないため) |
| ダウンロード・設定 | **右から滑り出す引き出し**(PC・スマホ共通)。幕を押すか Esc で閉じる。閉じている間は `inert` |
| カテゴリー検索 | 結果は検索パネルの中に並べる |
| はじめに | 初回だけ出す(規約・プライバシー・**迷惑メールフォルダ**の注意)。版は `index.php` の `$kmWelcomeVersion`。設定から読み直せる |
| 拡大時の建物名(屋外) | Website と アプリで「薄く(既定)/ 消す / そのまま」。薄い名前は押せない。屋内は従来どおり |
| ラベルの重なりほどき | **屋外だけ**に絞った(Website・アプリとも) |
| SSH ログインの知らせ | PAM(`optional`)から `MAIL_ADMIN_TO` へ。同じ利用者・同じ接続元は 10 分に 1 通。本文に切る・BAN するコマンドを載せる |
| 切る・BAN | `sudo kosenmap-ssh-kick --list / --ip X [--ban] / --unban X / --user U / --lock-user U / --unlock-user U` |
| 信頼する接続元 | `sudo kosenmap-ssh-kick --trust X / --untrust X / --list-trusted`。一覧は `/etc/kosenmap/ssh-trusted-ips`(root だけが書ける)。信頼済みは件名が `[KosenMap] **信頼済み**SSH ログイン: 通知`、**それ以外は「重要」付き**(X-Priority・Importance。Gmail は見ない) |

### 経路の条件と重み(2026-09-25、利用者の指示)

| 条件 | 扱い | 中身 |
|---|---|---|
| A 屋内優先 | **既定** | 出入り 1 回 3m → 15m、屋外の道 1.3 倍。中に道があれば中を通る |
| B 部屋を通り抜けない | **既定** | 部屋は出発地・目的地のときだけ。それで道が無くなれば通り抜けを許す(経路を消さない) |
| C 階の移動を減らす | 利用者の設定 | 1 層ぶんの重み × 2。Website は検索パネル、アプリは設定の「マップ設定」 |
| D 雨の日 | 利用者の設定 | 屋外の道をさらに × 3 |

重みの表は **Website `Main/dijkstra.js` の `KM_ROUTE_WEIGHTS` と アプリ `RouteSearch.kt` の `RouteWeights` の2か所**で、
`check.php` の `route-weights` が値を突き合わせる。**管理アプリでは設定「経路の重み(管理)」で値を変えて試せる**
(管理ビルドの管理者だけに出る。一般ビルドは手元の値を読まない)。

**将来の予定(利用者の指示):** 管理アプリで決めた重みを、**一般の既定として配る。** 受け口は用意済み ——
Website は `graphData.routeWeights`(`lib/map-data.php` に載せれば効く)、アプリは `RouteWeights` をそのまま
配信パッケージから読む形にする。まだ配る道(管理アプリ → サーバー → 配信)は作っていない。

### 管理マップの建物の階(2026-09-25)

管理マップにも「平面図 1 枚 = 1 つの階」を効かせた。階ボタンの下の「建物の階 ▾」から開いて、中に地点を置ける。
**重ね合わせの名残は外した** —— 道具の「外画像配置」、移動・削除で外画像を掴む動き、表示調整の「建物平面図を重ねる」、
外を拡大したときに下地だけ薄くなる動き(一般マップでも、平面図が出ないのに全体図が霞んでいた)。
配置のデータ(`km_map_overlays` / `AdMapOverlay`)は消していないので、`DRAW_BUILDING_OVERLAYS` を戻せば元どおり。

**本番でやること(SSH):** 配備のあと `sudo sh /opt/kosenmap/scripts/ssh-login-notify-setup.sh --fix`
(`/usr/local/sbin` へ root:root 755 で写し、`/etc/pam.d/sshd` に 1 行足す)→ **別の SSH を開いたまま**
`sudo /usr/local/sbin/kosenmap-ssh-login-notify --test`。PAM と sudo が走らせるのは写しだけで、
配備の利用者が書ける `/opt/kosenmap/scripts` は直接走らせない。

**Ubuntu Pro(2026-09-25 に実測):** Ubuntu 24.04.4 LTS・未加入。main の更新は 2029 年まで続くので**必須ではない**。
universe から入っているのは 4 本(fail2ban を含む)で、これらのセキュリティ更新は `esm-apps` が無いと遅れることがある。
自動再起動を切っているので Livepatch にも意味はある。入れるなら個人の無料枠(5 台まで)。

**残っている作業は [12-hardening-2026-09-15](12-hardening-2026-09-15.ipynb) §0 の表が正本**(2026-09-18 時点で #2 Android の配布と #15 旧ドメインの解約だけ)。

---

## 2026-09-02 時点の記録(以下)

**この文書は「いまどうなっているか」の記録**。手順は取扱説明書(`docs/*.ipynb`)と、以前の手順書(`Old/docs/`)に
あり、ここでは**状態と残作業**だけを書く。

> **§1〜§5 の数字は 2026-08-28 にホストで実測した値**(それ以降は測り直していない)。
> §6 の残作業だけを 2026-09-02 に見直した。**残りの全体像は `plan.md` の
> §残っていること** —— 会期後でよいもの(第4段)も含めてそちらに集めてある。
> **ドメインと利用者は上の「いま」を見ること**(この節の `ito8795.com`・`km` は当時の値)。

---

## 一行で

**本番 `https://ito8795.com` は10サービスすべて healthy で稼働中。**
メールは送信サーバーまで組み上がり、**逆引き(PTR)だけが未解決**。
管理画面はこの日に全面停止したが、**復旧済み**。

---

## 1. 本番(163.43.218.158 / `/opt/kosenmap`)

| 項目 | 実測 |
|---|---|
| サービス | 10個すべて **healthy** |
| 証明書 | `ito8795.com` —— **2026-11-26 まで**有効 |
| メモリ | 1.9Gi 中 1.1Gi 使用 / **available 831Mi** |
| ディスク | 99G 中 12G(13%) |

```
certbot  logto  mailpit  mailserver  mariadb
phpmyadmin  postgres  reverse-proxy  soketi  web
```

### 公開しているポート

`reverse-proxy` だけが外に出ている。**80 / 443 / 3001 / 3002 / 6001 / 8025 / 8281。**

- `3001`(Logto)と `6001`(Soketi)は**利用者のブラウザが直接つなぐので必須**
- `3002` / `8025` / `8281`(Logto Console / Mailpit / phpMyAdmin)は
  **nginx 側の IP 制限 + Logto のログイン判定**の後ろ。判定は `proxy_pass` の手前
- **`3306` は公開していない。** `mailserver` の `587` も公開していない

---

## 2. 認証(Logto)

| 種別 | 名前 | 備考 |
|---|---|---|
| アプリ | `Kosen_map` (M2M) | サーバーが Management API を叩くのに使う |
| アプリ | `KosenAPP` (Native) | **Android アプリ**。利用者がサインインする |
| アプリ | `Test` (Traditional) | |
| リソース | `Logto Management API` | スコープは **`all` の1つだけ** |
| リソース | `KosenMap PHP API` = `https://ito8795.com/api` | `admin:users:read/write`, `admin:api-keys:read/write` |
| ロール | `kosenmap-admin` | 上の4スコープ |
| ロール | `kosenmap-user` | **スコープ無し**(= 管理権限が無い、が正しい状態) |

サーバーが受け付けるトークンの条件(`logto_guard.php`):

```
iss = https://ito8795.com:3001/oidc
aud = https://ito8795.com/api      ← 1つだけ。ここが一致しないと 401
```

### ~~`staff:event:access` が存在しない~~ → 追加済み(2026-09-02)

コードには運営スタッフ区分がある(`LOGTO_STAFF_PERMISSION`)。イベントの通行止めを
通過でき、スタッフ限定の地点を見られる。**スコープは `https://ito8795.com/api` へ
登録済み。**`read:organization` / `read:user` も同じリソースに入った。

> **`read:organization` と `read:user` は Management API には効かない。**
> それらは独自リソースのスコープで、**Management API は別のリソース**(下記)。
> ここを取り違えて 2026-08-28 に管理画面を全面停止させた。
> **「読み取り専用の m2m ができた」と読まないこと。**

### 「読み取り専用の m2m」は Logto では作れない

Management API リソースのスコープは **`all` の1つだけ**で、読み取りだけを切り出せない。
独自リソースに `read:user` を置いても、**それは別のリソースなので Management API では 403**。

そのため `LOGTO_M2M_READONLY_*` は**空**で運用している(空なら `default` に落ちる)。
`kosenmap-readonly` のアプリ・ロール・リソースは**削除済み**。

---

## 3. この日に起きた障害(記録)

**管理画面が全面停止した**(`503 認証の設定が未完了のため、安全のため停止しました`)。

1. `.env` の打ち間違い —— `LOGTO_M2M_READONLY_APP_ID=="luw0…"` と **`=` が1つ余分**。
   値が `"` で始まらないため Compose が引用符を外さず、Logto に `="luw0…"` が
   送られて `oidc.invalid_client`
2. `=` を直すと今度は **403** —— スコープが独自リソースに付いており Management API では無効

**なぜ管理画面ごと落ちたか**: 停止判定 `km_logto_user_is_suspended()` が readonly を
使うため。それまで readonly は公開の人数集計だけが使っており、壊れていても影響しなかった。
**Logto Console も同じゲートの内側なので、直しに行けなくなる**形だった。

**対処**: readonly を空にして `default` に落とした。バックアップは
`/opt/kosenmap/.env.bak-20260828-*`。

**根本原因はこちらの文書の誤り** —— `.env.example` に「Management API のロールで
read:user と read:organization だけを与えること」と書いてあったが、それは不可能だった。
`.env.example` と `lib/logto-management.php` は修正済み。

> **~~残る弱点~~ → 対応済み(2026-08-29)**: 停止判定が `readonly` で失敗したら
> `default` で1回だけ試し直し、`error_log` と**管理画面上部の警告帯**に出すようにした
> (`lib/logto-management.php` / `admin/_inc/partials/page-header.php`)。
> `default` でも駄目なら従来どおり閉じる。
>
> **ただし本番で確かめていない。** `check.php` が見ているのは「落とし込みのコードが在るか」
> だけ。**readonly をわざと壊した状態で管理画面が開けることを、出す前にホストで確かめること。**

---

## 4. メール

**送信専用。受信はしない。** `compose.vps.yaml` の `mailserver`(`boky/postfix`)。

```
[web] [logto] --587(Docker 内部のみ)--> [mailserver] --25--> インターネット
```

- **`ports:` を書いていない。** 外から 587 に接続できない = 踏み台にされない
- `ALLOWED_SENDER_DOMAINS=ito8795.com` が二枚目の歯止め
- DKIM 署名あり。鍵は `mail_dkim` ボリューム(**要バックアップ**)

| 項目 | 結果 |
|---|---|
| 外向き 25番 → Gmail の MX | **到達できる**(`220 mx.google.com ESMTP …`) |
| 自ホストの 25 / 587 / 465 の待ち受け | **無し** |
| AUTH の提示 | **していない** |
| 送信キュー | 空。**まだ1通も送っていない** |
| SPF | `v=spf1 ip4:163.43.218.158 -all` OK |
| DMARC | `v=DMARC1; p=none;` OK |
| DKIM | **429文字・生成値と完全一致** OK(255字制限で2分割。正しい形) |
| **PTR(逆引き)** | **未設定(NXDOMAIN)** 未解決 |

アプリ側は `MAIL_HOST=mailserver` / `587` / `noreply@ito8795.com`。
Logto の SMTP コネクターも `mailserver:587` へ変更済み。
**`auth` の `user='dummy'` / `pass` は残しておく。** 相手が AUTH を提示しないので
「空の方が事故が少ない」と書いていたが、**逆だった** —— 検証環境では**ダミーを入れないと
配信できなくなる**(2026-09-02、利用者の実測)。**本番では未実施。**
本番で切り替えるときは、**先に Mailpit で1通通してから**にすること。

### 判定基準(先に決めてある)

次のどれかなら粘らず**送信サービスへ移る**。切り替えは `.env` の数行と Logto の設定だけ。

- 逆引きを設定できない / Gmail で迷惑メール扱い / SPF・DKIM・DMARC のどれかが pass しない
- mail-tester が 8 点未満

---

## 5. 検証環境(192.168.3.29 / `C:\dev\windows-dev-stack`)

校内 LAN。8サービス稼働、メールは **Mailpit のまま**(外へ配送しない)。
本番とは独立していることを確認済み。

**到達性(Gmail に届くか)はここでは測れない。** 送信元 IP も逆引きも本番と別物。

---

## 6. 残作業

| # | 内容 | 状態 |
|---|---|---|
| 1 | DKIM の TXT 登録 | **完了** |
| 2 | ~~逆引き(PTR)~~ | **不要と判明**(2026-08-29)。**PTR 未設定のまま Gmail に届き、迷惑メールにも入らなかった。** `mail-server.md` の判定基準「逆引きを設定できないなら送信サービスへ移る」は発動しない |
| 3 | Logto の後片付け・停止アカウント確認 | **完了** |
| 4 | 本番配備の許可 | **未解決**(下記) |
| 5 | 人数集計の作りの判断 | **決着・実装済み**(2026-08-29) |
| 6 | Android 再ビルド(`resource = https://ito8795.com/api`) | **コード変更は不要**(下記) |
| 7 | **配信中の地図** | **要やり直し。**手元は `revision 10` / 期限 `2026-09-29` に更新済みだが、**その実行は `-UploadToHost` の欠陥を直す前**なので本番へ届いたか不明。切れたことが見えるよう管理画面には出してある(下記) |
| 8 | Logto に `staff:event:access` が無い | **完了**(2026-09-02)。`read:organization` / `read:user` も追加された。**ただしその2つは Management API には効かない** —— 独自リソース `https://ito8795.com/api` のスコープであり、Management API は `all` 1つしか持たない別のリソース(§2) |
| 9 | 1.0.5 の変更一式の配備 | **配備済み**(2026-09-03)。`host-setup.ps1 -Fix` まで完走し、所有権・PHP からの読み書き・見取り図6枚・自己検査(380件)・`nginx -t` すべて OK |
| 10 | Logto の版 | **`1.42.0` に固定済み。**`docker compose ps` で確認(`ghcr.io/logto-io/logto:1.42.0`、healthy) |

### 配信中の地図について(#7)

**期限が切れること自体は異常ではない。** 会期が終わった地図を配り続けないための作りで、
過ぎると来場者アプリは地図とアクセスコードを消す。

問題は**切れたことがどこにも出ていなかった**こと —— 記録文書にだけ残り続けた。
管理画面「ダウンロード」に配信状況を出すようにした(期限切れ / まもなく期限 /
期限を読めない、のいずれかで警告し、更新コマンドも並べる)。

更新は手元から:

```powershell
cd C:\Users\itota\Documents\Website\server
.\scripts\new-map-release.ps1 -ExpiresAt "+30d" -KeepCode -UploadToHost
```

**地図の実体と `config/app-map.local.php` は配備対象外**(ホスト側が正本)なので、
`-UploadToHost` か手での配置が要る。

### 1.0.5 で溜まっている変更(#9)

手元では構文検査・自己検査とも通過済み(**件数は書かない** —— 増え続けるので、
書いた数がすぐ嘘になる。見るのは終了コードと FAIL 行)。**配備は利用者が実行する。**

```powershell
cd C:\Users\itota\Documents\Website\server\scripts
.\deploy-to-host.ps1 -Action up      # 置いて起動する
.\host-setup.ps1 -Fix                # 所有権を直し、PHP の目で確かめる
```

**2本目を省かないこと。** 配備はファイルを置くだけで所有権に触れない(触れないのが
正しい)。だが `config/*.local.php` と `uploads/` は **www-data の持ち物でないと動かない**。
そこを手作業に任せていたために「保存だけ失敗する」「地図を送れない」が起きた。

配る前に押さえること:

1. **`nginx/default.conf.template` を変えた。** ファイルを置くだけでは反映されない ——
   `-Action up`、または `-Action restart -Services reverse-proxy` が要る
2. **`api/floor-image.php` と nginx の変更は対。** 片方だけだと、図面が出ないか、
   直リンクが残って塞いだことにならない
3. **`map-access.local.php` の所有者が www-data(uid 33)であること。**
   ここが違うと「地図データ公開設定」の保存だけが失敗する。
   `host-setup.ps1 -Fix` が直し、**PHP から書けることまで**確かめる
5. **配備後、管理者は一度サインアウトすること。** Logto の `profile` スコープを
   足したので、それ以前から続いているセッションのトークンには入っていない。
   プロフィール画面のアカウント設定が 403 になる(画面にそう出る)
6. ~~`src/lib/legal.php` の `KM_LEGAL_OPERATOR` を埋めること。~~ **記入済み**(2026-08-30)。
   4項目とも埋まっているので「準備中」の警告は出ない。**本文を変えたら
   `KM_LEGAL_UPDATED` も変える** —— 読む人が改定に気づく唯一の手がかり
4. 配備しても**既定の動きは変わらない**。地図の錠は `mapMode = public` が既定で、
   管理画面で「パスワードが必要」を選んだときだけ掛かる
7. **`src/Main/zoom.js` を消してある**(`Old/Main/` へ退避)。`deploy-to-host.ps1` は
   `./src` を送るので、**ホスト側に古い `zoom.js` が残る**。読み込んでいないので
   実害は無いが、片付けるなら配備後に
   `sudo rm -f /opt/kosenmap/src/Main/zoom.js`(消さなくても動く)

### 決着した設計判断(#5)

**公開の口から Logto を切り離した。**
`km_user_stats_directory()` に `$mayRefresh` を足し、`api/app-stats.php` は
**ログイン済みの POST のときだけ**更新を許す。未ログインはキャッシュを読むだけで、
切れていても Logto を叩かず `stale = true` を返す。応答に `refreshedAt` も足した。

読み取り専用の m2m を作る案は**Logto の仕様上できない**(Management API のスコープは
`all` の1つだけ)。権限ではなく**経路**で分けたので、readonly 資格情報より強い。
併せて組織一覧の二重取得を1回にまとめた。

### #6 について

`app/build.gradle.kts:43` は既に `LOGTO_API_RESOURCE = "https://ito8795.com/api"`。
**残っているのは再ビルドと実機確認だけ**で、コードを直す作業ではない。

### 配備について(#4)

**決着:配備は利用者が実行する。** `deploy-to-host.ps1` は自動許可の対象外で、
そこを緩めるより「コマンドを示して止まる」方を運用の既定にした。
溜まっている変更は #9 を参照。

---

## 7. 測り方の落とし穴(実際に踏んだもの)

| 落とし穴 | 見分け方 |
|---|---|
| **この PC はセキュリティソフトが 25/465/587 を横取りする** | 存在しない IP でも「接続成功」になる。**バナーを読む** —— `421 Cannot connect to SMTP server … 10061` なら手元のプロキシ。本物なら `220 … ESMTP Postfix` |
| **ポートが塞がれていると誤断(2回)** | 何も待ち受けていなかっただけ。**先に本物を立ててから測る** |
| **メモリを見ずにコンテナを増やしてテスト機を落とした** | 追加前に `free -h` |
| **`.env` の鍵一覧を `^[A-Z_]+=` で絞って取りこぼした** | `LOGTO_M2M_…` は**数字を含む**。`^[A-Za-z_][A-Za-z0-9_]*=` を使う |
| **`ssh -L` では管理ポートへ入れない**(IP 制限は通るのにログイン画面を回り続ける) | **Cookie はポートを区別しないが、ホストは区別する。** `-L` は宛先を `localhost` に変えるので、`ito8795.com` のセッション Cookie が送られず、gate.php が毎回 401 を返す。**`-D`(SOCKS)を使う** —— ホスト名がそのままなので Cookie も証明書も一致する |
| **5.1 は外部コマンドへ二重引用符を正しく渡せない** | `ssh … "sh -c ""tr -d '\r' \| sh"""` の引用が崩れ、リモートで `sh -c tr` が走って **`tr: missing operand`**。**スクリプトが1行も実行されないのに、出力があるので「動いたが気になる点がある」ように見える。**引用が要る呼び出しは、`ProcessStartInfo.Arguments` を**自分で組み立てる** |
| **5.1 の `Process.StandardInput` は BOM を書く** | `Console.InputEncoding` が **BOM 付き UTF-8** なので、標準入力に触れた時点で `EF BB BF` が入る。スクリプトの先頭に付くと向こうの `sh` が1行目を読めない。**見えない3バイト**なので出力からは分からない。`[Console]::InputEncoding` を BOM 無しに差し替えてから起動する |
| **`ArgumentList` は 5.1 に無い** | `ProcessStartInfo.ArgumentList` は .NET Core から。5.1 では `Arguments`(1本の文字列)に自分で引用を付ける |
| **`docker compose ps --format` に Go テンプレートは渡せない** | compose v2 が受けるのは `table` と `json` だけ。渡すと `no such service: {{.State}}`。**`docker ps` の方は受ける**ので混同しやすい |
| **`pwsh` の構文解析で通っても、5.1 では読めない** | BOM の無い UTF-8 を PS7 は正しく読むので `Parser::ParseFile` は **0 件で通る**。5.1 だけが CP932 と読んで化け、`',' の後に式が存在しません` で落ちる —— **検査に通ったのに、手で叩くと壊れる**。新しく作った `.ps1` で3度目を踏んだ(2026-09-07)。`check.php powershell` が**先頭3バイトだけ**を見るようにした(`.sh` は逆に BOM を付けないことも一緒に見る) |
| **PowerShell 7 の `Set-Content -Encoding UTF8` は BOM を付けない** | 5.1 は BOM の無い UTF-8 を **CP932 として読む**ので、日本語コメントが化けて**構文解析の時点で落ちる** —— 「実行したら失敗」ではなく「**ファイルごと読めない**」。実際に `deploy-to-host.ps1` と `host-setup.ps1` を PS7 から書き戻して壊した(2026-09-03)。**中身は1文字も変えていないのに壊れる**ので、差分を見ても原因が分からない。手元スクリプトを書き換えたら `[System.IO.File]::WriteAllText($p, $t, [System.Text.UTF8Encoding]::new($true))` で BOM を付け直し、**両方の shell で `Parser::ParseFile` を通す** |
| **PowerShell 7 だけの書き方を手元スクリプトに混ぜる** | `?.` / `??` は 5.1 では**構文解析の時点で落ちる** —— 「実行したら失敗」ではなく「**ファイルごと読めない**」ので、何をしようとしたかも出ずに終わる。`register-backup-task.ps1` がそうなっていた。**両方の shell で `Parser::ParseFile` を通すこと** |
| **Windows PowerShell 5.1 は空文字の引数を落とす** | `& ssh-keygen -y -f $key -P ''` が **`option requires an argument -- P`** で必ず失敗し、パスフレーズの無い鍵まで「掛かっている」と誤判定した。**PowerShell 7 では再現しない**(起動した shell で答えが変わる)。空を渡したいときは `cmd /c "… -P `"`""` を挟むか、**外部コマンドに聞かずに自分で判定する**(`Test-KmKeyEncrypted` は鍵ファイルの `ciphername` を読む) |
| **手元から1回取るたびに、ホストの控えが3本まで削られていた** | `backup-data.ps1` がホストへ `--keep 3` を渡しており、host-backup.sh は作ったあとに世代整理をするので、週次タスクが走るたびに cron の14本が3本になっていた(2026-09-13、9/7〜9/12 の6本が一度に消えた)。**cron の設定は正しく見え、ホストの記録にも残らない** —— 消していたのは別の機械から来た1回。手元の `backup-task.log` の「消しました」6行で分かった。世代数はホストだけが決める。`check.php` が見張る |
| **日曜だけ同じ控えを2本作っていた** | 「毎日 2:40」と「日曜 2:50(--heartbeat)」の2行で、毎日の行は日曜も走る。世代は本数で数えるので、余分な1本が「14日分」の履歴を毎週削る。月〜土(`1-6`)と日曜(`0`)に分けた(cron 版 5)。`check.php` が曜日ごとの本数を数える |
| **「前回と同じ知らせか」を表示用の文で比べていた** | 再起動の項目は「(0 日前から)」「(1 日前から)」と日数が文に入るので、何も変わっていないのに毎日指紋が変わり、**毎日メールが出ていた**(本来は初回と7日ごと)。表示する文と見分ける鍵を分けた —— 再起動の鍵は要求された時刻、自動更新の停止は `unattended|stale`。件数のように**変われば知らせるべき数**は文のままでよい |
| **`chmod 750` でディレクトリの setuid が落ちない** | GNU chmod は数字で指定されたとき、ディレクトリの setuid / setgid を**残す**。`4755` に `chmod 750` を当てると `4750` になり、判定(`750` か)が毎回外れて「直しました」と言い続ける。先に `chmod u-s,g-s` で落とす。**私が手順として渡したコマンドにも同じ不備があった** |
| **件名「バックアップを添付しました」の添付は実行記録だけだった** | 633 KB は控えの大きさで、付いていたのは `kosenmap-logs-*.tar.gz.enc`。本文の開き方も `-out backup.tar.gz` だった。**受信箱に控えがあると思い込むと、ホストを失ったときに頼る先を間違える。** 件名を「バックアップを取りました (…)・実行記録を添付」にし、本文に控えの在りかを書いた |
| **開いた平文が、また残っていた(2回目)** | security-review-2026-09-10 の P0 で指摘された `opened-*` のうち1つが、世代ごと `D:\Backups` へ移されて**平文のまま**残っていた。原因は `open-backup.ps1` が「用が済んだら消してください」と表示するだけの作りだったこと。**表示で頼まず作りで消す** —— 既定で検査後に消し、残すのは `-Keep` のときだけ(24時間で世代整理が消す) |
| **手元の控えの置き場を4か所で決め打ちしていた** | 利用者が控えを `D:\Backups` へ移したら、世代整理にも「一番新しいものを開く」にも見えなくなった。`backup-lib.ps1` の `Get-KmBackupRoot` 1か所で決め、無ければ止まる(リポジトリの中へ黙って落ちない) |
| **手元の週次が失敗しても、誰にも知らせが来なかった** | 記録をファイルに足すだけだった。`backup-task-run.ps1` を挟み、失敗したらホスト経由のメールと Windows の通知を出す。**PC の電源が入っていない週はタスク自体が走らない**ので、ホストの日曜の便りが「手元の PC が今週取りに来ていません」と言う |
| **soketi 1.6.1 は node 20 では起動しない(ビルドは通る)** | ビルド元 `node:16-bullseye-slim` は保守終了(Node 16 は 2023 年、Debian 11 の LTS は 2026-08 末)。ホストで本番と切り離して `node:20-bookworm-slim` + soketi 1.6.1 を試したところ、**ビルドは通るが起動直後に落ちた**: `This version of uWS.js supports only Node.js 14, 16 and 18`(`uws_linux_x64_115.node` が無い)。同じ渡し方で本番の像(node 16)は動き続けたので、試験の不備ではない(2026-09-13)。**node 20 へは soketi の版を変えずには上げられない。** 残る道は `node:18-bookworm-slim`(Node 18 も保守終了だが OS は Debian 12)か、soketi 自体の置き換え。どちらも未着手 |
| **配備が `Cannot open: File exists` で全ファイル落ちた(2回目の配備から)** | 手元の `src` / `docker` / `nginx` などのフォルダに Windows の「読み取り専用」属性が付いており、bsdtar がそれを**ディレクトリのモード 555** として書いていた。GNU tar は展開の最後にそのモードを当てるので、**1回目は通り、次の配備で**書けないディレクトリの中のファイルを1つも置き換えられなくなる(2026-09-13)。書けるディレクトリ(`scripts/` や直下)の分だけは置き換わるので、**途中まで配備された状態で止まる。** 同時に出た `Ignoring unknown extended header keyword 'SCHILY.fflags'`(41件)は属性の拡張ヘッダーで、害は無いが本当のエラーを埋めていた。`deploy-to-host.ps1` で、作る側に `--no-fflags`、展開する側で**前後に**書けないディレクトリへ `chmod u+w`(前は今の 555 から抜けるため、後は次の配備のため)。**それだけでは足りなかった**: GNU tar はディレクトリのモードを「展開の最後」ではなく「そのディレクトリの外へ出たとき」に当てる。bsdtar のアーカイブは子ディレクトリの項目を先に並べて中身を後に置くので、中身に着く前に 555 が当たり、**空の場所への初回ですら落ちる**(本物のアーカイブの写しで 429 件)。`tar --delay-directory-restore` を足し、555 の状態から・繰り返し・空の場所への初回のすべてで exit 0 を /tmp で確かめた。`check.php` が見張る。**いつから**: ホストのファイルの ctime では、最後に全部が置き換わった配備は **9/9 12:21**(179件)で、それ以降の配備は 9/13 が初めて。手元の `Documents` 配下のフォルダ 525 個のうち 515 個は、NTFS の ChangeTime が **9/10 09:38〜09:39 に揃っている**(一斉に属性が付いた)。同じ時刻に Windows の更新(サービス スタック、復元ポイント 09:37:20)があり、09:06 には `ProgramData\Microsoft OneDrive` が作られているが、**どれが付けたかは特定できていない**。新しく作ったフォルダには付かない。9/9 から 9/13 までの間に本番へ出たものは無いので、**実害は 9/13 の配備が止まったことだけ**。対処は属性に依らない形にしたので、手元の属性は外していない |
| **`Old/restore-data.ps1` を直した(2026-09-14)** | 3つ直した: (1) コンテナ名を直に書いていた(`dev-*`。9/7 の改名で**動かなくなっていた**)→ compose の札(`-Project` とサービス名)で探す (2) **平文のダンプを server/ 直下へ置き、全部成功したときしか消していなかった**(`-DryRun` と失敗時は Logto の秘密ごと残った)→ `mktemp -d`(700)に置き finally で必ず消す。退避(km-before)も 600 (3) 行数の期待値を最初の INSERT 文からしか読んでいなかった → すべて足す。**本番と切り離した使い捨ての compose プロジェクト(kmrestoretest)へ実際に全部戻し**、地点 1037 / 経路 988 / MariaDB 31 表 / Postgres 79 表 / uploads 3 がすべて一致、一時フォルダ・コンテナ内の一時ファイル・server/ のダンプがすべて 0、`-Force`(退避あり)も exit 0 を確かめた。試験環境は片付け済み |
| **セキュリティ確認(9/10)の所見を直した(2026-09-14、未配備)** | **2** ランキングの `userId` を年ごとの鍵つきの印に(キー名は据え置き。配布済みアプリはこのキーを必須で読む)、`app-avatar.php` の GET をログイン必須・本人だけに(**古いアプリは自分の画像が出なくなる。アプリの更新を先に**)/ **3** 解除の試行を錠ごと(`web` / `app`)の表に分けた / **5** サインアウトで地図の錠も閉める / **6** 本文の大きさを既定 3m、`/admin/` 12m、APK だけ 210m / **7** DB が使えないときは `src/cache/unlock/` のファイルで数える / **8** 実測で `ALL PRIVILEGES ON Kosen_map.*`(`*.*` ではない)。コメントを訂正 / **9** 調べられた語は3つ以上の出どころ(IP の鍵つきの印)から来たものだけ出す + nginx でも絞る / **10** 1つの IP から数える端末を 300 まで / **11** Logto のトークンと停止判定のキャッシュを共有 tmp から `src/cache` へ、持ち主と権限を見てから読む / **12** 利用者の形でない応答では閉じる / **13** DB は CA が無ければ繋がない(本番の web には在ることを確認)/ **14** reCAPTCHA の CSP を `/recaptcha/` のパスまで / **15** チャットの埋め込みを HEX で逃がす / **16** 8025・8281 に `frame-ancestors 'self'`(3001 は Logto が自分で Console の 3002 を含めて送っているので触らない)/ **17** 見取り図に sandbox の CSP / **18** webhook は `createdAt` が1日より古いものを捨てる / **19** ダウンロードの保存名の形を確かめる / **20** ファイル名から `\` も落とす / **21** `KM_CSP_REPORT_ONLY` を compose から渡す / **22** `style=` を CSS のクラスへ / **23** 画像の MIME は拡張子から / 持ち越し: `/vendor/` を塞ぐ(本番で 200 だった)。**4**(文書の誤り)はノートブックで直っている。DB を使うものは使い捨ての環境で結合試験 27 件すべて通過、nginx は同じ像・同じネットワークの使い捨てコンテナで `nginx -t` 通過。`check.php security-review` が戻りを見張る。**P0-2 の鍵のローテートは未着手**(利用者の判断で今回の範囲外) |
| **診断(セキュリティ・ドメイン・負荷・地図の配信)を直した(2026-09-14、未配備)** | 6担当で直し、統合で `check.php hardening` の節(戻りの見張り)を足した。**A ドメイン**: 本番の `.env` で必須なのは `KM_DOMAIN` だけ。`compose.vps.yaml` が URL 系(APP_URL・LOGTO_ENDPOINT・証明書のパス・MAIL_*・mailserver の差出人など)を導き、無ければ `docker compose config` の時点で止まる。**`KM_API_RESOURCE`**(トークンの audience)は**ドメインを変えても据え置く値**として分けた。`scripts/host-domain.sh`(check / apply。`.env` を控えてから書き換え、up はしない)と `src/scripts/logto-domain.php`(Logto の戻り先 URI などを旧→新。既定は一覧だけ)を新設。Android は `-Pkosenmap.domain=` で接続先を変える / **B 負荷**: nginx に同時接続の上限(429)・待ち時間(上流は既定 60 秒。300 秒は downloads・8281・3001・3002・6001、180 秒は map-sync・map-publish だけ)・未認証で重い口の回数制限(`km_api` 240r/m、`/api/app-map.php` は `km_appmap` 30r/m)。PHP は `99-limits.ini`(実行 30 秒・外への待ち 15 秒・strict_mode)、DB は接続 5 秒・Web の文 15 秒・`--local-infile=0`・`--max-connections=120`、Soketi は接続と送信の上限・`USER node`、全コンテナに mem/pids の上限と no-new-privileges(mailserver を除く)。公開の JSON API は本文 64KB、アカウント画像は 2048px まで。JS の fetch は 15 秒で打ち切り、監視の自動更新は失敗で間隔を延ばす。Android は接続 10 秒・読み 20 秒・応答サイズの上限・送信の指数バックオフ / **C セキュリティ**: **PATH_INFO を断った**(`/contact.php/x` で入口の回数制限と include 専用の 404 を素通りできた。nginx の `\.php/` 404 と Apache の `AcceptPathInfo Off` の2枚)、web から `POSTGRES_*` を外し `LOGTO_WEBHOOK_SIGNING_KEY` を渡す(**アカウント削除の後片付けが一度も動いていなかった**)、3001/3002 の X-Forwarded-For を送信元だけに・Logto の HSTS を隠す、管理系3ポートを IPv4 だけに、**本番で Mailpit を起動しない**、phpMyAdmin は root を既定で断る、6001 は Origin を照合。PHP は例外の文面を画面に出さず照合用 ID だけ(`KmUserError`)、サインアウトを POST + CSRF に、管理画面の POST と管理系ゲートに `admin:users:write` を要求、監査ログ(拒否・停止・サインアウト・webhook の失敗)、reCAPTCHA のホスト名照合、account.php の停止判定。ホスト側は cron **版 6**(証明書の renew を毎日 3:47、status を毎月)、セキュリティ確認に fail2ban と Logto Console の MFA、緊急調査の出力を umask 077、バックアップの DB パスワードを MYSQL_PWD で。Android はランキング参加の既定を OFF + 同意、トークンを Keystore で暗号化、ログアウト時の失効の送り直し、来場者ビルドは admin:* を要求しない ほか / **D 地図の配信**: 配信 ID を `kosen-main`(Website の正本)と `kosen-event`(管理画面で JSON を添付)の2つに。版の比較は配信 ID ごと(`haveMapId`)、コードは鍵つきの lookup で先に引き(古い bcrypt だけのコードは1回 10 件まで)、**失敗は照合の前に数える**('app' は 30 回/15 分・成功しても消さない・IPv6 は /64)、配信先の無いコードを一覧に出して付け替え・停止できる。**確かめたこと(手元だけ)**: `check.php` すべて通過(件数は書かない)、変えた PHP に `php -l`、JS に `node --check` と既存テスト、compose は docker が無いので PyYAML で展開と併合を再現して検査(KM_DOMAIN 無しで止まる・VPS の導出値)、nginx は置換後のテンプレートを静的に検査(**`nginx -t` は未実行**)、Android は visitor・admin とも単体試験 571 件 失敗 0・APK 2つ作成(**実機は未確認**)。**本番でまだ実行していないこと**: (1) **配備**(`deploy-to-host.ps1`)と `docker compose config -q` → `build soketi` → `up -d` → `nginx -t`、残っている Mailpit の `stop` / `rm` (2) `host-updates-setup.sh --fix` による **cron 版 6** (3) `host-cert.sh fix-conf`(renewal の authenticator が standalone のまま) (4) **fail2ban の調査**(`failed` のまま。`fail2ban-client -t` から) (5) **Logto Console(admin テナント)の MFA を Mandatory に**(いまはパスワードだけで入れる) (6) MariaDB の **`Main@%` の権限**と `root@%`(実測のまま触っていない) (7) **Logto の Postgres 利用者を SUPERUSER でないロールへ**(未着手) (8) **Soketi の Node 16 更新**(soketi 1.6.1 は Node 20 で起動しない。上の行) (9) 配信先の無いアクセスコード **TEST1 の付け替え**(管理画面「アプリへ地図を配信する」。いまはアプリが地図を取れない) (10) **Android の配布は、サーバーの配備の後に**(新しいアプリは `haveMapId` を送り、配布前のサーバーでも壊れはしないが、ランキング同意・来場者ビルドのスコープ・lookup はサーバーと揃えて確かめる)。あわせて `Test.zip`(DB の接続設定を含む)の扱いと資格情報のローテートは利用者の判断待ち。**配備したら**: `/contact.php/x` が 404、管理画面のチャットが「接続済み」(6001 の Origin は `.env` の `KM_APP_URL` と**文字列で完全一致**で比べる)、Logto Console の Webhooks のテスト送信が 2xx、地図の取り込みが 504 にならない、を確かめる |
| **取扱説明書を足し、積み残しを直した(2026-09-14、未配備)** | **文書**: `docs/10-security-review-2026-09-14.ipynb`(診断の報告書。所見 71 件 = Web 43・Android 28、重大・高 0。カタログの ID ごとの結果・本番の実測・直したこと・**§6 に本番で実行する 0〜25 のセル**・§7 の判断待ち 14 件・§8 の限界)と `docs/11-getting-started.ipynb`(新しく使う人の入口。全体の図・部品と入口・管理画面・Android・ホストの基本・よくある誤解・用語集)を新設。00-start・04・05・09 の目次に 10・11 を足し、**04** は cron 版 6(証明書の renew 毎日 3:47・status 毎月1日)と届くメール・証明書のセル、**05** はコンテナの上限(mem/pids・no-new-privileges)・本番で起動しない Mailpit・Soketi の node 利用者・phpMyAdmin の root 禁止、**09** は §6「ドメインを変える」(host-cert.sh issue → host-domain.sh check/apply → up -d → logto-domain.php)と「.env の URL 系は KM_DOMAIN だけ」を追記 / **積み残しの修正**: `admin/monitor.php` の自動更新を `KmServices.startAuto`/`stopAuto` に、`admin/chat.php`・`kanban.php` の fetch に 15 秒の打ち切りと 429 の待ち(送信は自動で送り直さない)、`lib/security-notice.php` に `fail2ban`・`logto` の種別、`lib/distributables.php` の断りを `KmUserError`/`InvalidArgumentException` に、`ja.js`/`en.js` に `page.mapEditor.*` 3 つと欠けていた `log.action.*` 20 個(`KM_ADMIN_LOG_ACTION_LABELS` の 67 操作がそろった。ja の `map_sync` の古い文も直した)、`scripts/app-map-config-set.php` が作るコードを正規化して lookup を付ける(DB の鍵が読めなければ止まる)/ **統合で直した**: `%%host` のセルで `docker compose exec -T` や `host-cert.sh`(中で exec -T を呼ぶ)の後ろに行が続くのに `</dev/null` が無く、**後ろの行が飲み込まれて黙って実行されない**ところ(10・04・05・09 の計 8 セル)に付けた。05 の Mailpit の片付けに `--profile mailpit`、11 の目次に 10 を足し、古くなった「04 の表は版 5」を直した / 上の行の「`nginx -t` は未実行」は、その後に本番と同じイメージ・証明書で**通した**(`docker compose config` も本番の .env と KM_DOMAIN だけの .env で通過)。**本番では何も実行していない**(ノートブックのセルも未実行。chat/kanban の 429・時間切れはブラウザで試していない) |
| **ノートブックの `%%ps` で構文エラーが化けていた** | セルに構文の誤りがあるとファイルの1行目も実行されずに止まり、ファイルの中で決めていた UTF-8 が効かず CP932 の文字化けと色の制御文字が出た。原因のセルは私の書き間違い(03-backup の「いま一度走らせる」で `$((a; b))`)。**`backup-lib.ps1` は無関係。** `km_nb.py` は外側(`-Command`)で UTF-8 と色なしを決めてから呼ぶよう直し、全ノートブックの PowerShell セルを構文解析に通して誤りが1件だけだったことを確かめた |
| **Android のビルドが4段で落ちていた(2026-09-14 に通した)** | (1) JDK が `jdk-21.0.12.8` → `jdk-21.0.12.101` に更新され、`Test/gradle.properties` の `org.gradle.java.home` が消えた場所を指していた → 直した。**利用者の環境変数 `JAVA_HOME` も古いまま**(システムの方は新しい。設定の変更になるので触っていない)(2) **`Test` に「(1)」付きの写しが 75 件**。どれも **2026-09-09 23:35〜23:51 に作られ、元のファイルの方が新しく中身も違う**(同期か復元で古い版が戻ってきたと見られる。同じ頃 Google Drive for desktop が動いていた)。`app/` の 56 件が res の名前の規則違反・クラスの二重定義でビルドを止めていた → `Test/Old/conflict-copies-20260909/` へ**退避**(消していない。README あり)。`OldMD` / `php` / `scripts` / `.idea` の 19 件はビルドに関係しないので残した (3) 9/10 に一斉に付いた「読み取り専用」がビルドの出力 `app/build` のフォルダ 2567 個にも付いており、Gradle が中間物を消せなかった → `app/build` の中だけ外した (4) 同じ 9/9 23:47 に **`mipmap-*/ic_launcher(_round).webp` 10 件**(中身は 6/30 のテンプレート)が戻り、9/4 に差し替えた `.png` と同名で「Duplicate resources」→ 同じ場所へ退避。**結果: visitor・admin とも単体試験 533 件すべて通過、APK 2つ作成。** 同じ時刻に戻ってきたと見られる `MapLayerRenderer.kt` / `NodeInfoDialog.kt` / `MapLayerRendererTest.kt` / `res/xml/file_paths.xml`(どこからも使われていない。file_paths.xml は AndroidManifest のコメントに「消した」とある)は、ビルドを壊さないので**利用者の判断待ちで残してある** |
| **`Test/.git` が空のフォルダになっている(未解決)** | `HEAD` も中身も無く、`git` は「not a git repository」と言う(2026-09-14 に確認)。上の 9/9 の出来事と同じ頃に失われた可能性がある。**この PC からは戻せない**(Google Drive 側に版が残っていれば、そこから)。こちらでは触っていない |
| **ノートブックを VS Code で開いたまま、ファイルを直すと戻される** | 03-backup のセルの誤りを直したあと、開いていた VS Code の保存で**直す前の中身に書き戻された**(2026-09-14)。直すときは VS Code で閉じるか、直したあと「元に戻す(Revert File)」で読み直してから保存する |
| **文書を md から取扱説明書のノートブックへ移した** | 2026-09-14、利用者の指示で `server/docs/*.ipynb`(00-start〜09-new-host の10冊)を作り、**書いてあるコマンドをセルから実行できる**ようにした(VS Code + Jupyter、Python カーネル)。`docs/km_nb.py` が `%%ps`(手元の PowerShell 7)/ `%%host`(ホストの sh、標準入力で流す)/ `%%terminal`(sudo など対話が要るものを別の窓で)を足す。本番を変えるセルは `--confirm` で `yes` を求め、印(🟢🟡🔴🔑)をセルの tags に持つ。`check.php notebooks` が「確認の付け忘れ」「`%%host` で sudo」「印の無いセル」を落とす。🟢 のセルは画面の無い Python(VS Code のカーネルと同じ条件)で実際に流し、文字化け無し・確認で止まる・時間切れで止まることを確かめた。**`status.md` と `plan.md` 以外の md 12本は `server/Old/docs/` へ移し**(冒頭に帯)、生きているファイルの参照を直した。**ipykernel は Claude のアプリの中から入れたので、VS Code からは見えない可能性がある**(初回に VS Code が入れるよう促す) |
| **`host-setup.ps1` を単独で走らせると落ちていた** | `km-ssh.ps1` の `Get-KmRemoteProjectPath`(2026-09-07 に3本の写しを集約したもの)が、**`deploy-to-host.ps1` にしか無い関数**で相手のシェルを判定していた。配備から呼ばれたときは呼んだ側の関数が見えるので動き、**単独で叩いた人のところでだけ**「その関数が認識されません」で落ちる。ノートブックから叩いて発覚(2026-09-14)。パスの形(`/` で始まるか)で判定するよう直し、`check.php` が見張る |
| **`Old/restore-data.ps1` はそのままでは動かない(未修正)** | コンテナ名を `dev-mariadb` / `dev-postgres` / `dev-php-apache` と直に書いている。2026-09-07 の `km-*` への改名で直していない。**週次バックアップの出口はこれしかない**ので、使う前に直すこと(サービスの札で引く形にするのが筋。`Get-KmRemoteProjectPath` と同じ考え方)。ノートブック `03-backup` の §8 に警告を書いた(2026-09-14 に発見) |
| **合言葉の差し替えで、docker compose が .env ごと読めなくなった** | 2026-09-13 18:30 に `BACKUP_PASSPHRASE` を `"…"` で囲んだ値(中に `"` 4個・`'` 2個・`\` `$` 各1個)へ差し替えた直後から、`docker compose` が `failed to read .env: line 52: unexpected character …` で**すべて**拒んだ。メールは `docker compose exec web php` で出すので1通も出ず、`check-updates.sh` は「動いているコンテナがありません」、`host-backup.sh` は「mariadb が動いていません」と**別の理由**で止まる(直さなければ、その夜 02:40 の控えも取れなかった。18:49 に直したので間に合った)。**エラー文には値の断片が載る。** 18:49 に英数字100文字へ直して `docker compose config` が通ることを確かめた(値は見ていない)。再発防止: `host-backup.sh` は先に `docker compose config -q` を(エラー文を捨てて)確かめて分かる理由で止まる。合言葉の両端の引用符は外して使い、中に `"` `'` `\` `$` `` ` `` があれば添付しない。`deploy-to-host.ps1` は [3/5] で .env を読めないと分かったら、エラー文を出さずに止まる。あわせて、添付を作れなかったとき(km で手で走らせると `src/uploads/.km-attach` を作れない)に先へ進んでいたのを、添えずに理由を書くよう直した。`check.php` が見張る。同じ日に全種類のメール(更新・セキュリティ・日曜のバックアップ・手元の週次の失敗・問い合わせ)を1通ずつ送り、5通とも `status=sent`。ホスト(OpenSSL 3.0.13)で暗号化し手元(4.0.1)で復号できることも、合言葉を画面に出さずに確かめた |
| **ログのメールで、日本語が文字の途中で割れていた** | 2026-09-13 10:23 の「手元の週次バックアップ (ITO-PC): 失敗」(**`-HostName no-such-host.invalid -MailHostName ito8795.com` で送った試験の1通**。登録済みのタスクは `ito8795.com` を向いており、まだ一度も走っていない)の本文に「指紋を業�」「のコンソールと見比べられる」と割れて届いた。`src/lib/log-notice.php` の `km_log_notice_trim` が `preg_split('/\R/', …)` で行を割っており、**`/u` の無い `\R` はバイト `\x85`(Latin-1 の NEL)も改行とみなす** —— `者` は `E8 80 85`。**send-log.sh を通るすべてのログのメールで、`\x85` を含む漢字(`者` など)が割れていた。** 改行の3通り(`\r\n` `\r` `\n`)を明示して割るように直した(`/u` は壊れた UTF-8 が混じると本文ごと消えるので使わない)。伏せ字の正規表現の `\s` は `\x85` / `\xA0` に当たらないことを確かめた。`check.php` 自身も同じ割り方で自分のファイルを読んでいたので直し、`log-notice` の節で「割らない」「`/u` の無い `\R` を置かない」を見張る。あわせて、`-MailHostName` を付けた試験の知らせは件名に `【試験】` を付ける |
| **配備が転送のあと、何も表示せずに止まった** | 上の tar の失敗が1ファイル1行のエラーを大量に標準エラーへ出し、`Invoke-KmSshStdin` が「標準出力を読み切ってから標準エラーを読む」作りだったため、ssh.exe の標準エラーのパイプが埋まって**双方が相手を待った**(2026-09-13)。ホストではシェルがもう終わっているのに、手元の ssh は接続したまま残っていた。標準出力と標準エラーを**同時に**読み、`-TimeoutSec`(既定600秒、展開は300秒)で打ち切り、長いエラーは頭30行だけ見せる。ssh / scp の共通の引数に `ServerAliveInterval=15` / `ServerAliveCountMax=4` を足し、つながった後に死んだ回線を約1分で切る。`check.php` が見張る |
| **系列タグとビルド元が、固定も見張りもされていなかった** | `check-updates.sh` は `latest|alpine|stable|main|edge` しか比べておらず、`postgres:17-alpine` / `mariadb:11.4` / `phpmyadmin:5.2.3-apache` / ビルド元の `php:8.4-apache` / `node:16-bullseye-slim` は修正版が出ても誰も知らなかった。種類 `series` と `base` を足した。ビルド元は `build --pull` しない限り当たらない |
| **`/etc/cron.d/` のファイルは root 所有でないと実行されない** | `-rw-r--r-- 1 km km /etc/cron.d/kosenmap-updates` になっており、**cron が黙って無視していた**。ファイルは在り、中身は正しく、末尾に改行もあり、デーモンも `active`、**版の照合(`kosenmap-cron-version: 4`)も通る**。症状は「メールが来ない」だけ。同じ cron.d の `sysstat`(root 所有・同じ 644)は同じ時刻に動いていたので、**違いは所有者だけ**と切り分けられた(2026-09-09)。`/var/log/kosenmap` が空(リダイレクト先すら出来ていない)= 一度も実行されていない、が最初の手がかり。**`cat > "$CRON_FILE"` は既存ファイルの持ち主を変えない**ので、一度 km で作られると root で何度書き直しても km のまま。書いた直後の `chown` と、毎回の確認の**両方**を入れた。`check.php` が見張る |
| **版の照合が通ると、それ以上何も見なかった** | 上の件が `--fix` で直らなかった理由。`host-updates-setup.sh` は `kosenmap-cron-version` が一致すれば「定期実行あり」と言って終わっていた —— **中身が正しくても cron が読まない状態**を素通りする。**「書いてある」と「効いている」は別**なので、確認を分けた(所有者・権限を毎回見る)。`/var/log/kosenmap` も `4755 km:km` になっており(ディレクトリの setuid は無意味、末尾の 5 は**ホストの誰でも読める**)、点検結果とホストの弱点が誰にでも見える状態だった。`750 root:root` に揃える |
| **本番と検証機に同じ名前を付けていた** | `compose.yaml` が両方 `name: Test`、コンテナ名も両方 `dev-*` で、**`docker ps` の出力が完全に同じ顔**だった。「テスト環境の Docker を止めて」という依頼で、**公開中の本番を止めかけた**(2026-09-07。そのとき本番は2時間で28か所から使われていた)。名前は**間違えたときに何が起きるかで選ぶ** —— `dev-mariadb` を消すのと `km-mariadb` を消すのとでは手が止まる確率が違う。本番を `name: kosenmap` / `km-*` に改め、検証機(192.168.3.29)は `docker compose down` して compose ファイルを `*.retired` に改名した。`check.php compose` が見張る |
| **プロジェクト名を変えるとボリュームの実体名も変わる** | compose の実体名は `<プロジェクト名>_<宣言名>`。`Test` → `kosenmap` にすると `test_mariadb_data` ではなく `kosenmap_mariadb_data` を探し、**無いので空を黙って新規作成する。そして起動は成功する。** 中身だけが空になり、`test_letsencrypt`(証明書)を失えば **HSTS のため誰もサイトへ入れず**、`test_mail_dkim` を失えば **DNS の公開鍵と合わず送信メールが全部迷惑メール行き**になる。どれも出力からは分からない。**宣言側に `name:` を書いて実体名を固定する**(名前が古いのは承知のうえ —— 実体の改名にはデータの移し替えと停止が要る) |
| **プロジェクト名を変えた直後の `up -d` は port が衝突する** | 改名した compose から見ると、動いている旧コンテナは**他人のもの**。新しい `km-nginx-proxy` を作ろうとして 80/443 を旧 `dev-nginx-proxy` が握ったままなので `port is already allocated`。**失敗するのは新しい方だけで、古い方は動き続ける** —— 配備は失敗と出るのにサイトは生きているので分かりにくい。入れ替えは `docker compose -p test down` → `up -d` の一度きり。`deploy-to-host.ps1` が旧プロジェクトの残りを検出して手順を示して止まる |
| **`"$Var:..."` は変数名の一部として読まれる** | `"$User@$HostName:$RemotePath"` が **`Variable reference is not valid. ':' was not followed by a valid variable name character`** で落ちる。`:` はスコープ修飾子(`$env:`, `$script:`)の区切りなので、`$HostName:` まで1つの名前として読まれる。**実行時ではなく構文解析の時点**で落ちるので、そのファイルごと読めない。`${HostName}` で切る。`deploy-to-host.ps1` に `-BackupOnly` を足したときに踏んだ(2026-09-07)—— **5.1 の `Parser::ParseFile` が捕まえた。両方の shell に通す価値がここにある** |
| **dash の `echo` は引数のエスケープを解釈する** | `echo "  .\\backup-data.ps1"` が **`ackup-data.ps1`** と出る(`\b` が後退文字になり `.` を消す)。**bash の `echo` は解釈しないので、手元で bash 相手に試すと再現しない。** スクリプトは正常終了しており、壊れているのは案内文の方なので**出力を読んでも分からない** —— 表示どおり打った利用者の側でだけ失敗した(2026-09-07、`CommandNotFoundException`)。値は `printf '%s\n' '…'` の**引数側**へ回す(書式側の `\b` は printf でも解釈される)。`check.php shell` が見張る |
| **バックアップの置き場を `700 root:root` にしていた** | cron は **root**、`backup-data.ps1` は **km** で入るので、後者が一度も通らなかった。落ちるのは暗号化の段なので、**ダンプを3つ取り終えてから** `Permission denied`。そして **km は `docker` グループにいる**(`docker run -v /:/host` で root になれる)ので、**km から隠しても防御になっていない** —— 守っているのは権限ではなく暗号。置き場を `2770`(setgid、群れは `PATH_ROOT` の持ち主)、出来上がりを `660` に揃えた |
| **「作ったもの」と「消したもの」が同じ形で出力に並ぶ** | `backup-data.ps1` が `km-backup-*.cms` に見える行の**最後**を拾っていたが、世代整理が `消しました: km-backup-….cms` を**あとに**出すので、**いま消したものを掴んだ**(2026-09-07、scp が `No such file or directory`)。**世代整理が何も消さない間は正しく動く**ので、置き場が埋まるまで表に出なかった。直し方は「文章を上手に読む」ではなく**機械向けの行を1本立てる** —— `KM-ARCHIVE <名前>` を成功時に一度だけ出し、そこだけを読む。`check.php shell` が両側の合図の一致を見る |
| **匿名化した「あと」に記録を書くと、その1行だけ実名で残る** | アカウント削除で実際に起きた。`km_account_delete_data()` が `km_admin_log` の名前と ID を落とし、その直後に `km_admin_log_record('system','account.deleted', …)` が **`$KM_USER` から名前を拾って**書いていた。タイムラインに「**Test が**削除されたアカウントの情報を片付けました」と消えた人の名前が残る。**消した本人はもう画面を見ていないので、自分では気づけない**(利用者の指摘で判明)。**順番で直さない** —— 呼ぶ側が第4引数 `$anonymous` で明示する。名前と ID だけでなく **IP と User-Agent も落とす**(時刻と併せると指せてしまう) |
| **変形の途中でパネルの位置を測る** | 開閉は CSS の `transition`(0.3秒)で動く。押した直後に `getBoundingClientRect()` を読むと**まだ画面の外に居る位置**が返り、地図の余白が 0 として扱われる。結果は「地図がシートの下に潜ったまま」で、**シートも地図も出ているので不具合に見えない**。`transitionend` を待つ。動きを減らす設定では飛んでこないので、保険のタイマーも要る(2026-09-05) |
| **`let` の宣言より前に、それを読む関数を呼ぶ** | 起動時に検索パネルを畳む処理が、下で `let` している `currentFloorBounds` を読む関数を呼んでいた。TDZ で例外になり、**地図が読み込み画面のまま出てこない**。関数宣言は巻き上がるので、呼ぶ側からは「在る」ように見える。`check.php` では出ず、**開いて初めて分かった**(2026-09-05) |
| **ブラウザのペインを隠したまま測る** | 描画が止まるので `transition` が進まず、`getBoundingClientRect()` が**変形の途中の値を返し続ける**。「直したのに直っていない」ように見える。位置を測るときは**ペインを前面に出してから**(2026-09-05) |
| **窓が裏に回っていると `:focus` はどれにも当たらない** | `document.hasFocus()` が false のあいだ、`activeElement` はその要素のままなのに `element.matches(':focus')` は false。「キーボードの位置が中にあるうちは畳まない」という守りが、**別の窓へ切り替えた瞬間だけ外れる**。位置を見るなら `activeElement` で見る(2026-09-05) |
| **バックアップをメールに素で載せる** | 中には `.env` と `config/*.local.php`(DB のパスワード・Logto の M2M 秘密)、Postgres の利用者アカウント、MariaDB の問い合わせ本文と教職員氏名が入る。**受信箱1つの流出がサーバーの全権**になる。添付するなら `BACKUP_PASSPHRASE` で暗号化してから、合言葉はメールに書かない、無ければ添付しない(2026-09-07) |
| **PHP のコメントを自分で閉じる** | 強調の記号と `/dev/null` を続けて書くと、その並びがコメントの終わりになり、**ファイルごと構文エラー**。検査どころか何も走らない。`clamp(5px` と同じ形を、今度は自分の説明文でやった(2026-09-07) |
| **「見つかったら送る」は「毎日同じものを送る」** | 見つかった問題は**直すまで毎回見つかる**。再起動の要求は再起動するまで続くので、毎回送ると同じメールが毎日届き、読まれなくなる —— 本当に新しい知らせが来たときには開かれていない。前回の中身を `/var/lib/kosenmap/` に覚えて、**変わったときと7日ごとの念押しだけ**送る。**送れてから覚える**こと(先に覚えると、失敗した回で「送った」ことになり、一度の取りこぼしが永久の沈黙になる)。**直ったら忘れる**こと(覚えたままだと再発時に黙る)(2026-09-07) |
| **仕込んだファイルの「古さ」を中身の grep で見る** | 見張る文字列を足すたびに選び直すことになり、選び忘れた変更が黙って素通りする(cron の時刻を変えても書き換わらなかった)。先頭に `# kosenmap-cron-version: N` を置き、**番号が違えば書き直す**。番号が同じなら触らないので、手で変えた時刻も残る(2026-09-07) |
| **配備した `.sh` は実行ビットを持っていない** | 手元が Windows なので tar に入る時点で 644。ホストで `sudo /opt/kosenmap/scripts/…sh` と叩くと **`Permission denied` ではなく `command not found`** が返り、「配備されていない」と読み違える(実際にそう読まれた、2026-09-07)。`sudo sh …sh` なら動くので、そこで気づける。付け直すのは `host-setup.sh --fix`(標準入力から流し込まれるので、それ自体は実行ビットが要らない) |
| **compose のサービス名を決め打ちする** | 逆プロキシのサービス名は `nginx` ではなく **`reverse-proxy`**(コンテナ名は `km-nginx-proxy`)。決め打ちで叩くと `service "nginx" is not running` としか出ず、**落ちているのか名前が違うのかが読み分けられない**。名前は `docker compose config --services` に聞く(2026-09-07) |
| **コンテナの中へ叩きに行って資格情報で弾かれる** | `mariadb-admin ping` は **Access denied** を返す。サーバーは生きているのに「駄目そう」に見えた。compose は既に healthcheck を持っているので、`docker inspect` の `.State.Health.Status` を読む方が正しい(2026-09-07) |
| **公開ホストのエラーログは SSH の総当たりで埋まる** | `journalctl -p err -n 40` の 40 件が**すべて sshd の preauth** だった。本当のエラーは画面の外。件数だけ出して中身は外す。入口の設定(`sshd -T` の `passwordauthentication`)は別に見る —— **総当たりは止められないが、当てられる入口は閉められる**(2026-09-07) |
| **自己検査は配備先でも走ることを忘れる** | `check.php` は `host-setup.sh` が **web コンテナの中から**呼ぶ。見えているのは `src/` だけで、`scripts/` も `Old/` も無い(配備が送らない)。そこを素で読む検査を足すと、**配備のたびに FAIL が並ぶ** —— 手元では全部通るので気づけない。毎回出る FAIL は読まれなくなり、**本物の失敗がその中に埋もれる**(利用者の指摘で判明、2026-09-05。12件出していた)。`src/` の外へ出るのは `km_check_repo_root()` 1箇所に集め、`self` の検査がその数を見張る。調べられないものは黙って飛ばさず **SKIP と書く** |
| **配備は消さないので、退役したファイルがホストに残る** | `deploy-to-host.ps1` は tar を展開するだけ。手元から外したファイルはホスト側に残り続け、自己検査が毎回 NG を出す(`src/Main/zoom.js` が実際にそうなった)。片付けは `host-setup.sh --fix` の「退役したファイル」に**名指しで**並べる —— パターンで消すと、書き間違えたときに生きているファイルまで巻き添えにする(2026-09-05) |
| **アプリが「線以外」でも繋いでいることを見落とす** | 経路のグラフを**保存された線だけ**で組んでいた。アプリ(`RouteSearch.kt`)は、同じ接続ID(`transferGroupId`)を持つ出入口と階段を、探索のたびに自分で結んでいる —— 屋外と 1F を繋ぐ線はどこにも無いので、Website だけ**外から中への案内が必ず失敗**していた。出入口の点は両側に描かれ、文言も普通に出るので、**地図を眺めても見えない**(利用者の指摘で判明、2026-09-05)。**片方だけ読んで移植しない** —— データの形が同じでも、繋ぎ方が同じとは限らない |
| **DB に在る列が、画面まで届いているとは限らない** | `transfer_group_id` は取り込みで書いており DB には在ったが、`km_map_data()` が SELECT していなかった。列を追いかけて「在る」で満足すると、**ブラウザに渡っていない**ことを見落とす。行き先まで辿ること(2026-09-05) |
| **設定のつまみを出しただけで、読む側を書いていない** | 表示調整の「文字」(`labelSize`)が半年そうだった。つまみは動くし表示も変わるし `localStorage` にも残る —— **読む所がどこにも無いだけ**。動かした本人は「効きが弱いのかな」と思うだけで、不具合として言い出しにくい(利用者の指摘で判明)。`check.php` が `DEFAULTS` の鍵を拾って `tuning.<鍵>` を読んでいるか見るようにした(2026-09-05) |
| **`php -S` は1本しか捌けない** | 同時に来た読み込みが `ERR_CONNECTION_RESET` で落ち、`app.js` が読まれないまま画面だけ出る。**押しても何も起きないので、書いた処理を疑う**ことになる。おかしいと思ったらまず console を見て、読み込みが落ちていないか確かめる(2026-09-05) |
| **`docker compose exec -T` が後続のスクリプトを飲み込む** | パイプで渡すときは `< /dev/null` を付ける |
| **Docker Desktop は SSH 越しだと資格情報ヘルパーが使えない** | `docker pull` が `A specified logon session does not exist` で失敗。`docker save` / `load` で持ち込む |
| **`dns1.onamae.com` に聞くと別の答えが返る** | 権威は `01〜04.dnsv.jp` |
| **書き込み成功の報告を鵜呑みにしない** | この文書自体、一度「作成した」と報告しながら**ディスクに存在しなかった**。作ったら `Test-Path` で確かめる |