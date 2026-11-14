# 現況

- **本番の証明書に webview.ito4.jp を足した**(11-09、利用者が配備 -Action up のあと host-cert.sh issue): 名前は admin・auth・html・ito4.jp・share・webview の 6 つ、期限 2027-02-06。読むだけで確かめた: webview.ito4.jp は 404(枠のファイルは 11-7 まで本番に無い)・/editor/・html・auth は 200・reverse-proxy に KM_WEBVIEW_DOMAIN・web に KM_WEBVIEW_URL・nginx の emerg/crit は 0
**この文書の形**(2026-11-07 に組み直した。利用者の指示「構造がバラバラなので整理」):
1. **いま** —— 本番の実測の表(ここが正)
2. **残っていること** —— 次にやること(中身は [plan.md](plan.md) の「次のプラン」)・保留・抹消・配備待ち
3. **記録** —— 月ごと・新しい順。1 件 = `### 日付 — 題`。**新しい記録はその月の先頭に足す**(箇条の途中や古い節の下に差し込まない)
4. **2026-09-02 時点の記録** —— それより前の実測(ドメインは旧 `ito8795.com`)

## いま(2026-11-07 に本番で実測。読むだけ)

**§1 以降は 2026-09-02 の記録**(ドメインは旧 `ito8795.com` のまま書いてある)。**いまの値はこの節が正**。

| 項目 | 実測(2026-11-07) |
|---|---|
| 公開名 | `https://ito4.jp` / 管理画面は別オリジン `https://admin.ito4.jp` / サインイン `https://auth.ito4.jp`(443 番。10-19)/ HTML 展開 `html.ito4.jp`・共有 `share.ito4.jp`(11-05)。旧 `ito8795.com` は DNS・証明書を撤去し、**11-07 に解約**([14](14-domain-ito4.ipynb)) |
| audience(API リソース) | `https://ito4.jp/api` |
| サービス | 8 個(`reverse-proxy web postgres logto mailserver certbot mariadb glances`)。glances 以外は healthy。**phpMyAdmin はふだん止めてあり、トンネル開通SSH.cmd の合図の間だけ動く**(11-07)。Soketi は 11-02 に撤去、Mailpit は本番では使わない |
| イメージ | nginx `1-alpine`・certbot `latest`・postfix `latest-debian`(5.2.1)・Logto **1.46.0**・MariaDB 11.4・Postgres 17・phpMyAdmin 5.2.3。**どれもタグ + digest で固定**(11-06)。週 1 回 Trivy(日曜 4:47)、片付け(日曜 5:47) |
| 証明書 | `ito4.jp`(SAN に `admin`・`auth`・`html`・`share`)—— **2027-02-03 まで**。CAA は `0 issue "letsencrypt.org"` |
| ホスト | Ubuntu 24.04・カーネル **6.8.0-149.149**(11-07 から。11-11 18:18 に利用者が再起動)。**CVE-2026-98432 は直った**(11-11 に Ubuntu の頁が「noble: Fixed in 6.8.0-149.149」になった)。つなぎ km-ipopt・MPTCP 0 はまだ入っている(外してよい。外すのは利用者)・Docker 29.9.2 |
| メモリ / ディスク | 1.9Gi 中 1.1Gi 使用(available 880Mi)/ 99G 中 37G(40%。イメージ 14.6GB・ビルドのキャッシュ 10.7GB → 日曜の片付けで減る見込み) |
| PHP | 8.4.27(10-22。RCE の直った版) |
| 地点 | `km_map_nodes` 904 行 |
| ホストに入る利用者 | **`kmops`**(配備・`%%host`・控え。docker あり・sudo なし・鍵 `km_ops`・転送は禁止)と **`km`**(sudo のみ・docker なし・鍵 `km_vps`・SOCKS のトンネル)。クラウド既定の `ubuntu` は退役([12](12-hardening-2026-09-15.ipynb) §7-4 B) |
| 週次の控え | この PC のタスクが `kmops` へ門番付きの鍵(`km_backup`)で入って取る(同 §7-4 A) |
| DB | `Main`・`Ito` とも `Kosen_map` だけ・TLS 必須・`172.30.2.%` から。`root@%` は無し。Logto は `logto_app`(SUPERUSER でない)(09-18 の値) |
| 認証 | MFA は admin(Totp・WebAuthn)・default(Totp・WebAuthn・BackupCode・EmailVerificationCode)とも **Mandatory**。パスワード方針は両方とも 12 文字以上・流出パスワード拒否・利用者の情報と繰り返し・連番を拒否(11-07 に Logto の DB を読むだけで確認)。メールのテンプレートは 9 種類のうち **正本どおりは SignIn だけ**(残り 8 つは `scripts/logto-email-templates.php --apply` 待ち) |
| プロジェクト状況 | plan.md の段 1〜6 と保留 3 つの 17 件(11-07 に登録。Id 91〜107)。**進み具合の正**。段 7 以降もここに置く(分類つき)。手元から `scripts/proj-tasks.ps1`(鍵 `KM_PROJ_PASSKEY`)で読み書きする |
| 地図のデータ(段 2) | 英語の名前・読み 0 件 / 階段の向き 0 件(55 地点) / 色分け 0 階(17 階)(11-07) |

## 残っていること(2026-11-07 に利用者と見直した)

**次にやること** —— 段ごとの中身・誰が・順番は [plan.md](plan.md) の「次のプラン」が正本。

| 段 | 何 |
|---|---|
| 1 | **完了**(11-07): DKIM の旧鍵を退避・Logto の MFA・パスワード方針・テンプレート 9 種類・メールの見え方 |
| 2 | 地図のデータ: 英語の名前・読み / 階段の向き / 場所の色分けの絵(areaPath) |
| 3 | **完了**(11-07): 試験だけで見ていない画面(版 55)・共有のリンクをアプリで開く(19:47 に Google の確認が通った)。Wi-Fi 測位の比べ・AR の実測は保留へ |
| 7 | 実機で見つけたものの続き: 英語化の残り(#109)は **版 56 で完了**・「教室」からトイレを外す(#110)はアプリ済み・Web は配備待ち |
| 4 | **完了**(11-07): 本番の Web 地図の速さの測り直し・案内チャットの確かめ(AI の答えとカード・403・家の PC が止まったとき・無い店) |
| 5 | **完了**(11-08): 検証機 192.168.3.31(Hyper-V の VM)を本番と同じ形にした(コード・イメージ・cron・DB と Logto の利用者・方針)。入れ直しはデスクトップの `検証機を本番と同じに.cmd`。プロジェクト状況 #103 は消した |
| 6 | ストリートビュー(360°)を Website へ: **11-07 に作った・配備待ち**(WebGL・撮りそろうまで管理者だけ)。残りは利用者が撮る(本番の写真は 0 枚) |
| 9 | **完了**(11-08): Web Code Editor(`/editor/`)を配備・QR のリンク集に入口・スマホの幅も利用者が確認。優先度 4 は段 10 |
| 11 | **完了**(11-11 までに本番へ): VS Code 型のエディタ `https://ito4.jp/code/`(ブラウザの中だけ・拡張機能は同梱だけ・枠は `webview.ito4.jp`・プレビューは別の窓・同期は /editor/ と共通・「このエディタについて」・規約 第8条の3)と、11-8 の拡張機能(Path IntelliSense・リンクされた編集・括弧の色・自作の「Web の検査」)。中身は plan.md の段 11 |
| 10 | **完了**(11-08): Web Code Editor 優先度 4。10-A(複数ファイル・プロジェクト・ZIP・読み込み・ショートカット・配色)と 10-B(サインインしてサーバーへ同期・規約 第8条の3)を本番へ配備 |
| 7〜 | **管理画面「プロジェクト状況」に置く**(11-07、利用者の指示。分類つき。plan.md には段の見出しと設計だけ書く) |

**配備待ち**(手元で済み): **JS の候補の MDN の説明を外す・/code/ の書き換えを組み立ての前へ・コンテナでの自己検査(`editor-src/`・`code-src/`・`src/editor`・`src/code`・`src/scripts/check.php`・`src/lib/search-aliases.php`)**・**地図の埋め込み(段 15。nginx を変えたので `-Action restart -Services reverse-proxy`。`lib/map-embed.php`・`embed/`・`Main/embed.*`・`api/embed-places.php`・`admin/embed-sites.php`・横の一覧・訳・記録の文・`nginx/default.conf.template`・`scripts/local-sanitize.tables`。配備のあと管理画面で html.ito4.jp と share.ito4.jp を登録)**・エディタの雛形の直し(段 14-5。`lib/editor-snippets.php`・`admin/editor-snippets.php`・`editor-src/`・`code-src/`。**配備だけで本番の `<<a` が直る**。そのあと管理画面で v3.json を取り込む)・プロジェクト状況の番号の列と並び(`admin/projects.php`・`lib/tasks.php`)・段 6 ストリートビューを Website へ(`Main/panorama-viewer.js`・`lib/panorama.php`・`lib/map-data.php`・`Main/app.js`・`Main/i18n.js`・`Main/styles.css`・`index.php`・`admin/map-editor.php`・`admin/assets/js/map-editor.js`・`admin/map-settings.php`・`lib/admin-log.php`)・建物の「中へ」が 2 度出る(萩友会館など)・建物の階の呼び名・案内のカードの階(`Main/app.js`・`Main/user-history.js`・`lib/guide-chat.php`)・寮食堂に萩友会館の階が出る(`lib/building-floors.php`。アプリは版 57 で公開済み)。「教室」からトイレを外す(段 7-2)・案内の例のボタンの崩れ(段 4-2)は 11-07 夜に配備済み(本番の app.js・styles.css で確認)。それより前のものは 11-07 にすべて配備済み(イベントの地点の倍率・置き去り 16 個の退避・読み・別名の CSV・`api/area-paint.php`・階段の向きを下の階から埋める・色分けの画面の 404・国の拒否で Google を 443 だけ通す(`google.allowed: true`)・プロジェクト状況の口と鍵・Logto のテンプレートの台本)。
**進み具合はプロジェクト状況が正**(`scripts/proj-tasks.ps1 list`)。

**保留**: いまは無し(11-12。カーネル・Web の画面での確かめは 11-11 に済み、Wi-Fi 測位の比べは打ち切り、AR の実測は段 13 へ)

**抹消**(利用者、11-07。plan.md「やらないと決めたこと」): Play ストアへの公開申請・教職員の申請の Teams 通知。

**閉じたもの**: 09-18 時点の残り([12-hardening-2026-09-15](12-hardening-2026-09-15.ipynb) §0 の #2 Android の配布・#15 旧ドメインの解約)は 11-07 までに済んだ。

---

## 記録 2026 年 11 月(新しい順)

### 2026-11-14 — JS の候補の MDN の説明を外した・コンテナの中の自己検査の FAIL 92 件・GitHub の控えが 11-08 から止まっていた(手元で済み・検証機で確かめた・**配備待ち**)

利用者(11-14)「host-setup.ps1 で FAIL」「秘密の検査に当たりました」「本番で確認しましたが JS で、MDN のコメントが出ています。消してください」。

- **JS の MDN の説明**: 候補とカーソルを合わせたときの説明は、TypeScript の型の定義(lib.dom.d.ts・lib.webworker.d.ts)の注釈から来る。MDN から来たかたまり(「[MDN Reference](」を含むもの・要約の書き方「**`名前`**」を含むもの)を組み立てで外す。`@deprecated` は残す(打ち消し線のため)・仕様から来た説明は残す。関数は 1 つ(`editor-src/build/strip-mdn-docs.js`)で、/editor/ は monaco の lib.js を読み込んだ値から作り直し(7,192 個)、/code/ は付属の型の定義を書き換える(lib.dom 5,923・lib.webworker 1,977・esnext.intl 12)。リンクの無い要約(Document・DocumentFragment の getElementById)は検証機で見つけて足した。検証機の Chrome の /code/ で `document.getElementBy` の候補に説明が出ないこと、/editor/ の ts.worker に MDN の文が 0 件なのを確かめた
- **/code/ の書き換えを組み立ての前へ**(11-13 の HTML・CSS も): 出来上がったあとで部品の名前を替えていたので、指している workbench の中身が変わっても名前が変わらなかった(BTJat3r1 のまま)。前の workbench を覚えているブラウザが、もう無い古い名前の部品を取りに行くおそれがあった。いまは写しを `code-src/node_modules/.km-patched/` に作り、拡張機能の index.js の URL をそちらへ向け直す(size も直す)→ workbench の名前も変わる(CDUKObmD → BO5iAUbg)
- **host-setup の自己検査**: コンテナの中では FAIL が **92 件**あった(host-setup.sh は先頭 20 行しか出さないので、見えていたのは一部)。原因は 2 つ: (1) 作業ツリーにしか無いファイル(scripts/・nginx/・compose.yaml・guide-llm/・editor-src/…)を読んでいた → `km_check_repo_file()` で読み、無ければその節の FAIL を「手元の作業ツリーで確認する」の SKIP に(手元では作業ツリーがあるので今までどおり FAIL になる)。src/… はコンテナでも src から読む。(2) **本番の web コンテナに intl が無い** → 読み・別名の重なりを全角と半角で同じと見なせていなかった(`ＡＢＣ` と `abc` が両方残る)。Normalizer が無ければ `mb_convert_kana(…, 'asKV')` で見る(map-node-projects.php と同じ)。検証機のコンテナで 2,287 件通過・90 件 SKIP・FAIL 0
- **GitHub の控え**: /code/ の組み立て(`src/code/assets`。67MB・633 ファイル)が 11-08 から写しに入るようになり、圧縮した部品の `password: "…"` の形で秘密の検査に当たって、**11-08 のあとの push が全部止まっていた**。写し(`tools/sync-from-website.ps1`)と控えの `.gitignore` から外した(殻の index.php・preview.php は入れる)。status.md の Cloudflare の番地は言葉に替えた。検査を通して push した(66c68df..64966c3)
- 試験: check.php 3,242 件(手元)・editor.test.js 300 件
### 2026-11-14 — 段 15 地図を別のサイトに埋め込む(手元で済み・検証機で確かめた・**配備待ち**)

利用者(11-14)「マップの API を作成してください。別のサイトで表示されるような埋め込み方を想定してください」。決めたこと: 登録したサイトだけ・v1 は見る / 階 / 地点を指す / 押すと名前・JSON の口も作る・最初の埋め込み先は HTML 展開サイト。中身は [plan.md](plan.md) の段 15。

- **作った**: `/embed/`(iframe の地図。Leaflet・来場者と同じ地図をセッションなしで読み、氏名・メモ・経路は出さない)・`/embed/v1.js`(`KosenMap.embed()`。postMessage は相手のオリジンを決め打ち)・`/api/embed-places.php`(登録したオリジンにだけ CORS)・管理画面「地図の埋め込み」(登録・直す・止める・貼るコード・30 日の回数)・表 `km_embed_sites`・`km_embed_views_daily`(新しく作るだけ)。nginx の `/embed/` は X-Frame-Options を出さず、PHP が札ごとに `frame-ancestors` を出す
- **検証機の Chrome で確かめた**(html.<検証機> のオリジンから): 地図が出る・`focus`・`select`・`floor`・階を替えると指した輪が消える・JSON を読める。オリジンを登録から外すと**枠はブラウザが止め**、JSON も読めない。地点 426 件に氏名・研究室は無い
- **途中で直した**: 引いた倍率で名前が重なって読めない → 1 段拡大するまでは触れた印の名前だけ / 階を替えても赤い輪が残る / 最初の `floor` の知らせが `ready` より先に届く / 記録の文言を変数で組んでいて check.php の「すべての操作に文言」に掛かった / 貼るコードの見本の `<script` を check.php が nonce 無しの台本と見た
- 試験: check.php に段 15 の 13 項目(3239 件すべて通過)・editor.test.js 297 件。plan.md の段 14-5 の最後の行に段 15 の見出しがつながっていたのも直した
### 2026-11-14 — 段 14-5 雛形 JSON(v2)の点検と取り込みの直し(手元で済み・検証機で確かめた・**配備待ち**)

- 利用者「スニペット集の JSON を作成したので、不足分や説明不足などを確認・修正してください」→ 決定(AskUserQuestion): プロパティ 1 つの 506 個は雛形から外す・廃止タグは外す・説明はこちらが書く
- **本番で見つけたこと(読むだけ)**: 本番には 11-13 12:50 に v2 が取り込まれていて、HTML の body が `<` で始まるため、いま `<a` を選ぶと `<<a` になる。css 641 個は取り込みのときに捨てられていた
- **取り込み**(`lib/editor-snippets.php`・`editor-src/src/data/snippets.js`): 先頭の `<` を外す(**読むときも**外す → 配備だけで本番の `<<a` が直る)・`css` を受ける・知らない項目は捨てて知らせる・上限 1MB / CSS 1,000 個。表は変えない(CSS の数は中身から数える)。管理画面に CSS の数と取り込みの知らせ(1 回だけ)
- **CSS の雛形の候補**: /editor/(`languages.js`)と /code/(`jaExtension.js`)の CSS と `<style>` の中。出すのはルール・プロパティの書き始め(`{` `}` `;` の後)だけ
- **説明**: `editor-src/src/data/css-more.js` に CSS のプロパティの日本語の説明 281 個(自分で書いた)。`CSS_JA` は 167 → 448 個
- **`Downloads\v3.json`**(v2 は触らない)と `v3-changes.txt`: 先頭の `<` 286 個・廃止タグ 25 個・プロパティ 1 つの 506 個を外した・`${1(…)}` の壊れた入力欄 5 個・重なり 3 個・`wbr`・`&` で始まる 32 個をふつうのセレクタへ・CSS の説明 17 個・JS の説明 13 個。HTML 144・144・CSS 132・JS 270・147KB
- **確かめた**: check.php 3225 件・editor.test.js 297 件。検証機の Chrome: 管理画面で v2 を取り込むと「< を 336 個外しました」・CSS 641 個 → v3 を取り込むと HTML 144・CSS 132。/editor/ で `<a` →「a リンク」が `<a href=…>`(二重にならない)・`<style>` の中で `disp` に「display flex」などが出る。/code/ の CSS でも出る。打った文字は戻した。**検証機には v3 を入れたまま**
- 検証機の自己検査で 3 件 NG(assetlinks・案内の nginx の zone・`KM_GUIDE_LLM_URL`)。今回は nginx・compose を触っていない(検証機に `.env.example` が無いなどで、前からのもの)
### 2026-11-13 — 訪問者の Cloudflare が 11-08 から取り込めていなかった(cron 版 15。**11-13 本番で済み**)

- 利用者「訪問者で Cloudflare からデータが取得されていないようです」
- **見たこと(本番を読むだけ)**: `km_cf_import_state` の最後の成功は 11-08 12:53(手で流した 1 回)・その後は成功も失敗も 1 つも無い・WAF 29 件・通信量 3 時間のまま
- **原因**: cron(版 14)の行が `--label Cloudflare の取り込み --only-failure …`。cron の行は引用しないので `の取り込み` が別の引数になり、`send-log.sh` が「知らない引数」で exit 2 → 取り込みの台本まで行かない。出力は root だけが読む `cloudflare.log` に入るだけで、メールも画面の「失敗」も出なかった
- **直したもの**: `scripts/host-updates-setup.sh` の `--label` を `Cloudflare取り込み` に・`CRON_VERSION=15`(古いホストの cron を書き直す合図)。check.php に「cron の `--label` に空白が無い」を足した(直す前の行なら落ちるのを確かめた)。docs/17 §6 の手順を版 15 に
- **取り戻せるもの**: WAF の記録は Cloudflare が 31 日持つので、11-08 からの分は次の 1 回で全部入る。時間ごとの通信量は Cloudflare が約 3 日しか持たないので、**11-08 12 時〜11-10 夜ごろの分は取れない**(台本は持っている範囲だけを頼むので、断られはしない)
- 試験: check.php 3221 件が通過
- **本番(11-13、利用者が配備・km で `--fix` と手で 1 回)**: cron は版 15・`--label Cloudflare取り込み`。手の 1 回は「問い合わせ 3 回」で成功(22:27)→ WAF 8175 件(11-08 10:53〜11-13 22:11。止まっていた間の分も入った)(日ごと 406・3298・1355・1854・443・819 件)・通信量 74 時間分。**抜けは 11-08 13 時〜11-10 22 時**(Cloudflare が約 3 日しか持たない分。思ったとおり)と 11-13 8 時の 1 時間(Cloudflare の答えにその時間が無かった)
- **cron がひとりでに動いた**: 23:13:04 に成功(問い合わせ 2 回・WAF 8178 件・通信量 75 時間分)
### 2026-11-13 — 段 14-2〜14-4 CSS の候補の説明・ホバーの英語の説明・雛形の JSON の取り込み(**11-13 本番で済み**)

- 利用者「CSS の候補も外す、カーソルを合わせたときの英語の説明は設定だけで消す。雛形の JSON 管理は、管理画面の取り込みだけ作る。デプロイ終わっているので作業を開始」
- **14-1 は本番に出ていた**(読むだけ): `htmlServerMain-d27FPn2I.js` が 1 つで書き換えた形・workbench がそれを指す・`/code/` 200・雛形に title・前の組み立ては `retired/code-assets/` に
- **14-2 CSS の候補の説明**: 同じ部品(`km-html-no-completion-docs`)を広げ、CSS の言語サーバーと、HTML の言語サーバーの中の CSS の説明の入口(設定なしで呼ばれたら作らない)を書き換える。どれも 1 か所ちょうどでなければ組み立てを止める
- **14-3 ホバー**: `html`・`css`・`scss`・`less` の `hover.documentation`・`hover.references` を false(日本語の画面だけ。英語の画面では残す)
- **14-4 雛形の JSON の取り込み**:
  - 形(version 1): `{ "version": 1, "html": { "ja": [{tag,label,body}], "en": […] }, "js": [{label,body,ja,en}] }`。HTML の body は先頭の `<` を書かない
  - `editor-src/src/data/snippets.js`: 同梱の雛形を JSON にする・形を確かめる・口の答えを当てはめる・起動のときに読む(待たない・4 秒で切る・届かなければ同梱)。/editor/(`languages.js`)と /code/(`jaExtension.js`)は候補を出すたびにここを読む
  - `src/lib/editor-snippets.php`: 形の確かめ(サーバーとエディタで同じ上限: 300 個・tag 40・label 80・body 2000・説明 400 字・ファイル 256KB)・新しい表 `km_editor_snippets`(取り込むたび 1 行。「同梱に戻す」も 1 行。新しいほうから 20 行残す)・読むときにも確かめる
  - `src/api/editor-snippets.php`: GET だけ・何も受け取らない・DB が読めなければ `custom: false`
  - `src/admin/editor-snippets.php`(公開ページ →「エディタの雛形」): いまの状態・書き出し 2 つ・取り込み・同梱に戻す。CSRF を先に見る・記録に残す(`editor-snippets.import`・`.reset`)
  - `src/lib/editor-snippets.default.json`: 同梱の雛形の JSON(管理画面の「同梱の雛形を書き出す」が返す。editor.test.js が data/html.js・js.js と同じ中身かを見る。**雛形のデータを直したら、この JSON も作り直す**)
  - 検証機の台帳 `scripts/local-sanitize.tables` に `km_editor_snippets keep`
- **確かめた(検証機の Chrome)**: 口は `custom:false`・POST は 405・`/lib/…default.json` は直には 404。管理画面で形の違う JSON は断られて何も変わらない → 正しい JSON(「検証のリンク」を足したもの)を取り込むと 28 個になり、`/code/` の `<a` の候補に「検証のリンク」が出る → 同梱に戻すと 27 個・口は `custom:false`。CSS の `disp` の候補に説明の欄が出ない・`<head>` にカーソルを合わせると日本語の説明だけ。打った文字は戻した。**/editor/ の画面では見ていない**(同じ部品を読む。試験と組み立てで確かめた)
- 試験: check.php 3220 件・editor.test.js 290 件が通過
- **本番(11-13、利用者が配備・読んで確かめた)**: 口は `custom:false`・POST は 405・`default.json` は直には 404・`/code/` 200・`cssServerMain-KcKeC6UY.js` と `htmlServerMain-HcmBYS8Q.js` が書き換えた形(HTML・CSS の説明の入口とも)・管理画面「エディタの雛形」200(利用者が開いた)・PHP の失敗の記録なし・`lib/guide-chat.php` の ngrok のヘッダも外れた。プロジェクト状況 #134 は外した
### 2026-11-13 — 段 14-1 /code/ の HTML の候補から英語の説明(MDN)を外した(手元で済み・検証機で確かめた・**配備待ち**)

- 利用者「HTML の `<a` を入力したときのいらないコメント(Mozilla のコメント)はどうしても削除できませんか」。**初めは別のもの(行の右に出る赤い文)と取り違えた** → 利用者が「Mozilla のコメントです」と教えてくれた
- **原因**: 付属の HTML の言語サーバーは、候補の説明を設定を見ずに作る(カーソルを合わせたときは `html.hover.*` を見る)。設定では消せない
- **直し方**: `code-src/vite.config.js` の `km-html-no-completion-docs`。組み立てた `htmlServerMain-*.js` の説明を作る関数を「設定が無ければ作らない」に書き換え、中身のハッシュで名前を付け直す。見つからなければ組み立てを止める。Rolldown は出力の表への代入を受けない(無視されて部品が消えた)ので、消して `emitFile` で出し直す形にした
- **ついでに直した**(取り違えて見つけたもの): 打ちかけの `<a` に HTMLHint の spec-char-escape が赤い文を出す → 既定で外した(`.htmlhintrc.json` で戻せる)。はじめのプロジェクトの雛形に `<title>` が無く、開いた直後から誤りが 1 件出ていた → 足した
- **確かめた(検証機の Chrome)**: `<a` で付属の候補(a・abbr・address…)と自作の雛形が出る・候補の説明の欄に英語の文が出ない・赤い文が出ない。内蔵のブラウザは検証機の拡張機能の部品を読めず(extensionHost.worker の取得に失敗)、付属の候補が出ないので確かめに使えない
- 試験: check.php 3208 件・editor.test.js 284 件が通過
- 本番へ出す手順・戻し方・残り(CSS の説明・カーソルを合わせたときの説明・雛形の JSON 管理)は plan.md の段 14
### 2026-11-12 — Android の組み立てを 1 本の台本に(利用者「ビルドを楽にするため、PS1 を作成」)

- `Test\scripts\build-release.ps1`: JDK を探す(gradle.properties が無い JDK を指していたら直し方を出す)→ 組み立てのフォルダの読み取り専用の印を外す → 単体試験(一般・管理。件数は結果の XML から数え、0 件や失敗なら止める)→ 前の版を `Old\release-v<版>-<日付>` へ写す → リリース版 2 つ → 版と署名の確かめ(2 つが同じ版・前より大きい・署名の鍵が前と同じ)。Gradle は `--no-daemon`
- オプション: `-Push`(apk-push.ps1 で受け箱へ。Git 付属の ssh が動かないときのために Windows の ssh を渡す)・`-PushOnly`・`-CheckOnly`(今ある APK を確かめるだけ)・`-DebugOnly`(試験とデバッグ版。版は上がらない)・`-SkipTests`・`-Help`
- 確かめた: `-CheckOnly`(版 58・署名そろう)・`-DebugOnly`(試験 960 件 × 2・デバッグ版 2 つ。2 分 13 秒)・`-DebugOnly -Push` は断る。**リリース版を作る道(写す → 組み立て → 比べる)と `-Push` は、この台本ではまだ流していない**(版を無駄に上げないため。同じ手順は同じ日に手で通した)。古い `build-beta.ps1` は残した

### 2026-11-12 — Wi-Fi 測位の比べは打ち切り・AR と 360° を 1 つに・学内の 3D を計画に・アプリから RTT の表示を外した(版 58)

- 利用者の指示: 「#99 は削除。理由: 十分な精度を得られたから」「#100・#104 は合わせる」「AR の画像などは学内全域の点群(3D)+画像でもらえそうなので、学内 3D で Wi-Fi の精度向上も計画に」「360° は Insta360 X5 を使う予定」「RTT は AP305C で非対応なので表示は削除」
- **プロジェクト状況**: #99(Wi-Fi 測位の比べ)は消した(十分な精度が出たので比べは要らない)。#100(AR の実測)は #104 に合わせて「ストリートビュー(360°)と AR の素材」(段 13)に。新しく「学内の 3D で Wi-Fi の測位の精度を上げる」(段 13)。保留の欄から Wi-Fi 測位の比べ・AR の実測を外した
- **計画**: plan.md に段 13(学内の 3D を使う)。データをもらってから形式・座標の合わせ方を決める
- **アプリ(版 58・一般と管理)**: 学内の AP(AP305C)は 802.11mc に応じないので、RTT の表示を外した
  - 計算方法の選択肢から「Wi-Fi RTT」(保存してあった端末はハイブリッド法に戻る)
  - 測位の診断の「Wi-Fi RTT: …」の行
  - 比べの構成 G(Wi-Fi RTT)。記号は詰めない(前の記録・CSV の G と混ざらないように)
  - 測位のパラメータの画面の「Wi-Fi RTT」の部分(一覧に出さないだけ。値の表と配る JSON はサーバーと項目をそろえるため残した)
  - **測る仕組みそのものは残した**(応じる AP が無ければ何もしない。対応した AP に替わったら表示を戻せば使える)
- 試験: 一般・管理とも 960 件が通過。リリース版 58(内部の番号 1000058)・署名は前の版と同じ(d71fbf0a…)。前の版 57 は `Test\Old\release-v57-20261112` へ写した。受け箱へ送った(検めに通れば自動で公開)
- **はまった所**: `Test\app\build` のフォルダ 2175 個に読み取り専用の印が付いていて、Gradle(Java)がフォルダを消せず AccessDenied で落ちた → `attrib -R /S /D` で外した。既存の Gradle のデーモンは使わず `--no-daemon` で流した。apk-push は Git 付属の ssh がこの環境で動かないので `GIT_SSH` に Windows の ssh を渡した
### 2026-11-12 — 段 12 案内AIのつなぎを ngrok から Cloudflare Tunnel へ(**完了**。利用者が管理画面で「届きました(0.1 秒・unsloth:…gemma-4-26B…)」を確認)

- 利用者(11-11)「トンネルを経由する方針で行きましょう」。計画は plan.md の段 12、手順は docs/17 §7
- **`guide-llm/start-guide-llm.ps1`**: `guide-llm.env` に `CLOUDFLARE_TUNNEL`(UUID)があれば cloudflared で出す。設定ファイルは一時フォルダに作り(秘密は入らない)、行き先は `CLOUDFLARE_HOSTNAME` → 門番と「それ以外は 404」。`cloudflared tunnel --config … --protocol … run`。止めたら門番も止め、設定ファイルを消す。UUID・ホスト名・つなぎ方は形を確かめてから書く。トンネルの鍵(`%USERPROFILE%\.cloudflared\<UUID>.json`)は在るかだけ見る(中身は読まない)。`-NoNgrok` は `-NoTunnel` に(古い名前も受ける)
- **ngrok の分岐は残した**(`CLOUDFLARE_TUNNEL` が無く `NGROK_DOMAIN` があれば今までどおり)。cloudflared で確かめが済んだら消す(`ngrok-skip-browser-warning` の行も一緒に)
- **`lib/guide-chat.php`**: 門番への呼び出しに `User-Agent: KosenMap-Guide/1`(PHP の curl は既定で付けない。Cloudflare の Browser Integrity Check は無い要求を断る。いまは切ってあるが、入れても止まらないように)
- 見本 `guide-llm.env.example` に 3 つ(値なし)・注記と管理画面の見出し(「+ ngrok」→「+ トンネル」)・docs/17 に §7(作り方・WAF のルール 2 つ・確かめ方・戻し方・本番から届くかを見るセル)。**本番サーバーの IP は文書に書かない**
- **確かめたこと**: 台本の構文・`-Help`・設定ファイルの中身(台本から抜き出して動かした)・形の確かめ(UUID でない名前・改行入りのホスト名を断る)。check.php 3205 件が通過
- **まだ確かめていないこと**: **cloudflared そのものは動かしていない**(この PC に入っていない。入れるのは利用者)。つながるか・Norton が間に入るか・設定ファイルを cloudflared が受けるかは、利用者が入れたあとに見る
- 利用者がすること(docs/17 §7-1): cloudflared を入れる → login → create → route dns → `guide-llm.env` → WAF のルール 2 つ → 本番の `.env` の `KM_GUIDE_LLM_URL` を替えて `-Action up` で配備### 2026-11-11 — カーネルの CVE が閉じた・Web の画面での確かめ・英語の札を直した(利用者「カーネルの直った版へ上げて再起動しました。Web の画面での確かめを行ってください」)

- **カーネル(CVE-2026-98432)**: 本番は 11-07 から 6.8.0-149.149(11-11 18:18 に利用者が再起動。新しく入った版は無い)。**11-11 に Ubuntu の頁が「noble: Fixed in 6.8.0-149.149」になった**(11-07 までは「作業中」だった。changelog の「ipv4: free inet_opt and ireq_opt after an RCU grace period」がその直し)。再起動のあと 8 サービスとも動いている・更新待ちは distro-info の 2 つだけ。つなぎ(`km-ipopt.service`・`/etc/km-ipopt.nft`・`net.mptcp.enabled=0`)はまだ入っている。外してよい(外すのは利用者。コマンドは返事に書いた)。プロジェクト状況 #105 は消した
- **段 11-8 は本番に出ていた**(読むだけ): `/code/` 200・ESLint の部品と Path Intellisense のファイルがある。#130 は消した
- **Web の画面での確かめ**(保留だった 4 つ。#107 は消した):
  - ログインしたまま配備してログインが残るか: 本番は再起動のあとに入り直したセッションしか無く判定できない → **検証機で web のコンテナを作り直し(`up -d --force-recreate web`)、同じ Cookie のセッション(CSRF の札)が残る**のを確かめた。本番も同じボリューム `km_web_sessions` を `/var/lib/km-sessions` に付けている(www-data・700)
  - 色分けの塗り: 本番の地図に塗りが出ている(Chrome)。検証機の管理画面「地図の色分け」で 1F を作り直せた(区画 77・5・3、見本が出る)。17 階とも「アプリの絵・11-07」で塗ってある
  - 英語の名前・読み: 管理画面「地点の読み・別名・URL」に入っている(検証機は本番の 11-08 の写し)。本番の地図を English にすると名前が英語になり、「Stairs」で英語の名前が探せる。表示は日本語に戻した
  - 札・QR のカメラ: Web にカメラの読み取りは無い(アプリの項目。11-07 に実機で確かめ済み)
- **見つけて直した(配備待ち。#131)**:
  - 英語の地図で建物の札がどれも「Buildi…」になり見分けられない(札は 7 文字以上を 6 文字 + … に縮める決まり)→ 半角は 0.5 文字と数える(`Main/app.js` の `shortPoiName`。「Building 1」はそのまま・全角の名前は今までどおり)
  - 英語の画面に日本語のまま残っていた文を辞書に足した(`Main/i18n.js`): 案内の例 2 つと「いちばん確実」・「苦手な質問」とその中身・テスト用アカウントの札の説明・操作方法の既定の 6 行。i18n.test.js が「案内の例・苦手な質問・操作方法の既定の文は全部訳がある」を見る
  - 試験: check.php 3202 件・i18n.test.js 43 件が通過。検証機の Chrome で英語の札が「Facility D03…」(半角 12 文字)まで出るのを確かめた(案内とテストの札は検証機のアカウントでは出ないので、辞書の試験で見た)
- **案内AIのつなぎを ngrok から Cloudflare Tunnel へ**(利用者の案): 勧めた(返事に理由と形)。まだ作っていない
- **11-12 夕方: 利用者が cloudflared 2026.10.7 を入れ、login・create(kosenmap-guide)・route dns(llm.ito4.jp)・配備まで済ませた。** こちらで `guide-llm.env` に UUID と名前を入れ、LLM を起こさずに cloudflared だけ 12 秒起こして確かめた: 設定ファイルは `ingress validate` が OK・**QUIC で 4 本つながった(成田。Norton は邪魔しない)**・この PC からは 403(WAF が止める)・**本番の web からは 502(トンネルまで届き、門番が居ないだけ)**。本番の `KM_GUIDE_LLM_URL` は `https://llm.ito4.jp`・User-Agent と英語の札の直しも出ている。**残り**: 利用者が Unsloth を起こして `start-guide-llm.ps1` を動かしたときに、本番から 200 と管理画面「届くか確かめる」を見る → 問題なければ ngrok の分岐を消す
- **11-12 夜: 通しで届いた**(利用者が Unsloth と台本を起こし、管理画面「届くか確かめる」が 0.1 秒で OK)。**ngrok の分岐を消した**: 台本の分岐と `NGROK_DOMAIN`・見本の行・PHP の `ngrok-skip-browser-warning`・注記。check.php は「ngrok を呼ばない」を見る(3205 件が通過)。利用者の `guide-llm.env` の `NGROK_DOMAIN` の行は残っているが、もう読まない。PHP のヘッダを外した分は次の配備で出る(急がない)。プロジェクト状況 #132 は消した
### 2026-11-09 — 段 11-8 /code/ に拡張機能を足した(利用者「HTMLHint、Auto Rename Tag、Path Intellisense、IntelliSense for CSS class names in HTML、Bracket Pair Colorization、HTML CSS Support、ESLint、を追加できますか？」。検証機だけ・**配備待ち**)

- **調べたこと**: 7 つのうち Web 版の入口(`browser`)があるのは Path Intellisense だけ。HTMLHint・Auto Rename Tag・CSS class names(Zignd)・HTML CSS Support・ESLint は Node 専用。Bracket Pair Colorizer は作者が廃止(VS Code に入った)。利用者が 4 つの案をすべて選んだ
- **Path Intellisense 2.8.0**: Open VSX の sha256 と一致・認証済みの発行者・ライセンスは LICENSE.txt の MIT。Web 版の入口(1 ファイル)に外への通信・eval・動的な読み込みなし。ロック(`extensions.lock.json`)と `VSIX_KEEP` に足した
- **VS Code の設定**: `editor.linkedEditing`(開きタグを直すと閉じタグも)・`editor.bracketPairColorization.enabled`・`editor.guides.bracketPairs: active`
- **自作の拡張機能「Web の検査」**(`code-src/src/webChecks.js`・純粋な部分は `webChecksCore.js`。拡張機能の欄でオフ・設定 `kmWebChecks.*` で 1 つずつ切れる):
  - HTMLHint 1.9.2: .html を検査。規則は既定 + プロジェクト直下の `.htmlhintrc.json`(`.htmlhintrc` は拡張子が無くプロジェクトに置けないため)
  - ESLint 10.14.0 の Linter: .js・.mjs・.cjs を `@eslint/js` の推奨の規則 + ブラウザの大域変数(globals 17.14.0)で。**利用者の eslint.config.js は実行しない**。使っていない変数は注意に・script の一番外は見ない(onclick から呼ぶため)・ほかの script の .js の一番外の名前は no-undef にしない。規則の名前から ESLint の説明へリンク
  - CSS のクラス名・id: プロジェクトの .css と `<style>` から集めて class="…"・id="…" で補完。どの CSS にも無いクラス名に注意(ほかのサイトの CSS を読む HTML・CSS が 1 つも無いプロジェクトでは出さない)
  - 部品は初めて要るときに読む(ESLint は 1.2MB)
- **ESLint をブラウザで動かすための手当て**(`vite.config.js` の `km-eslint-browser`。形が変われば組み立てを止める): 規則の設定の確かめ(ajv の compile が new Function を使う。CSP に 'unsafe-eval' が無い)を「いつも通す」に替えた(規則はこちらが決めた推奨のものだけ)・`process.env.TIMING` を外した・`node:path` を `src/shims/path.js` へ・esquery は CJS の版を読む(ESM の版は default だけで「r.parse is not a function」になった)。組み立てた ESLint に new Function が無いことを check.php が見る
- **Chrome で見つけて直した**: 起動の直後は `workspace.findFiles` が「Search provider not initialized」で落ちる → ファイルの一覧は `workspace.fs` で自分でたどる・失敗した集めは覚えない
- **確かめた(検証機の Chrome)**: HTMLHint(id の重複)・無いクラス名の注意・class="ca" で card・card-title が出る・`<p>` を `<pre>` に直すと閉じタグも・`src="./` で同じフォルダのファイルが出る・ESLint(no-undef・no-debugger・構文の誤り。lib.js の関数は no-undef にならない)が「問題」と Error Lens に出る・括弧の色・拡張機能の欄に「Path Intellisense」「Web の検査」・CSP と eval の誤りなし。試しのフォルダは消した
- 「このエディタについて」に Path Intellisense・ESLint・@eslint/js・globals・HTMLHint(権利者は LICENSE で確かめた)を足した
- 試験: check.php 3202 件・editor.test.js 283 件が通過。npm audit 0 件
- 検証機の自己検査(コンテナの中)の FAIL は、案内AIの compose・自宅 PC の台本・assetlinks などコンテナに無いファイルを見る項目で、今回の変更とは関係なし
### 2026-11-09 — 段 11-7 VS Code 型のエディタを本番へ(利用者が配備・**完了**)

- 利用者が配備。読むだけで確かめた: `/code/` と `preview.php` は 200・webview の 4 つのファイルは 200 でほかは 404・殻の中のスクリプトの sha256 がヘッダと一致(Cloudflare が書き換えていない)・CORS・規約の新しい文面。利用者も「全部OK」。プロジェクト状況 #125〜#129 は消した
### 2026-11-09 — 段 11-7 の準備: 本番にも /code/ を配る形にした(配備は利用者)

- `scripts/deploy-to-host.ps1`: 本番だけ `./src/code` を除外していたのをやめた。配る中身の見張り(mustContain)に `src/code/index.php`・`preview.php`・`.vite/manifest.json` を足した
- **/code/ の古い組み立ての退避**を足した(`retired/code-assets/`。前の 1 世代だけ残す。拡張機能の下のフォルダ `*.vsix/` も見て、空になったフォルダは消す)。/editor/ と同じ考え。**検証機では退避が無かったため 19 世代・367MB 溜まっていた** → この配備で 110 個を退避して `src/code` は 68MB
- nginx・compose・証明書は 11-09 の配備(`-Action up`)と `host-cert.sh issue` で済んでいるので、今回の配備は `-Backup -Yes` だけでよい
- 配備のあと利用者がすること: 管理画面「QR のリンク集」に `https://ito4.jp/code/` を足す
- 規約 第8条の3 の新しい文面(11-6)も、この配備で本番に出る
### 2026-11-09 — 段 11-6 VS Code 型のエディタの仕上げ(入口・ライセンスの表示・規約)(検証機だけ。**本番へは送らない**。11-7 で配備)

- **/editor/ の入口**: 帯に「VS Code 型で開く」(打ちかけを保存してから `/code/?p=<いまのプロジェクト>` を別のタブで)。殻は `/code/` を組み立てて置いてあるときだけ出す(本番は 11-7 まで無いので出ない)。狭い画面では出さない
- **QR のリンク集**: 管理画面「QR のリンク集」(DB)で管理しているので、**11-7 で /code/ を出したあと利用者が足す**(`https://ito4.jp/code/`)
- **このエディタについて**(`code-src/src/about.js`): 下の帯の右の ⓘ・コマンド「このエディタについて(ライセンス)」。読むだけの文書(`km-about:`)を Markdown のプレビューで見せる。部品(VS Code 1.145.2・monaco-vscode-api 39.0.3・日本語パック・Prettier の拡張機能 12.4.0 と Prettier 本体・Error Lens 3.30.0)・版・ライセンス(どれも MIT)・権利者と MIT の本文・「Microsoft の製品ではありません」。権利者は vsix の LICENSE で確かめた(npm の部品は LICENSE を同梱していないので年は書かない)。check.php が VS Code の版とロックの版を突き合わせる
- **規約 第8条の3**(利用者が 11-09 に文面を確かめた): 冒頭に「かんたんな画面 /editor/ と、VS Code 型の画面 /code/」・2 つは同じプロジェクトを扱う、を足し、「VS Code 型の画面は … Microsoft の製品ではありません。拡張機能は同梱したものだけ … 「このエディタについて」に示します」の項目を足し、プレビューの項目に「VS Code 型の画面の拡張機能」も別のアドレスの枠で動く、を足した。改定日は 2026-11-09。プライバシーポリシーは変えない(「Web Code Editor」が /code/ も含む)。**この文面は次の配備で本番に出る**ので、11-7(/code/ を出す配備)と一緒に出すのがよい
- **確かめた(検証機の Chrome)**: 「このエディタについて」の表示・/editor/ の「VS Code 型で開く」から同じプロジェクトの /code/ が開く・規約のページの文面と改定日
- 試験: check.php 3193 件(組 `code` 50 件)・editor.test.js 272 件が通過
### 2026-11-09 — 段 11-5 Open VSX の拡張機能を版と sha256 で固定・Prettier を絞る(検証機だけ。**本番へは送らない**。11-7 で配備)

- **固定**: `code-src/extensions/extensions.lock.json` に Prettier 12.4.0・Error Lens 3.30.0 の版・入手先・sha256・ライセンス(どちらも MIT)・確かめたことを書いた。11-09 に Open VSX が公表する sha256 ともう一度照合して一致。check.php が vsix の sha256・一覧・入手先をロックと突き合わせる(差し替えたら止まる)
- **Prettier を絞った(配る大きさ 14.4MB → 5.4MB)**: vsix の取り込み部品は Web 版の入口がある拡張機能のファイルを全部出す。Prettier の Web 版の入口 `dist/web-extension.cjs` は Prettier 本体と言語の部品を抱えた 1 ファイルで、ほかのファイルを読まない(動的な import・fetch・node_modules の参照なし)ので、`vite.config.js` の `trimVsix` で `node_modules/prettier`(8.5MB)・Node 用の `dist/extension.js`・試験・`.claude` などの登録と出力を外した。vsix そのものは変えない。Prettier 本体のライセンスの表示(`node_modules/prettier` の LICENSE と THIRD-PARTY-NOTICES)は残した
- **確かめた(検証機の Chrome)**: CSS を Shift+Alt+F で Prettier が整形(右下に Prettier)・Error Lens の表示。整形は元に戻した
- **Code Spell Checker**: Open VSX で「spell checker」の上位 25 件を調べ直したが、Web 版の入口(`browser`)を持つ綴りの検査は無かった(Code Spell Checker・Harper・typos・LTeX・Spell Right はどれも Node か別のプログラムが要る)。**利用者が「入れない」と決めた**(11-09。自作もしない)
- 試験: check.php 3189 件(組 `code` 46 件)・editor.test.js 272 件が通過
### 2026-11-09 — 段 11-4 同期を syncManager.js に切り出して /editor/ と /code/ で共通に(検証機だけ。**本番へは送らない**。11-7 で配備)

- **切り出し**: 段 10-B で `editor-src/src/main.js` に書いた同期(送る・読む・突き合わせる・ぶつかりを選ぶ・消す)を、画面から離して `editor-src/src/project/syncManager.js` にした。口とのやり取りと純粋関数は `sync.js` のまま。画面ごとに違うところ(開いているもの・打ちかけの保存・記録の書き換え・読み込んだあと)は `host` で受ける。main.js から約 260 行が抜けた
- **/editor/**: 部品を使う形に置き換えただけで、動きは変えていない(帯の印・一覧の印・サーバーにだけあるもの・ぶつかりの 3 つの選び方・消す)
- **/code/**(`code-src/src/codeSync.js`):
  - 殻が /editor/ と同じくサインインのセッションを見て(fail open・DB は読まない)、CSRF の札とサインインしているかを meta で渡す。口は同じ `api/editor.php`
  - 下の帯に状態(/editor/ と同じ文言)。押すと、ぶつかっていれば選ぶ画面(VS Code のダイアログ)、そうでなければメニュー(使っている量・いま送る・突き合わせる・サーバーにだけあるものを読む)
  - サインインしていなければ帯は「サインイン」(戻り先は `/code/`。sign-in.php は `?` を通さない)
  - 書いたら 3 秒待って送る・手で保存(Ctrl+S)したら待たずに送る・タブを隠したら送る・戻ったら突き合わせる
  - 「プロジェクトを開く…」の一覧に同期の印と、サーバーにだけあるもの(選ぶとこの端末へ読み込んで開く)
  - ファイルの口(projectFs.js)は記録の読み書きを 1 列に並べる(書き込みと、同期が server を書くのが入れ違うと、古い写しで server を消すため)
- **確かめた(検証機の Chrome・サインインしたまま)**: /code/ で打つ → 3 秒で送って版 4→5・/editor/ で打つ → 送って版 6 が /code/ にも届く・ぶつかり(記録の版を 1 つ戻して作った)で「サーバーのものを読む」「この端末のもので上書き」・メニューと一覧の印。サインインしていない殻は `km-signed-in` 0。試しに打った分は元に戻して送り直した(版 7)
- 試験: editor.test.js 272 件(`[project/syncManager]` 14 件を足した)・check.php 3185 件(組 `code` 42 件)が通過
### 2026-11-09 — 段 11-3 VS Code 型のエディタのプレビューと webview(検証機だけ。**本番へは送らない**。11-7 で配備)

- **枠のオリジン `webview.<ドメイン>`**(拡張機能ホストと webview の枠を本体から離す):
  - 部品の `iframeAlternateDomain`(拡張機能ホスト)と `getWorkbenchServiceOverride` の第 2 引数(webview)に、殻の meta `km-webview-origin`(`lib/site.php` の `km_site_webview_origin()`)を渡す。部品は URL のホストだけを差し替えるので、名前は 1 つでよい
  - nginx: 新しい server `${KM_WEBVIEW_DOMAIN}` は 4 つのファイル(index・fake・service-worker・webWorkerExtensionHostIframe)だけを GET で返し、ほかは 404。枠の親は本体だけ(`frame-ancestors`)。拡張機能ホストの枠の CSP には中のインラインの台本の sha256 を書く(check.php が組み立てた HTML と比べる)
  - 本体の `/code/assets/` は CORS を webview のオリジンだけに許し、枠の HTML は返さない(本体のオリジンで開かせない)
  - CSP `'code'` の frame-src から `'self'` を外し、webview と html のオリジンだけにした
  - compose に `KM_WEBVIEW_DOMAIN`(reverse-proxy)・`KM_WEBVIEW_URL`(web)。検証機の証明書は `host-local.sh certs`(新しい口。.env を触らない)で webview の名前を足した。本番の `host-cert.sh status` は名前が無いと「注意」を出す(11-7 まで)
  - 試作の仮の口 `nginx/km/code-proto-iframe.conf` は `Old/removed-2026-11-08-code-proto/` へ退避
- **見つけて直したもの**:
  - worker の URL が `/code/assets/…` のままだと、別のオリジンの枠の中で解決できない → `new URL(…, location.href)` で完全な URL に
  - Markdown のプレビューが白い: `fake.html` の `frame-ancestors` が `'self'` だけだった(祖先をすべて見る)→ 本体も足した
  - Chrome のキャッシュに CORS の無い古い応答が残り、拡張機能ホストが Code 81 で止まった(検証機の Chrome だけ。本番は初めて配るので起きない)
- **動いたもの(検証機の Chrome)**: 枠はすべて `webview.192-168-3-31.sslip.io`・Error Lens・Markdown のプレビュー・.ipynb のセル(信頼の確認は残す)
- **プロジェクトのプレビュー = 別の窓 `/code/preview.php?p=<id>`**:
  - VS Code の webview の中に見せる側の枠を重ねると、webview のオリジンの CSP に html のオリジンを足すことになるので、本体のオリジンの別の窓にした(CSP は `'editor'`)
  - /editor/ の組み立て(`projectBuilder.js`)と見せ方(`preview.js`・html のオリジンの `editor-run.html`)をそのまま使う。/code/ の自動保存(0.8 秒)の知らせ(BroadcastChannel)で組み直す
  - コンソールの「ファイル:行」を押すと、/code/ がそのファイルのその行を開く(BroadcastChannel `km-code-reveal`。同じプロジェクトの決まりどおりのパスだけ。`paths.js` の `revealTarget`)
  - 入口: 下の帯の「プレビュー」・エディタの右上のボタン(HTML・CSS・JS)・コマンド「プレビューを別の窓で開く」(Ctrl+Shift+Alt+V)
  - 窓は名前で使い回す。`noopener` を付けると毎回新しいタブになった(Chrome で確かめた)ので、窓の側が読み込んだ直後に `window.opener = null` で自分から切る(開く側で切っても読み込み直しで戻っていた)
- **本番の DNS**(利用者が 11-08 に Cloudflare で足した。読むだけで確かめた): `webview.ito4.jp` は ito4.jp・html.ito4.jp と同じ Cloudflare の番地(橙)。いまは HTTPS が 526(本番の証明書に名前が無い)→ 証明書に足す
- **証明書の発行の下見が橙の名前を断った**(11-09、利用者が本番で `host-cert.sh issue … --also webview.ito4.jp`): 手順 1 が「DNS がこのホストの IP か」だけを見ており、段 8 で Cloudflare を通るようにした名前(ito4・auth・html・share・webview)がすべて ★ になった(発行は試していない)。→ **Cloudflare の番地(nginx の realip と同じ一覧 `nginx/km/cloudflare-real-ip.conf`)も認める**ように直した。本当に届くかは今までどおり手順 2(80 番の `/.well-known/acme-challenge/` を外から取る)が確かめる。検証機の sh で判定を試した(Cloudflare の 番地(104.21.・172.67. で始まるもの) は認め、このホスト・範囲の外・形の違うものは認めない)
- 試験: editor.test.js 258 件(`revealTarget` 3 件を足した)・check.php 3181 件(組 `code` 39 件)が通過

### 2026-11-08 — 段 11-2 VS Code 型のエディタの拡張機能(検証機だけ。**本番へは送らない**。11-7 で配備)

- **拡張機能の欄**:
  - 部品 `@codingame/monaco-vscode-extension-gallery-service-override` 39.0.3 を足した(npm audit 0)。入手先の URL は渡さないので、検索して入れる口は働かない
  - 空の検索だと「インストール済み」(いつも 0 個)になるので、開いたときに `@builtin` を入れて同梱のものを並べる
  - 歯車から「無効にする」「有効にする」だけ使える。アンインストールと別の版は灰色
- **自作の日本語の用法**(`code-src/src/jaHelp.js` は純粋関数、`jaExtension.js` は拡張機能の形。データは /editor/ の `editor-src/src/data/*` を使い回す):
  - VS Code 付属の HTML・CSS の言語サーバーは、英語の標準データを必ず使い、切る設定が無い。同じデータを足すと候補が 2 つずつ並ぶ
  - そのため日本語は **指を合わせたときの説明**(英語の説明の下に、日本語の説明と例)と **雛形**(HTML の `<` の後の「img 画像」など・JS の雛形)で足した
  - 拡張機能の欄に「日本語の用法(Web Code Editor)」として出て、オフにできる。起動の前に登録しないと欄に出ないので、そうした
- **言語の色分けを確かめた**: Python・Java・PowerShell・バッチ・SQL・YAML・XML・シェル・PHP・TypeScript(どれもアイコン付き)。配色テーマは追加の 8 つが選べる
- **.ipynb**:
  - ノートブックの部品が遅れて作られ、テキストのまま開いていた → 起動の直後に一度使うようにして、「Jupyter Notebook」の画面が既定になった
  - 開くと「このノートブックの作成者を信頼しますか?」が出る(VS Code の守り。そのまま残す)
  - **中身はまだ出ない**: 出力の枠(webview)がサイト既定の CSP で止まるため。11-3 の `webview.<ドメイン>` で直す
- **見つけて直したもの**: ホバーの `<a>` が markdown の中で HTML として消えていた(「rel( の属性)」)→ コードの形にした
- 試験: editor.test.js 255 件(`[code/jaHelp]` 8 件)・check.php 3165 件(組 `code` 23 件)が通過

### 2026-11-08 — 段 11-1 VS Code 型のエディタの基盤(検証機だけ。**本番へは送らない**。11-7 で配備)

- **作ったもの**(`code-src/src/`):
  - `projectFs.js`: ファイルの口。名前・種類・上限(20MB・500)の確かめは `/editor/` と同じ決まり
  - `commands.js`: コマンド 5 つ(プロジェクトを開く・新しいプロジェクト・ZIP/ファイル/フォルダを読み込む・ZIP で書き出す・かんたんなエディタで開く)。エクスプローラーの右クリックと、下の帯のプロジェクト名
  - `main.js`: 狭い画面では /editor/ を勧める
- **/editor/ 側**:
  - `db.js` の BroadcastChannel `km-editor-changes` で、ほかのタブの変更を読み直す
  - `?p=` で開くプロジェクトを選べる
  - 種類を広げた: .ts・.py・.ipynb・.php・.ps1・.bat など 25 個。サーバーは `KM_EDITOR_EXTRA_TYPES`。HTML 展開の型の表は広げない
- **CSP の 'code' を確定**:
  - 'editor' に足すのは `'wasm-unsafe-eval'`・`worker-src blob:`・`frame-src 'self'` だけ
  - worker を同じオリジンのファイルから直に起こす形も試したが、その worker にサイト既定の CSP が付いて WASM が止まった → blob のまま
  - 11-0 の測定の台本は外した
- **見つけて直したもの**:
  - **プロジェクトを切り替えても、前のプロジェクトのタブが残った**(「ファイルが見つかりません」)。どのプロジェクトも `/workspace` で、画面の状態の鍵(workspace の id)が同じだった → id をプロジェクトごとにした
  - 10-B の check.php が `.php` を断る前提だった → `.exe` で見る
- **検証機の Chrome で確かめた**:
  - CSP の違反なし・コンソールのエラーなし
  - プロジェクトの切り替え(ページを開き直す)と、プロジェクトごとのタブ
  - `/editor/?p=` で同じプロジェクト。/editor/ で打つと /code/ に、/code/ で打つと /editor/ に届く
- **まだ見ていないもの**:
  - 狭い画面の案内(Chrome の窓の大きさを変えるので見送り)
  - 読み込み・書き出し(ファイルを選ぶ画面・ダウンロードになるため。11-7 の前に利用者と一緒に)
- 試験: editor.test.js 247 件・check.php 3161 件(新しい組 `code` 19 件)が通過

### 2026-11-08 — 段 11-0 VS Code 型のエディタの試作と測定(検証機だけ。**本番へは送らない**。続けるかは利用者が決める)

- **元**: `server/code-src/`(Vite 8.3.5・`@codingame/monaco-vscode-api` 39.0.3 と同じ版の部品 74 個)。出力は `src/code/`、殻は `code-src/public/index.php`。プロジェクトは /editor/ と同じ IndexedDB `km-editor` を `file:///workspace` として見せる(`code-src/src/projectFs.js`)
- **測った値**:
  - 組み立ては Windows で約 5 秒。配る大きさは 73.8MB(拡張機能の source map 59MB は配らない)
  - 初めて開いたときの転送は 4.6MB、起動まで 1.7 秒(2 回目は 0.6〜0.8 秒)
  - 本体のページのメモリは 70MB
- **CSP**: /editor/ と同じ厳しさを Report-Only で送って数えた。本体のページで足りなかったのは次の 3 つだけで、**本体に `'unsafe-eval'` は要らない**:
  - 拡張機能ホストの枠(`frame-src`)
  - 色分けの WASM(`'wasm-unsafe-eval'`)
  - blob の worker 3 つ(`worker-src blob:`)
- **cross-origin isolation(COOP/COEP)は無くても動いた**(JS/TS の補完・型の説明)
- **動いたもの**:
  - 日本語の画面・エクスプローラー(新しいファイル)・HTML/CSS/JS の色分け
  - JS/TS の補完・CSS の検査(日本語の文)
  - Open VSX の **Prettier 12.4.0**(既定の整形に選んで整形)・**Error Lens 3.30.0**(行の右に「不明なプロパティ: 'colr'」)。どちらも Open VSX の sha256 と一致・認証済みの発行者・Web 用の入口に外への通信も eval も無し
- **動かなかったもの(予想どおり。11-3 で直す)**: Markdown のプレビューなどの webview。枠の HTML にサイト既定の CSP が付き、中で Service Worker も登録するので、**`webview.<ドメイン>` の別オリジンが要る**
- **仮の口**: 拡張機能ホストの枠は同じ理由で起動しなかった。検証機だけ `nginx/km/code-proto-iframe.conf`(枠の HTML にだけ中の meta と同じ CSP)で動かした。部品には `iframeAlternateDomain`(別オリジンへ出す設定)があるので、11-3 で移して消す
- **本番へ出さない守り**: `deploy-to-host.ps1` は本番のときだけ `./src/code` と `code-proto-iframe.conf` を除く(手元の tar で確かめた)
- **見つけたこと**:
  - vsix のファイルは `/code/assets/…` のままだと拡張機能ホストで読めない(Failed to fetch)→ Vite の `renderBuiltUrl` で完全な URL にした
  - Code Spell Checker は Web 版が無い(GPL-3.0)
  - npm audit は KaTeX の low 4 件(11-1 で overrides)
- check.php 全体 3139 件が通過

### 2026-11-08 — 段 10-B Web Code Editor のプロジェクトをサーバーへ同期(サインインした人。**11-08 に本番へ配備済み・完了**。nginx は 21:50 に起こし直した)

- **口**: `api/editor.php`(POST・GET は blob だけ。CSRF を先に見る・持ち主はセッションの sub だけ・止められた/テスト用のアカウントは断る)。サインインしていなければ `state` は 200 `{signedIn:false}`、ほかは 401
- **置き場**: `lib/editor-projects.php`。表 `km_editor_projects`(新しい表。`local-sanitize.tables` は wipe)・`uploads/editor/<public_id>/blobs/<sha256>` と `manifest.json`。上限は 1 人 30 プロジェクト・200MB、1 プロジェクト 20MB・500 ファイル、1 回 20MB(nginx 21m・zone `km_editor` で POST を 1 分 30 回)。アカウントを消すと一緒に消える(`account-delete.php`)
- **送り方**(`editor-src/src/project/sync.js`): 目録(パス → sha256・大きさ)と、サーバーに無い中身だけを zip(名前はハッシュ)で。版が違えば 409 → 「両方残す / サーバーのものを読む / この端末のもので上書き」を選ばせる。打ち止めて 3 秒・Ctrl+S・タブを隠したときに送る。開いたとき・戻ってきたときにサーバーの一覧と突き合わせる
- **規約**: 第8条の3 とプライバシーポリシー(保存するもの・保存期間・アカウント削除)。文面は利用者が確認(11-08)。改定日 2026-11-08
- **検証機の Chrome で確かめたこと**(利用者がサインイン): サインインで 2 つのプロジェクトが自動で上がる・1 ファイル変えると送るのは zip 255 バイト(中身 1 つ)だけ・ほかの端末の変更を開いたときに取り込む・ぶつかりの 3 つの選び方・一覧に使っている量・サーバーにだけあるものを読み込む・削除でサーバーからも消える・ほかで消されたものに「サーバーに送る」。CSRF なし 403(blob の GET も)・PUT 405・サインインなしの save 401。サインアウトしても手元は消えない(手元を消すのは「削除」だけ)
- **検証機で見つけて直したもの**: 送ったときの 409 で知ったサーバーの版を覚えておらず、「この端末のもので上書き」が古い版で送ってまたぶつかっていた → 409 の版を覚える(`main.js` の `pushProject`)。サインインなしの 401 の本文が `success:true` だった → false に
- **試験**: `editor.test.js` 236 件・check.php 全体 3139 件(組 `editor` 66 件)が通過
- **本番(配備のあと、読むだけ)**: `/editor/` 200(meta `km-csrf`・帯 `wce-sync`・組み立ては `main-HRk6rWft.js`)・`/api/editor.php` を CSRF なしで 403・規約に第8条の3 と改定日・動いている nginx に zone `km_editor` と `location = /api/editor.php`。初めの配備では reverse-proxy を起こし直しておらず設定が入っていなかった → 起こし直してもらって確認。表 `km_editor_projects` は、サインインした人が初めて使うときに作られる(配備の時点ではまだ無い)

### 2026-11-08 — 段 10-A Web Code Editor を複数ファイル・複数プロジェクトにした(計画書の優先度 4。**11-08 に本番へ配備済み・完了**。スマホの幅も利用者が確認)

- **保存**: IndexedDB `km-editor`(`editor-src/src/project/db.js`。使えなければメモリだけで動き、帯に出す)。設定は localStorage `km-editor-settings-v2`。段 9 の書きかけ `km-editor-v1` は初めに「はじめのプロジェクト」(`index.html`・`style.css`・`script.js`)へ移す。**古い鍵は消さない**(印 `km-editor-v1-migrated` だけ)
- **画面**: 左にファイルの木(新規・フォルダ・名前の変更・削除・追加・ドロップ)・× の付いたタブ・プロジェクトの一覧(新規・開く・名前の変更・複製・削除)・ファイルを探して開く・画像は見るだけ・配色に「くっきり(明・暗)」(Monaco の hc-light・hc-black)・JSON(worker 付き)・XML・Markdown の色分け
- **ショートカット**: 設定で付け直せる(`keybindings.js`)。ブラウザのキー(Ctrl+W・T・N・Tab など)・編集のキー(Ctrl+Z・C・V など)・修飾なし・重なりは断る
- **プレビュー**: `preview/projectBuilder.js` が入口の HTML から CSS(`@import`・`url()`)・JS・ES モジュール(`data:` URL へ)・画像を中に入れて組む。`fetch`・XHR は台本がプロジェクトのファイルを返す・`<a href="about.html">` は入口を替える・エラーは「ファイル:行」で押すと開く。`html-host/editor-run.js` の上限を 32,000,000 字にし、`km-editor-nav` を回す
- **ZIP・読み込み**: `src/Main/html-zip.js` を使い回す(写しは作らない)。ZIP・ファイル・フォルダ・ドロップ。共通のフォルダを外す・隠しファイルと `__MACOSX` は除く・使えない拡張子は数えて知らせる。書き出しは ZIP と 1 つの HTML
- **試験**: `editor.test.js` 209 件・check.php 全体 3113 件(組 `editor` 40 件)が通過。**検証機の Chrome で**: 移し替え(遅延 100ms も引き継ぎ)・ZIP の読み込み(.php を断り .DS_Store を除く)・CSS / 画像 / モジュール / `fetch('data.json')`・about.html への移動・エラーの行から開く・Ctrl+P / Alt+W / Alt+PageUp / Ctrl+B・Ctrl+W と Ctrl+S(重なり)を断る・Alt+R に付け直して再読み込み後も効く・くっきり(暗)・ファイルとフォルダの操作・プロジェクトの複製と削除・英語。スマホの幅は配備のあと利用者が実機で確認 OK。本番は応答 200・`editor-run.js` の上限 32,000,000 字と km-editor-nav を読むだけで確かめた
- nginx は変えていない(配備は `-Action` なし)

### 2026-11-08 — 段 9 Web Code Editor を作った(利用者が渡した計画書 1.1 の優先度 1〜3。「ito4.jp で公開」「補完は HTML・CSS・JS すべて日本語で」「処理はできるだけクライアントで」。**11-08 に本番へ配備済み・完了**。リンク集の入口とスマホの幅は利用者が確認)

- **形**: `https://ito4.jp/editor/`。元は `server/editor-src/`(Vite 8.3.5・Monaco 0.57.0。`npm audit` 0 件。dompurify は overrides で 3.4.17)、`npm run build` の出力 `src/editor/` を配る。サーバーは殻と静的ファイルだけ(DB・API・サインインなし)。設計は plan.md 段 9
- **CSP**: 新しい profile `'editor'`。Monaco が `style="…"` を innerHTML で組むので **style-src だけ `'unsafe-inline'`(nonce は外す)**。script は nonce のまま。ほかのページは変えていない(check.php で見張る)
- **プレビュー**: srcdoc は親の CSP を受け継ぐので、`html.<ドメイン>/editor-run.html`(`html-view.php` に 3 つの道)で動かす。二重の sandbox(allow-scripts のみ)。Chrome で中の JS から `parent.document`・`top`・`localStorage`・`document.cookie`・`indexedDB` がすべて SecurityError、`location.origin` は `null` を確かめた
- **補完**: 標準のデータ(英語)に自分で書いた日本語を重ねた(HTML のタグ 114・属性 111・グローバル属性 29・値 65・雛形 27 / CSS のプロパティ 167・値 114・疑似クラスと疑似要素 36 / JS の API 159・書き方の型 18)。`<a` → 「a リンク」→ `<a href="URLを入力">リンクの文字</a>`、`display` → 「箱の種類と並べ方」、`document.` → 日本語の候補が上に
- **検証機で見つけて直したもの**: デバウンスの既定の時計 `{ set: setTimeout }` がブラウザで Illegal invocation になり、保存とプレビューの自動更新が止まっていた(node では落ちない)→ 関数で包み、node の試験に「this が window でないと落ちる setTimeout」を足した。`/editor`(末尾の / なし)を Apache が `http://` へ転送していた → nginx で https の `/editor/` へ 308
- **内蔵ブラウザでは確かめられない**: sslip.io は公開サフィックスなので html. が別サイト扱いになり、内蔵ブラウザが枠を `ERR_BLOCKED_BY_CLIENT` で止める(HTML 展開の `/html.php` も同じ)。検証機の確かめは Chrome で(利用者の指示)
- **配備**: `deploy-to-host.ps1` の mustContain に殻・manifest・editor-run、`editor-src/` は入れない。ハッシュ付きの古い組み立ては配備のたびに `retired/editor-assets/` へ(前の 1 世代だけ)。nginx の静的キャッシュに `ttf`
- 試験: `src/scripts/js/editor.test.js` 110 件・check.php 3105 件(組 `editor` 32 件)
- **本番(11-08 配備のあと、読むだけ)**: `/editor/` 200・CSP は `'editor'` のとおり(`frame-src https://html.ito4.jp`・`worker-src 'self'`)・`/editor?lang=en` → 308 `https://ito4.jp/editor/?lang=en`・本体の JS と CSS は 200 で 30 日のキャッシュ・`html.ito4.jp/editor-run.html` は 200 で `frame-ancestors https://ito4.jp`・`editor-run.js` は手元と同じ・本体のホストの `/editor-run.html` は 404

### 2026-11-08 — 検証機(192.168.3.31)を起こし、本番と並べた(利用者「docs/13 §0 の続きから。検証機が本番と変わりなくなるまで」)

- 起こすまでに直したこと(利用者):
  - おすすめの snap が 80 番を取っていたので snap と snapd を外した(docs/13 0-1 に「何も選ばない」と書いた)
  - 再起動のあと eth0 に DNS が無かったので、`/etc/systemd/resolved.conf.d/kosenmap-dns.conf`(DNS=192.168.3.1)を足した
- 配備: この PC から kmops で、最新のコードを `deploy-to-host.ps1 -HostName 192.168.3.31 -User kmops -KeyPath …km_test30_ops` で置いた。そのあと `docker compose up -d` で全部 healthy になった(phpMyAdmin は本番と同じく合図の間だけ)
- 並べた結果(読むだけ):
  - 同じもの: イメージの digest(nginx・Logto 1.46.0・MariaDB・Postgres・phpMyAdmin・glances)・PHP 8.4.27・カーネル 6.8.0-149・Docker 29.9.3・cron(kosenmap-updates)・待ち受け(22・80・443・127.0.0.1:3002。検証機だけ Mailpit の 8025)
  - わざと違うもの: certbot と postfix の代わりに Mailpit・自前の CA なので HSTS は付けない(`hsts-enable.conf.example` の注意)
  - 直したもの:
    - **本番の `src/vendor/` が 08-28 のまま**だった(11-02 に外した pusher・psr/log が残っていた)。原因は `deploy-to-host.ps1` の判定: composer.lock を**送ったあとに**ホストの lock と比べていたので、一度も入れ直していなかった。ホストの `vendor/composer/installed.json` の「名前=版」と lock を比べる形に直した → **次の本番の配備で入れ直す**(本番は 2 つ多い・検証機は一致を読むだけで確かめた)
    - 検証機にタスクマネージャーの許可(`nginx/km/taskmgr-allow.local.conf`。LAN の 192.168.3.0/24)を置いた(本番はあり・検証機は無く、誰も入れなかった)
    - 検証機の `scripts/host-setup.sh`(11-06 の土台の写し。本番には無い)を `~kmops/bootstrap-leftovers/` へ退避
- 利用者が済ませた: `src/vendor/` と `certs/` を kmops の持ち物に(sudo chown)・外へ出る定期処理 5 つを止めた(`local-disable.sh --apply`。「すべて止まっています」)・PC に検証機の CA(指紋 449DEAEE…)を入れた
- Console が `r.identifiers` で落ちた → 原因は **PC が 107 の古い CA(6AEAF2AE…。CN は同じ「KosenMap Local CA (test)」)しか信頼していなかった**こと。Console の `auth.<ドメイン>/api/…` が TLS で断られ、nginx に 1 件も届いていなかった。docs/13 0-8 に「先に CA を入れる・指紋で見分ける」を書いた
- 本番と違っていた名前を直した: 検証機の証明書に admin・html・share が無く、管理画面も別オリジンになっていなかった → `host-local.sh` が証明書にいつも auth・html・share を入れる(status でも確かめる)ようにし、`init --admin-domain admin.192-168-3-31.sslip.io` で作り直した。PC から証明書を検証したまま 5 つの名前が開け、`/admin/` は本番と同じく admin の名前へ 308。docs/13 0-5 に `--admin-domain`・sudo を付けないことを書いた
- 0-8: 利用者が Console で管理者・m2m を作り、Logto の設定と .env を本番に近づけた。.env を本番とハッシュどうしで比べた(値は出さない): **本番の秘密と同じ値は 0**(同じなのは DB の名前 `logto` だけ)
- phpMyAdmin の「環境保管領域が完全に設定されていません」: 本番と同じ形で作った(DB `phpmyadmin` の 19 表・`pma` は `phpmyadmin.*` だけ・TLS 必須・パスワードは検証機で作って .env の `KM_PMA_CONTROLPASS`。phpMyAdmin が止まっている間でも像から SQL を読む)
- 0-9 データ: `local-refresh.ps1 -CopyPublicImages` で 11-08 12:04 の控えから入れた(表 99・色分けの絵 17 枚・本番の秘密との一致 0・抜き残し 0)。-SeedLogto は流していない(Logto は利用者が手で組んだ)。途中で直したもの:
  - `run/` は本番と同じく root の持ち物で書けず、`local-sanitize.sh` の塩で止まった → 塩・取り込みの記録・ダミーの利用者を検証機だけの `local-state/`(700)へ(`local-sanitize.sh`・`local-import.sh`・`local-logto-seed.sh`・`.php`・check.php・docs/13)
  - `local-sanitize.sh` が、自分で入れたダミーのプロジェクト状況 3 件を「空にする表に残っている」と数えて毎回止まっていた → ダミーは数えない
  - (自分の誤り)一括の置き換えで `local-import.sh`・`local-logto-seed.php`・`check.php` を壊した(1 組だけの配列が平たくなり、1 文字ずつ置き換えた)。検証機の写し(本番と同じハッシュ)から戻し、壊した差が置き換えた文字だけであることを確かめてから直し直した
- **デスクトップの `検証機を本番と同じに.cmd`**(利用者の指示)→ `scripts/testhost-sync.ps1`: 配備(-Action up)→ `restart reverse-proxy` → 本番の控えからデータ(`local-refresh.ps1 -CopyPublicImages`)。**検証機では控えを作らない・GitHub へ送らない**(利用者の指示): `deploy-to-host.ps1` は本番以外へは `-Backup`・`-BackupOnly`・`-OpenBackup` を断り、GitHub へは出さない。通しで流して 4 分 27 秒・終了コード 0・GitHub の控えは増えていない。※ その指示の前、18:08 の検証機への配備で一度 GitHub へ出ていた(中身は手元の同じコード)
- 本番の形に揃えた(利用者「本番の形に揃えてください」。docs/12 §6-3・§3 と同じ手順を検証機で):
  - MariaDB: `kosenmap@%` → `kosenmap@172.30.2.%`・TLS 必須、`root@%` を消した、phpMyAdmin の root は閉じたまま(`KM_PMA_ALLOW_ROOT=`)。web から TLS で DB を読めた(地点 904)。データの入れ直し(`local-import.sh`)は DB を作り直すだけで利用者には触れない。DB と利用者の名前(`kosenmap`)はコードが名前に頼っていないので変えていない。本番の `Ito`(自分用)は利用者が自分で作るもの
  - Logto: `logto_app`(SUPERUSER・CREATEROLE・CREATEDB・BYPASSRLS なし)で繋ぐ。表 79 個の持ち主・権限の不足の記録 0・healthy
- 0-10 Android: 検証機の Logto に組織・API リソース(sslip の名前)・ネイティブアプリがあることを確かめ、.env の値で debug 版を両方作った(版 58・`192-168-3-31.sslip.io` と `auth.` を含み `ito4.jp` は含まない・debug の署名)。デスクトップの `検証機の端末用` に APK 2 つと CA。docs/13 §9 のセルに `-Pkosenmap.logtoAppId` を足した
- 利用者が端末に CA と debug 版を入れ、サインインできた(Logto の記録でトークン 200・天気・統計も 200)
- **検証機で見つけた本番の不具合**: サインインのあと `api/user-map-data.php`(履歴とブックマークの同期)が 401。**本番でも 7 日間で 26 回すべて 401**(アプリからの同期は一度も通っていなかった)。原因はこの web(Apache + mod_php)で `$_SERVER['HTTP_AUTHORIZATION']` が空のままなこと: user-map-data.php はそれだけで「アプリか」を決めていて、アプリを「サインインしていない Web」と取り違えていた。`api/stall.php`(屋台の鍵)も同じで、正しい形の鍵でも「鍵が要ります」の 401 だった。
  `api_bootstrap.php` に `km_request_authorization()`(HTTP_AUTHORIZATION → REDIRECT_ → getallheaders())を足し、2 つの口をそれで読むようにした(Logto の確かめの `logto_authorization_header()` は前から getallheaders() も見ていたので通っていた)。check.php に 2 件(3073 件すべて通過)。検証機に配備し、偽のトークンで 2 つとも先へ進むことを確かめた。**本番は配備待ち**
- 利用者が本番へ配備した(18:51)。本番の `vendor/` は composer.lock と一致(pusher・psr/log が消えた)・本番の同期の口も偽のトークンで先へ進む。検証機の端末で同期が 200 になった
- 最後に並べ直した: コードは同じ(違うのは composer が入れるたびに名前の変わる autoload 3 つと、わざと付けない HSTS)。cron は本番 24 行・検証機は同じ 24 行のうち外へ出る 8 行を止めた形。`config/db・logto-m2m・recaptcha.local.php` は検証機に無いが、どれも .env が先に効くので要らない
- Logto の残りの違いを本番に揃えた(検証機の DB だけ):
  - メールのコネクタが本番の `mailserver:587` のまま(検証機に無い)で、確認コードを送れなかった → `mailpit:1025`・認証なし(local-logto-seed.php と同じ)。Logto から Mailpit の 220 を確かめた。写してあった SMTP の利用者名とパスワードは本番と同じ値だったが、本番でも 2 つは同じ 5 文字(中の網のメールサーバーの仮の値)。消した
  - Console(admin テナント)の MFA が NoPrompt・パスワード方針とロックが空 → 本番と同じ(MFA Mandatory の Totp・WebAuthn・12 文字以上・5 回で 60 秒)。default の MFA にも本番と同じくメールの確認コードを足した。**検証機の Console の管理者は MFA が 0 件なので、次に入るときに登録を求められる**
- 組織のトークン(教職員)が 403 なのは、検証機の組織に利用者が居ないため(アプリは通常のトークンへ落とす。本番でも教職員でない人は同じ)
- 自己検査 3071 件すべて通過

### 2026-11-08 — 検証機の Console は -D で(利用者「トンネルはやっぱり -L ではなく -D のほうじゃないとだめですね」)

- docs/13 の 0-8 の `ssh -L 3002:…` を、デスクトップの `検証機トンネルSSH.cmd` に替えた(本番の `トンネル開通SSH.cmd` の写し。km@192.168.3.31・鍵 km_test30・SOCKS 127.0.0.1:10801・Chrome のプロフィール chrome-socks-test31。本番のトンネルと同時に開ける)

### 2026-11-08 — Cloudflare の分析を訪問者の画面に・位置を DB に(利用者「GraphQL Analytics API は Free プラン OK。Logpush は Free プランダメ。作ってください(Website 側管理画面、訪問者に紐づけること)」「位置の保存は永久にしてください」。**11-08 完了**)

- 答え: 訪問者の位置は**ブラウザの IndexedDB に 30 日**覚えるだけで、DB には残っていなかった → DB に残す形にした(利用者の選択: DB に保存・WAF と通信量・cron で毎時)
- **位置**: `lib/visitor-geo.php`(`km_visitor_geo` = IP ごとの最新・`km_visitor_geo_history` = 中身が変わったときの前の結果)。**消さない。** 引くのは今までどおりブラウザで、引いた結果を `admin/api/visitor-geo.php` へ送る(CSRF・書ける管理者だけ・IP を URL に載せない)。開くたびにサーバーの位置を先に読むので、ほかの PC で引いた IP は引き直さない
- **Cloudflare**: ホストの root の cron(版 14・毎時 13 分)が `scripts/host-cloudflare-import.sh` で GraphQL を取り、web の `scripts/cloudflare-import.php` が DB へ(`km_cf_waf_events`・`km_cf_traffic_hourly`・`km_cf_import_state`)。**トークンは web に渡さない**(root だけの `cloudflare.local.conf`)。
  WAF・通信量とも「読み終えた所」を別に覚える(記録の無い時間で止まらない)。範囲は Cloudflare の settings(notOlderThan・maxDuration・maxPageSize)に合わせ、読めなければ控えめに。初回は最大 30 日さかのぼる。WAF の記録の国・ASN は、まだ位置の無い IP にだけ入れる
- **画面**(訪問者): タイル「Cloudflare で止めた」・「Cloudflare 全体(日別)」(要求・キャッシュ・転送量・脅威・止めた数・サーバーに届いた訪問)・「止めた国」・「止めた接続」(同じ IP の訪問の数と判定。サーバーにも届いた IP は色付き)・止めた記録の CSV
- check.php に組 `cloudflare`(35 件)。台帳(`local-sanitize.tables`)に 5 表を wipe で。全体 FAIL 0。描く JS は見本のデータで node で動かした。
- 配備のあと利用者がトークン・`cloudflare.local.conf`・cron 版 14 を入れて手で流した(1 回目は HTTP 401 → 直して 2 回目で成功・問い合わせ 7 回)。本番の DB(読むだけ): WAF 29 件(11-08 10:53〜。WAF のルールを作ったのが当日)・
  国は US 11・CH 7・SG 4・NL 3 ほか。firewallCustom 28・firewallManaged 1。通信量は 3 時間ぶん(ゾーンを載せたのが当日)。位置は Cloudflare から 19 件。
  無料の版の範囲(settings): WAF は 31 日前まで・1 回 7 日・1 万件、`httpRequests1hGroups` は **約 3 日前まで**・1 回 3 日。画面へ渡す関数も本番で通った(29 件・3 時間・13KB)
- 利用者が管理画面の訪問者で表示を確かめた(「大丈夫でした」)→ **完了**。プロジェクト状況 #113 は消した
### 2026-11-08 — プロジェクト状況の # を直せるように・日曜の検査のメール(利用者「プロジェクト状況の # は編集できるようにしてください」「メールが来ていました」。**11-08 配備済み**)

- **# は km_tasks.id そのもの**(proj-tasks.ps1 の -Id・「#112」と同じ)。編集の画面に「番号(#)」の欄を足した。1〜999999・使い済みの番号は断る・補足(km_task_details)も同じトランザクションで付け替える。
  表の作りは変えない(行の id を UPDATE するだけ)。UPDATE では AUTO_INCREMENT が進まないことがあるので、足すときに番号が当たったら「いちばん大きい番号 + 1」で入れ直す。
  口は PATCH の本文 `{"id":5}`、手元は `proj-tasks.ps1 update -Id 113 -NewId 8`。管理の記録は task.update に「#前 → #後」。check.php に「番号:」の 8 行(FAIL 0)。
  配備のあと本番で: #107 → #106 は 400(使い済み)・#107 → #110 → #107 で題・状態・段の番号・メモがそのまま戻った(自動の番号より小さい番号で試したので、次に足す番号は変わらない)
- 日曜 04:47 のイメージの検査のメール: 「新しく 141 件」は週 1 回の検査の初回の差分(前回の手動 156 件から減った)。check-updates の表示だけ(当てない)では、**新しい digest があるのは glances だけ**(4.5.9。11-07 公開)。
  Logto 1.46.0 は最新・ほかはタグの最新のまま = 上流待ち。次回からは増えたときだけ届く
- 日曜 05:47 の片付けのあとも、ビルドの控えが 10.66GB(最後に使ったのが 2 か月前のものも残る)。ディスクは 38%(空き 59G)で急がない。利用者が記録を貼った → 「ビルドのキャッシュ(14 日より古いもの) Total: 0B」。**原因は `docker builder prune` に `-a` が無いこと**(無いと dangling しか消さない)。`host-docker-prune.sh` に `-a` を足した(11-08 配備済み)。**手で流しても 93B しか消えなかった**(8 月 28 日に作った「消してよい」控えも残る。Docker 29.9.3)。1 回の作り直しで約 1GB 増える(最後に使った日ごとに足して実測)ので、日数に加えて**合計 3GB の上限**(`--max-used-space`)でも削るようにした。**11-08 配備・手で流して 10.66GB → 3.89GB**(ディスク 36G → 30G 使用・空き 64G)。プロジェクト状況 #106 は消した
### 2026-11-08 — 段 8 Cloudflare: 配備・橙にした・完了(利用者「やりました」「一旦レコードを全て灰にしました。橙にするべきものを教えて下さい」)

- 利用者が配備・`ssh.ito4.jp`(灰)・`ssh-host-alias.ps1 -Apply`・ネームサーバーを Cloudflare(elaine・carmelo)へ。SSH は `ssh.ito4.jp` 経由で通る
- **取り込みで `html.ito4.jp` が抜けた** → 証明書の更新の下見が NXDOMAIN で失敗。利用者が灰で足した。MX は元から無い(SPF・DMARC は移った)
- **配備だけでは realip が効かなかった**: テンプレートは 1 ファイルの mount なので、置き換えても動いている nginx は古い中身のまま(送り元が 172.71.x・104.22.x ばかり)。
  コンテナの一時の場所で新しいテンプレートを `nginx -t`(OK・範囲 22)→ 利用者が `-Action restart -Services reverse-proxy`。docs/17 の手順 0 に restart を書き足した
- 橙にするのは `ito4.jp`・`auth`・`share`・(あとで)`html`。`admin`・`ssh`・`mail` は灰。PTR は Cloudflare では効かない(逆引きは VPS の会社。いまは無し)
- 確かめ(読むだけ): 印を付けた要求の記録の送り元が**この PC の IP**(Cloudflare ではない)。`/.well-known/acme-challenge/` は橙でも転送されず nginx に届く。`ito4.jp` の assetlinks は 200(`share` は元から無い)
- 控えの push が「公開の IPv4」で止まった(Cloudflare の範囲)→ 両方の `push-github.ps1` に「`nginx/km/cloudflare-real-ip.conf` の `set_real_ip_from` だけの行」を除く例外を足して出した
- 利用者が証明書の更新の下見(通った)→ `html` を橙に。`html` も記録の送り元が本当の IP・acme-challenge は nginx に届く(読むだけで確かめた)
- 利用者が WAF の国のルールを作った。日本から 4 つの名前・assetlinks・acme-challenge が通る(loc=JP)。Google の Digital Asset Links も ito4.jp の文を読めている
- **段 8 完了**: WAF のあとの証明書の更新の下見が通った(Let's Encrypt の海外からの確かめも除外で通る)。Cloudflare の Events に「日本以外を Block」で CH(スイス)からの GET / が止まった記録(利用者)。プロジェクト状況 #112 は消した
- 確かめていないもの: 回数の制限が 1 人ずつ効くか(連打は自分の IP が BAN されうるので試していない。設定は IP ごと)

### 2026-11-08 — 段 8 ito4.jp を Cloudflare に載せる用意(利用者「ito4.jp を Cloudflare に移行します。確かポートはやめたはずですよね？」「1〜3 と docs/17 の書き直しを用意してください」。**手元で作った・未配備**)

- 本番を読むだけ: 外から開いているのは **22・80・443 だけ**(ほかは 127.0.0.1:3002 の Logto Console)。nginx の像に realip がある(`nginx -V`)。**ポートの壁は無い**
- 橙にする前に止まるもの 3 つを用意した([17](17-cloudflare.ipynb) §2):
  - **SSH**: `scripts/ssh-host-alias.ps1`(この PC の `~\.ssh\config` の先頭に `Host ito4.jp` → `HostName ssh.ito4.jp`・`HostKeyAlias ito4.jp`)。写しの config で付け外し・`admin.ito4.jp` へ曲げて kmops に繋がることを確かめた(本物の config は触っていない)
  - **国の拒否**: `host-geoblock.sh` に `ALLOW_CLOUDFLARE`・`CLOUDFLARE_PORTS`(cloudflare.com の一覧を国の drop より前に accept・一覧が無ければ当てない・nginx の範囲と見比べる)。`host-stats.sh` は通した数を BAN に数えない。見本 `geoblock.local.conf.example` にも
  - **本当の送り元**: `nginx/km/cloudflare-real-ip.conf`(IPv4 15・IPv6 7・`real_ip_header CF-Connecting-IP`)を `default.conf.template` の http の段で include。配備の `$mustContain` に足した。**`nginx -t` は未(この PC に docker が無い)** → 配備のあと docs/17 の %%host のセル
- docs/17 を書き直し(結論・名前の分け方・用意したもの・Cloudflare の画面で決めること・手順 0〜8・戻し方・未確認)。目次の 1 行を 5 冊で直した。plan.md に段 8、「やらない」の Cloudflare の行は「覆した判断」へ
- check.php に「国の拒否: …Cloudflare…」「Cloudflare: …」の 8 行。全体 FAIL 0

### 2026-11-07 — 段 5 検証機の下ごしらえ(利用者「全てオススメで」「UbuntuServer は私が作成します。ブリッジ接続で行きます」「機能の追加の無効化すべきものを上げて無効化処理を行うコマンド…これは Windows ですよ。検証機の方です」。**手元で作った・未配備**)

- **VM と Ubuntu は利用者が作る**。29 には利用者が外部スイッチ「新しい仮想スイッチ」と VM「Ubuntu」(第 2 世代・2.5GB・4 vCPU・円盤なし)を作ってあった。台本で VM を作る `testhost-vm.ps1` は `Old/scripts/` へ
- **192.168.3.30 は別の機械(MAC 30-6D-F9-AF-86-45)が使っていた** → 空いている 192.168.3.31 を勧める(名前 `192-168-3-31.sslip.io`)
- 作ったもの:
  - `scripts/testhost-bootstrap.sh`(入れた Ubuntu を本番の土台に: docker.com の Docker・スワップ 2GB・鍵だけの SSH・ufw は LAN だけ・外へのメールを断る・印)
  - `scripts/local-sanitize.sh` を台帳で作り直し、`scripts/local-sanitize.tables`(99 表を keep/scrub/wipe。台帳に無い表は空にする)・列の名前で人の識別子を拾う・メールと本番の名前を置き換える・英語名と読み・ポスター・予定・設定も
  - `scripts/local-import.sh`(使い捨ての MariaDB(網なし・tmpfs)で抜いてから本物の DB へ)・`scripts/local-refresh.ps1`(PC から通しで。本番の秘密とハッシュで比べる・開いた控えを消す)
  - `src/scripts/local-logto-seed.php` と `scripts/local-logto-seed.sh`(空の Logto に本番と同じアプリ・ロール・組織・webhook・メールとダミーの利用者 8 人)・`src/scripts/local-dummy-files.php`(ポスター・360° のダミー画像)
  - `scripts/local-disable.sh`(VM の外へ出る cron と設定を止める: 受け箱・国の拒否・証明書・控え・検査・案内 AI・reCAPTCHA)
  - `scripts/testhost-windows.ps1`(29 の Windows の要らない機能を止める。既定は見るだけ・記録して -Undo で戻せる)。**読んだら IIS と FTP・LPD・Simple TCP・メッセージ キュー・WinRM が自動起動で動き、SMB 1.0・Telnet・TFTP なども有効だった**
  - 検証機だけの鍵 `~\.ssh\km_test30`・`km_test30_ops`(パスフレーズなし。最初に作った分は `""` が付いたので `~\.ssh\Old\` へ)
- `host-local.sh --fresh-secrets` は `KM_PROJ_PASSKEY` も作り、Logto・案内 AI のキーを空の行で置く
- check.php に組「testhost」(台帳がコードの CREATE TABLE と本番の 99 表を覆うか ほか)。全体 3019 PASS・0 FAIL。docs/13 §0 に通しの順番
- 途中で本番の kmops の鍵が数分断られた(`Permission denied (publickey)`)。その後は通る

### 2026-11-07 — 段 5 検証機の計画(利用者「計画書はあくまで参考程度…すぐ実装ではなく前提を確認」「機密は誤って読んでも分からないダミー」「教職員名の暗号化は抹消」。**作っていない**)

- 検証機 192.168.3.29 を鍵で読むだけ(利用者名は `ito`): Windows 11 Pro for Workstations・Hyper-V 有効・VM 0 台・メモリの空き 2GB 前後・HDD・有線が 100 Mbps でつながっている
- 本番の 99 表の列を読み(中身は読まない)、今の `local-sanitize.sh`(25 表)から漏れる表と列を洗い出した
- 計画は plan.md 段 5(5-0 前提 〜 5-6 決めること)。プロジェクト状況 #103 を 10%・答え待ちに。教職員名の暗号化は plan.md「やらないと決めたこと」へ

### 2026-11-07 — プロジェクト状況を番号順に・完了したら消す・#108 を読んだ(利用者の指示。Web は配備待ち)

- 管理画面「プロジェクト状況」に **# の列**と**並び**(並べた順 / 番号順 / 番号の大きい順 / 段の番号順)。選ぶとすぐ読み直し、保存・削除のあとも分類と並びのまま戻る。
  並べ替えは `lib/tasks.php` の `km_tasks_sort`(純粋関数。段の番号は数として比べる = 段10 は段7-1 の後・「保留」は後ろ)。`proj-tasks.ps1 list -Sort id|idDesc|plan` も同じ。check.php に 2 つ
- **これからは、終わった項目は「完了」にせず消す**(利用者「今後、完了したらプロジェクト項目から削除」)。終わった中身はこの記録に書く
- #108「DB に保存されている教職員名を暗号化するか」を読んだ(本番: 氏名の入った地点 67・氏名のモードは「常に隠す」・地図はパスワード)。見立ては会話で返した(地図のパスワードを鍵にする案は合わない。やるならサーバーの鍵で氏名だけ・新しい表に)

### 2026-11-07 — 段 6 ストリートビュー(360°)を Website へ・寮食堂(利用者の答え「見せ方は B: WebGL」「撮りそろうまで管理者だけ」「撮るのは後日」「寮食堂は別の建物」。Web は配備待ち・アプリ 版 57 は apk-push 済み)

- 始める前に本番を読んだ: **写真は 0 枚**(`km_node_panoramas` が空)。作っても、撮るまで見せるものは無い
- **見る画面** `Main/panorama-viewer.js`(新規。外の部品なし):
  - WebGL で、画面の 1 点ごとに向きから正距円筒の写真の位置を引く(球の網は作らない)。上下 ±85°・視野 30〜100°(指・ホイール・＋/−)
  - WebGL が作れない端末は 2D の canvas で横に見回すだけ(アプリと同じ見え方)。写真は押してから `api/panorama.php?h=` で取り、端末の上限(最大 8192)を超えたら縮めて載せる
  - 写真の向き(heading)があれば左下に「地図の上」の針。Esc で閉じ、開いたボタンへフォーカスを戻す。Tab は窓の中だけを回る
  - 計算は純粋な関数(`KmPanoramaMath`)。`scripts/js/panorama.test.js` 19 件
- **地図のデータ**(`lib/map-data.php`): 地点の uuid で写真の一覧を引き、`panoramas: [{sha256, heading}]` を付ける。**地図編集にはいつも・公開の地図は設定が ON のときだけ**。
  付けるのは錠・教職員の決まりで**出している地点の分だけ**(出さない地点のハッシュは載せない)。uuid は今までどおり外に出さない
- **出し口**: 公開の地図は地点の詳細に「🔭 360° 写真」(写真があるときだけ)。地図編集は地点の窓に「360° 写真を見る(n 枚)」(押すと窓を閉じて開き、閉じたら窓を開き直す。Bootstrap の窓がフォーカスを引き戻すため)
- **設定**: 管理画面「地図データ公開設定」に「ストリートビュー(360° の写真)」の枠。写真の枚数・地点の数と「来場者にも出す」(既定 OFF。`km_settings` の `panorama_public`。記録は `map.panorama_public`)
- 英語は `Main/i18n.js` の辞書に 10 語。check.php に 8 つ(すべて通過)
- 試し場(node のサーバーで見る画面と試しの写真だけ)で確かめた: WebGL で写真の真ん中が正面・ドラッグ(右へで左を向く)・ホイールで拡大・矢印のキー・Esc・2 枚の切り替え・
  読めない写真(前の写真を消して文を出す)・WebGL を塞いだときの 2D・375 幅で横にはみ出さない
- **寮食堂**: 建物の階の手がかり「食堂」が「寮食堂」にも当たり、萩友会館の階が出ていた。手がかりを「萩友会館」だけにした(Web `lib/building-floors.php`・アプリ `BuildingFloorCatalog.kt`。check.php と building.test.js に足した)。
  アプリは**版 57**(1000057・署名 d71fbf0a…)。v56 を `Test\Old\release-v56-20261107\` へ写してから両フレーバーを作り、apk-push。Pixel 7a にも両方入れた
- 門番と ngrok は止めた(利用者「検証が終了しているなら止めて」)

### 2026-11-07 — 段 4-2 案内チャットの確かめ(利用者「Unsloth を起動しただけ」「ngrok を C:\Users\itota\Documents\ngrok に入れた」「模型を 2 つ以上読み込ませない」「案内チャットの構造がめっちゃ崩れている」。Web は配備待ち)

- **崩れの原因**: 10-30 に足した地図の札(曲がる所・階段・今いる場所)が、案内の例のボタンと**同じ class 名 `km-guide-chip`** で
  `position: absolute; left: 0; top: 0` を持っていた。例のボタンがすべて画面の左上(検索の窓の裏)に重なり、「例(押すと聞けます)」の下が空だった。
  地図の札を **`km-pin-chip`** に改めた(`Main/styles.css` 13 か所・`Main/app.js` 4 か所)。本番のページで同じ直しを仮に当てて、例が見出しの下に並ぶのを見た。
  check.php に「例のボタンと地図の札は別の class・例のボタンを絶対位置にしない」を足した
- **模型は 1 つだけ**(`guide-llm/server.py`): 今までも「ほかを外してから読む・Unsloth へは alongside なし・外しきれなければ読まない」だった。
  抜けていた 2 つを塞いだ: ① **読み込み中の模型**(Unsloth の一覧で loaded が false のまま status が unloaded でないもの)も「使っている」と数えて外す・数える
  ② **一覧を読めない頼み先**があれば読まない(そこで何を読んでいるか分からない)。check.php に足した
- `start-guide-llm.ps1`: 入れた直後の窓では PATH に ngrok が無いので、利用者・機械の PATH を読み直してから探す
- **門番(手元)**: gemma-4-12B を 1 つだけ読んで起動(7 秒)。鍵なしは 401・鍵ありは 4 秒で答え
- **ngrok がつながらない**: `ngrok diagnose` で TLS が ERR_NGROK_8003。接続先の証明書の発行元が
  **「Norton Web/Mail Shield Untrusted Root」**(Norton が暗号の通信を開いて、ngrok の証明書を自分の印で付け直している)。
  ngrok は自分の発行元しか信じないので、つながらない。利用者の答え「**ngrok のエンドポイントの有効期限が切れていた。再度設定した**」のあと、`online` になり本番から届いた
- **本番で確かめたこと**(テストユーザー 1、PC の Chrome。**段 4-2 は完了**):
  | 確かめ | 結果 |
  |---|---|
  | テストユーザー以外は 403 | ○(匿名の POST は 403。CSRF の印もテストユーザーにしか出ない) |
  | 家の PC が止まっているときは言葉の結果だけ | ○「いま案内の AI が使えません。言葉で探しましたが…」。「トイレはどこ？」は近い順に 4 件のカード(約 20〜55m) |
  | 無い店にはカードを出さない | ○ 言葉の探しでも AI でも「(登録されている中には)見つかりませんでした」(「タピオカ専門店ドラゴンパールはどこ？」) |
  | テストユーザーに AI の答えとカード | ○「保健室はどこ？」→ 文と保健室のカード 1 件(約 180 m・関係 100%)。門番の記録では 1 問 1〜6 秒 |
- いまはイベントモードではない(利用者「屋台などは調べられない」。地図のデータの `event` は null・ポスター 0)ので、企画番号・食べ物の店は見つからない
- **カードの階が「bldg_hagitomo_2f」**: 案内の一覧(`lib/guide-chat.php` の `km_guide_catalog`)が建物の階をそのまま出していた。地図のデータの `floors` の名前(萩友会館 2F)にした。check.php に足した

### 2026-11-07 — 萩友会館の「中へ」が 4 つ(利用者「萩友会館（食堂・保健室）の中の階層を見ようとすると、項目が 2 つのはずが 4 つ」。Web は配備待ち)

- **原因**: Web の `facilityFloorLinks`(`Main/app.js`)は、接続 ID に建物名が入った地点の階を「キャンパスの階」として数える。萩友会館は**中の階段・出入口**の接続 ID にも建物名が入っているので、
  建物の階(bldg_hagitomo_1f・2f)がキャンパスの階としても数えられ、「bldg_hagitomo_1fF・bldg_hagitomo_2fF・萩友会館 1F・萩友会館 2F」の 4 つになっていた。
  アプリ(`BuildingFloors.kt`)は `isBuildingFloor` で外していたので 2 つ
- **直し**: キャンパスの階を数えるときに建物の階を外す。本番の地図のデータに当てると、同じことが**図書館・テクノセンター・第一/第二体育館・武道館・機械実習工場**でも起きていて、どれも直る(萩友会館 4 → 2・図書館 4 → 2 など)
- **階の呼び名**: `${floor}F` のままの所(部屋の詳細・検索の候補・一時地点の詳細・履歴)で建物の階が「bldg_hagitomo_2fF」と出ていた。`plainFloorLabel`(画面の状態に左右されない。外 / nF / 建物の階の名前)に寄せた
- 試験: `scripts/js/building.test.js` に萩友会館の形(22 件すべて通過)・check.php に 2 つ(すべて通過)
- **気づいたこと**: 「寮食堂」を押すと萩友会館の階が出る(建物の階の手がかり `食堂` が「寮食堂」にも当たる)。利用者「別の建物」→ 手がかりを「萩友会館」だけにした(上の段 6 の記録)

### 2026-11-07 — 段 4-1 本番の Web 地図の速さを測り直した(利用者の指示「このPCのChrome、スマホはPixel７で」。錠は解けていた)

perf-probe.js と同じ手順(1 階・ズーム 4 段の出し入れ・横へ 8 回)。PC は利用者の Chrome(最大化のまま幅を変えられなかったので 1920px)、
スマホは Pixel 7a の Chrome を adb の転送(`chrome_devtools_remote`)で PC から動かし、読み直してから測った(2 回)。
| 幅 | 床の画像 | 要求 | DOM | 拡大中の札 | 大きさの測り | コマ 中央値 / 95% / 最大 | 50ms 超 | 長い処理 |
|---|---|---|---|---|---|---|---|---|
| 10-30 PC 977px(直す前) | 17 枚・329KB | 38 | 334 | 73 | 1709 回 | 4.2 / 4.3 / 37.3ms | 0 | 0 |
| 10-30 スマホ幅 375px(直す前・PC で) | 17 枚・329KB | 38 | 334 | 73 | 1601 回 | 4.2 / 4.3 / 20.8ms | 0 | 0 |
| **11-07 PC Chrome 1920px** | **4 枚**・70KB | 33 | 332 | 64 | **172 回** | 4.2 / 4.3 / 45.8ms | 0 | 0 |
| **11-07 Pixel 7a Chrome 400px**(1 回目) | **4 枚** | 34 | 331 | 64 | **172 回** | 11.1 / 11.2 / 120.9ms | 3 | 3(計 273ms) |
| **11-07 Pixel 7a Chrome 400px**(2 回目) | **4 枚** | — | — | 64 | **172 回** | 11.1 / 11.2 / 122.5ms | 2 | 2(計 198ms) |
- 端末の速さに左右されない数は**大きく減った**: 大きさの測り 1709 → 172 回(約 1/10)・床の画像 17 → 4 枚。読み込みは最初の応答 55〜75ms・load 270〜330ms
- Pixel は 90Hz(1 コマ 11.1ms)で、ほぼすべてのコマが間に合う。ただし **100ms 前後の長い処理が 2〜3 回**ある(ズームの出し入れのどこか)。気になるなら次に、どの段で出るかを開発者ツールで追う
- 10-30 のスマホの値は PC の内蔵ブラウザで幅だけ 375px にしたもの。実機の値は今回が初めて

### 2026-11-07 — 段 7: 来場者の画面をすべて英語に・「教室」からトイレを外す・共有のリンクがアプリで開いた(利用者の答え「#109 は全て英語に、#110 はトイレを外して」。アプリ 版 56 は apk-push 済み・Web は配備待ち)

- **段 3-2 は完了**: 19:47 に Google の窓口が `com.ito.kosenmap` を返し、端末の確認をやり直して `ito4.jp: verified`。`https://ito4.jp/` はアプリが開き、地図でない頁(news.php)は今までどおりブラウザ。
  国の拒否で Google を 443 だけ通したあと、**Google が失敗の結果を取り直すまで 3〜4 時間**かかった(その間 Google からの接続は 0 件)
- **#110**: トイレの地点も種類は `room` なので「教室」に並んでいた。「教室」のジャンルに外す言葉(WC・トイレ・便所・お手洗い・restroom・toilet)を足した(企画のある部屋は残す)。
  Web は `Main/app.js` の KM_GENRES の `room.exclude`、アプリは `EntryGenres.kt` の `MapGenre.ROOM.exclude`。check.php(2964 通過)・`EntryGenresTest.classroomGenreLeavesOutRestrooms`。実機の英語の画面で、Classrooms にトイレが無いことを確かめた
- **#109**: 設定の画面は XML から作るので `ui()` が書けない。言語はアプリの中の切り替えで、端末のロケールではないので values-en も効かない。
  そこで `SettingsEnglish.kt` に**キーごとの英語の表**を置き、画面を作った直後に題・説明・選択肢を差し替える(保存する値は変えない)。管理の項目(ADMIN_REQUIRED_PREFERENCES)は日本語のまま。
  `SettingsEnglishTest` が、来場者に出る項目・分類の見出しがすべて表にあることと、選択肢の数が XML と同じことを確かめる
- ほかに英語にしたもの: 表示調整のパネル・メニュー(ログイン)・人数の行・ログイン / ログアウト・更新の知らせと通知・受信テスト(進み具合と結果)・Wi-Fi の短時間認識の手順・AR の説明と案内と状態・
  ランキング(タブ・同意)・アカウント・写真(ストリートビュー)・マップ取得の知らせ・アクセスコード・地点の詳細の AR・役割の名前・種類の名前・色分けの凡例。
  英語の名前がある地点は、一覧の 2 行目に日本語の部屋番号(「管-120」)を出さない(番号は英語の名前に入っている)
- サーバーが返す文(エラーの本文)は日本語のまま。版 55 は `Test\Old\release-v55-20261107\` へ写した
### 2026-11-07 — 段 3-1 を実機で(Pixel 7a・Android 17)。英語の画面と全画面の窓を直してアプリ 版 55(利用者の指示「バックグラウンドで続けつつ、段 3-1 を進めて」。apk-push 済み)

- **直したもの**(アプリ。両フレーバーの単体試験は全部通過・版 1000055・署名 d71fbf0a…):
  - ジャンル・検索の一覧で、1 行目が「Class 2C (Adm-120) 管-120(1F)」、2 行目が「1F ・ 管-120」と同じものを 2 回出していた → 1 行目は名前だけ(`roomTitle`)
  - 2 行目に建物の階の内部の名前 `BLDG_TECHNO_1F` がそのまま出ていた → 階の名前。建物の名前だけの副題は繰り返さない
  - 建物の階に英語の名前を足した(Library 1F・Shuyu Hall 1F・Techno Center 1F・Gym 1・Budokan・Machine Factory・idemitsu Miraichi。段 2-1 の下書きと同じ呼び方)
  - 地図の検索の一覧が、英語の画面でも日本語の名前のままだった
  - **全画面の Dialog の下の端が画面の外だった**: 窓が y=118 から高さ 2400 で置かれ、下の端は 2518(118px はみ出し + バー 130px)。
    最初の画面の一覧の最後の行が押せず、札・QR と地図の QR の「閉じる」が見えなかった。窓の中からはバーの高さが 0 に見え、`navigationBarsPadding`・`decorFitsSystemWindows = false`・窓の MATCH_PARENT のどれも効かなかった
    → 本体の窓からバーの高さを読み、窓の下の端が見える所を越えた分を空ける(`FullScreenDialog.kt`・試験 `FullScreenDialogTest`)
  - 初めて開いた地図が図の左上(1F では建物の無い白い所と図の外の黒い帯)だった → その階の地点の真ん中へ寄せる(`initialMapFocus`)
  - 経路を出すと、上の「使う階」の札が「AR 案内」のボタンに重なっていた → AR 案内が出ているときは下へずらす
  - 札・QR の画面・地図の QR の読み取りを英語に対応させた
- **確かめた・問題なし**: 英語の最初の画面・ジャンルのチップ・1F→2F の経路の矢印(1F の上り口 270° から、▲ Up to 2F)・札・QR のカメラ
- **残り(来場者が見る画面で日本語のまま)**: 表示調整のパネル・メニューの上の段(「オンライン 1 / 全 3・組織内 3…」)と「アプリロック解除」・設定。
  日本語の文は 95 ファイルに約 2,100(多くは管理ビルドだけの画面)。範囲を利用者と決める
- **気づいたこと(データ)**: ジャンル「教室」は種類が「部屋」の地点を全部入れる決まり(Web と同じ)なので、トイレ(男WC・女WC)も並ぶ
- **実機でしか見られないもの(利用者の手が要る)**: 横画面と全画面・ピンチで帯を隠す(adb では 2 本指を送れない)・予定と屋台(イベントを入れたとき)
- 実機の台本 `Test/scripts/device-check.ps1`。版を進めずに試す版を作るときは `gradlew assembleVisitorRelease "-Pkosenmap.versionCode=55"`(**PowerShell では引用符で包む**。包まないと `.` で切れて失敗する)

### 2026-11-07 — 共有のリンクがアプリで開かなかった: 国の拒否が Google の確認を落としていた(段 3-2。利用者の答え「Google だけ 443 を通す」。手元で済み・配備と当て直しは利用者)

- **実機**(Pixel 7a・Android 17・版 52): `pm get-app-links com.ito.kosenmap` が `ito4.jp: 1024`(確認の失敗)で、`?room=`・`?from=&to=`・`rain=1` 付きのどれも Chrome で開いた
- **assetlinks・署名(D7:1F:BF:0A…)・アプリの受け口(一般向けだけ・path="/")・共有のリンクの引数の読み取りは、どれも合っていた**
- **原因**: 国の拒否が「日本だけ通す」(`bans.json`: `invert: true, deny: ["jp"]`)。App Links を確かめるのは端末ではなく Google のサーバーで、海外から 443 番の assetlinks.json を取りに来る。
  Digital Asset Links の API が `UNREACHABLE_CONNECT_TIMEOUT`(接続の時間切れ)を返していた
- **直したこと**(`scripts/host-geoblock.sh`): `ALLOW_GOOGLE=yes` で、国で落とす前に `GOOGLE_PORTS`(既定 443)だけ次を通す
  - Google の巡回と取得の一覧(common-crawlers・special-crawlers・user-triggered-fetchers・user-triggered-fetchers-google)
  - Google 全体(goog.json)から Google Cloud の利用者の分(cloud.json)を除いたもの。cloud.json が無ければ当てない
  - 一覧は国と同じく update で取り、失敗したら前の一覧を使う。既定は no
- `host-stats.sh`: 通した数を `google.packets` に分け、落とした数に混ぜない。見本 `geoblock.local.conf.example`・取扱説明書 [04](04-host-jobs-and-mail.ipynb) §9-1
- **試した**: 本番のホストの使い捨てのコンテナ(自分の網の中だけ。本番の防火壁には触れない)で update・`apply --dry-run`(`nft -c` 通過)・apply。
  Googlebot の IP は通し、Google でない海外の IP は落とし、日本の IP は今までどおり通した。check.php 5 件(全 2963 件通過)
- 実機の台本 `Test/scripts/device-check.ps1`(状態・入れる・リンク・写真・アプリの言語・クラッシュ)。写真は `Test/captures/`(GitHub に出ない)
- 階段の向き(段 2-2): 本番の `km_map_node_stairs` に 11 個の上り口が全部入っていた。配備のあと、本番のデータで埋め方を数えて
  **階段 38 地点の上り・2F 以上の下りが全部決まった**(決まらないのはエレベーター 9 地点だけ)→ **段 2-2 は完了**(プロジェクト状況 #95)
- 配備は済み(11-07。Main と admin と host-geoblock.sh が手元と一致)
- **当てたあと**: 1 回目は設定の行が引用符の崩れで `"ALLOW_GOOGLE=yesnGOOGLE_PORTS=443n"` になり効かなかった(私が渡した 1 行のコマンドの誤り。PowerShell → ssh → sh で崩れた)。ホストのシェルで直に打って効いた(`google.allowed: true`)
- それでも Google の窓口は時間切れのまま・端末の確認も 1024。海外から 443 番への新しい接続を nft で記録したところ(`nf_log_ipv4` は効いている)、窓口を 3 回呼んだ間に **1 件も来なかった**。
  72 時間の nginx の記録にも Google からの要求は 0。**Google が失敗の結果を取っておいて取り直していない**見込み。10 分おきに 3 時間見続ける(通ったら端末で `pm verify-app-links --re-verify`)
- 段 3-3(Wi-Fi 測位の比べ)・3-4(AR の実測)は保留へ(利用者、11-07)

### 2026-11-07 — 階段の向きは一番下の階だけ入れればよい(利用者の指示「1 階のみ指定すれば良い。2F の降りるは 1F 登るの反対」。色分けの絵は利用者が確認 OK。Web は配備待ち・アプリは版 52 で公開)

- **決まり**(Web の `Main/route-view.js` の `filledStairHeadings` とアプリの `StairGuide.kt` の同じ名前の関数): 同じ階段の組ごとに下の階から順に、
  入っていない**上り口はすぐ下の階の上り口と同じ**、入っていない**下り口はすぐ下の階の上り口の逆(+180°)**。**手で入れた向きが優先**(造りの違う階だけ入れれば直せる)。
  エレベーター・外・建物の階は埋めない。データは書き換えず、描くときに埋める(イベントで隠した階段も材料にする)
- Web: 経路の「▲ 2F へ上る」と、拡大したときの「▲上行き」の両方の矢印がこれを使う。node の試験 8 件(全 50)・check.php 2 件(全 2958 件通過)
- アプリ 版 52(1000052・署名 d71fbf0a…): 試験 `StairGuideTest.headingsComeFromTheFloorBelow`・全試験通過。版 51 は `Test\Old\release-v51-20261107\`。apk-push 済み
- **入れる数は 61 → 11**(`server/out/stairs-headings-lowest-2026-11-07.csv`)。図書館・テクノセンター・萩友会館の階段 3 系統(7 地点)は、建物の階では矢印を出していないので要らない

### 2026-11-07 — 管理画面「地図の色分け」が読み込み中のまま(利用者の報告。10-30 からの不具合・手元で直した・配備待ち)

- **原因**: 計算の部品を `km_public_asset('Main/area-segmentation.js')` の**相対パス**で読んでいたので、`/admin/Main/…` を探して 404(text/html)になり、画面の JS が黙って止まっていた。
  管理のオリジンでも `/Main/area-segmentation.js` は 200(text/javascript)で返るので、頭に `/` を付けた
- 部品が読めないときは「読み込み中…」のままにせず、赤字で理由を出す
- check.php: 管理画面の `src="<?= km_e(km_public_asset(` を禁止(組 `area-paint-app`。14 件)。全体 2956 件通過
- 同じ画面の console に出る overlayscrollbars の CSP(style-src)の警告は、管理画面のどの頁でも出るもので、この件とは関係ない
- 利用者の答え: 下書きの CSV は OK・取り込みまで済んだ(11-07)

### 2026-11-07 — 段 2 の答えを入れた・色分けの絵は管理アプリから(利用者の答え「寮の棟は Dormitory A など・萩友は しゅうゆうかいかん で合う・守護室は守衛室・回析は回折・液体は流体ではない・色分けの絵は Android アプリのものを」。手元で済み・配備待ち)

- **2-1 下書きの CSV を作り直した**(`server/out/aliases-draft-2026-11-07.csv`): A〜E棟 → `Dormitory A`〜`E`・液体工学実験室 / 液体力学計算室 → `Liquid …`・部屋番号の英語(`Adm-`・`Adv-`)はそのまま。
  守護室・X線回析装置室は**名前の打ち間違い**なので、読み・別名に「守衛室」「X線回折」を入れ(直す前も後も探せる)、`check` の列に「地図の編集で直す」と書いた。確かめてほしいことは 17 → 7
- **2-3 色分けの絵は管理アプリが塗ったものを使う**: 新しい口 `api/area-paint.php`(POST・**管理者のトークンだけ**・1 回に 1 階の PNG 8MB まで・nginx はこの口だけ 12MB)。
  受け取るのはアプリの階の表記(`1F`・`OUTSIDE`・`BLDG_…`)で、その階の床の画像が**組み込みのまま**で、**大きさが同じ**ときだけ(Website で差し替えた階 = 本番の第二体育館は断る)。
  塗りの記録に `origin`(`app` / `web`)を足し、管理画面「地図の色分け」に「アプリの絵」と出る。タイムラインの文言 `map.area_paint_app`
- **Website の組み込みの床の画像とアプリの下絵は同じファイル**(17 階のうち 16 は SHA-256 まで同じ・第二体育館は大きさだけ同じ)。check.php の組 `area-paint-app`(12 件)が突き合わせる。全体 2954 件通過
- **アプリ 版 51**(1000051・署名 d71fbf0a…): 管理ビルドの「設定 → 地図の色分けを Website へ送る」。各階を地図の画面と同じ計算で塗り、1 階ずつ送って階ごとの結果を出す。
  試験 `AreaPaintUploadTest`(URL・本文・階の表記・上限)。版 50 は `Test\Old\release-v50-20261107\`。apk-push 済み(自動で公開)

### 2026-11-07 — 段 1 を閉じ、段 2(地図のデータ)を始めた・進み具合はプロジェクト状況で共有(利用者の指示「1-3 OK。次に進めて。今回からプロジェクト状況の API で進捗共有を」)

- **段 1 は完了**: 本番で確かめた —— 口 `api/project-tasks.php` は鍵で通る・テンプレート 9 種類とも正本どおり・DKIM の旧鍵は `retired-2026-11-07/` に退避済み
- **プロジェクト状況に plan.md の段 1〜6 と保留 3 つを登録した**(Id 91〜107。段の番号・分類・メモつき)。以後の進み具合はここを正とし、`scripts/proj-tasks.ps1 list` で見られる
- **手元の道具の直し**: 応答の分類の「なし」の鍵が空文字 `""` で、PowerShell の既定の JSON 読みが受け付けず文字列のまま返していた → ハッシュ表として読む
- **2-1 英語の名前・読み**(手元で済み・配備待ち): 管理画面「地点の読み・別名・URL」に **CSV の書き出し・取り込み**を足した(`lib/search-alias-csv.php`)。
  上げる → 変わる行だけを前 → 後で見せる → 「取り込む」で書く。空のセルは変えない・`-` で消す・見せたあとに画面で直された行は上書きしない・Shift_JIS も読む。
  下書きは `server/out/aliases-draft-2026-11-07.csv`(本番の 511 地点。英語の名前は全部・読み 322・`check` の列に確かめてほしいこと 17)。
  本番は英語の名前・読みとも 0 件だった。名前に教職員の氏名は無かった(研究室・教員室は番号だけ)
- **管理の記録の文言の抜け**: `search-aliases.save`(10-30 から)に文言が無く、タイムラインに内部名が出ていた。check.php の拾い方がハイフンを拾わず気づけなかった → 文言を足し、拾い方も直した
- **2-2 階段の向き**: 本番の階段 55 地点すべて向きなし(うちエレベーター 10 は要らない)。一覧 `server/out/stairs-headings-2026-11-07.csv`(45 地点・14 系統・入れる値 61)。入れるのは管理アプリ
- **2-3 色分け**: 本番の 17 階どれも塗りなし。管理画面「地図の色分け」で作る。手順は [11-getting-started](11-getting-started.ipynb) §4 に足した(前の版は `server/Old/docs/`)
- 見つけた名前の気がかり(下書きの `check` の列): 守護室(守衛室?)・X線回析(回折?)・液体工学 / 液体力学(流体?)・「エレベーター 4号棟 4号棟」・「女WCお手洗い」・名前の途中の改行 2 つ。
  ほかに、同じ番号の部屋(教員室 管-306 が 2 つ・7-202 と 7-203 に複数)・図書館 2F の階段が同じ名前で 2 つ

### 2026-11-07 — 段 1 とプロジェクト状況の口(利用者の指示「段 1 から進めて。段 7 からはプロジェクト状況に追加(分類も)。編集できる API を。docs の .env の KM_PROJ_PASSKEY を使わないとアクセスできないように」。手元で済み・配備待ち)

- **1-1 旧ドメインの DKIM 鍵**: 本番で読むだけで確かめた —— KeyTable・SigningTable は `ito4.jp` だけ、`ito8795.com.private` は `ito4.jp.private` と**同じ中身の写し**(09-17 の切り替えで複製した元)。
  使われていないので、鍵の置き場の中の `retired-2026-11-07/` へ移すコマンドを利用者に渡した(消さない)
- **1-2 Logto の設定**(Logto の DB を読むだけ): MFA・パスワード方針は「いま」の表のとおりで直すものはない。
  メールのテンプレートは 9 種類のうち SignIn だけが正本どおりで、8 つは件名の `(Verification Code)` が無い古い形だった(10-12 に Console へ貼ったのが残っていない)。
  `src/scripts/logto-email-templates.php` を作った —— 既定は比べるだけ・`--apply` で SMTP のコネクタの `templates` だけを入れ替え(送信サーバーなどの設定は読んだまま返す・画面に出さない)・書いたら読み直して確かめる。
  正本は手元の `docs/logto-email-templates.json`(配備しない)を標準入力で渡す。本番で比べるだけを流して「8 つが違う」を確かめた
- **1-3 メールの見え方**: `--apply` のあと利用者が端末で見る(手順は利用者に渡した)
- **プロジェクト状況の口** `api/project-tasks.php`(GET 一覧・1 件 / POST 足す・まとめて 50 件まで / PATCH・PUT 直す / DELETE 消す)。
  鍵は `lib/proj-passkey.php` にまとめた(**今後ほかの口もこれを使う**): 環境変数 `KM_PROJ_PASSKEY` が無い・32 字未満なら 503、鍵は**ヘッダーだけ**(`X-KM-Proj-Passkey` か `Authorization: Bearer`)、
  URL に載せたら 400、sha256 どうしを hash_equals、**外れだけ**数えて 15 分 8 回で 429(錠 `proj-api`。当たりはロック中でも通す)、外れは 0.3〜0.6 秒待たせる。
  書いた操作はタイムラインに「プロジェクトの鍵(API) がタスクを…」と残る。nginx はこの口のクエリをログに残さない・本文 64KB・回数は km_api
- **分類**を足した: サーバー・運用 / セキュリティ / Website / アプリ / 地図のデータ / 検証環境(+ サービス監視)。**段の番号とメモ**は新しい表 `km_task_details`(km_tasks に列は足さない)。画面「プロジェクト状況」で出して直せる
- **鍵の置き場**: 正本は手元の `server/docs/.env`(配備は docs を送らない・GitHub の控えは .env を除く)。本番の `/opt/kosenmap/.env` へは `scripts/proj-passkey-sync.ps1` が写す
  (鍵は引数に載せず標準入力・前の .env は `.env.before-proj-passkey-<日時>` に残す・突き合わせは sha256 だけ)。手元の道具は `scripts/proj-tasks.ps1`(list・get・add・update・remove・topics)
- **確かめたこと**: check.php の組 `proj-api` 36 件。手元で口を呼び、鍵なし 503・URL の鍵 400・メソッド違い 405・外れ 401・8 回目から 429・当たりはロック中でも通ることを見た。
  SQL は本番の MariaDB で**一時表だけ**(km_tasks も一時表で影にした)で流し、結合・上書き・lastInsertId を確かめた(本物の km_tasks は 0 件のまま)
- **組み直す前の status.md** は `server/Old/docs/status.before-restructure-2026-11-07.md`

### 2026-11-07 — phpMyAdmin は常時 OFF・トンネル開通SSH.cmd で起こす(11-07、利用者の指示「OFF にする条件は Chrome を終了したとき」。11-07 昼に本番で確認: 12:00:02 に起き、Chrome を閉じたあと 12:02:03 に止まった)

18〜21 時の形は外した。
トンネルの SSH を `-N` から `-t … "while touch .kosenmap-pma-wanted; do sleep 20; done"` に変えた(km の家の目印を 20 秒ごとに触る。sudo は要らない)。
`host-pma-window.sh` は cron 版 13 で **1 分ごと**に、目印が km の持ち物の普通のファイルで 60 秒以内に触られていれば起こし、そうでなければ止める(時刻と持ち主だけ見る・リンクはたどらない)。
バッチは phpMyAdmin が SOCKS 越しに答えるまで(最長 2 分)待ってから Chrome を開き、Chrome を閉じると合図を出しているトンネルを切る → 2 分以内に止まる。
トンネルなしで使うときは km で `--open 60`。管理画面の設定に出す手張りのコマンド(`km_site_logto_console_tunnel()`)も同じ形にした。前のバッチは `server/Old/desktop/`

### 2026-11-07 — 見直し(利用者の答え: カーネル再起動 OK・Logto 1.46.0 OK・旧ドメイン解約 OK・イベントの地点はイベント中のみ出す・日曜の自動処理と画面の確かめは保留)

- **本番で確認(読むだけ)**: カーネル 6.8.0-149(12:30 起動・reboot-required は消えた・km-ipopt active・MPTCP 0)、Logto 1.46.0、全サービス healthy、phpMyAdmin は止まったまま(正しい)。
  CVE-2026-98432 は Ubuntu の頁でまだ「Vulnerable, work in progress in 6.8.0-149.149」なので、つなぎは外さない
- **イベントの地点はイベント中だけ、いちばん引いた倍率でも出す(手元で済み・配備待ち)**: Web は `buildFloorMarkers` がイベントの地点(企画・臨時の名前・ポスター・一時地点)を
  `event:<種類>` の束に分け、`mapStyle.eventVisible` が**表示を始める倍率だけ無視**する(種類を外す・部屋名を消す・建物名が拡大で消えるは今までどおり)。
  写し(1F に企画・臨時の名前・一時地点・ふだんの部屋)で、倍率 -1 で赤い縁の 3 つだけが出て、イベントでないときは出入口だけ(今までどおり)を確かめた。
  アプリは `visibleGeneralMapNodes(eventNodeUuids = …)` で同じ決め方(版 50 = 1000050)。試験 map-style 68 件・GeneralMapNodeRendererTest
- **本番の置き去り 16 個**: 手元と本番の 515 ファイルを突き合わせると、違うのは今日の 3 つだけ(= ここまでの「配備待ち」はすべて配備済み)。
  手元で前に外した AdminLTE の見本(invoice・pricing・maintenance。サインインが要るので害なし)・移行スクリプト・check.php にまとめる前の 4 本・旧ロゴが本番に残っていた。
  `deploy-to-host.ps1` の退避の一覧に足した(次の配備で `retired/` へ移す。消さない)
- **旧ドメインの名残**: 本番は使われない DKIM 鍵(`ito8795.com.private`)と nginx/km/hsts-enable.conf のコメントだけ。手元の「旧名も本番として扱う」判定は、控えに旧名が残るので残す
- **status.md の古い「未解決」を閉じた**: §6 の #4(決着)・#7(配信の版・期限の作りで置き換え)、10-06 の残り 3 つ、案内チャットの記録の「模型」の欄(本番にある)、版 45 の未送信、compose の config -q

### 2026-11-06 — 検査のあとの 4 手順(11-06、利用者の指示「この手順で行きましょう」。配備済み)

- (1) digest: タグの無かった固定(nginx・certbot・postfix・mailpit)に追うタグを付けて新しい digest に上げた(`nginx:1-alpine`・`certbot/certbot:latest`・`boky/postfix:latest-debian`・`axllent/mailpit:v1`)。
  タグが無いと update-containers.ps1 の refresh が比べられなかった。**postfix は 5.1.0 → 5.2.1。5.2 から DKIM の既定が rspamd に変わったので `DKIM_BACKEND: opendkim` で今までの形を保つ**(鍵はそのまま)。
  残り(phpMyAdmin・Postgres・php:8.4-apache・composer:2)は同じタグの新しい digest があるので `update-containers.ps1 -Choice refresh` が上げる。Logto 1.46.0 は別(`-Choice logto`。DB の移行あり)
- (2) web の作り直し: refresh の最後の `docker compose build --pull` が新しい土台で作る
- (3) phpMyAdmin: `scripts/host-pma-window.sh`。cron 版 12 で 5 分ごと、**18:00〜20:59(日本時間)だけ動かし、外は止める**。時間の外に使うときは km で `--open 60`(1〜180 分)、取り消しは `--close`。
  配備の `up -d` で起きても 5 分以内に止まる。nginx は phpMyAdmin を通さないので、止まっていても他に響かない
- (4) 次の検査(配備のあと手で、または日曜 4:47)で残った分を見て、nginx だけ Chainguard を試すか決める
- **結果(11-06 朝、利用者が refresh・cron 版 12・再検査を済ませた)**: 直る版のある HIGH・CRITICAL は **606 → 156**。
  **nginx は 0 件(CRITICAL・HIGH とも 0)なので Chainguard は試さない**(判断済み)。web は直る版 0(残り 85 は Debian に直しが出ていないもの)、postfix は 2(残り 131 も同じ)。
  残りの多くは Logto 1.45.0 の npm(60)・MariaDB/Postgres の gosu(28・26)・glances(24)・phpMyAdmin(11)・certbot(5)で、上流の作り直しか Logto 1.46.0 待ち。
  postfix 5.2.1 は opendkim で `DKIM-Signature … (s=mail, d=ito4.jp)` を付けている(ログで確認)。phpMyAdmin は時間の外で止まっている(7:55 に Exited)。
  手で回した検査は知らせ済みにならないので、11-08 4:47 のメールは 156 件ぶん

### 2026-11-06 — JAVA_HOME(11-06)

`scripts/java-home.ps1`(この PC。既定は見るだけ、`-Fix` で直す)。原因は**ユーザーの JAVA_HOME が古い JDK(21.0.12 の build 101)を指したまま全体(21.0.13+9。インストーラーが直した)を上書きしていた**こと。
-Fix はユーザーの方を消し(次の更新でもインストーラーの値が効く)、全体の PATH に残った古い jdk の bin を外し(管理者のときだけ)、gradle.properties を新しい JDK に合わせる

### 2026-11-06 — イメージとビルドのキャッシュの片付け(11-06、利用者の指示「自動で解放」。配備済み)

`scripts/host-docker-prune.sh`。cron 版 11 で毎週日曜 5:47(検査のあと。検査の錠を待つ)。
消さないもの: コンテナ(止まっているものも)が使う像・compose の像・Dockerfile の FROM・Trivy・道具(`alpine:latest`・`composer:2`)・**compose の像ごとに 1 つ前の版**。ボリュームには触らない。
ビルドのキャッシュは 14 日より古いものだけ。既定は見るだけ(`--apply` で消す)。本番で見るだけを流すと、消すのは 15 個・約 10.5GB(test-web・test-soketi・kosenmap-soketi・Logto 1.42/1.43・古い phpMyAdmin 5 つ…)、
残す 1 つ前の版は Logto・MariaDB・nginx・glances・phpMyAdmin・Postgres の 6 つ。起こしていない profile の mailpit は残さない

### 2026-11-05 — HTML 展開サイト(11-05、利用者の指示。配備済み)

ZIP・HTML・CSS・JS・画像・フォルダを落とすと、実際の URL で開ける。リンク集に貼るのは道具のページ `https://ito4.jp/html.php`。
**見せる側は別のアドレス `https://html.ito4.jp/`**(上げたスクリプトが ito4.jp の上で動かないように。`__Host-KMSID` は渡らない)。入口は `html-view.php` 1 本で、nginx の新しい server がすべての要求をここへ書き換える(本体の server では 404)。
- 置き場所は 3 つ:
  - **このブラウザ(既定)**: 見えない iframe の `html.<ドメイン>/bridge.html` へ postMessage で渡し、IndexedDB に置いて Service Worker(範囲 `/l/`)が返す。**ito4.jp と html.ito4.jp が同じサイトであることが前提**(別サイトだと保存領域が分かれて SW が効かない)
  - **サーバーの一時フォルダ**(ローカルが使えないとき): `uploads/html-temp/`・24 時間・上げたブラウザだけ。夜の控えには入れない(host-backup.sh の除外)
  - **サーバーに保存**(サインイン時): `uploads/html-sites/<id>/`(中身は sha256 の blobs と版ごとの `v<N>.json`)。**版は 10 まで、11 版目でいちばん古い版を消す**(番号は増え続ける)。1 人 10 サイト・300MB。止められた・テスト用のアカウントは保存できない
- 見るのは本人だけ: 道具のページで 60 秒・1 回限りの札を作り、`html.<ドメイン>/auth` が札を消費して `__Host-KMHV`(ブラウザを閉じるまで・DB 側は 12 時間)を置く。**サインアウトでその人の印を全部無効にする**(写しで、サインアウト後に同じブラウザで見られたのを見つけて直した)
- CSS・JS だけを落とすと、選んでいるサイトに足す(同じ名前は差し替え。サーバーなら新しい版)。HTML が 2 つ以上なら入口を選ばせる。上限: 送る ZIP 20MB・展開 40MB・1000 ファイル
- 表(使ったときに作る): `km_html_sites`・`km_html_versions`・`km_html_temp`・`km_html_tickets`・`km_html_viewers`。アカウントを消すとサイトも消す。管理画面「バックアップ」に一覧と削除(記録 `html.delete`。中身は管理画面から開けない)
- 規約に第8条の2・プライバシーポリシーに Cookie と保存期間を足した(`KM_LEGAL_UPDATED` 2026-11-05)。nginx に zone `km_html`・compose に `KM_HTML_DOMAIN`/`KM_HTML_URL`・host-cert.sh の確かめに html の名前
- 確かめ: check.php に組 `html-host`(全体 2741 件通過)。写し(PHP の組み込みサーバー・SQLite・`app.localhost` と `html.app.localhost`)で、ローカル・一時・保存・版 11・CSS だけ・入口の選択・札の使い回し 404・他人や Cookie なしの 404・サインアウト後の 404・375 幅を見た
- **配備の手順**: (1) DNS に `html.ito4.jp` → (2) `-Action up` で配備 → (3) ホストで証明書に `html.ito4.jp` を足す(今の名前も並べる)→ (4) リンク集に `https://ito4.jp/html.php`
- **11-05 に (1)〜(3) を利用者が実施**: DNS は A だけ(ito4.jp の名前にはどれも AAAA が無い)。証明書は 17:14 に 4 つの名前で取れた(期限 2027-02-03)が、
  **`host-cert.sh issue` は今のドメインに名前を足すときも「ドメインを変える」案内を出して reload しなかった** ので、nginx が古い証明書のままだった。
  issue は今の `KM_DOMAIN` と同じなら reload して status を出すように直した(check.php 2742 件)。`km` で走らせると docker が無いので「certbot がありません」で何もせず終わる(`kmops` で走らせる)
- **renew で reload したあと本番で確かめた(11-05)**: `html.ito4.jp/` は 302 で道具のページへ・でたらめな `/s/`・`/t/`・`/l/` は 404・POST は 405・`/auth` の悪い札と cross-site は 404・
  bridge.html の CSP は `frame-ancestors https://ito4.jp`・robots.txt は Disallow・本体の `/api/html-host.php` は GET 405・CSRF なし 403
- **使い始める前の関門(11-05、利用者の指示「展開する前に、利用規約と I'm not a robot を表示」。配備済み)**:
  道具のページは、規約の第8条の2(と全文を開ける details)・「利用規約に同意します」・reCAPTCHA を出し、通るまで落とす場所を出さない。
  口の `gate` が同意と reCAPTCHA(action `html_host`。問い合わせと同じ `km_recaptcha_verify`、届かなければ通さない)を確かめ、**セッションに規約の版と期限(2 時間)を置く**。
  サーバーに置く `temp`・`temp_patch`・`save` は印が無ければ 403(`gate: true`)で断り、ページが関門を出し直す。このブラウザに置くときはページの JS が同じ期限を見る。
  Google のスクリプトは関門を出すときだけ読む。CSP の html-host に reCAPTCHA(パスまで)を足した。規約の第8条の2と、ポリシーの 7(reCAPTCHA の使い道)を直した。
  check.php 2761 件。写しで: 同意なし・確認なし・違う確認は断る、口を直に叩いても 403/400、通ると落とせて一時フォルダにも置ける、読み直しても通ったまま、
  期限切れで落とすと関門が出て reCAPTCHA を読み込む、375 幅ではみ出しなし(Google の試験用キーは今は無効と出るので、写しの判定だけ差し替えた)。本番の reCAPTCHA は enterprise・`ito4.jp` で使える状態
  **11-05 に配備済み(利用者)**: 本番の道具のページは関門を出して落とす場所を隠す・関門の前の `temp` は 403(`gate`)・でたらめな確認は 400
- **ZIP の読み方を強くした(11-05、利用者の指示と試しの ZIP 5 つ。配備済み)**: ZIP を読む・作る・解くを `Main/html-zip.js`(`window.KmHtmlZip`。通信しない)に分けた。
  - 先頭のドライブ名(`C:/`)・`/`・`./` は断らずに外す(`..` は断る)。**同じ中身の木が 2 組**(`Users/…/web/` と `web/`)なら短い方の 1 組にする。いちばん上の階に HTML が 1 つならそれを入口に
  - **パスワード付き ZIP**: ZipCrypto と WinZip AES(方式 99・AE-1/AE-2・128/192/256)。パスワードはブラウザの中だけで使い、解いたものを暗号化しない ZIP に作り直して送る。
    AES の CTR は little-endian なので AES のブロック暗号化を JS に書いた(FIPS-197 の試験値 3 つで確かめた)。鍵は WebCrypto の PBKDF2-SHA1・HMAC を先に確かめる
  - **ZIP 以外の圧縮は断る**(7z・RAR・tar・gz・bz2・xz・zst・lzh・cab… を拡張子と先頭のバイトで。`.zip` に名前を変えた 7z も)。ZIP は単独で 1 つだけ。
    **型の表に無いもの(exe・docx・中の zip など)は除いて知らせる**(サーバーの `km_html_normalize_files` も除く)。サーバーは暗号化・方式 99・ドライブ名を断ったまま(最後の守り)
  - 確かめ: check.php 2772 件。写しで利用者の 5 つ(web・webfull・webzettai・webpass(パスワード 1234)・1web)がどれも 4 個で開き CSS・JS・README.md が効く。
    7-Zip で作った ZipCrypto(格納・deflate)・AES-128・AES-256 は正しいパスワードで開き、違えば聞き直す。本文を壊した AES は HMAC で断る。7z・tar・gz・`.zip` に改名した 7z/gz・BZip2 方式・ZIP 2 つ同時は断る。
    サーバーへの保存も解いた 4 個で届き、パスに `C:/Users/…` は残らない
- **共有リンク(11-05、利用者の指示「パスワードあり・なしで共有できるように(要アカウントログイン)」。配備済み)**:
  答え: ログインが要るのは共有する人だけ・いつも最新の版・期限は作るときに選ぶ(1 日・7 日・30 日・止めるまで)・中身の前に注意の画面を挟む。
  - **共有の中身は別のアドレス `https://share.ito4.jp/<32 字>/`**(html.ito4.jp の IndexedDB・Service Worker に他人の共有のスクリプトを届かせない)。
    nginx は html の server に `${KM_SHARE_DOMAIN}` を足しただけ。`html-view.php` がホストで `lib/html-share-view.php` へ振り分ける(本体のセッションは読まない)
  - 新しい表 `km_html_shares`(パスワードは `password_hash`・期限・開かれた回数)と `km_html_share_grants`(Cookie `__Host-KMSH`・12 時間・ブラウザを閉じたら消える)。1 サイト 5 本まで
  - 注意の画面はスクリプトなし(CSP `default-src 'none'`)。「開く」の POST は share のオリジンだけ → 止められた持ち主 → 外れの回数(IP ごと 15 分 8 回。先に数え、成功しても消さない)→ `password_verify`
  - 止めた・期限切れ・サイトを消したものは 404(行を消す)。管理画面のバックアップのカードに「共有を止める」(記録 `html.share_stop`)。規約第8条の2とポリシーを直し、関門の版を `#share` 付きにした(同意し直し)
  - 写しで見つけて直した: 注意の画面を `Referrer-Policy: no-referrer` にしていたので「開く」が `Origin: null` になり 403 → `same-origin` にし、`null` は `Sec-Fetch-Site` で見る
  - 確かめ: check.php 全部通る。写し(`share.app.localhost`)で: なし・ありで開く(CSS・JS が効く)、違うパスワード 401・9 回目 429(正しくても)、よそのオリジン・Origin なし・PUT は断る、
    新しい版を保存すると共有も新しい版、止めた・サイトを消したら(印があっても)404、5 本目の次は断る、画面のフォームから作れる、共有のページから html の IndexedDB は見えない、375 幅ではみ出しなし
  - **配備の手順**: (1) DNS に `share.ito4.jp`(A。AAAA は要らない)→ (2) `-Action up` で配備 → (3) **kmops で** `host-cert.sh issue` に `--also share.ito4.jp` を足して取る(今の名前も並べる。issue が自分で reload する)
  - **11-05 に配備済み(利用者)。本番で確かめた(読むだけ)**: 証明書に 5 つの名前(share 入り)・nginx の証明書と一致・web の `KM_SHARE_URL` は `https://share.ito4.jp`・
    `share.ito4.jp/` は 302 で道具のページへ・robots は Disallow・でたらめな番号は 404(CSP `default-src 'none'`・`Referrer-Policy: same-origin`・noindex)・スラッシュなしは 308・
    `/s/` の道は 404・よそからの POST は 404・PUT は 405・html のホストで共有の道は 404。表 `km_html_shares`・`km_html_share_grants` はでき、共有は 0 本(外れの回数の表は初めて間違えたときに作る)

### 2026-11-05 — 一関の天気で「雨の日」を自動に・予定に天気・イメージの検査(Trivy)・ベンチマーク(2026-11-05〜06、利用者の指示。配備済み)

依頼は「一関の天気で天候の項目に自動でチェック」「distroless・Chainguard はどうか。Trivy・Docker Scout は入っているか」「サーバーでベンチマーク」。計画は `~/.claude/plans/stateful-whistling-kazoo.md`。
- **頂いた `weather.tsukumijima.net` の `city=030030` は大船渡(沿岸南部)で、一関ではない。** 一関は気象庁の「内陸(030010)」・予報の気温の地点 33911・アメダス 一関 33911。答えは「気象庁の JSON を直接」。
- **天気(サーバー)**: `lib/weather.php`(予報 30 分・アメダス 10 分で取り直し、`cache/weather.json` に flock で置く。失敗しても 6 時間までは古いものを使う。取る先は `www.jma.go.jp/bosai/` だけ・転送しない・512KB まで)と
  `api/weather.php`(GET。`Cache-Control: public, max-age=300`)。雨とみなすのは、30 分以内の観測で 10 分の雨量 > 0 か 1 時間 0.5mm 以上、または今の 6 時間の降水確率 50% 以上(雪も同じ。気温 1℃ 以下の観測は雪と出す)。
  天気の文は `lib/weather-telops.php`(気象庁の 118 の番号)。`api/app-schedule.php` に `weather.days` を足した(今の形はそのまま)。
- **Web の地図**: 「雨の日」は**既定で自動**(15 分ごと・表に出ている間だけ読み直す)。自分で切り替えたらその日だけ手動(`km-route-options-v1` の `rainManualDay`)で「自動に戻す」が出る。
  共有のリンクの `rain=1` は今までどおり優先して天気では変えない。天気が分からなければ外す。予定(一覧と「会場 × 時間」)の日の見出しに天気と出典。`Main/weather.js`・試験 `scripts/js/weather.test.js`(20 件)
- **Android(版 49 = 1000049・署名 d71fbf0a…。両フレーバー。apk-push で送った = 検めに通れば公開)**: `RouteWeather.kt`。決め方は Web と同じで、**分からないときは今の値のまま**(オフラインで使う人の設定を壊さない)。
  設定に「雨の日を天気で決める」・予定の日の見出しに天気。試験 `RouteWeatherTest.kt`。手元の `JAVA_HOME` が無くなった JDK(21.0.12.101)を指していたので `gradle.properties` を 21.0.13.9 に直した
- **イメージの検査**: Trivy も Grype も Docker Scout も入っていなかった(10-06 に手で 1 回回しただけ)。`scripts/host-image-scan.sh` を足し、cron 版 10 で**毎週日曜 4:47**。
  **docker.sock は渡さない**(`docker save` した tar を読み取り専用で `--input`)。Trivy のコンテナは root の FS も読み取り専用・権限なし・1GB・1 コア。
  **Trivy の配布物に供給網の事件があった**(CVE-2026-33634。v0.69.4 と Docker Hub の 0.69.5・0.69.6)ので `aquasec/trivy:0.76.1@sha256:790fd66d…` に固定(Docker Hub と GHCR で同じ digest。0.76.2 は出たばかりで避けた)。
  直る版のある HIGH・CRITICAL が**新しく出たときだけ** `notify-security.php` で送る(種別 `images`)。結果はタスクマネージャーの「イメージの検査」。**distroless・Chainguard は初回の結果を見てから決める**(利用者の答え)
- **ベンチマーク**: タスクマネージャーの「ベンチマーク」(押す・履歴・前の回や選んだ回との差・消す)・`scripts/bench.php`(ホスト)・`scripts/bench-web.ps1`(この PC から外の応答。`bench-record.php` で同じ表へ)。
  13 段(CPU 6・メモリ・ディスク 2・DB(一時表だけ)・画像・アプリ・Web(中から))。**1 回空回し → 3〜5 回の中央値・ばらつき 10% 超えに印・スコア = 1000 × 基準 ÷ 中央値・合計は相乗平均(データで変わるアプリと Web は入れない)・版が違えば比べない**。
  基準は 11-06 に本番で 2 回測って固定した(2 回の差は 3% 以内。1 回 25〜35 秒)。sha256 は本番の CPU に専用の命令が無く手元の 8 倍かかるので 128MB にした。同時に 1 つ(GET_LOCK と running の行)。新しい表 `km_bench_runs`。
  口 `admin/api/bench.php` は nginx でタスクマネージャーと同じく IP で絞る。記録 `bench.run`・`bench.delete`
- 確かめ: check.php 全部通る(組 `weather`・`image-scan`・`bench` を足した)。写しで天気(雨の予報・観測・雪・晴れ・分からない・手動のその日だけ・375 幅・英語)、ベンチマークの画面(進み具合・失敗した段・差・375 幅)、
  イメージの検査の表(名前は逃がす)。検査のスクリプトの awk・sed は Git の道具で確かめた(この PC の sh は子のプロセスを起こせない)
- **配備のあと(利用者)**: (1) `deploy-to-host.ps1 -Backup -Yes -Action up`(nginx の設定が変わる)→ (2) km で `sudo sh /opt/kosenmap/scripts/host-updates-setup.sh --path /opt/kosenmap --fix`(cron 版 10・実行ビット)→ (3) 初回の検査 `sudo sh /opt/kosenmap/scripts/host-image-scan.sh --path /opt/kosenmap`(数分〜十数分)→ 結果を見て distroless・Chainguard を決める
- **11-06 に配備済み(利用者)**: `api/weather.php` は 200(その時は「雨の予報 50%」)・cron 版 10。**初回の検査**(手で。`--notify` なし)は 9 イメージ・失敗 0・直る版のある HIGH・CRITICAL 606 件:
  | イメージ | CRITICAL(直る) | HIGH(直る) | うち OS | 主なもの |
  |---|---|---|---|---|
  | boky/postfix | 38(30) | 409(286) | 431 | **イメージが 2026-01-01 のまま**(Debian 13.2)。openssl・perl・brotli… |
  | phpmyadmin | 8(7) | 153(60) | 150 | 同梱の twig 3.11.3(3.26 以上で直る) |
  | kosenmap-web | 2(1) | 139(55) | 141 | 土台(php:8.4-apache・Debian 13.7)の linux-libc-dev など。作るための -dev は外してある |
  | logto 1.45.0 | 3(3) | 60(57) | 0 | npm の部品(shell-quote・dompurify・axios)と Go の stdlib |
  | mariadb・postgres | 1(1) | 27・25 | 2・0 | ほぼ gosu の Go 1.24.6 の stdlib |
  | nginx・glances・certbot | 1〜3 | 10〜23 | 11〜19 | Alpine の brotli・expat・pcre2、certifi |
  **見立て: いま distroless・Chainguard に替える利点は小さい。** 直る版のある 606 件のほとんどは「digest を新しくする」(update-containers.ps1)と「web を新しい土台で作り直す」で消える。
  多いところ(postfix・phpMyAdmin・web)は distroless に代わりが無く、Chainguard の無償の版は最新だけ(版を固定して上げる今の運用と合わない)。
  順番: (1) 全部の digest を上げる(特に postfix)→ (2) web を作り直す → (3) phpMyAdmin は使わない間は止めることも考える → (4) 次の検査で残ったものを見て、nginx だけ Chainguard を試すか決める。
  手で回した初回は「知らせ済み」を覚えていないので、**最初の日曜(11-08)の 4:47 に 606 件ぶんのメールが 1 通届く**(並べるのは 20 件まで)。そのあとは新しく出たものだけ

### 2026-11-03 — 上のバーの文字が狭い幅で縦に折れていた(11-03)

項目は折らない(`white-space: nowrap`)。「マップへ」は sm 未満、ダッシュボード・タスクマネージャーは xl 未満でアイコンだけにし、
名前は aria-label と title に残す(言語の切り替えにも追随)。写しで 375・800・1024・1280 幅とも 1 行・はみ出しなしを測った。配備済み

### 2026-11-03 — タスクマネージャーの縦を画面の残りに合わせた(11-03、利用者「縦幅が小さいのがもったいない」)

lg 以上で app-content から .km-tm まで flex で伸ばし、パフォーマンスのグラフ(16rem 以上)とプロセスの表が残りを受け取る。
プロセスは Glances から 40 件取り(本番で 24KB・数 ms)、入る行数だけ出す(狭い画面と低い窓は 15 行。表の見出しは枠の中で上に留まる)。34rem は下限のまま。写しで 1600x1300 → 28 行・グラフ 807px、1400x560 → 15 行で枠の中をスクロール、375 幅 → 従来どおりを測った

### 2026-11-03 — イベントのセットアップ・ポスターの一括・会期後の片付け(11-03、利用者の指示。配備済み)

会期の準備で行き来していたページを 1 枚の `admin/event-setup.php`(サイドバー「会期」の先頭)にまとめた。
10 の手順(1 日付と時間 → 2 地図に出すもの → 3 スケジュール → 4 屋台・ポスター → 5 お知らせ → 6 来場者の操作の記録 → 7 公開の錠 → **8 有効にする → 9 アプリへ配信** → 10 控えと最終確認)に ✓・!・― の印(`_inc/event-setup-status.php`)。
**8 と 9 はプランと逆にした** —— アプリへ配る地図に焼き込むのは有効なイベントだけなので、有効にしてから配る。新しいイベントは無効のまま作る。保存は今のページと同じ関数を呼ぶ。
- ポスター・広告に **全て出す・全て止める・✓を付けたものを出す・止める**(ポスター・広告の画面とセットアップの 4。記録 `poster.bulk`)
- **会期を終える**(押したときだけ): イベントを無効に・イベント用の配信を止める・**ポスター・広告を全部(止めているもの・広告も)控えへ移して消す**。
  控えは `uploads/poster-archives/posters-<日時>-<16進32>.zip`(posters.json・元の画像・README。表 `km_poster_archives`)。**書いて読み戻して確かめてから消す**(失敗したら何も消さない)。
  控えはバックアップの画面の「ポスターの控え」から落とす・消す(戻す機能は無い。手で消すまで残る。夜の控えにも入る)。✓は会期が終わってから最初から付く
- check.php に組 `event-setup`(41 件。一括の受け方・控えの順番・作業用の場所で zip を作って読み戻す・手順の印)。写しで準備中・開催中・終了後・新規・権限なし・スマホ幅・暗い配色を見た
- **11-03 10:26 に配備済み(利用者)。** 読むだけで確かめた: 本番のファイルは手元と同じ・`event-setup.php` はサインインへ 302・`_inc` と `/uploads/poster-archives/` は 404・
  コンテナの中で check.php event-setup 41 件が通過・`km_poster_archives` はまだ無い(片付けを押すまで作らない)・uploads は www-data で書ける。
  ポスター 59 件はすべて止まっている(11-02 22:13 に出し方を「表示しない」にしたときから。今回の一括は使われていない)

### 2026-11-03 — Console のトンネルは km の鍵の SOCKS で・phpMyAdmin を戻した(11-03、利用者の指示)

11-02 に書いた `ssh -i km_ops -L 3002:…` は**通らなかった** ——
kmops の鍵には全部 `no-port-forwarding`(降格のときの host-ops-user.sh)が付いていて、`-L` も `-D` も待ち受けは開くが先で「administratively prohibited」。
**km の鍵(km_vps)で `ssh -N -D 127.0.0.1:10800 km@ito4.jp`** を張り、`--proxy-server=socks5://127.0.0.1:10800 --proxy-bypass-list="<-loopback>"` と専用のプロフィールの Chrome で開く
(curl で `localhost:3002` → 302 `/console` を確かめた)。デスクトップの `トンネル開通SSH.cmd` がトンネル → Chrome → Chrome を閉じたらトンネルを切る、までを行う。
設定の画面・site.php・docs/05・docs/11・compose と nginx の注記を直した。
- **phpMyAdmin は 127.0.0.1:8281 だけで戻した**(compose.yaml の phpmyadmin。nginx は通さない・網は appdb と edge・root と空パスワードは禁止のまま)。
  `pma` 利用者・`phpmyadmin` の DB・`.env` の `KM_PMA_*`・ボリューム `test_phpmyadmin_sessions` は残っていたのでそのまま使う(docs/05 の「名残を消す」セルは外した)。
  配備の退避の一覧から `docker/phpmyadmin` を外した。compose は本番の `.env` で `config -q` を通した(一時フォルダ)。**配備は `-Action up` が要る**

### 2026-11-03 — notice.php が公開中のお知らせを出さなかった(11-03、利用者の指摘)

本番は「お知らせ」のスイッチが 10-25 00:24 から OFF(`map_notices_enabled = 0`)で、
OFF のときは一覧もくわしいページも「いまお知らせはありません」(404)にする作りだった。公開中の 2 件(id 1・2)は DB に残っていた。
スイッチは地図に出すか(「はじめに」の先頭・引き出しのボタン)だけにし、notice.php は「公開する」だけで決めるように直した。管理画面のスイッチの説明も合わせた。check.php 2685 件。
**11-03 に配備済み(利用者)**: 一覧は 200 で 2 件・`?id=1`・`?id=2` は 200・無い番号は 404 で「見つかりませんでした」・地図(/)にはお知らせも引き出しのボタンも出ていない(スイッチ OFF のまま)

### 2026-11-02 — 配信の期限を会期の終わりに合わせる・開いたままのアプリでも期限で消す(2026-11-02。版 43 = 1000043・署名 d71fbf0a…。受け箱へ送った)

利用者「会期が終了してもマップが残り続けるのはなんでですか？」。原因は 3 つ:
(1) 期限は配信のたびに手で入れ、初期値は前回の値(初回 30 日後)で、会期と連動していなかった
(2) アプリは起動・地図の画面を開いた・取り直したときにしか期限を見ず、裏から戻しただけでは消えなかった
(3) 「配信を停止」は、すでに受け取った端末の地図を消さない(これは作りどおり)。
- Website: `km_map_event_overlay_for_app` が会期の終わり(`endsAt`。重ねるイベントの一番遅い ends_at、終わりの無いものがあれば null。
  `km_map_event_window_ends_at`)も返す。配信の画面で**イベント用の正本(kosen-event)の期限の初期値を会期の終わり**にし、
  入力欄の下に「会期の終わり」を出す。会期より後の期限を入れると、入力中に注意を出し、配信後にも警告を出す(配信は止めない)。
  Website の正本(kosen-main)はふだんの地図なので、会期では切らない(今までどおり前回の値)。check.php 2611 件。
- アプリ: 地図の画面が前に出るたび(STARTED)に期限を見直し、開いたまま期限を迎えたらその時刻(1 秒後)に見直す
  (`mapExpiryRecheckDelayMillis`)。期限で消した直後に見直しても「期限が切れました」の表示を「未取得」に言い換えない。
  Pixel(来場者 版 43)で入れ替え・裏へ回して戻しても落ちず、期限前(本番の期限 11-02 21:00)の地図はそのまま出ることを見た。

### 2026-11-02 — アプリの地点の詳細を Website にそろえた(2026-11-02。版 44 = 1000044・署名 d71fbf0a…。受け箱へ送った)

利用者「アプリ側を Website に揃えてください」。
Website の Main/app.js の nodeDetailHtml と同じ項目・並び・呼び名にした(アプリの NodeDetail.kt。NodeDetailTest):
- 種類は「部屋」「施設」などの言葉(それまで type1 の英字「room」がそのまま出ていた)・階
- 行: 普段の名前(臨時の名前・ポスターの見出しで置き換えたときだけ)→ 部屋番号(置き換え前の地点から)→ 担当 → 分類(type2。それまで「種類」)
  → メモ(note。それまで「備考」。臨時の地点は Website と同じ「備考」)→ 立入禁止 + 理由 → 地点の URL(くわしく見る ↗)→ 企画 → 備考(企画のポスターの説明・イベント中だけ)
- 下の欄: 屋台 → この場所の予定(**開催中**の印・説明も)→ ポスター(画像・見出し・説明・🌐 外のサイトで見る・**ポスターごとの ♡**)→ 中へ → 🧭 経路・ここから出発・**↗ 共有** → ブックマーク
- ♡ は Website と同じポスターごと(表 km_poster_likes)。アプリだけの地点ごとのいいねは出さない(古い版のために口は残す)
- 共有は Website と同じ URL(`/?room=<uuid からハイフンを抜いたもの>`・Website の臨時の地点は `/?poi=<番号>`。アプリで作った一時地点は出さない)
- Website: api/node-extras.php が `posterLikes`(ポスターごとの ♡。この地点に出しているポスターだけ押せる)と `link`(地点の URL)を返し、
  `posterLike` で ♡ を付け外しする。配信の折り込み(km_app_map_events_from_web)が通行止めの理由 `closureReasons` も入れる。check.php 2615 件
- Pixel(来場者 版 44)の 1F 第二実習室: 「Let's play Densan!」「部屋 ・ 1F」「普段の名前: 第二実習室」「部屋番号: 管-105」「分類: メディア室」「企画: 22」、
  「🧭 経路」「ここから出発」「↗ 共有」「経路を解除」「★ ブックマーク済み」。屋台・予定・ポスターは、利用者がアクセスコードを消したので読めず(「アクセスコードが違います」)、端末では見ていない

### 2026-11-02 — 版の更新を簡単に・アプリは裏で新しい版を確かめて通知(2026-11-02。版 45 = 1000045。受け箱へはまだ送っていない → 同じ日に版 46・47 を送って自動公開した(下))

利用者「版の更新が面倒くさいので、簡単に」「アプリ側でも、版の更新を自動確認して更新するよう通知を」「管理アプリ側は更新を確認してくれない問題も」。
- **受け箱の自動公開**(lib/apk-inbox.php): 検めにすべて通った届けは、届いたその場で公開する(メールの URL を開いて押さなくてよい)。
  メールは「公開しました」になり、同じ URL から 7 日間は「前の版に戻す」。検めは押したときと同じ(署名が今の配布物と同じ鍵・版が上がる・パッケージ名)。
  自動の公開に失敗したら「公開を待っています」で残し、理由をメールに書く。受け箱の画面で「自動で公開しない」に戻せる(設定 apk_inbox_auto_publish・既定はする)。check.php 2622 件
- **管理アプリが更新を確かめなかった原因**: 確かめるのは画面が作られたとき(起動)だけだった。Pixel の管理アプリのプロセスは版 42 を入れた 11-01 22:59 から
  17 時間生き続けていて、裏から戻すだけでは一度も確かめていなかった(本番の記録でも管理用の問い合わせは 22:54〜56 の手で押した 3 回だけ)。
  一般用はプロセスがよく作り直されるので確かめていた
- アプリ(AppUpdateNotifier.kt): 前に戻るたびにも確かめる(onStart。間隔は 1 日 → 6 時間)。**JobScheduler で 6 時間ごとに裏で確かめ**、
  新しい版があれば通知「(管理)アプリの新しい版があります(版 N)」(同じ版は 1 回だけ)。押すとアプリが開き、いつものダイアログから取ってきて入れる。
  Android 13 からの通知の許可は最初に 1 回だけ尋ねる。自動の確認の失敗はログに残す(AppUpdate)。WorkManager は足さない
- Pixel 7(一般用 版 45): 通知の許可の画面が出る・ジョブが 6 時間ごとで予約される・手で走らせると本番へ問い合わせる(16:07 に 200)。本番は版 44 なので通知は出ない(正しい)。
  版を下げての試しは Android が断る(データを残したまま版を下げられない)ので、**通知が出るところは、配備のあと版 46 を自動公開させて Pixel の版 45 で確かめる**
- **配備のあと(16:08)本物どうしで確かめた**: 版 46 を apk-push(16:13)→ 受け箱 #29 が 16:15:06 に届き 16:15:07 に「自動公開」で公開(押す操作なし)・
  メール「[KosenMap] 新しいアプリを公開しました」が届いた。Pixel 7(一般用 版 45)の裏の確認を走らせると通知「アプリの新しい版があります(版 46)」、
  押すとアプリが開いて「新しい版があります 46(1000046)」、「取得する」で「インストールの許可が要ります」まで進んだ(不明なアプリのインストールは端末のセキュリティ設定なので、私は切り替えない)
- 許可を ON にして戻っても「アップデートを確認」を押し直す必要があったので、**戻ってきたらそのまま続く**ようにした(MainActivity.onResume の pendingInstallOffer)。
  設定の説明「起動時にも 1 日 1 回」も「6 時間ごと・通知」に直した。版 47 = 1000047 を apk-push(16:20)→ 受け箱 #30 が 16:25:08 に自動公開(一般用・管理用とも 1000047)
- apk-push.ps1 の最後の案内を「検めに通れば自動で公開してメールで知らせます」に直した

### 2026-11-02 — 地図の配信の版も簡単に(2026-11-02。版 48 = 1000048)

利用者「アプリのマップ配信の版も簡単に更新できるように」。
それまでは、配信し直すたびに期限を選び直し(イベント用は管理アプリの JSON も添付し直し)、**端末はメニューの「マップを取得」でコードを入れ直すまで古い版のまま**だった。
- Website(admin/map-publish.php の「いま配っているもの」): **「最新の内容で配り直す(版 N)」の 1 ボタン**。ファイルも期限も選ばない。
  前回の設定(チェックサム・Website のイベントの折り込み・元のファイル名)のまま、いまの Website の内容(イベント・ポスターの見出し・読み・英語の名前)で作り直して版を上げる。
  期限はイベント用なら会期の終わり(まだ先なら)、それ以外はいまの期限を引き継ぐ。どちらも過ぎていれば押せない(推測で埋めない。下の「配信する」で選ぶ)。
  イベント用は添付された元のファイルを `uploads/app-map-kosen-event.source.json` に残すようにした(**この版の配備より前の配信には無いので、一度だけ添付して配信する**)。
  操作の記録は map.publish の「配り直し」。check.php 2634 件。写しの設定で、配っていない・添付して配る・配り直し(版 1 → 2、中身・元の名前・チェックサムを引き継ぐ)・期限切れ・元のファイル無しを動かして確かめた
- アプリ(MapAutoCheck.kt): **地図の画面を開いている間、保存してあるアクセスコードで 15 分ごとに版を確かめ、新しければ自分で入れる**(トースト「地図を新しい版(版 N)にしました」)。
  手で取るのと同じ道(fetchAndApply)なので、チェックサム・版・中身の段・検証は同じ。この端末で直した内容があるときは被せず、「マップを取得」から入れるよう知らせる。
  外れたコードで学校の回線の枠を食わないよう、401(コードが違う・消された)はそのコードでは二度と自動で確かめない(指紋だけ覚える。手で取れば解ける)・410 は 6 時間・429 は 1 時間あける。
  失敗は画面に出さない(ログだけ)。学習データの同期は自動の確認では回さない。試験は両フレーバーとも通った(MapAutoCheckTest 6 件ほか)
- 版 48 = 1000048(署名 d71fbf0a…)を apk-push → 受け箱 #31 が 16:40:07 に「自動公開」。Website の配り直しは**配備してから**使える(アプリの自動の確認は今の本番の api/app-map.php のままで動く)
- **アプリにイベントが届くのは Website の管理画面から地図を「配信」したとき**(焼き付けた写し)。イベント「Test」を始めたあとまだ配信していないので、来場者アプリには出ていない(予定は別の口なので出る。会場 × 時間も Website と同じ並びを見た)。

### 2026-11-02 — 使っていない入口の撤去と管理画面の整理(2026-11-02〜03、利用者の指示。配備済み)

11-02 の脆弱性のメール 15 件はすべて Soketi の部品だった。
利用者の決定: チャットも削除・サービス監視はタスクマネージャーへ・外部接続 API と phpMyAdmin の両方を削除・Logto Console は SSH から。
- **外したもの**(中身は `Old/removed-2026-11-02/`): Soketi(compose・nginx の `/ws/app/`・Origin の map・`lib/soketi.php`・pusher)、管理画面のチャット(`km_chat_*` の表は DB に残した。0 件)、
  phpMyAdmin(compose・nginx の 8281・`docker/phpmyadmin/`)、テーブルの外部接続 API(`api/table.php`・`lib/table-api.php`。`km_table_api_keys` は残した。0 件)、旧サービス監視 `monitor.php`(`taskmgr.php?tab=services` へ 301)。
  `-Action up` は `--remove-orphans` 付きにした(外したサービスのコンテナが残らない)
- **Logto Console**: nginx の 3002 の入口を外し、logto の 3002 を `127.0.0.1:3002` だけに publish。`ADMIN_ENDPOINT` の既定は `http://localhost:3002`(本番の .env に `LOGTO_ADMIN_ENDPOINT` は無いので既定が効く。11-03 に読んで確かめた)。
  開き方は `ssh -N -i ~/.ssh/km_ops -L 3002:127.0.0.1:3002 kmops@<ホスト>` → `http://localhost:3002`。host-geoblock.sh の `ADMIN_PORTS` は空。**外に開くのは 80・443 と SSH だけ**。戻し方は docs/05 §5-2
- **タスクマネージャーを Windows 風に**: 左の縦タブ(プロセス・パフォーマンス・コンテナ・サービス・詳細。`?tab=`)。パフォーマンスは CPU・メモリ・スワップ・ディスク・ネットワークの小さなグラフと、選んだ項目の大きな面グラフ(素の SVG)。
  サービスのタブは health-check の結果とコンテナを 1 つの表に(30 秒ごと。ほかのタブでは止める)。Glances の network と fs は**有効にしていない**(コンテナの中では本物のホストの値にならない。ディスクとネットワークは host-stats.sh の値)
- **配備のあとに「ページを更新してください」**: `lib/release.php` が src の php・js・css・html の「パス・大きさ・更新時刻」から 12 文字の印を作る(/tmp に 30 秒覚える。286 ファイルで約 13ms)。
  `api/release.php` が印を返し、`Main/release-watch.js` が 5 分ごとと、タブ・フォーカスが戻ったときに比べて違えば上に帯を出す(自動では読み込み直さない。「あとで」はそのタブの間だけ)。管理画面の全ページと公開ページ 9 つに入れた。nginx の管理画面の別名からも `/api/release.php` へ届くようにした
- **無理に置かれた項目を移した**: チュートリアルの終わりの知らせ → お知らせ(ページ名「お知らせとチュートリアル」)、地点の URL → 「地点の読み・別名・URL」、部屋のいいね → チャート、教職員氏名の登録状況 → 教職員の地点、
  アプリへ配信中の地図(停止・再開・削除)→ 地図の配信、管理アプリのイベントの取り込み → アプリの地図を取り込む、イベント中の記録 → イベントモード。チャートのランキングの写しはリンクに。サイドバーは用途ごとの 8 つのまとまりに組み直した(URL は変えていない)
- check.php 2639 件(移した項目が先にあり元に無いこと・印の計算・帯の差し込み・タスクマネージャーのタブなどを足した)。文書は 11・05・08・13・04・09・12 を直し、05 の phpMyAdmin の手順は `Old/removed-2026-11-02/docs/` へ移した
- **11-03 の配備が途中で止まった**: 展開のあと、外したファイルをホストの `Old/retired/` へ移す段で `Permission denied`(本番の Old/ は 10-12 から root の持ち物)。
  展開までは済み(src は新しい版)、退避と `up` は走っていない。退避先を置き場の直下の `retired/`(kmops の持ち物)に変え、書けなければ最初の 1 つで理由を出して止まるようにした。同じコマンドで配備し直す
- **11-03 に配備済み(利用者)。** 読むだけで確かめた: km-soketi・km-phpmyadmin が居ない・8281/3002/3001/6001 は外から閉じている・`/ws/app/` と `/api/table.php` は 404・`/api/release.php` は ito4.jp と admin.ito4.jp の両方で印を返す
- **配備のあと**: host-setup で check-updates.sh と host-geoblock.sh を配り直す(スクリプトの中身は 11-03 に本番と同じと確かめた。ファイアウォールの規則を入れ直したかは root でないと読めない)。
  phpMyAdmin の名残は消さない(11-03 に戻して使う)
- **11-03 夕方に `-Action up` で配備済み(利用者)**: km-phpmyadmin が healthy・ホストの 127.0.0.1:8281 が 200。上のバーの修正も入っている。
  カーネルは 6.8.0-147 のまま(CVE-2026-98432 を直した版は apt にまだ無い)。つなぎの km-ipopt は active・MPTCP 0

### 2026-11-01 — 振り返りのプランの残り(2026-11-01 夜にプランと突き合わせた)

フェーズ A・0〜6 の作るものは全部済み。残りは確かめだけ。
- 毎日の点検(root の cron 04:17 の `check-updates.sh --notify`)の脆弱性のメールは、**11-02 04:17 が初めての本番の通し**(11-01 10:04 に配り直した。それまでは止めていた)。
  Soketi の npm の high があるので「[KosenMap] 脆弱性が出ました」が届くはず。届いたか、次の日に同じものが来ないかを利用者が見る。
- ~~案内チャットの記録の「模型」の欄(km_guide_chat_log.model)は、本番の表にまだ無い(84 行)。~~ **11-07 に本番で確認: 欄あり**(84 行)。DB の利用者 Main は Kosen_map に ALTER を持つので、
  初めて質問を残すか管理画面「案内チャット」を開いたときに足される。管理画面の模型の欄も、AI を起こしていないので見ていない(会期後は起こさない)。
- 利用者が本番で見るもの: ログインしたまま配備してもログインが残るか(フェーズ 0 の 4)、共有の経路の選択・不具合のひな形(フェーズ 0 の 2・3)、
  管理画面での色分けの塗り・英語の名前・読み(入れるまで地図に出ない)、札・QR のカメラ、アプリの版 31〜40 の公開。
- アプリ(端末は来場者版 40):予定・屋台を本番につないだ画面は、まだ見ていない(会期後で 0 件)。アプリのズームの滑らかさも測っていない(Website は測った)。
  → 下の「アプリのズームを実機で測った」で測って直した。予定は残り(下)。

### 2026-11-01 — アプリの地図の動きをさらに詰めた・企画のポスターの見出しをアプリの名前に(2026-11-01 夜。版 42 = 1000042・署名 d71fbf0a…。受け箱へ送った)

利用者「ノードの遅延を詰めて」「アプリ側でも、上書きした部屋の名前(第二実習室 → Let's play Densan!)に」(例として)。
- 版 41 を試しの版(profileable。測り終えて外した)で記録した残り: 札の字 22%・階段の札 6%・連なりの絞り込み 6%・画面の組み直し 16〜23%。
- **直した(アプリ)**:
  - 札の字を「基準 ÷ 倍率」の大きさで描いていたので、拡大の途中は毎コマ新しい字の大きさになり、フォントの測りと並べを作り直していた → 字は画面の px のまま、キャンバスを倍率で縮めて描く(`drawTextAtScreenSize`)。測った大きさも覚える。
  - 階段の札(上行き・下行き)が、描くたびに階段ごとに全地点の接続の鍵を組み直していた → 鍵 → 階段の表を地図ごとに 1 度(`stairsByTransferKey`)。
  - 札の文字(短くする処理)をコマをまたいで覚える(地点の写しごと)。
- Pixel 7a・同じ手順: **版 40 → 41 → 42 で、遅れたコマ 99.5% → 13〜16% → 10〜12%、中央値 53 → 10 → 7〜8ms**。拡大したあとの札の字・階段の札は崩れない(画面で見た)。
  残りは画面の組み直し(地図を動かすたびに MapScreen 全体を組み直す作り。変えるのは大きな直しになるので止めた)。単体試験 918 件 × 両フレーバー。v41 は `Old\release-v41-20261101`。
- **名前**: アプリはイベント中、臨時の名前(Website のイベントの alias → 配信で nameOverrides)と「番号 名前」を出していたが、**企画の番号のポスターの見出しは知らなかった**(地点のポスターは詳細を開くまで取らない)。
  → 配信のとき(Website の地図・イベント用の地図の 2 か所)に、出しているポスターの見出しを番号の地点の臨時の名前に入れる(`km_app_map_poster_names`・`km_app_map_poster_titles`。ポスターは臨時の名前より先、Website の projectDisplayFor と同じ)。
  ポスターの出し方が off なら入れない。**今の本番はポスターが off で全部止めてある**ので、Website でも今は「22 第二実習室」の形。check.php 2597 件。
- **同日、利用者がポスター 59 件を有効にし、出し方を「いつも」にした**: 公開の地図データにポスター 59 件(番号付き 58)。Website の札は第二実習室が「🖼️Let's …」になった(Let's play Densan!)。
  配信に入る見出しは 58 件(22 = Let's play Densan!)。ただし本番のアプリの地図は「イベント用の地図(kosen-event)」で、21:45 に**折り込みなし**で配信されていた(イベントは空)。
  → 配信の画面で「Website のイベント(…)も折り込む」をオンにして配信し直すと入る。欄の説明が「通行止め」だけだったので、臨時の名前・ポスターの見出しも入ると書いた(admin/map-publish.php・i18n の ja/en)。
- 利用者が 23:18 に折り込みオンで配信し直した(版 5・イベント Test 入り)が、**臨時の名前は 0 件**だった:
  添付の地図(管理アプリの書き出し)は 904 地点のどれにも企画の番号(projectNumbers)が無い。番号の正本は Website の表 km_map_node_projects(Website の地図はそこから付ける)。
  → 折り込むときは、添付に番号の無い地点へ表の番号を足してから見出しを入れる(`km_map_projects_apply` + `array_diff_key`。添付の番号が先)。
  これで番号の「22 第二実習室」・赤い縁(eventNodeUuids は番号を見る)もアプリに入る。本番の地図と DB で書き込まずに流して、番号 33 地点・名前 33(22 = Let's play Densan!)。check.php 2601 件。
- 版 6 も名前 0 件だった: 配信(23:24:58)が**配備より先**だった(配備は手元の更新時刻を保つので、届いた時刻は ctime で見る。23:27:47)。利用者が配り直した版 7(23:30:30・折り込みあり)で番号 33・名前 33(22 = Let's play Densan!)。
- **Pixel の来場者アプリ(版 42)で確かめた**: 23:30:54 に地図を取り直した(424,936 バイト)。1F の第二実習室が赤い縁の「Let's …」、押すと見出し「Let's play Densan!」・企画 22・この場所の予定(テスト 2)・ポスターの写真が出る。
  ほかの企画の地点(熊本地震支援…・後援会…・喫茶け…)も赤い縁でポスターの見出しになっている。

### 2026-11-01 — 試しのイベントを本番で見た(2026-11-01 22 時、利用者がイベント「Test」(id 9)を始めた)

公開の地図データは錠なし・予定 34 件(21:00〜11-02 21:00)・
企画の番号付きの地点 33(1F 8・2F 9・3F 9・4F 4・外 3)。ポスター・一時地点・屋台は 0。
- Website(内蔵のブラウザ・幅 1149): 🎪 イベントと 📅 予定のチップが出る。拡大すると 1F の企画の地点 8 か所が赤い縁(`km-node-event`)、ほか 38 か所が薄い(`km-node-unrelated`)。
  予定の一覧は「開催中」の印つき、「会場 × 時間」は会場ごとの列(1-1 専-301 …)に 21:00 から並ぶ。イベントの案内の文(bannerText)は ⚙️ 設定の中(10-23 からの作り)。
  **いちばん引いた倍率(-1)では部屋の点を出さないので、イベントの地点も出ない**(出入口 14 個だけ、どれも薄い)。1 段拡大すると出る。イベント中だけ引いた倍率でも出すかは利用者の判断。→ **11-07 に利用者が「イベント中のみ出しましょう」と決め、Web・アプリとも直した**(下の 11-07 の項)。
- アプリ(版 41): 端末の地図はイベントの前に取ったものなので、イベントは出ない。「マップを取得」はアクセスコードが要り、私は入れないので止めた(利用者がコードで取り直してから見る)。

### 2026-11-01 — アプリのズームを実機で測った・地図を描くたびの重い処理を直した(2026-11-01 夜。版 41 = 1000041・署名 d71fbf0a…。受け箱へ送った)

フェーズ 2 の「アプリ: 同じ計測で確かめる」。
- 測り方: Pixel 7a の /system/bin/uinput で仮のタッチ画面を登録し(shell は uhid の組。/dev/input へ直に書くのは SELinux で断られる)、2 本指のピンチと 1 本指の移動を送った。
  手順は Website と同じ(1 階・拡大 4 回 → 横へ 8 回 → 縮小 4 回。1 コマ 16ms)。コマの値は `dumpsys gfxinfo com.ito.kosenmap`。台本は scratchpad の make-uinput.ps1。
- **版 40: 207 コマ・中央値 53ms・90% 73ms・99% 93ms・遅れたコマ 99.5%**(動かしている間ずっと 19 コマ毎秒。UI スレッドが毎コマ間に合っていない)。
- 試しの版に限って profileable と名前を残す設定を付け、端末の simpleperf で UI スレッドを記録した(**測り終えて外した。版 41 の APK に profileable は無い**)。
  UI スレッドの 88% が地図を描く処理で、その 78% が `isWallLine`: **床の線 1 本ごとに、全地点(状態付きの一覧)から uuid の表を作り直していた**。
  線は数百本・地点は千件ほどなので、1 コマで数十万回の状態の読み取りと表への書き込み。札の置き方(drawGeneralMapNodes)は 6% だった。
- **直した**:
  - `isWallLine` に表を受ける形を足し、描画(drawGeneralMapLines・DrawLines)・経路探索(isRoutableLine)・壁の通り抜け防止(WallCrossingGuard。現在地のたび)・色分けの材料で、表は 1 度だけ作る。
  - 札の文字(nodeLabelLines)は 1 コマに地点ごと 1 回(それまで 4 回。9% だった)。
  - 札の置き方を前のコマから使い回す(LabelLayoutCache)。倍率と中身が同じなら決め直さない。指で拡大・縮小している間は前の置き方を伸び縮みさせ、指を離したら決め直す(Website の zoomend と同じ)。
- **版 41: 360〜387 コマ・中央値 10〜11ms・90% 20〜25ms・99% 32〜38ms・遅れたコマ 13〜16%**。拡大して指を離したあと、札は点の真上にそろい重ならない(画面で見た)。
  残り(詰めない): 札の文字を描く 12%・画面の組み直し 15%。
- 確かめ: 単体試験 918 件 × 両フレーバー。v40 は `Old\release-v40-20261030` に写した。端末の来場者版は 41(管理版 22 は触っていない)。
- **予定のボタンは「アクセスコードで地図を取ったときだけ」出る**(MapScreen の mapAccessCode)。Pixel の地図はコードなしで入っているので出ない。
  コードは私が入れないので、予定を本番で見るのは利用者がアプリで「マップを取得」をアクセスコードでしてから(会期後で予定・屋台とも 0 件)。

### 2026-11-01 — Pixel 7a の Chrome で地図の動きを測った・ズームのたびのコマ落ちを直した(2026-11-01 夜)

パソコンから Pixel の Chrome に開発者ツールの口でつないだ(`adb forward tcp:9333 localabstract:chrome_devtools_remote`。ito4.jp のタブだけ。式は scratchpad の cdp-eval.js)。
Chrome 156・8 コア・400×741@2.7・90Hz。読み込みは DOMContentLoaded 380ms・300ms を超えた要求 0、ボタンの重なり 0。
- **ズームのたびに 50〜114ms の長い処理が出ていた**(フェーズ 0 と同じ手順で 6〜7 回、50ms を超えたコマ 6〜9。PC は速くて出ない)。
  CPU の記録: Leaflet の `Tooltip._setPosition` が 532ms、スタイルの計算し直し(UpdateLayoutTree)が 622 回・502ms。
  `_setPosition` は呼ばれるたびに `offsetWidth` / `offsetHeight` を読んでから位置を書く。ズームのアニメーションでコマごとに札 1 枚ずつ呼ばれ、札の数(70〜80)だけ計算し直しが起きていた。
- **直した(Main/app.js の先頭 `cacheTooltipSize`)**: 上向きの札(このサイトの札は全部)は、大きさを 1 度読んで札に覚えさせる。中身を書き換えたら忘れる、
  文字が変わったら読み直す(英語の画面では i18n.js が札の文字を後から書き換える)、文字の大きさなどを変えたら(`kmDeclutterLabels`)世代を上げて全部忘れる。
  あわせて、札の並べ直しは、初めて測る札・文字が変わった札だけ、測る前に Leaflet に置き直させる(古い幅で置かれたまま測らない)。
- **Pixel のページにこの直しだけをその場で入れて測った**(配備の前): `_setPosition` 532 → 65ms、計算し直し 622 → 142 回、アニメーションのコマ(FireAnimationFrame)の合計 419 → 67ms。
  長い処理 6〜7 → 3〜4 回、いちばん長いコマ 78〜111 → 67〜100ms。
- **配備のあと本番で測った(同日、読み込み直したページ・同じ手順 × 2 回)**: DOMContentLoaded 433ms、300ms を超えた要求 0、ボタンの重なり 0。
  | | 1 回目 直す前 → 後 | 2 回目 直す前 → 後 |
  |---|---|---|
  | 長い処理(50ms 超) | 7 回(67〜114ms) → 5 回(57〜78ms) | 6 回 → 2 回(55・61ms) |
  | 50ms を超えたコマ | 9 → 8 | 6 → 3 |
  | いちばん長いコマ | 122 → 78ms | 78 → 56ms |
  | コマの 99% | — → 55ms | — → 44ms |
  CPU の記録でも `_setPosition` は 58ms(直す前 532ms)のまま。残りは上の「残り」の 2 つ(ズームの始めと終わりの計算し直し 50ms 前後・初めて測る札)。
- **残り**: ズームの始めと終わりの、地図全体のスタイルの計算し直し(1 回 50ms 前後。Leaflet が `leaflet-zoom-anim` を付け外しする)と、
  新しく出た札を初めて測る並べ直し(100ms 前後)。どちらもズーム 1 回に 1〜2 回で、コマごとではない。セレクタの照合は合計 10ms で、CSS の書き方が重いのではない。
- 試し場(日本語・英語)で、拡大 4 段・縮小 4 段とも点の真上からずれた札 0、覚えた値の並べ方 = 全部測り直した並べ方。check.php 2591 件。

### 2026-11-01 — 利用者の Chrome(Claude in Chrome)で本番を測った・スマホのボタンの重なり(2026-11-01 夜、利用者の指示)

- **初めて開いたとき、数 KB の JS が 1・2・3・4 秒待たされていた**(Chrome 158・16 スレッド。HTML は 78ms・FCP 424ms なのに DOMContentLoaded 4.2 秒・LCP 11.5 秒)。
  1 本ずつなら 20ms。ホストの上から nginx へ HTTP/2 で 20 本同時でも同じ形(10 本は 40ms、残りが 0.7〜3.7 秒)。Apache へ直・nginx のコンテナから web へ直の 20 本は 30ms 以内。
  nginx を通したときの Apache の Scoreboard は `KKKKKKKKKKKKKKKKKKWKKS`(K = つないだまま次を待つ)。
  → nginx(1.31.6)が応答のあとも web への接続を開けておき、prefork の子が 1 本ずつ KeepAliveTimeout(5 秒)握られる。待っている子は 10 本前後なので、
  11 本目からは prefork が子を 1 秒ごとに足すのを待っていた。続けて開くと子が残っているので速い(だから気付きにくい)。
  **直した**: `docker/php/apache-km.conf` に `KeepAlive Off`(Apache の前は nginx だけ)。配備のあと web を起こし直すと効く(マウントした設定ファイル)。
- そのほかの通信は良い: HTTP/2・gzip(app.js 264KB → 42KB)・静的ファイルは 30 日のキャッシュ・app.js は 33ms(TTFB 25ms)。2 回目に開くと 25 本がキャッシュから出て DOMContentLoaded 135ms。
- **スマホで 🐞 が +/− の下に入っていた**(幅 375px: 🐞 [318–366, 584–632]・ズーム [315–363, 579–671])。操作の帯に右の限りが無く、右端まで出ていた。
  → 帯は右下のズームの列(48px)の手前で折り返す(`max-width`)。🐞 は 2 段目へ。
  横にしたスマホ(740×360)では ⛶ が上から積んだ階の丸に 5px かぶっていた(10-30 に ⛶ を足して右下の列が 56px 伸びた)
  → 低い画面(高さ 600px 以下)では ⛶ をズームの左に並べる(grid。帰属表示は下の段)、帯は 2 つ分の手前で折り返す。
  試し場で 320×640・360×740・375×700・412×915・568×320・667×375・740×360・844×390・1280×560・1280×800 のどれも、帯・ズーム・⛶・階・帰属表示の重なり 0。check.php 2589 件。
- **配備と web の起こし直しのあと本番で確かめた(同日)**:
  - ホストの上から nginx へ、25 秒空けて 20 本同時 × 3 回: **全部 35〜44ms**(直す前は 10 本の後が 0.7〜3.7 秒)。終わった後の Scoreboard は `__W__`(握られた子なし)。
  - 利用者の Chrome から、キャッシュを使わずにページの JS・CSS 22 本を同時に: **全部 181ms 以内**(直す前は最大 4 秒)。
  - スマホ 375×700 と横 740×360(階の一覧を畳んだ状態): 帯・ズーム・⛶・階の重なり 0。🐞 は 2 段目 [110–158]。
    **階の一覧を開いている間は、一覧が ⛶ や +/− の上にかぶる**(開いた一覧は上に重ねる作り。地図に触れるか階を選ぶと畳む)。
  - **利用者の Chrome で地図の動き**(1 階・1920×889・240Hz の画面・フェーズ 0 と同じ手順 × 2 回):
    239.5 / 239.9 コマ毎秒、コマ 中央値 4.2 / 95% 4.3 / 99% 4.3 / 最大 12.5・20.9ms、16.7ms を超えたコマ 0・1、長い処理 0、大きさの測り 30・95 回。
    **Chrome の窓が後ろにあると Chrome がコマを 1 秒に 1 回に落とす**(測ると全部 1000ms になる)。窓を前に出してから測ること。遅い端末の値はこの PC では出せない。

### 2026-11-01 — 本番を戻して、止めていた分を配備した(2026-11-01、利用者)

つなぎ(IP オプション付きの TCP を捨てる・MPTCP を切る)を入れ、apt upgrade(docker-ce 29.9.2 へ)をして、`-Action up`。
カーネルは 6.8.0-147 のまま(直した版は未)。`km-ipopt` は active・enabled、`net.mptcp.enabled` は 0。コンテナ 10 個とも動いている。
- **本番で確かめた**:
  - `Main/i18n.js` が出ている。`/?lang=en` で英語(検索の欄・チップ・ログイン・パスワードの画面)、`?lang=ja` で日本語。どちらもエラーなし。右上のアカウントの欄も出ている。
  - `api/stalls.php` は 200(屋台は 0 件)。`api/stall.php` は鍵なしで 401、`api/app-schedule.php` はコード違いで 401(nginx の km_api を通って PHP まで届いている)。
  - 地図は会期後の「隠す」(パスワードの錠)なので、地点・英語の名前・屋台のカード・予定・速さの比べはまだ見ていない。
- **脆弱性の点検を kmops で「調べるだけ」走らせた**(メールは送らない。状態のファイルは root のものなので読めない):
  - PHP・composer: 勧告なし。
  - Soketi の npm: high が 7 件ほど(axios・follow-redirects・js-yaml・basic-ftp・decode-uri-component・@pm2/agent・get-uri)と moderate(ip-address)。次の点検でメールが行く。
  - **Ubuntu の USN が読めていなかった**: ubuntu.com の `notices.json` は `limit` が 20 までで、`limit=40` は 422 で断られていた(10-30 に書いたときから)。
    → `check-updates.sh` は `limit=20` で `offset=0` と `20` の 2 枚をもらう。`security-audit.php usn` は区切りを挟んだ何枚でも読み、`km_security_usn_merge` で 1 つにする(読めない枚は飛ばす・同じ USN は 1 つ・1 枚も読めなければ保留)。
    ホストの部品の一覧(719 行)と本物の 2 枚(40 件)で手元で通した: 当たり 0 件(apt upgrade の直後なので正しい)。libbrotli1 をわざと 0.0-0 にすると USN-8956-1 を出した。check.php 2583 件。
    **web の作り直しと host-setup での check-updates.sh の配り直しが要る**(配備は利用者)。
- **錠を解いてもらって本番で見た(同日)**: 地図のデータは階 17・地点 816・線 767。英語の名前・読み・階段の向き・色分けの絵(areaPath)は、管理画面でまだ入れていないので無い(出し方は条件付きで正しい)。
  イベントは無し・ポスター・予定・屋台は 0 件(会期後)。最初の画面は出る。
- **フェーズ 2 の比べ(本番・PC 977px・フェーズ 0 と同じ手順)**:
  | | 床の画像 | 要求 | DOM | 拡大中の札 | 大きさの測り | コマ 中央値 / 95% / 最大 | 長い処理 |
  |---|---|---|---|---|---|---|---|
  | フェーズ 0(10-30) | 17 枚・329KB | 38 | 334 | 73 | 1709 回 | 4.2 / 4.3 / 37.3ms | 0 |
  | フェーズ 2 の後(11-01) | **3 枚・59KB** | 29 | 381 | 73 | **1737 回** | 4.2 / 4.3 / 25.0ms | 0 |
  - 床の画像(今の階と隣だけ)は効いた。**大きさの測りは減っていなかった**。どこで測っているかを数えると、横への移動は 0 回(フェーズ 2 の直しは効いている)で、
    **残りは全部ズームのたび**: 点(369)・札(283)・札の点(274)を毎回測っていた(フェーズ 2 の 1「札の大きさは作ったときに測って覚える」を、ズームの方でやっていなかった)。
- **直した(Main/app.js・styles.css)**:
  1. 点と札の形は 1 度測って覚える(`labelGeometry`。点の位置からのずれと大きさ)。ズームでは `latLngToContainerPoint`(計算だけ)から箱を出す。中身が変わったとき(`kmDeclutterLabels`)は忘れる。
     引いた屋外で CSS で消している建物の図形(`.km-facility-faded .km-node-facility`)は、覚えた形を使わずに外す。
  2. **札を隠すのを `display: none` から `visibility: hidden` に**。Leaflet は札の位置を札の大きさから決める(上向きは幅の半分と高さを引く)ので、
     隠している間にズームすると大きさ 0 で位置を決め、**次の倍率で出てきた札が点の右下へずれたまま**だった(試し場の 1 階、1 段の拡大で 78 枚。本番でも起きていたはず)。
  3. 地図から外して消えかけている札(Leaflet が opacity 0 にして 0.2 秒残す)を、並べ直しで数えない(もう無い札に押されて隣の札がずれていた。試し場で 9 枚)。
- **試し場で確かめた**(scratchpad の map-harness を前の会話の記録から作り直した。8 月のアプリの地図):
  - 同じ手順の大きさの測り: **211 回**(新しく出た札を初めて測る分だけ)。長い処理 0。
  - 1 階の拡大 4 段・縮小 4 段、屋外の拡大 5 段・縮小 5 段(建物の図形を消す倍率をまたぐ)、スマホ幅 3 段で、覚えた形で並べた結果と、全部測り直した結果が**全段で同じ**。
  - 点の真上からずれた札: 直す前 78 枚 → 0 枚。check.php 2586 件。
  - 本番の値は、配備のあとに同じ手順で測り直す(フェーズ 2 の残り)。
- **配備のあと本番で測り直した(同日。フェーズ 2 の比べはこれで終わり)**: 1 階・同じ手順。右は同じ画面のまま 2 回目(新しく出る札を測り終えたあと)。
  | 幅 | 床の画像 | 要求 | 大きさの測り(フェーズ 0 → 今) | コマ 中央値 / 95% / 最大 | 50ms 超 | 長い処理 |
  |---|---|---|---|---|---|---|
  | PC 977px | 17 枚 329KB → **3 枚 59KB** | 38 → 29 | **1709 → 158 回**(2 回目 94) | 4.2 / 4.3 / 62.5ms(2 回目 25.0) | 1(2 回目 0) | 1(2 回目 0) |
  | スマホ 375px | 17 枚 329KB → **3 枚 48KB** | 38 → 29 | **1601 → 159 回**(2 回目 95) | 4.2 / 4.3 / 33.4ms(2 回目 25.0) | 0 | 0 |
  - 拡大中の札は前と同じ 73(出す札の数は変えていない)。点の真上からずれた札は 0。
  - この PC は速いので、コマの値は前後でほぼ同じ。遅い端末の値は実機か Chrome の開発者ツール(CPU を遅くする設定)で見る必要があり、内蔵のブラウザでは測れない。
  - Ubuntu の USN も本番で「調べるだけ」走らせた: 20 件ずつ 2 枚とも読め、保留なし・当たり 0 件(apt upgrade の直後)。

---

## 記録 2026 年 10 月(新しい順)

### 2026-10-30 — Linux カーネルの TCP の穴(CVE-2026-98432)でサーバーを止めた(2026-10-30、利用者)

IPv4 の TCP のつなぎ始め(3 回の握手)で、解放したメモリを使う穴。
認証なしで外から届く(カーネルの CNA は 9.8、Red Hat・Amazon などは 5.9〜8.1)。Anthropic が 10-27〜28 に「多経路 TCP(MPTCP)で待つサーバーで root を取れた」と公表した
(実験室の話。出回っている攻撃の報告は 10-29 時点で無く、CISA の KEV にも無い)。IPv6 は対象外。
- **うちが対象の理由**: ホストは Ubuntu 24.04.4 で、カーネルは全コンテナで共通。nginx が 443 で外から TCP を受けている。**コンテナを替えても直らない**(ホストのカーネルの更新と再起動が要る)。
- **Ubuntu の状況(10-30 に ubuntu.com/security で確認)**: 24.04(noble)の linux は「Vulnerable, work in progress」(作業中の版 6.8.0-149.149)。**まだ直した版は出ていない**。
  本家の直した版は 5.15.223・6.1.190・6.6.159・6.12.113・6.18.56・7.2.10・7.3。
- うちで外から受けているのは nginx(C。MPTCP は使わない)。Go の部品(Mailpit)は本番で使っていない。Go 1.24 以降は待ち受けで MPTCP を既定で使うので、公表された攻撃の条件には当たりやすい。
  ただし **MPTCP が無ければ安全とは言えない**(Go のチームも「MPTCP なしでも届くかもしれない」と言っている)ので、直した版のカーネルが出るまで止めておく判断は妥当。
- **戻し方**(直した版が出てから。ホストに `km` で入る):
  1. `sudo apt update && sudo apt full-upgrade` → `sudo reboot`
  2. `uname -r` が直した版(`apt changelog linux-image-$(uname -r)` に CVE-2026-98432)であることを確かめる
  3. `kmops` で `.\deploy-to-host.ps1 -Action up -Backup -Yes`(止めている間に溜まった Website の変更もこれで出る)
- 直した版より先に開けるときのつなぎ(**直しではない**): `sysctl net.mptcp.enabled=0`(MPTCP を切る)と、`net.ipv4.tcp_migrate_req` が既定の 0 のままかを見る。
  Ubuntu Pro(個人は 5 台まで無料)の Livepatch で、再起動なしに当たることもある(加入するかは利用者)。
- フェーズ 6 の 2(脆弱性のメール)に、ホストのカーネル(Ubuntu の USN)も見るよう足す。
- **2026-11-01 09:41 に利用者がサーバーを起こした**(公式の説明を受けて)。読むだけで確かめた値:
  - カーネルは **6.8.0-147-generic のまま**(直す前。changelog に CVE-2026-98432 は無い)。ubuntu.com/security でも noble の linux は「Vulnerable, work in progress 6.8.0-149.149」で、直した版はまだ出ていない(11-01)。
  - `net.ipv4.tcp_migrate_req` = 0(2 つ目の通り道は閉じている)。`net.mptcp.enabled` = 1。
  - ito4.jp の A レコードはホストの IP そのもので、Cloudflare を通していない(443 も 22 も直に外から TCP を受ける)。防火壁は ufw。
  - 配っているのは 10-30 朝の版(`Main/i18n.js` が 404)。止めている間の変更はまだ出ていない。
- **公式の説明から分かったこと**: 穴に入る物(`ireq_opt`)は、**SYN が IPv4 のオプション付きのときだけ**作られる(`tcp_v4_save_options` はオプションが無ければ空)。
  1 つ目(SYN の再送と子のソケットの解放の競り合い)も 2 つ目(要求ソケットの付け替え。`tcp_migrate_req=1` か BPF のとき)も、この物を解放してから読む話。
  → **IPv4 のオプション付きの TCP をホストの入口で捨てれば、どちらの通り道にも入れない**(直しではなく、パッチまでのつなぎ)。
  ふつうの通信は IP のオプションを使わない(経路の途中でも捨てられることが多い)ので、見る人への影響は無い。MPTCP は説明に出てこないが、使わないので切っておく。
  例外: NetLabel(CIPSO)を設定したホストでは、受け手の側で `ireq_opt` が付く。うちは使っていない。
- **つなぎの手順**(利用者が `km` で入って sudo。コマンドは返事に書いた): `/etc/km-ipopt.nft`(`prerouting` の `raw` の段で `ip hdrlength != 5 meta l4proto tcp` を捨てる。
  `flush ruleset` は書かないので ufw と docker の規則は消えない)と、再起動でも読む `km-ipopt.service`。`/etc/sysctl.d/90-km-cve-2026-98432.conf` で `net.mptcp.enabled=0`。
  **`nftables.service` は有効にしない**(既定の `/etc/nftables.conf` が `flush ruleset` で ufw と docker の規則を消す)。
  直した版のカーネルを入れて再起動し、`uname -r` で確かめたら、この 2 つを外してよい(残しても害は無い)。

### 2026-10-30 — 管理画面の新しいページは画面で確かめない(2026-10-30 夜、利用者の判断)

予定の CSV の取り込み・屋台の鍵・「読み・別名」の英語の名前の欄。
手元に MySQL も Docker も無い(PHP の pdo_mysql も無い)ので試し場で開けない。lint と check.php の決まりで済ませる。

### 2026-10-30 — Website の最初の画面を、Pixel で見つけた不具合と同じ目で見直した(2026-10-30 夜。試し場・幅 375px)

- 階が 2 回出る・外が内部の値で出る・一覧が切れる・入力の焦点が来ない は、Web には無かった(探す欄は開いたら入力の焦点が当たる。ジャンルは地図の引き出しに一覧を出す)。
- **幅 375px で、見出しの両脇の「← もどる」「✕ 地図へ」が縮んで「もど/る」「地図/へ」と折れ、見出しも「ジャンルか/ら探す」と折れていた**。
  ボタンは縮めず折らない。幅 480px 以下では、もどる・地図へを 1 行目の両端、見出しを 2 行目に(Main/styles.css)。配備済み。

### 2026-10-30 — アプリにも「拡大したら地図以外を隠す」を入れた(2026-10-30 夜。版 40 = 1000040・署名 d71fbf0a…。受け箱へ送った)

利用者の指摘「拡大しても帯は消えない」。
フェーズ 2 の 3 は Website だけに作り、アプリには入れていなかった(上の「アプリのフェーズ 2」に書いたとおり)。**それを前の報告で「実機で未確認」と書いたのは誤り**。
- 決まりは Website と同じ: 指で拡大して、その階の全体が入る倍率の 4 倍を超えたら隠す(上限 5 倍の 8 割を超えたら必ず。MapChrome.kt の shouldHideChromeForZoom・MapChromeZoomTest)。
  縦の Pixel は全体が 0.62 倍なので 2.48 倍を超えたら。縮めて下回るか、地図の何もない所をたたくと戻す。
- 隠すもの: 上のバー(TopBarController に拡大中の形を足した。横画面と同じく、上の端から引くか取っ手で 4 秒出る)・下の操作の帯・「札・QR」。
  残すもの: 階のボタン・方位・経路の使う階・地点の詳細・AR。上のバーを隠すと地図の入れ物が上へ伸びるので、その分だけ地図を下へずらして見ている所を動かさない。
- 横画面は Pixel で確かめた(回転の設定を利用者の許可で変え、見たあと自動回転に戻した): バーと時計の帯が隠れ、上の端から引くと出て 4 秒で隠れる。
  引き始めの 24dp は地図にも渡るので、地図が少し動く(前から)。
- **ピンチの拡大は利用者が Pixel で確かめた(2026-10-30 夜)**: 2.5 倍あたりを超えると上のバーと下の帯が消え、縮めるか何もない所をたたくと戻る。消えるとき・戻るときに地図は跳ねない。

### 2026-10-30 — 実機の続き(2026-10-30 夜。版 39 = 1000039・署名 d71fbf0a…。受け箱へ送った)

フェーズ 1〜4 で「実機でまだ見ていない」としていたものを Pixel 7a で見た。
- 見られたもの: 表示調整の「文字サイズ」(171% で札が大きくなり、重なる札をよける。見終えて 100% に戻した)/ 階の列の縦スワイプで 1F → 2F(フェーズ 3 の 4)/
  階段の「▲上行き 3F ▼下行き 1F」の札 / 検索の「もしかして」(「mdia」→「メディア室 D027」。フェーズ 4 の 2)/ ダークモードは床の画像の外が暗い背景(画像そのものは明るいまま。設計どおり)。
- 直したもの: 地図の「検索」の窓も開いたら入力の焦点を当てる(押すまでキーボードが出なかった。最初の画面と同じ)。
- **見ていないもの**: ピンチで拡大したら帯を隠す(フェーズ 2 の 3。adb から 2 本指は出せない)/ 横画面と全画面(端末の回転の設定を変える要があるので触らなかった)/
  札・QR の読み取り(カメラ)/ 屋台の今・予定・写真(本番が止まっている)。利用者が手で見るか、本番を戻してから。

### 2026-10-30 — アプリを実機(Pixel 7a・Android 17)で確かめた(2026-10-30 夜。版 38 = 1000038・署名 d71fbf0a…。受け箱へ送った)

- 端末に残っていた地図で、来場者版を上書きで入れて(データは残る)動かした。日本語・ダークモードで、最初の画面 → ジャンル → トイレ → 出発地を探す → 経路(赤い線・目的地のピン・使う階・階段の「▲上行き」)まで通った。
  英語に切り替えて同じ流れと、地点の詳細・履歴・予定・下の帯・上の帯・位置情報の案内を見た。残る日本語は場所の名前(データ)と設定の画面(測位と管理の項目が中心なので日本語のまま)。
  見終えてから端末のアプリは日本語に戻した。管理版(端末は版 22)は触っていない。予定は本番が止まっているので「読み込み中」のまま(想定どおり)。
- **実機で見つけて直したもの**(版 34 → 38):
  - 「🌐 English」を押しても画面が変わらなかった: 最初の画面はダイアログで、Context が Activity ではなく `recreate()` が呼ばれていなかった(AppLanguage.kt で包みを外して探す)。
  - 「行き先: トイレ D021(1F)(1F)」と階が 2 回出た(roomName が既に階を付けている)。外の地点が「(OUTSIDE)」と内部の値で出ていた(日本語でも。floorLabel で「外 / Outside」)。
  - 最初の画面の結果の一覧が 520dp で切れて画面の下半分が空いていた(残りいっぱいに)。探す欄は開いたら入力の焦点を当てる(押すまでキーボードが出なかった)。
  - **「建物」のジャンルの先頭に「1号棟 階段」など階段・エレベーターが 37 件並んだ**: 名前の「棟」に当たっていた。種類で集めるジャンル(階段・教室・建物)は、名前が当たっても別の種類(部屋・建物・階段・出入口)の地点を入れない。**Website(Main/app.js の genreResults)も同じ決まりだったので両方直した**(EntryGenresTest・check.php)。
  - 英語の画面で残っていた上の帯(ゲスト・ログイン)・ドロワーのメニュー・位置情報の案内・検索・履歴・案内できない理由・「使う階」「AR 案内」「札・QR」を英語にした。
- 確かめ: アプリの単体試験 915 件(両フレーバー)・check.php 2580 件・JS の試験。v34〜v37 は `Old\release-v34-20261030` 〜 `v37` に写した。

### 2026-10-30 — フェーズ 6: 多言語・運用・守り(2026-10-30。Web は 11-01 に配備済み・アプリは版 34 = 1000034・署名 d71fbf0a…。受け箱へ送った。本番は止めているので取りに行くのは戻してから)

- **英語の公開ページ(6 の 1)**(Main/i18n.js・試験 39 件):
  - 言語は `?lang=en|ja` → 端末に覚えた値(localStorage `km-lang`)→ ブラウザの言語(日本語でなければ英語)。切り替えはアカウントの欄と最初の画面の「🌐 English / 日本語」。
  - 画面の文は日本語 → 英語の辞書と型(「12 人待ち」「講義室1を目印に右へ」「ブックマーク(3)」「3階」など)で、MutationObserver が後から出た文も訳す。HTML の改行と字下げは 1 つの空白にして引く。
    **入力欄・`translate="no"`・コードは訳さない**。辞書に無い文は日本語のまま(壊さない)。日本語のときは何もしない(読み込むだけ)。
  - **建物・部屋・催し・メニューの名前は中身なので訳さない**。主な場所だけ、管理画面「データベース → 読み・別名」の新しい欄「英語の名前」で入れる(lib/english-names.php・別表 km_english_names。CREATE だけ)。
    公開の地図データに `nameEn`(地点・一時地点)・`titleEn`(ポスター)を付け、英語のとき i18n.js が app.js より前に名前と入れ替える(日本語の名前は読みに回すので日本語でも探せる)。
  - 試し場で `?lang=en` を見た: 最初の画面・帯・チップ・設定・表示調整・履歴・経路・予定まで、残る日本語はデータの名前だけ。
- **脆弱性のメール(6 の 2)**(lib/security-audit.php・scripts/security-audit.php・check-updates.sh の「脆弱性」の段):
  - 毎日の点検で 4 つを見る: PHP(php.net の同じ系列の最新とセキュリティの印)・`composer audit`・Soketi の `npm audit`(moderate 以上)・Ubuntu の USN(**入っている版が直した版より古いものだけ**。Debian の版の比べ方で。カーネルの CVE-2026-98432 のようなもの)。
  - 新しい勧告があれば必ず送る(件名「[KosenMap] 脆弱性が出ました。対処してください (N件)」・当て方つき)。**送れてから** `/var/lib/kosenmap/vulns-seen` に覚え、同じものは二度と送らない。読めなかった所は点検の出力に「判定を保留」と出す(「無い」とは書かない。メールにはしない)。
  - GitHub のセキュリティ勧告は composer audit・npm audit が同じ元(GitHub Advisory Database)を引くので別には見ない。php.net と USN の本物の答えの形は手元で確かめた。**ホストでの通しはまだ**(本番を止めている)。
- **宣伝と当日の段取り(6 の 3)**: plan.md「宣伝と当日の段取り」に、作る物(入口の掲示・現在地の札の置き場)・時期・係の声かけ・当日の見張りを書いた。コードは足していない。
- **アプリ(版 34)**: AppLanguage.kt。端末の言語が日本語でなければ英語(最初の画面の「🌐 English / 日本語」で切り替えて覚える)。
  来場者の画面(最初の画面・ジャンル・下の帯・地点の詳細・経路の案内と階段の札・予定・屋台・前回の経路)の文を「日本語と英語の対」(`ui(ja, en)`)にした。管理の画面と診断は日本語のまま。
  地点の名前は配信の `titleEn`(Website の「英語の名前」)を出し、検索でも引く。地図のデータは書き換えない(管理版が保存する地図に英語が混ざらない)。values-en/strings.xml も足した。
- 確かめ: check.php 2579 件・JS の試験(i18n 39 ほか全部)・アプリの単体試験 914 件(両フレーバー)。**アプリの英語の画面は実機でまだ見ていない**。
- 配備のあとに要るもの: web の作り直し(新しい PHP・JS)。check-updates.sh は host-setup の HOST_SCRIPTS で配られる。表は開いたときに作る。

### 2026-10-30 — フェーズ 5: 予定・屋台の情報(2026-10-30。Web は 11-01 に配備済み・アプリは版 33 = 1000033・署名 d71fbf0a…。受け箱へ送った。本番は止めているので取りに行くのは戻してから)

- **予定の会場と見せる相手**(lib/event-schedule.php・別表 km_event_schedule_extra(CREATE だけ)・管理画面「スケジュール」):
  - 会場 = 地点を 1 つ選べる(ステージの演目など番号の無い催し)。公開の地図では会場を場所の先頭にする(出している地点だけ)。
  - 見せる相手 = 一般 / スタッフだけ / 出さない(今の「一般に出す」の印は、一般のとき 1 のまま残す)。スタッフだけの行は **km_map_teacher_session**(教職員の組織のアカウントとお試しの閲覧リンク)の地図にだけ届き、「スタッフだけ」の札を付ける。
    地図のデータは `no-store` なので、ほかの人の控えに残らない。**お試しの閲覧リンクでも見える**(教職員と同じ扱いのため)。
- **CSV の書き出し・取り込み**(Excel で開ける UTF-8 BOM・CRLF):
  - 見出しの名前で読む(列の順は問わない)。Excel の Shift_JIS と「2026/11/3 9:00」の日時も受ける。式で始まる値は頭に `'` を付けて書き出し、取り込みで外す(CSV の式の差し込み対策)。
  - id のある行は直す・空の行は足す(消すのは画面で)。会場は地点 id か、地図の名前とそっくり同じ名前(同じ名前が 2 つなら誤り)。
  - **2 段**: 読んで確かめる(行ごとの誤りを出す。誤りが 1 つでもあれば入れない)→ 合言葉(セッション・10 分)で「この ◯ 行を取り込む」。512KB・500 行まで。
- **会場 × 時間の表**(Main/schedule-grid.js・試験 11 件): 予定の欄で「一覧 / 会場 × 時間」を切り替え(端末に覚える)。列 = 会場、行 = 30 分、同じ会場で重なれば列を分ける。
  位置は CSSOM で入れる(CSP)。マスを押すと会場の地点を開く。試し場で明るい・ダークの両方を見た。
- **屋台がつなぐ API**(lib/stalls.php・表 km_stalls / km_stall_status・管理画面「データベース → 屋台」):
  - 管理画面で屋台を足すと鍵 `kms_…` を出す。**鍵はその 1 度だけ見せる**(表には SHA-256 と末尾 4 字。URL に載せない)。なくしたら「鍵を出し直す」、「止める」で鍵も地図も止まる。
  - 屋台は `POST /api/stall.php`(`Authorization: Bearer kms_…`)でメニュー(30 品)・「◯人待ち」・ひとことを送る。1 屋台 5 秒に 1 回(429)・本文 8KB・入口は nginx の km_api。
    端末の無い屋台は、管理画面から係が入れられる(メニューは 1 行 1 品「名前,値段」、売り切れは頭に ×)。
  - **古い値は出さない**: 待ちは 30 分・メニューは 12 時間で消えた扱い。
  - 場所は企画の番号(一時地点を取り込み直しても外れない)か地点。公開の地図は地点・一時地点の詳細に「🍽 屋台の今」(待ちの人数で緑・黄・赤)を出し、
    **見えている間だけ 30 秒ごとに `GET /api/stalls.php` を読み直す**(場所を返さない・錠が掛かっていれば空・端末の中だけ 15 秒控える)。
  - **Soketi では押し出さない**: `/ws/app/` は管理画面の Origin だけに開けている(2026-10-19)。来場者の全員に WebSocket を開けるのは守りと負荷の判断なので、
    読み直しで足りるうちはこのまま(開けるなら利用者の判断で、公開側の Origin と同時接続の上限を足す)。
  - 案内チャット: メニューの品の名前でも探せる(「焼きそば どこ」)。カードと LLM に「3 人待ち・焼きそば 400 円」を渡す。
- **ストリートビュー(フェーズ 5 の 3)**: 置き場・API・アプリの 360° 表示は 2026-10-06 からある。Website に広げるときに決めること(見せ方 2 案・撮る決まり)を plan.md「ストリートビュー(360°)を Website に広げる下地」に書いた。**作らない**。
- **アプリ(版 33)**: 下の帯に「予定」(ScheduleDialog.kt・EventSchedule.kt。`POST /api/app-schedule.php` を地図のアクセスコードで。一般の行だけ)。
  一覧と会場 × 時間の表(並べ方は Web と同じ。ScheduleGridTest は schedule-grid.test.js と同じ例)。場所を押すとその地点を開く。
  部屋の詳細(api/node-extras.php に schedule・stalls を足した)に「🍽 屋台の今」と「この場所の予定」。教員室・研究室は出さない。
- 確かめ: check.php 2559 件・JS の試験(schedule-grid 11・stalls 5 ほか全部)・アプリの単体試験 911 件(両フレーバー)。
  管理画面の「スケジュール」(CSV)と「屋台」はサインインと DB が要るので試し場では見ていない(lint と check.php の決まりだけ)。**アプリの画面は実機でまだ見ていない**。
- 配備のあとに要るもの: web の作り直し(新しい PHP・JS)と reverse-proxy の restart(nginx の km_api に stall・stalls・app-schedule を足した)。表は開いたときに作る。

### 2026-10-30 — フェーズ 4: 検索と AI の案内(2026-10-30。Web は 11-01 に配備済み・アプリは版 32 = 1000032・署名 d71fbf0a…。受け箱へ送った)

- **読み・別名**(lib/search-aliases.php・表 km_search_aliases(CREATE だけ。ALTER 不要)・管理画面「データベース → 読み・別名」):
  地点(id)・一時地点(番号)・ポスター(番号)ごとに「れっつぷれいでんさん、レッツプレイ電算、電算部」のように入れる(読点・カンマ・改行で区切る。1 件 10 個・1 つ 40 字)。
  公開の地図データが `readings`、アプリへの配信が `searchAliases` として付ける(出している地点だけ)。検索・最初の画面・案内チャットの一覧・アプリの検索に入る。
  管理画面は絞り込んだ 150 行まで出し(PHP の max_input_vars)、変えた行だけ保存する。地図の取り込みでは消えない。
- **ポスターの説明も検索に入れた**(公開の検索の別名)。
- **もしかして**(AI を使わない。Main/search-rank.js の suggest・アプリの SearchSuggest.kt): 何も当たらないとき、ひらがな → ローマ字にそろえた形どうしと
  そろえた形どうしの近さ(2 字ずつの重なり・編集距離・長い名前の一部)で一番近いものを 1 つ「もしかして、こちらですか？」と出す(近さ 0.6 以上・2 字以下では出さない)。
  漢字は読めないので、読みの欄で拾う。試し場で「講議室」→ 第一講義室、読み「でんさん」→ 読みを付けた部屋を確かめた。
- **企画の番号**: 番号そっくり(「8」)は名前の完全一致より上(1100 点)。検索の欄の案内を「場所・企画の番号で検索」にした。アプリも同じ。
- **案内チャットを会話に**(lib/guide-chat.php・Main/guide-chat.js): 画面が直前の 2 往復(質問・答えの頭 200 字・カードの名前)を送る(**サーバーには残さない**)。
  「他には？」は直前の質問を探し直して直前のカードを除く。「そこへの行き方」の「そこ」は直前の最初のカード。関係の無い話には答えず案内の話へ戻すよう頼む。
  本文の上限を PHP 8KB・nginx 12k にした(**reverse-proxy の restart が要る**)。
- **トイレなどは近い順**(lib/guide-distance.php): 今いる場所(現在地の QR か展示の場所)から、線の上の最短の道のり(Main/dijkstra.js と同じ繋ぎ方。部屋は通り抜けない・
  階段は 1 層 200px・出入口 150px・壁と通行止めは通らない)で並べ、カードに「🚶 約 15 m」。トイレ・救護・休憩・「近い」などを聞いたときだけ。
- 確かめ: check.php 2526 件・search-rank の試験 16 件・アプリの単体試験 906 件(両フレーバー)。管理画面「読み・別名」と案内チャットの会話は、サインインと LLM が要るので試し場では見ていない。

### 2026-10-30 — アプリのフェーズ 3(2026-10-30。版 31 = 1000031・署名 d71fbf0a…。受け箱へ送った。公開は利用者)

Website にそろえた。
- **最初の画面**(EntryScreen.kt): 地図を読み終えたら 1 回、大きなボタンで探し方を聞く(番号・キーワード・部屋の名前・ジャンル・すぐ地図へ)。
  行き先を選ぶと出発地(📍 今いる場所から / 📷 近くの札・QR を読んでそこから / 🔎 探す / 決めない)→ 経路。「次から出さない」は端末の設定 `entry_screen_skip`。
  一般向けビルドだけ自動で出す。共有のリンク(場所・経路)から開いたときは出さない。下の帯の「はじめ」でいつでも開ける。出すときは「前回の経路」を聞かない。
- **ジャンル**(EntryGenres.kt。Website の KM_GENRES と同じ決まり): 一時地点の種類(トイレ・模擬店・展示・体験・休憩・受付・救護・ステージ)も拾い、
  小さい分類(臨時のトイレ・トイレ・企画 …)ごとに見出し。空のジャンルは出さない・イベントは会期中だけ。最初の画面の「ジャンルから」で使う(アプリには上のチップが無い)。
- **番号で探す**: 企画の番号がそっくり同じ地点 → 番号の頭が合う地点。当たらなければ言葉としても探す。
- **QR の現在地**: `https://ito4.jp/?here=<id>` のリンク(一般向けビルドが受ける)とアプリの中の札・QR の読み取りの両方で、その地点を今いる場所にする(この起動の間だけ)。
  `?here=` は共有とは数えず、最初の画面を出す。
- **階の列を指で上下にはらう**と上下の階へ(上へ = 上の階・30dp 以上。縦画面だけ。横画面は列を送る方を優先)。
- 確かめ: 単体試験 902 件(両フレーバー。EntryGenresTest 6 件・SharedMapLinkTest と RoomRecognitionTest に here を足した)。**画面は実機でまだ見ていない**。
- **本番は止まっている**(CVE-2026-98432)ので、受け箱の取りに行きと公開のメールはサーバーを戻してから。

### 2026-10-30 — 位置を自動で取る方法の調べ(2026-10-30。フェーズ 3 の 7。作らない)

plan.md「位置を自動で取る方法」。ブラウザは Wi-Fi・BLE・気圧を読めず、
iPhone の Safari は WebXR を出していない。GPS は屋外だけで階が分からない。**Website は QR と、同じ `/?here=` を書いた NFC のシール**(コード不要。iPhone も Android もかざすと開く)で足りる。
自動の測位と AR はアプリに任せる(既にある)。

### 2026-10-30 — 校内の QR で現在地を合わせる(2026-10-30。フェーズ 3 の 6)

Website は現在地を測れないので、校内に貼った QR で「今いる場所」を決める。
- QR の中身は `/?here=<地点の id>`(index.php が字の形だけ確かめて meta で渡す。共有のリンクとは数えない —— 読んだ人はこれから行き先を探すので最初の画面を出す)。
  開くとその階へ移って寄せ、「📍 今いる場所」の札を出す。覚えるのは**このタブの間だけ**(sessionStorage。サーバーへは送らない)。
- 使う所: 経路の出発地が空のとき(展示の PC の出発地より先)・最初の画面の出発地の一番上「📍 今いる場所(○○)から」・案内チャットの「現在地から」「ここはどこ」
  (Main/guide-chat.js が地点の id を送り、api/guide-chat.php が字の形を確かめて展示の場所より先に使う。地図に無い id は効かない)。
  「位置の更新」で選んだ所も今いる場所にする。経路が今いる場所から始まる間は、出発のピンと重ねないよう札を隠す。
- 最初の画面の「📷 校内の QR を読む」は読み方の案内だけ。**このページではカメラを使わない**(サイトは Permissions-Policy で camera=() にしてある)。
  スマホのカメラで読めば、どの端末でも開ける。
- 管理画面「データベース → 現在地の QR」(admin/map-here-qr.php・lib/here-qr.php): 名前のある部屋・建物・出入口・階段を階ごとに並べ、選んで「印刷用に並べる」。
  A4 に 3 列 × 4 段(名前・階・「スマホのカメラで読むと、ここから行き先までの道順を出せます」)。QR はこのサーバーで作る(lib/qr.php)。1 回に 60 個まで。
- 試し場: `?here=<2F の A階段>` で 2F が開いて札が出る → 最初の画面に「📍 今いる場所: A階段」→ 講義室1 → 「今いる場所(A階段)から」で「▼ 1F へ下りる」の経路。
  管理画面の印刷のページは、サインインが要るので試し場では見ていない。

### 2026-10-30 — 経路の案内を分かりやすく(2026-10-30。フェーズ 3 の 3)

振り返り「どこで曲がるのか」「階段を上るのか下りるのか」「案内中も地図がごちゃごちゃ」。
- **曲がる所の目印**: 角度の決まりはアプリの RouteInstructions.kt と同じ(20° 未満は直進・60° までは斜め・135° 超は引き返す。Main/route-view.js の routeTurns)。
  近く(60px 以内)の名前のある部屋・建物・出入口・階段を目印にして「講義室1を目印に右へ」を角に札で出す。案内の帯にも「次: ↱ …」。
  引いて見ているとき(階の全体から 1.5 段未満)は札を矢印だけにする(札どうし・出発のピンと重なる)。
- **階段**: 経路が通る階段に「▲ 2F へ上る」(朱)「▼ 1F へ下りる」(青)「エレベーターで 3F へ」「2F から到着」。上り口・下り口の向きがあれば矢印をその向きへ。
  拡大すると、ほかの階段にも行ける階(▲上行き 2〜5F / ▼下行き 1F / エレベーターは ⇅)。アプリの StairGuide.kt と同じ言葉・同じ色。
  地図データに階段の向き(stairUpHeading / stairDownHeading。km_map_node_stairs を uuid で引く。uuid は出さない)を載せた。
- **案内中は経路の地点と目印だけ濃く**、ほかの地点と札は 35% に(押すことはできる)。目的地が一時地点ならその印も濃く。
  Leaflet が印と札に opacity を直接書く(札は 0.9)ので !important で上書きする。
- アプリ(次の版): 曲がる手順の文に目印を足した(RouteInstructions.kt の turnLandmark。「講義室1を目印に右へ」)。試験 2 件。
- 試し場: 出入り口 → 講義室1 で「↰ 女子WC 1号棟を目印に左へ」「↱ A階段を目印に右へ」「▲ 2F へ上る」「2F から到着」、帯に「次: ↰ …」。route-view の試験 42 件。

### 2026-10-30 — ジャンル(大 → 小)(2026-10-30。フェーズ 3 の 2)

上のチップをジャンルにした
(🚻 トイレ・🍢 食べる・飲む・🎨 見る(展示)・🧪 体験・🪑 休む・座れる・📋 受付・救護・🎤 ステージ・🏃 階段・EV・📖 教室・🏢 建物・会期中は 🎪 イベント)。
- **一時地点も拾う**(振り返り「臨時のトイレが 🚻 に出ない」)。ジャンルごとに、入る一時地点の種類・地点の種類・名前の言葉・企画の地点を
  1 か所で決めた(app.js の KM_GENRES)。結果は小さい分類ごとに見出し(例: トイレ → 「臨時のトイレ(1)」「トイレ(26)」)。
- 当てはまる所が 1 つも無いジャンルはチップを出さない(会期の外に空のジャンルを並べない)。最初の画面の「ジャンルから」も同じ。
- 一時地点の種類に **体験(experience)・休憩・座れる場所(rest)** を足した。地図編集・案内チャット・アプリへ渡す言葉・アプリからの取り込みの言葉もそろえた。
  DB の列は文字列なので表はそのまま。アプリは知らない種類も「種類2」の言葉で出すだけなので、版を上げなくても壊れない。
- 試し場(臨時のトイレと模擬店を足した 1 階)で、トイレに臨時のトイレが出る・食べる・飲むに模擬店が出る・空のジャンルが隠れる、を見た。

### 2026-10-30 — 最初の画面(2026-10-30。フェーズ 3 の 1。Main/entry.js)

地図を開くと大きなボタンで探し方を聞く
(🔢 ポスター・企画の番号 / 🔤 キーワード / 🚪 部屋の名前 / 🗂 ジャンル / 🗺 すぐ地図へ)。
- 1 画面ずつ: 探す → 行き先 → 「📍 地図で場所を見る」か「🚶 ここへの道順」→ 出発地を探す(または経路の欄で選ぶ)→ 経路。どの画面にも「もどる」「✕ 地図へ」。Esc でも地図へ。
- 探すのは地図の検索と同じ一致率・同じアイコン(app.js の window.kmEntryApi)。部屋の名前は部屋だけ、出発地は案内できる所だけ。
  ジャンルは上のチップをそのまま並べ、押すとチップと同じ一覧(フェーズ 3 の 2 でチップの側を大 → 小に直す)。
- 「はじめに」を閉じてから出す。共有のリンクから来た人・「次からこの画面を出さない」にした人には出さない(端末に覚える。展示の PC では覚えない)。
  操作の帯の「🏠 はじめ」からいつでも開ける。
- **チュートリアルは自動で始めない**(この画面の「使い方を見る」から)。お知らせは下の 1 行(一覧のページへ)。
  広告は「すぐ地図へ」「✕」で閉じたときだけ(行き先を選んだ人には出さない。「はじめに」を閉じたときの広告はやめた)。
- 試し場: キーワード「講義室」→ 講義室1 → 道順 → 出発地「出入」→ 出入り口 → 経路が引けて案内の帯が出る。スマホの幅では画面いっぱい・ボタンは 1 列。

### 2026-10-30 — web の PHP に GD を入れた(2026-10-30、利用者の指示)

docker/php/Dockerfile に GD(PNG・JPEG・WebP)。
ポスターの縮小版(?w=640)と、管理画面で差し替えた床の WebP の写しが本番でも効くようになる(組み込みの床は前から手元で作った写し)。
- 作るときだけ要る -dev は最後に外し、gd.so が使う共有ライブラリだけ残す(公式の php イメージの拡張と同じ apt-mark の手順)。
  作り終えたら imagewebp・imagecreatefromjpeg があるかを確かめ、無ければ作るのを失敗させる。
- 縮小版と写しの置き場を一時置き場から cache/(floor-webp・image-thumb)へ。web の tmp は 64MB の tmpfs で、アップロードの一時ファイルと取り合うため。
- **配備の up はイメージを作り直さない**(docker compose up -d だけ)。配備のあとホストで `docker compose build web` → `up -d web` が要る。
  手元に Docker が無く、作れるかはホストで初めて分かる。

### 2026-10-30 — 階の列でホイール・スワイプ(2026-10-30。フェーズ 3 の 4)

階の列(畳んだ丸を含む)の上でホイールを回すか、指で 30px 以上上下にはらうと、
上下の階へ移る(上 = 上の階。外 → 1F → 2F …)。地図の拡大へは渡さない。続けて回しても 250ms に 1 段。一覧が縦に送れるときは送る方を優先。
試し場で 1→2→(続けて回すと止まる)→3→2、はらって 3→2、軽く触っただけは移らない、拡大率は変わらない、を見た。アプリはフェーズ 3 のアプリの版でそろえる。

### 2026-10-30 — ポスターの押し間違い(2026-10-30。フェーズ 3 の 5)

「地図で見る」は 🗺 の塗りのボタン、外のページは「🌐 外のサイトで見る ↗」の点線の縁で右の端へ離した
(一覧・大きく見る幕・地図の詳細・広告で同じ言葉)。外のサイトへ出る前に「外のサイト(相手の名前)へ移ります」と 1 度聞き、やめたら移らず数えもしない
(Main/poster-stats.js。外へ出るリンクはどれも data-km-poster-link を持つので 1 か所で足りる)。

### 2026-10-30 — 直す前の速さ(2026-10-30。フェーズ 0 の 6。本番 ito4.jp・フェーズ 1 まで配備済み・フェーズ 2 の前)

利用者に地図の錠を解いてもらい、
内蔵のブラウザで scripts/perf-probe.js と同じ手順(1 階・ズーム 4 段の出し入れ・横へ 8 回)を流した。PC は 16 スレッド・16GB。
| 幅 | 床の画像 | 床の画像(展開後) | 要求 | DOM | 拡大中の札 | 大きさの測り | コマ 中央値 / 95% / 最大 | 50ms 超 | 長い処理 |
|---|---|---|---|---|---|---|---|---|---|
| PC 977px | 17 枚(全階) | 329KB | 38 | 334 | 73 | **1709 回** | 4.2 / 4.3 / 37.3ms | 0 | 0 |
| スマホ 375px | 17 枚(全階) | 329KB | 38 | 334 | 73 | **1601 回** | 4.2 / 4.3 / 20.8ms | 0 | 0 |
- 本番の階は 17(建物の小さな図を含む)。開いたときに全部読んでいた → フェーズ 2 で 今の階 + 隣(1 階なら 外・1・2 の 3 枚)。
- この PC は速く、コマの落ちは前後とも出ない見込み。**比べるのは大きさの測りの回数と床の画像**(端末の速さに左右されない)。
  CPU を 6 倍遅くする設定と Lighthouse は内蔵のブラウザでは使えない(開発者ツールが無い)。遅い端末の値は実機か Chrome の開発者ツールで。
- perf-probe.js に、大きさの測りの回数・床の画像の枚数と大きさ・拡大中の札の数を足した。配備のあとに同じ手順で測って並べる。

### 2026-10-30 — 大事な地点だけ出す(2026-10-30。フェーズ 2 の 2。利用者の判断「B」)

建物の中でも、置けない札は隠す(それまで 2026-09-25 の指示で屋外だけ)。
- 点は残る。押せば名前が分かり、拡大すると出てくる。**選んだ場所の札だけは隠さない**(label-layout.js の keep。アプリは目的地も)。
- 置く順: イベントの地点 > 施設・出入口 > 階段 > 部屋 > ほか。イベントで関係ない(薄い)地点は一番後ろ。
- **前からのずれを直した**: 上下にずらす量を margin-top にそのまま書き、Leaflet の -6px を消していた。ずらした札が 6px 下へ落ちて隣と重なっていた。
  試し場の 1 階で、出ている札どうしの重なりが 2〜8 組 → **全倍率で 0**。
- アプリ(版 30): LabelLayout.kt に keep、labelPriority にイベント・関係ない地点、MapScreen は屋内外とも declutterLabels。

### 2026-10-30 — 拡大したら地図以外を隠す(2026-10-30。フェーズ 2 の 3)

利用者が自分で、その階の全体の 4 倍(2 段)より拡大したら、
検索・チップ・アカウント・操作の帯を薄く消す(押せなくもする)。階のボタン・経路の帯・ズームは残す。
縮める・地図の何もない所をたたく・Tab/Esc で戻る。検索の候補で飛んだとき・打っている間・経路の欄を開いている間は隠さない。
「自分で」は地図の上の操作から 1.5 秒以内の拡大で見る(Leaflet はピンチ・ホイールの zoomstart に originalEvent を付けない)。

### 2026-10-30 — 全画面・横画面(2026-10-30。フェーズ 2 の 4。Main/top-bar.js)

アプリの TopBarController と同じ動き。
- ズームの上に全画面のボタン(requestFullscreen が使える端末だけ。iPhone の Safari には出ない)。
- 全画面か横にしたスマホ(高さ 500px 以下)では上の帯を隠し、上の真ん中に取っ手(▼)。上の端 40px から 24px 引くか、取っ手を押すと 4 秒出る。
  打っている間・アカウントの欄が開いている間は隠さない。

### 2026-10-30 — 軽くする(2026-10-30。フェーズ 2 の 5)

- **床の画像を可逆の WebP で**(lib/floor-webp.php。見た目は同じ): 1 階 49KB → 11KB、外 119KB → 40KB(3〜8 分の 1)。
  WebP を読める端末(Accept)だけ。**本番の PHP には GD が無い**ので、組み込みの床は手元で `php src/scripts/make-floor-webp.php` で横に写しを作って一緒に配る
  (PNG より古い写しは使わない。check.php が写しの抜けを見る)。管理画面で差し替えた床は GD があれば一時置き場に作る(今の本番では PNG のまま)。
- **ポスターの一覧・検索の候補は縮小版**(lib/image-thumb.php・?w=640 の WebP)。これも GD が要るので、今の本番では元の画像のまま。
- 先読みは**いまの階と上下の隣だけ**(それまで全階)。切り替えたら、その階の隣を裏で読む。
- 地点 1 つずつの影(drop-shadow)をやめて細い線の縁に(地点 200 個それぞれにぼかしの層ができていた)。
- 表示調整に「軽い表示」(自動 / いつも / しない)。自動はメモリ 2GB 以下・4 コア以下・データ節約の端末。
  飾りの影(--shadow-sm/lg・札・ピン)と動きを止める。チュートリアルの暗幕・経路の階の赤い輪(どちらも影で描く)は残す。
- 試し場: php -S が同時の読み込みで接続を切り、HTML や app.js が途中で切れていた。node の入口(map-harness-node・8774)を前に置いた。

### 2026-10-30 — アプリのフェーズ 2(2026-10-30。版 30)

札の隠し方を Website とそろえた(上の「大事な地点だけ出す」)。全画面・横画面は前から TopBarController にある。
拡大したら部品を隠す動きはアプリには入れていない(アプリは上のバーと下の操作が小さい)。単体試験 両フレーバー通過。実機の画面はまだ見ていない。

### 2026-10-30 — 札の並べ直しを軽く(2026-10-30。フェーズ 2 の 1)

振り返り「ズームがカクつく」。
- **横へ動かしただけでは札を測り直さない**(拡大率・階・札の数が同じなら)。札と点は同じだけ動くので、ずらし方は変わらない。
  拡大・表示調整・階の切り替えなど外からの呼び出しは必ず測る。経路の線の重なりの塗り直しも、拡大したときと札を書き終えたときだけ。
- 札の置き方の書き込みを 60 個ずつ requestAnimationFrame に分ける(建物の中は 200 を超える)。次の並べ直しが始まったら古い分は書かない。
- 試し場(8 月のアプリの地図の 1 階・地点 191)で、2 段拡大して 12 回横へ動かす間の大きさの測り(getBoundingClientRect)が
  **4008 回 → 334 回**。手元の PC は速く、長い処理(50ms 超)はどちらも 0。遅い端末での値は本番の錠を解いてから測る(フェーズ 0 の 6)。
- 試し場: php -S に router を挟み、index.php に 8 月のアプリの地図(uploads/app-map-kosen-main.json)を地図データとして埋め込む(scratchpad の map-harness)。

### 2026-10-30 — アプリのフェーズ 1(2026-10-30。版 29)

Website とそろえた。
- 地図の周りの色を Website と同じに(明るい #F3EBDD・暗い #0F1116。床の画像は暗い表示でも明るいまま)。MapScreen の外側の Box。
- 地図の上の丸いボタンの縁を 1dp の灰色 → 2dp の濃い色(明るい #4B4036・暗い #CBD5E1。Website の --km-control-edge)。
- **目的地の赤いピンと脈打つ輪**(DestinationPin.kt)。経路の到着地、経路が無ければ選んだ場所。それまで到着地に印が無かった。
  輪を動かすのは目的地があるときだけ(無いときに回し続けると毎コマ描き直す)。大きさは拡大率で割って画面の上で一定。
- 文字の大きさ(80〜260%)と色分けは前からある。
- 確かめ: 単体試験 両フレーバー通過。**実機の画面はまだ見ていない**(この PC に端末が無い)。

### 2026-10-30 — 場所ごとの色分け(2026-10-30。フェーズ 1 の 3)

アプリの色分け(AreaSegmentation.kt)を Website にも。
- 計算はアプリと同じ決まりで JS に移した(Main/area-segmentation.js。試験 scripts/js/area-segmentation.test.js はアプリの試験と同じ場面 34 件)。
- **塗るのは管理画面**「データベース → 地図の色分け」(admin/map-areas.php)。このブラウザで各階を塗り、PNG を admin/api/map-areas.php へ 1 階ずつ送る。
  材料はアプリと同じ: 地点(部屋・施設・道・階段・出入口)・壁の線(両端が壁の地点)・管理アプリで手で決めた区画の種類と色・区画の印。
  表に階ごとの区画の数と、確かめ(漏れ・名前なし・あいまい)の数・見本(確かめの色)を出す。漏れが出たら管理アプリで壁の線を足して作り直す。
- 置き場は uploads/area_*.png、記録は km_settings の `map_area:<階>`(塗ったときの床の画像の URL も)。配るのは api/floor-image.php?…&area=1(地図の錠の内側)。
  **床の画像を差し替えると古い塗りは出さない**(URL の版が変わる)。公開の地図は塗った床があればそれを出し、表示調整「場所ごとの色分け」で切れる(既定は出す)。
- 来場者の端末では計算しない(手元の 1 階 1600×1200 で 0.1 秒・PNG 78KB だが、遅い端末のズームを邪魔しない)。
- 確かめ: 手元で 1 階の画像と 8 月のアプリの地図の地点 191 個で塗った → 閉じた区画 106・部屋 77・廊下 5・階段 3・名前なし 20・**漏れ 0**(画面で見た)。
  check.php 2442 件(URL の出し分け・材料の組み立て・口の順番)。**管理画面の画面そのものと、本番の地点での塗りは配備してから見てもらう**。

### 2026-10-30 — 縁取り・目的地・文字の大きさ(2026-10-30。フェーズ 1 の 2・4・5)

- 2: ぼかし(backdrop-filter の blur)を 28 か所すべて外し、面を透けない色に(--glass 97%・--glass-heavy は不透明)。
  検索の窓・チップ・左下の操作・階の列・アカウントの欄・経路の帯に 2px の濃い縁(--km-control-edge。明るい #4B4036・暗い #CBD5E1)。
  いまの階は内側に 3px の縁(外側の縁は階の並びの箱で切れた)。
- 4: 選んだ場所は青い輪 → **赤いピン + 脈打つ輪**(ピンの先・指す点・輪の真ん中を 48px でそろえた。試し場で座標を測って 0.5px 以内)。
  出発・到着の吹き出しを 13px → 17px、到着は赤い面に白い字で、指す点に脈打つ輪。動きを減らす設定の人には輪を止める。
- 5: 表示調整の「文字」の上限を 200% → 260%(アプリの文字サイズと同じ)。丸め・管理アプリで配った既定の上限もそろえた。
- 確かめ: 試し場で明るい・暗い配色の操作の縁、階の列、ピンと吹き出しを見た。map-style.test.js 54 件・check.php 2438 件。

### 2026-10-30 — 地図の背景とカーソル(2026-10-30。振り返りのプランのフェーズ 1 の 1)

振り返り「背景が白くてカーソルが見えない」・ダークモードで周りが灰色。
- 地図の周りを温かい色 #F3EBDD に(--bg-map。読み込みの幕も同じ)。#map に背景を指定し、Leaflet の既定の灰色(#ddd)を使わない。
- 地図の上のカーソルは白い縁取りの十字(Main/cursor-map.svg。真ん中に赤い点)。引いている間は grabbing、押せる印・札は今までどおり pointer。
- 暗い配色でも**床の画像は明るいまま**にし、周りとの境に薄い縁。沈めたい人は表示調整「暗い配色のとき床の画像も暗く」(dimFloorImage。既定は OFF)。
- 確かめ: 試し場で 1 階の画像を Leaflet に載せ、明るい配色・暗い配色・暗い配色 + 床も暗く、の 3 通りを見た。check.php 2435 件。
- アプリ側(ダークモードの地図の配色をそろえる)は、フェーズ 1 の残りとまとめてリリース版を作る。

### 2026-10-30 — 本番で確かめた(2026-10-30 0:03)

配備のあと `/var/lib/km-sessions` が `drwx------ www-data www-data`(利用者)。
残り: ログインしたまま、もう一度配備してログインが残るか。

### 2026-10-29 — 利用者数の数え方(2026-10-29。フェーズ 0 の 5)

振り返りの「利用者 170 人」は送り元 IP の数で、学校の回線は何人でも 1 つに数えていた。
- 管理画面「訪問者」に**端末の目安**を足した(送り元(IP)の札の下と、日別の表の列)。新しい印・Cookie は作らず、記録に既にあるものだけで数える:
  サインイン・お試し・アプリは種別の印、それ以外は IP と端末の名乗り(User-Agent)の組。プライバシーポリシーは変えなくてよい。
- 目安であること: 同じ回線の同じ機種・同じ版のブラウザは 1 つに、回線が変わった端末(Wi-Fi ↔ モバイル)は 2 つに数える。
- 確かめ: 試しの 5 件(同じ IP の 2 機種 + サインインした 1 人が 2 つの IP)で IP 2・端末 3。check.php 2431 件。

### 2026-10-29 — 直す前の速さ(2026-10-29。フェーズ 0 の 6)

測る手順を server/scripts/perf-probe.js にした(地図を開いてコンソールで実行。読み込み・部品の数・
ズームと移動の間のコマの間隔と長い処理)。**本番の地図は会期のあと錠が掛かっていて、まだ測れていない**(パスワードは私が入れない)。
利用者に錠を解いてもらってから、そのままの速さと CPU 6 倍遅くした速さを測り、ここに残す。

### 2026-10-29 — 配備でログインを消さない(2026-10-29。フェーズ 0 の 4)

PHP のセッションが web の /tmp(tmpfs)にあり、配備で web を作り直すたびに全員がログアウトされた。
- 名前付きのボリューム km_web_sessions を /var/lib/km-sessions に置き、99-limits.ini の session.save_path をそこへ。compose.yaml と compose.vps.yaml(`!override`)の両方に書いた。
  名前付きのボリュームは初め root の持ち物なので、start-with-local-ca.sh が起動のとき www-data・0700 にしてから Apache を起こす(Dockerfile は触らない)。
- **同じ日の直し**: 本番で `/var/lib/km-sessions` が無かった(compose の作り直しが届く前に見たか、作り直されていない)。99-limits.ini で
  save_path を決め打ちにしていると、ボリュームが無いまま ini だけ届いたとき全員がログインできなくなるので、ini からは外し、
  lib/session.php の km_session_save_path が「在って書けるときだけ」そこへ向ける形にした(無ければ /tmp のまま)。check.php 2433 件。
- この PC に Docker が無いので、**本番で確かめる**: 配備のあと `docker exec km-php-apache ls -ld /var/lib/km-sessions` が www-data・drwx------、
  ログインしたまま、もう一度配備してもログインが残ること。初回の配備では /tmp のセッションが移らないので、そのときだけ全員が一度ログアウトされる。

### 2026-10-29 — 共有に経路の選択を載せる(2026-10-29。フェーズ 0 の 3)

共有の URL に階段/エレベーター・階の移動を減らす・雨の日が無く、開いた人の端末の設定で別の経路が引かれていた。
- Web: shareRoute が `vp=elevator|stairs`・`ff=1`・`rain=1` を付ける。index.php は決まった値だけ meta(km-focus-route-options)へ。
  focusFromUrl はその選択で引く(**このページの間だけ**。localStorage は書き換えない。欄の表示だけ合わせる)。
- 共有から引いた経路は履歴(「前回の経路」)に入れない(km:route の shared。user-history.js)。
- アプリ(版 28): SharedMapLink.Route に options。MapScreen は**出発地と目的地がその共有の経路の間だけ**使い、端末の設定は変えない。履歴にも入れない。
  地図の左下に「不具合」(ブラウザで contact.php?subject=bug&where=app。【どこで】Android アプリ)。
- 確かめ: check.php 2430 件。アプリの単体試験 両フレーバー通過(共有の選択の読み取り・不具合の URL)。手元の試し場は地図データが無いので、共有のリンクの動きは本番で見てもらう。
- アプリ: v27 を Old\release-v27-20261025 へ写し、両フレーバーのリリース版 28(1000028・261029.2348)。署名は前と同じ鍵(d71fbf0a…)。apk-push で受け箱へ送った。

### 2026-10-29 — 不具合の報告を見える所に(2026-10-29。フェーズ 0 の 2)

アンケート 13「不具合の連絡先が分かるか」で「いいえ」が 5/8。窓口(contact.php)は ⚙️ の奥だけだった。
- 入口: 操作の帯の「🐞 不具合」(スマホは丸いアイコンだけ。テストモードでも出す)・経路の帯の 🐞・案内チャットの注意書きの後ろ・ポスターの一覧の上。どれも別のタブで開く。
- contact.php?subject=bug&where=map|route|guide|posters で開くと、件名を「不具合の報告」にし、本文に【どこで】【端末】【時刻】【何が起きたか】のひな形を入れる。
  URL に載せるのは決まった値だけ(場所の名前・検索語・個人のことは載せない)。端末は User-Agent から「スマホ(Android / Chrome)」程度だけ(lib/forms.php)。
- 確かめ: check.php 2424 件(ひな形・端末の見分け・知らない where)。スマホ 375 幅で操作の帯が 1 行に収まるのを見た。
  手元は reCAPTCHA が無くフォームを出さないので、ひな形の入った画面は本番で見てもらう。

### 2026-10-29 — ログインの入口をいつも見せる(2026-10-29。振り返りのプランのフェーズ 0 の 1)

高専祭の振り返り「ログインが消える」。
- 原因: ログインの欄は「ダウンロード・設定」(⚙️)の中だけで、スマホでは引き出し(案内チャット・場所の詳細)が開いている間 ⚙️ ごと隠れた。
  展示の PC は左下〜右上の小さな欄(.km-test-auth)で、「テスト中」の札・階の列と重なった。
- 直し: **右上にアカウントの欄**(#km-account、<details>)。閉じると「👤 ログイン」/「👤 ○○ さん」、スマホは丸い 👤 だけ。
  押すと今までのログイン/ログアウトの欄(1 回だけ組み立てる $kmAuthLinksHtml)が開く。外を押す・Esc で閉じる(panel.js)。
  出す所はここ 1 か所だけ(設定の中と .km-test-auth は外した)。z-index 1350 で、引き出し・シート・検索の窓より手前。
  PC の種類のチップは右に 200px 空ける。人の形はダークモードで沈まないよう、絵文字でなく文字の色の SVG。
- 案内チャットを引き出しの土台にする(開いたまま)のは **PC だけ**にした。スマホは「💬 案内」で開き、閉じれば地図に戻る。
- 確かめ: 試し場(php -S)で PC 1280 幅とスマホ 375 幅・ダークモード。横にはみ出さない・窓と重ならない・開いて閉じる。check.php 2419 件。

### 2026-10-29 — AI の頼み先を Unsloth に・AI を選べるように(2026-10-29。プランのフェーズ A)

利用者「Unsloth API が使えるようになったので変更」
「AI を選択できるように」(答え: 頼み先は自宅 PC の設定で選ぶ・模型は管理画面で選ぶ。Unsloth は http://127.0.0.1:8888/v1)。
- 本物の Unsloth を調べた: Unsloth Studio(Unsloth UI Backend 2026.10.10)。OpenAI と同じ /v1/chat/completions・/v1/models、
  読み込み /v1/load {model_path}・外す /v1/unload・いま読んでいる模型 /v1/status。**鍵(Bearer)が要る**(無いと 401)。
  会話の model は飾りで、いま読んでいる模型が答える。enable_thinking・reasoning_effort を受ける。**道具(Python・ターミナル・MCP)を使わせる欄がある**。
- 門番(server.py)を作り直した: 頼み先を 2 つ持つ(UNSLOTH_API_URL / BIONIC_API_URL。Bionic は古い LLM_API_URL でも読む)。
  一覧を「unsloth:<id>」「bionic:<id>」で作り、鍵の要る GET /v1/models で返す。頼みの model が一覧にあればそちら、無ければ既定。
  模型は GPU に 1 つだけ(ほかを外してから読む。入れ替えの間は 503 で答えない)。POST /v1/select で先に読み込みを始める。
  起動のときは既定の模型をアンロードしてからロード。**Unsloth には enable_tools・mcp_enabled を必ず false**。
  空の答えは 1 度 low で頼み直す。localhost は 127.0.0.1 にする(Windows で 1 回ごとに約 2 秒待った)。
  **同じ番号を 2 つ目の門番が取れないようにした**(Windows の SO_REUSEADDR で、止め損ねた古い門番が答え続けた)。
- 起動の手順: 頼み先それぞれが答えるかを見る。読み直しは門番に移した(15 分まで待つ)。**本物の python を探す**
  (この PC の C:\Python315 は DLL が欠けて動かず、「python」は Microsoft Store の案内だった。py → python → uv python find の順。GUIDE_LLM_PYTHON で指定もできる)。
- Website: 管理画面「案内チャット」に「模型(AI)」(門番の一覧から。変えたら読み込みを頼む)、「模型ごと」の 👍/👎・平均の時間。
  質問と答えの記録に答えた模型(km_guide_chat_log.model。本番の表には ALTER で足す)。「届くか確かめる」に答えた模型を出す。
- guide-llm.env: 既定を Unsloth にした(UNSLOTH_API_KEY は利用者が Unsloth の画面で作って入れる)。前の Bionic の値は BIONIC_API_URL へ。
- 確かめ: 作り物の Unsloth と Bionic で門番を通しで(起動の読み直し・一覧・既定の会話と道具を止める項目・Bionic へ切り替え・一覧に無い名前・空の答えの頼み直し)。
  check.php 2413 件。管理画面の模型の欄はまだ見ていない。
- **本物の Unsloth で確かめた(同じ日、鍵が入ったあと)**: 利用者「作り物ではなく直接アクセスして検証」「模型は 1 つだけ。2 つ以上で PC が固まりかねない」。
  - 一覧は模型ごとに loaded・quant(量子化)・display_name を持つ。文字起こしの模型(task 付き。qwen3-asr)も混ざる → 一覧から外した。
  - /v1/load には alongside(今の模型を残して横に読む。既定 false)と gguf_variant がある → **alongside: false と一覧の quant を必ず付ける**。
    外しきれていなければ読まない(503)も足した。multi_model_mode は off。
  - 入れ替えの途中で Unsloth が接続を切ることがあった(ConnectionResetError。読み込み自体は終わっていた)→ 一覧で読み込まれるまで待つ。
  - 門番越しに gemma-4-12B-it-qat → granite-4.1-8b → gemma と入れ替え、**どの時点も Unsloth に載っている模型は 1 つ**(/api/inference/loaded-models を見張った)。
    読み込みは 5〜8 秒、入れ替えの間は 503、答えた模型が返事に入る。enable_thinking false で考えずに答える(true は reasoning_content に出る)。
  - start-guide-llm.ps1 -Bench(gemma-4-12B-it-qat UD-Q4_K_XL): **20/20 が JSON・中央値 0.34 秒・最大 0.58 秒**。
    bench.py は答えた模型を結果に残すようにした。guide-llm.env の GUIDE_LLM_MODEL をこの模型にした(何も読んでいないと一覧の先頭を読むため)。
  - 利用者の -Bench で止まった件: **C:\Python315 は Lib(標準ライブラリ)が無いのに、警告を出して終了コード 0 で動いてしまう**(py -3 がこれを選んだ)。
    python を探すとき、標準ライブラリのファイル(http/server.py ほか)が在るかまで確かめる → uv の Python 3.13 を選ぶ。check.php 2415 件。

### 2026-10-25 — 案内チャット・段 C(2026-10-25、利用者の企画書「ito4.jp 模擬店案内・会場内ルート案内システム構築計画」)

- 決まり(利用者): LLM は Gemma4 E4B / 12B QAT / Qwen3.5-9B / GPT-OSS-20B だけ。**Unsloth と ngrok は必須**。VPS 側は企画書の FastAPI ではなく**今の PHP に足す**。
- **自宅 PC**(`server/guide-llm/`、Python): `server.py` が Unsloth で模型を 1 つ読み(4 つの名前だけ通す)、`/v1/chat/completions` の形で答える。
  127.0.0.1 だけで待つ・Bearer の鍵を定数時間で比べる・1 件ずつ・出す字数の上限・**本文をログに書かない**。
  `start-guide-llm.ps1` が WSL で起こし(鍵は WSLENV で渡す)、ngrok を固定のドメインで出す。`-Setup` で入れる、`-Bench` で 20 件の速さを測る(`bench.py`)。
  設定は `guide-llm.env`(見本 `.example`。git に入れない)。
- **VPS**(`lib/guide-chat.php`・`api/guide-chat.php`): LLM に 2 回尋ねる。1 回目は質問を JSON に分け、2 回目は**プログラムが探した記録だけ**を渡して答えの文を作らせる。
  探すのは公開の地図データ(`km_map_data`。教員室・氏名・広告は入らない)。**カードは登録から作る**(LLM の文からは作らない)。LLM に id は渡さない。
  順位は一致の度合い → ♡ の数だけ(価格・待ち時間の欄は無いので使わない。企画書 §4.1)。経路は端の地点を決めるだけで、引くのはブラウザの dijkstra.js。
  LLM が止まっていても、言葉で探した結果を返す(企画書 §8)。
- **使える人はテストユーザー 1 人だけ**(企画書 §8): 管理画面「案内チャット」(既定 OFF)で ON にし、テスト用アカウントを 1 つ選ぶ。そのアカウントで開いたときだけ
  「💬 案内」と Main/guide-chat.js を出す。口は CSRF → 使える人 → 本文 2KB・質問 200 字 → 1 分 6 回(セッション)・1 日の上限(既定 100)の順。
  nginx は `km_guide`(IP ごと 10 回/分)・本文 4k・90 秒待つ。LLM は https だけ・転送しない・TLS を確かめる・返事 64KB まで・1 回目 20 秒・2 回目 30 秒。
- 秘密: `.env` の `KM_GUIDE_LLM_URL` / `KM_GUIDE_LLM_KEY`(/ `KM_GUIDE_LLM_MODEL`)→ compose.yaml の web。空なら言葉の検索だけ。
- 数えるのは日ごとの回数(聞いた・LLM なしで答えた・LLM に届かなかった)だけ。**質問と答えの本文はどこにも残さない**。管理画面に 7 日ぶんと「届くか確かめる」。
- **2026-10-25 に自宅 PC 側を作り直した**(利用者の指示「WSL は使わない」「Unsloth に API リクエストする」。模型は unsloth/Qwen3.5-9B-GGUF):
  `server.py` は模型を読まない**門番**になった(Python の標準ライブラリだけ・Windows でそのまま)。利用者が動かしている Unsloth の API
  (`UNSLOTH_API_URL`。この PC の中だけ)へ渡す前に、鍵(401)・本文 32KB・メッセージ 20 件・出す字数 512・模型の名前(4 つだけ)を門番が決め、
  余計な項目は渡さない。<think> と GPT-OSS の考えの部分は落とす。本文はログに書かない。`start-guide-llm.ps1` は Unsloth の API が答えるかを見てから門番と ngrok を起こす。
  requirements.txt は要らなくなったので消した。
  **guide-llm.env.example に利用者が入れた鍵・ドメインを guide-llm.env(git に入れない)へ移し、見本は空に戻した**(見本は公開の控えに入る。送る前に気づいたので、鍵は出ていない)。
  手元で、作り物の Unsloth の API と門番を立てて確かめた(鍵なし・違う鍵 401、形の誤り 400、大きすぎる本文は断る、Unsloth が止まっていれば 502、考えの部分を落とす、字数 512 まで)。
- **同じ 2026-10-25 に、頼む先を Unsloth から Bionic に替えた**(利用者「Unsloth にバグが報告されていて使えないので Bionic に代替」。
  模型は Qwen3.5-9B、Bionic の識別名は 3885219b6810b007914f3a7950a8d1b469d598a5)。**Unsloth は必須でなくなった**(ngrok は必須のまま)。
  設定の名前を `UNSLOTH_API_URL` / `UNSLOTH_API_KEY` から `LLM_API_URL` / `LLM_API_KEY` に替えた(guide-llm.env の値はそのまま移した。古い名前のままなら起動で止める)。
  識別名からは 4 つのどれか分からないので、`GUIDE_LLM_FAMILY`(gemma4-e4b / gemma-12b-qat / qwen3.5-9b / gpt-oss-20b)で言い切る。名前からも分かるときに食い違えば起こさない。
  門番は起こすときに、識別名が Bionic の `/v1/models` にあるかを確かめる(無ければ止まる。一覧の口が無い API なら確かめずに進む)。
  `chat_template_kwargs`(Qwen の考えを止める)を断る API には、1 度だけそれを外して頼み直す。
  手元で作り物の Bionic の API を立てて確かめた(識別名 + family で起きる、family なし・4 つ以外・食い違い・読み込まれていない・Bionic が止まっているときは起きない、
  断る API には外して頼み直す、一覧の口が無くても起きる、鍵なし 401)。
- **本物の Bionic でベンチ(2026-10-25)**: 初めは 20 件中 19 件が空の返事だった。Bionic は考えを `reasoning_content` に分けて出し、
  `chat_template_kwargs` の enable_thinking を聞かないので、Qwen3.5-9B が 160 字をすべて考えに使っていた。
  書き方を 8 通り試し、効いたのは **`reasoning_effort: "none"` だけ**(考え 0 字・2.4 秒)。門番が模型ごとに付ける(Qwen は none、GPT-OSS は low)。
  本文が空の返事は 502 にした(PHP は言葉で探した結果を出す)。直したあと: **20 件すべて JSON・中央値 2.58 秒・最長 2.77 秒**。
  模型は空を文字の "null" で返すことがあるので、`km_guide_parse_classification` はそれを空として読む。
- 確かめ: check.php 2358 件(LLM を差し替えて、使える人・回数・探し方・作らないこと・止まっているときを試した)。
  画面は poi-harness で口を差し替えて、質問 → 答え → カード → 「目的地にして出発地を選ぶ」で経路の欄が開くところまで見た。
  自宅 PC の Bionic へは門番越しにベンチまで通った。**2026-10-25、利用者が配備して ngrok 越しに VPS の PHP からつながった**(利用者「つながりました」)。
  残り: テストユーザー 1 で答えとカードが出るか、ほかの人は 403 か、自宅 PC を止めて言葉の結果だけ出るか、無い店(メロンパン)でカードが出ないか。
- **2026-10-25 の直し(利用者の画面とお願い)**:
  - 「第二実習室から体育館への行き方」が**トレーニングルーム**へ行った: 「体育館」は多くの地点に途中で当たって同じ点数になり、名前の字の順で
    カタカナの「ト」が先だった。**同じ点数なら名前の短い方**を先にした(場所を探すときは ♡ も見ない)。経路の答えには出発地・目的地の役目を付けて渡す。
  - 「レッツプレイ電算 → Let's play densan!」のような書き方の違い: 言葉で当たらなかったときだけ、LLM に**言い換え**を出させていつもの検索で探し、
    それでも無ければ**名前の一覧から番号で選ばせる**(id は渡さない・番号は範囲を確かめる)。本物の Bionic で、レッツプレイ電算 → Let's play Densan!、
    たいいくかん → 第一体育館、第2実習室 → 第二実習室 が当たり、メロンパンは何も選ばなかった。全部で 55 秒まで・言い換えは 25 秒を過ぎたら出さない。
    名前の一覧で門番の本文が大きくなるので、門番の上限を 64KB にした(**自宅 PC の門番を起こし直す**)。
  - **質問と答えを残す**(利用者「機能改善のために保存」。消さずに残す): km_guide_chat_log。**誰が聞いたかは残さない**。メールアドレス・電話番号らしい文字は伏せる。
    管理画面「案内チャット」に一覧(👎 だけに絞れる)と、「消す」と打ってからのまとめて消す。
  - **評価**(利用者「BAD だったら理由を聞く」): 答えごとに 👍 / 👎。👎 は理由(7 つ)とひとこと(200 字・伏せる)。答えごとの合言葉(ハッシュだけ残す)で、ほかの答えには付けられない。
  - **「AIは間違えることがあります」**と、保存することを入力欄の下にいつも出す。**チャットは開いたまま**(引き出しの土台。場所の詳細を閉じる・「←」でチャットに戻る。×は出さない)。
    テストユーザーはスマホで見ない方針(利用者)なので、スマホで開いたままのシートが操作の帯を隠すのはそのまま。
  - 確かめ: check.php 2380 件。本物の Bionic で通しの答え(上の例)。試し場で、開いたまま・注意書き・👍・👎(理由とひとこと)・詳細から戻るを見た。
  - **テスト用アカウントの出発地**(利用者「テストユーザーのみ、出発点を Let's Play Densan に」): 経路の出発地が空なら、第二実習室 管-105
    (Let's play Densan! の地点。既定は地点 id で持つ)を入れる。地図の経路(目的地を選ぶとすぐ経路)と案内チャット(出発地を言わなかったとき)。
    管理画面「テスト用アカウント」→「画面に出すもの」で変えられる・空にすると入れない。一般の人には印(meta km-test-start)を出さない。
  - **「レッツプレイ電算はどこ？」が本番で見つからなかった**: 質問を分ける段で、LLM が探す言葉を「電算」だけにして「レッツプレイ」を捨てていた
    (本物の Bionic で 3 回とも)。模型の書き方の直しの弱さではなかった。名前は区切らず書かれたとおり 1 つにするよう頼み方を直し、例を 1 つ足した
    (別の名前で)。直したあと 5 回とも当たり、「れっつぷれいでんさん」も当たった。ほかの質問(体育館・たこ焼き・メロンパン・食べ物)も変わらない。
  - check.php 2388 件。
  - **模型を識別子だけで替える**(利用者「GUIDE_LLM_FAMILY を custom にして、API 識別子のみ変更でロード」「既にロードされていたらアンロードからロード」):
    GUIDE_LLM_FAMILY=custom で 4 つの決まりを外す(利用者の決定)。start-guide-llm.ps1 は Bionic のモデル管理の口(LM Studio と同じ
    /api/v1/models・/unload・/load)で、GUIDE_LLM_MODEL の模型が**ロード済みならアンロードしてからロード**する。識別子が無ければ一覧を出して止まる。
    考えを止める指定は、空なら模型の性質から決める(「off」が選べる Qwen・Gemma は none、選べない GPT-OSS などは low)。考える模型には、
    門番が考える分を 768 字上乗せする(GPT-OSS 20B は上乗せ無しで 160 字のうち 153 字を考えに使い、本文が空だった)。
    読む長さは GUIDE_LLM_CONTEXT_LENGTH(空なら Bionic の既定)。ベンチは失敗の返事でも止めずに NG と数える。
    本物の Bionic で: 2 回続けて起こし、1 回目はロード、2 回目はアンロード → ロードを確かめた。
    **GPT-OSS 20B のベンチ: 20 件中 15 件が JSON・中央値 3.96 秒**(読めなかった 5 件は `"to":null"` のように終わりが崩れた)。
    Qwen3.5-9B は 20 件すべて・2.58 秒だった。
  - **考えるの ON/OFF を Website で**(利用者「Website 側で、考えるの ON-OFF はできませんか」): 管理画面「案内チャット」に「考える」
    (模型に任せる / OFF / ON 少し・ふつう・しっかり。既定は任せる)。PHP が頼みのたびに reasoning_effort で伝え、門番が受ける
    (止められない模型に OFF が来たら low に置き換える。start-guide-llm.ps1 が Bionic の一覧から GUIDE_LLM_REASONING_OFF_OK を入れる)。
    ON の間は口が 1 回 40 秒まで待ち、言い換えは 15 秒を過ぎたら出さない(全部で 55 秒は同じ)。
    本物の Bionic(Gemma 4 12B QAT)で門番越しに: OFF 2.5 秒・考え 0 字 / 少し 6.5 秒・197 字 / しっかり 6.4 秒・191 字、どれも JSON が残った。
    Gemma 4 12B QAT のベンチ: 20 件中 19 件(1 件は Bionic の 502)・中央値 2.67 秒。check.php 2392 件。

### 2026-10-25 — 案内チャット: 関係の率で選ぶ・例(2026-10-25)

利用者が案内の記録 45 件(LLM.csv)を見て「うまく動作しているとは言えない。
ユーザーの問いかけに対して関係のあるものを率として考えてみては？」。
- 記録で外れていたもの: 意味で当たらない(怖いと感じる店・音楽を聞ける・WC)、ひらがな(けんけつ)、1 字の言葉がエレベーターに当たる(え)、
  見つからないときの「同じ種類の代わり」が献血・バス・ゴミ箱を出す、駐車場やエレベーターが 3 枚重なる。
- 直し: 経路でない質問は、店と場所の一覧(名前・見出し・種類・階・説明の頭。ゴミ箱は入れない)を LLM に渡して**関係の率(0〜100)**を付けさせ、
  60% 以上を率の高い順に最大 5 件(同じ名前は 1 つ)。返させるのは番号と名前と率だけで、**名前が番号の記録と合わなければ付け替えか捨てる**
  (番号のずれで「プロコン」に関係の無い店が 100% になった)。一覧に無い固有の名前に、似ているだけのものを高くしないよう頼む。
  途中で切れた返事も書き終わった行は使う。率が付けられなければ今までの言葉の一致に戻す。カードに「関係 85%」、答えの LLM にも率を渡す。
- 本番の公開の地図データ(api/map-data.php。一覧 300 件)と本物の Bionic(Gemma 4 12B QAT)で記録の質問を流した: 怖いと感じる店 → 怨魔怪屋敷2・VRお化け屋敷、
  けんけつ → 献血、絵を飾っている → アトリエすずらん(美術部)、音楽 → Audio cafe、WC → トイレ、ジャンプスケア → お化け屋敷 2 つ、
  企画番号 8 → Nagia Drink、ロボコン・プロコン・いちのせき → 何も出さない。1 問 2〜8 秒(WC は候補が多く 15 秒)。
- **例**(利用者「例を押せば自動入力して送信」「企画番号の例」「苦手な文も例に」): チャットの最初に押せる例(企画番号 8 を先頭に「いちばん確実」)と、
  たたんだ「苦手な質問」(おすすめ・空いている店・値段・登録と違う呼び方・近くの○○・あいさつ。理由つき)。試し場で、押すと送ることを見た。
- check.php 2404 件。
- **チャットの経路は AI の出発地で**(利用者「～から体育館への行き方などの時に、出発点を Let's play densan から外して、AI の経路を出すように」):
  「地図に経路を出す」は目的地を先に入れるので、そこでテスト用の出発地(Densan)が入り、先に Densan からの経路を引いていた。
  チャットの経路では入れない(setAsLocation の opts.noTestStart)。出発地を言わなかったときはサーバーが Densan を route.from に入れて返し、
  言ったのに見つからなかったときは黙って Densan にせず欄で選んでもらう。カードの「経路」や地図で目的地を選んだときは今までどおり Densan。
  試し場で、AI の出発地(第二体育館 出入口)のまま経路が引かれ、カードの経路は Densan からになるのを見た。
- **現在地 = Let's play Densan**(利用者「現在地はどこですか？や、現在地から～～への行き方。などは lets play densan に」):
  現在地はテスト用アカウントの出発地(km_test_start_node。既定は Densan の地点)。「現在地はどこ？」「ここはどこ」「今いる場所を教えて」は
  **LLM に聞かずに**登録から答える(自宅 PC が止まっていても答えられる)。「現在地から」「ここから」「今いる場所から」の経路は Densan から
  (その言葉を地図の名前として探さない)。「ここのトイレはどこ？」のように別のものを聞く文は現在地の質問にしない。
  あわせて、経路のカードと目的地で、名前が番号だけの一時地点(「99」)は見出し(「献血」)にした。
  本物の Bionic で: 現在地はどこ → Densan(0 秒)、現在地から体育館 / ここから献血 → Densan から、第二体育館から図書館 → 第二体育館から、
  ここのトイレはどこ → トイレ。check.php 2409 件。

### 2026-10-25 — ログインが切れる(2026-10-25)

利用者「posters.php へ行って、ito4.jp へ戻ると、ログインが切れているときがあります」。
- 原因: web コンテナは php.ini を読まないので、セッションの寿命が組み込み既定の **24 分**だった。最後の操作から 24 分たったセッションは、
  約 100 回に 1 回のリクエストのついでに走る掃除で消える。ログイン中かどうかはセッションの ID トークンがあるかだけで決まるので、
  Logto のサインインは有効なまま、ここだけ先に切れた(地図を開いたまま 24 分以上たってから posters.php を開くと、そこで消えることがある)。
- 直し: **12 時間**にした(docker/php/99-limits.ini の session.gc_maxlifetime と lib/session.php の KM_SESSION_LIFETIME_SECONDS。
  掃除は /tmp のセッション全部を見るので同じ値にする)。セッションを始めるのは全部 km_session_start を通る(grep で確かめた)。
  長くなる分、地図の錠を解いた印も使わないまま最大 12 時間残る(以前は 24 分)。
- 確かめ: 手元の PHP で、30 分前のセッションを置いて掃除を必ず走らせると、直す前は消え、直した後は残った。check.php 2393 件。
- 別の原因として、**web コンテナを作り直す配備では全員のセッションが消える**(セッションは /tmp の tmpfs。compose.yaml の注記どおり)。これは変えていない。
- **利用者の補足「ブラウザの戻るボタンを押したらログインが切れる(ログインボタンを押すとすぐ入れる)」**: セッションは生きていて、
  戻るボタンでブラウザが**メモリから戻した画面**(bfcache。Safari や最近の Chrome は no-store でも戻す)がサインイン前に描いたものだった。
  本番の地図・posters.php はどちらも Cache-Control: no-store(確かめた)なので、HTTP のキャッシュではない。
  直し: index.php に描いたときの状態の印(meta km-logged-in)、Main/back-refresh.js が**メモリから戻ったときだけ**(pageshow の persisted)
  新しい口 api/session-state.php(Logto に問い合わせない・Cookie の無い人にセッションを作らない・真偽だけ)に聞き、違えば読み直す。
  普通に開いたときは聞かない。確かめ: 口を手元の PHP で 3 通り(Cookie なし・トークンあり・なし)。ブラウザで、印 0 の画面に
  「メモリから戻った」を送ると読み直し、印が合っていれば何もしないのを見た(この内蔵ブラウザは戻るボタンでメモリから戻さなかったので、知らせは作って送った)。
  check.php 2395 件。
- **地図の 6 つの直し・段 B アプリ(版 27。2026-10-25)**:
- **札は 7 字以上なら 6 字 + …**(NodeAppearance.kt の `shortLabel`。コードポイントで数える。16 字で切っていたのをやめた。薄く出す建物の名前も)。
- **イベントの地点は太い赤い縁・関係ない地点は 55% 透かす**(MapEvent.kt の `eventNodeUuids` = 臨時の名前・企画の番号・一時地点。
  GeneralMapNodeRenderer.kt が同じ形を白・赤の 2 枚敷いて縁にする。札の縁も赤 3.5)。線は前から地点と札の下に描いているので、線の透かしは要らない。
- **経路が使う階**: 上の真ん中に「使う階 1F → 外 → 3F」(押すとその階の経路の始まりへ。いまの階は赤)。右の階のボタンも経路が通る階は縁を赤に。
  並びは RouteInstructions.kt の `routeFloorSequence`(Website の route-view.js と同じ決まり)。
- **共有のリンクをアプリで開く(一般向けだけ)**: src/visitor/AndroidManifest.xml に https://ito4.jp/(トップだけ)の VIEW と autoVerify。
  SharedMapLink.kt が room / poi / from・to / fromPoi・toPoi を index.php と同じ形の決まりで読み、MapScreen が地図の中で探して開く(無ければ知らせる)。
  開いたときは**前回の経路を聞かない**(WifiViewModel.openSharedLink)。アプリには広告とチュートリアルが無いので、そのまま。
  Website は nginx で `/.well-known/assetlinks.json`(一般向けのパッケージ・リリースの鍵の SHA-256 D7:1F:…:93:AF)を返す。**配備するまで確かめは通らない**(選ぶ画面が出るだけ)。
- **チュートリアル・お知らせの ON/OFF はアプリには無い**(どちらもアプリに無い機能)。
- 単体試験 890 件(両フレーバー。足したもの: 6 字・共有のリンクの読み取りと地図での探し方・イベントの地点・経路の階)。
  前の版を Old\release-v26-20261025 へ写し、版 27 を両フレーバーで作った(内部の番号 1000027・署名 d71fbf0a…・一般向けだけ ito4.jp のリンクを受ける)。apk-push.ps1 で送った。
- 実機では見ていない(共有のリンクから開くのは、配備して assetlinks.json が出てから)。

### 2026-10-24 — お知らせを管理画面で書く(2026-10-24、利用者の判断「Bludit/Grav ではなく、今の管理画面に編集画面(詳細ページも)を足す。プレビューがあると尚良し」)

- 中身を `lib/notices.php` の決め打ちから**表 `km_notices`** へ移した(CREATE だけ。表を作ったときだけ PHP の脆弱性の報告を 1 件目として入れる。公開・最初の画面は 11/30 まで)。
- 管理画面「お知らせ」(`admin/notices.php`): 題・短い文・本文(`## 見出し` と空行で段落。HTML は書かせない)・日付・最初の画面に出すか・出す最後の日・公開するか。
  **プレビュー**は保存せずに、最初の画面のお知らせとくわしいページを、公開ページと同じ組み立て(`km_notice_detail_html`)で下に出す。一覧に「下書き / 公開待ち / 公開中 / 最初の画面に出している」。
- 公開側: `notice.php?id=<番号>`(公開しているものだけ・DB が止まっても出す)・`notice.php`(一覧)。index.php は公開していて最初の画面に出すものだけを「はじめに」に出す(読めなければ出さない)。
- **配備のあと、管理画面「お知らせ」を一度開くこと**(表ができて 1 件目が入る。それまでは最初の画面に出ない)。
- check.php 2225 件。管理画面はこの端末から開けないので見ていない(組み立ての関数とプレビューの中身は check.php で試した)。
- **スマホ: 経路の欄を上いっぱいに・種類のチップを 1 列に(2026-10-24、利用者の指摘「経路を調べるを開いた時、キーボードの影響で、検索バーが隠れてしまいます。
また、前面と背面の調整がうまくいっていない」「トイレや教室などは、１列にし横スクロールが出来るようにしてください」)**:
- **経路の欄(`#search-panel`)はスマホでも上いっぱい**(検索の窓とチップの場所に入れ替わる。欄が開いている間は窓とチップを出さない)。
  下から出していたときは、出発地を押すと出るキーボードの裏に入力欄が入り、ブラウザがページごと上へ送って上の検索の窓が画面の外へ出ていた。
- 欄を短くするため、**階を移るときの好みと C・D は「経路の設定」に畳んだ**(`<details>`)。畳んでいても効いている設定は見出しの横に出す(`updateRouteOptionsState`)。
  実測: 幅 412・高さ 480(= 高さ 860 の端末でキーボードが出たときの見える範囲)で、欄は 12〜330px に収まり、候補も操作の帯の上で切れる。
- **重ね順を決めた**(決めていなかったので、あとに書いた左下の操作(900)と階の列(指定なし)が欄の上に出ていた)。
  経路の欄 1150・左の引き出し 1200・検索の窓 1300、階の列は 900 にそろえた。実測で欄が操作の帯と階の列の手前に来ることを確かめた。
- ページごと送られたままにしない: `html:has(#ui-layer)` にも `overflow: hidden`、高さは `100dvh`、入力欄から離れたときとキーボードが下りたときに送りを戻す(`resetPageScroll`)。
- **種類のチップはスマホで 1 列・横に送る**(前日に折り返す形にしたが、2 行で地図の上を広く覆っていた)。帯は画面の端から端まで取り、内側に余白を置く。
  下の部品の位置(`--km-top-offset`)は 2 行ぶん(78px)から 1 行ぶん(44px)へ。
- tutorial: 経路の欄の中を指すときは**下**に置く(欄が上いっぱいになり、上には置けないため。前は上に寄せていた)。
- check.php 2245 件。poi-harness(いただいた書き出し)を PC(1280×800)とスマホ(412×860・412×480)で見た。

### 2026-10-24 — 地図の 6 つの直し・段 A Web(2026-10-24、利用者の指示。計画は「共有・6 文字・透かし・赤い縁・ON/OFF・階の表示」と LLM の案内)

1. **共有から開いたとき**: index.php が room / poi / from・to のどれかで `<meta name="km-from-share">` を出す。
   tutorial.js・welcome.js・user-history.js(前回の経路。同期の答えもここでは聞かない)はこの 1 つを見て出さない。
   **広告は出す**: tutorial-end.js に `window.kmShowAd()`(セッションで 1 回・選び方と数え方は同じ)。app.js が開いた所を出してから 1.5 秒後に呼ぶ。
2. **札はすべて 7 字以上なら 6 字 + …**(`shortPoiName` を地点の札にも)。詳細・検索・案内の帯は縮めない。
3. **経路の線が地点や札に重なる所は 70% 透かす**: 新しい `Main/route-view.js`(純粋関数。`splitRouteByObstacles`)で、
   画面の座標で、出している印と札の箱に重なる区間だけ不透明度 0.3。動き終えるたびに塗り直す。
   **イベントで関係ない地点**(臨時の名前・企画の番号・ポスターが無い地点)は 55% 透かす(`.km-node-unrelated`)。
4. **イベントの地点に太い赤い縁**(`.km-node-event`: 図形に 3.5px の赤・札に 3px の赤。一時地点も)。見分けは app.js の `eventNodeRole`。
5. **チュートリアルとお知らせの ON/OFF**: `lib/map-switches.php`(km_settings の `map_tutorial_enabled` / `map_notices_enabled`。既定 ON・読めなければ ON)。
   管理画面「お知らせ」の「地図を開いたときに出すもの」で切り替え(記録 `map.switches`)。
   チュートリアル OFF は tutorial.js を読まず、広告は「はじめに」を閉じたときに出す。お知らせ OFF は最初の画面・引き出し・notice.php(404)に出さない。
6. **経路が使う階をいつも見せる**: 案内の帯の上に「使う階 1F → 外 → 1F → 3F」(押すとその階へ・いまの階を塗る)。右の階の列にも赤い輪、畳んだ取っ手に赤い点。
   ついでに、スマホで案内の帯が下の操作(2 行に折り返す)に重なっていたのを、操作の帯の実際の高さの上に置くよう直した(`--km-action-bar-height`)。
- check.php 2303 件・JS の試験 `route-view.test.js` 18 件。poi-harness(試しのページは消えていたので、残っていた組み立て済みのページから地図データを取り出して作り直した)で、
  共有のリンク(経路)・広告・透かし・赤い縁・階の列を PC とスマホの幅で見た。チュートリアル OFF の広告も見た。右の階の列の印は、試しのページでは階のボタンを PHP が作るので見ていない。

### 2026-10-24 — 一般向けアプリでイベントが丸ごと効かない(2026-10-24、利用者の指摘「Android 側の一般向けで、イベント時にイベントの名前で表示されていない」)

- 原因は**アプリではなく配信**だった。一般向けビルドは**配信に載っているイベントしか効かせない**(`resolveEventSelection`。来場者に切らせないため。
  管理ビルドは自分で選べるので、同じ地図でも効いて見える)。配信は**焼き付けた写し**で、`km_map_event_overlay()`(= いま有効なものだけ)で焼いていたため、
  **会期の前に配信した地図にはイベントが 1 件も入らない** —— 当日になっても配信し直すまで、臨時名称も臨時の地点も通行止めも出なかった。
- 直し: `km_map_event_overlay_for_app()` を足し、配信は「**次に効く会期**」を焼く。いま有効なものがあればそれ、無ければ**次に始まる会期**を、
  その開始時刻(`startAtMillis`)つきで焼く。アプリは `activeMapEvent()` と `nextEventBoundaryMillis()` で**その時刻から自分で効かせる**(配信し直し不要)。
  **会期をまたいで混ぜない**(工事(長期)と高専祭(翌週)を 1 つに畳むと、高専祭の通行止めが今日から効く)。
- 配信の 2 か所(`km_app_map_publish` / `km_app_map_publish_event` の折り込み)で使う。公開ページは今までどおり「いま有効」だけ(`km_map_event_overlay`)。
- 気づける場所: 管理画面「アプリへ地図を配信する」は**実際に焼かれる会期**を出す(会期前なら印と開始時刻)。
  「ダウンロード」は、Website にイベントがあるのに配信に載っていなければ「配信し直してください」と赤く出す。
- **アプリ側は直していない(APK は作り直さなくてよい)。** 配信し直せば、いま配っている版でそのまま効く。
- 追って、利用者から「臨時の名前にまだなっていない」。**配った地図が持っている中身の件数**を管理画面「ダウンロード」に出した
  (臨時の名前・臨時の地点・通行止め・出さない地点)。「イベントは載っているのに名前が置き換わらない」とき、
  載っているのが**空のイベント**なのか別の原因なのかが画面から分からなかった。臨時の名前が 0 件なら「配信し直してください」と赤く出す。
  アプリ側の経路(`nodesForDisplay` の `nameOverrides`)と、Website → アプリの変換(別名 → `nameOverrides`。`km_app_map_node_id` で
  32 字の id ↔ uuid を突き合わせる)は読み直して問題なし。公開ページには別名が出ているので、別名の表そのものも正しい。
- check.php 2278 件。DB の要る所はコードの形で見た(`km_map_event_overlay_for_app` の SQL は NOW() を使うので SQLite では試せない)。

### 2026-10-24 — QR のリンク集(2026-10-24、利用者の指示「https://ito4.jp/qr.php の QR を作った。URL を並べて表示。管理画面で設定」)

- 公開ページ `qr.php`(先頭はいつも地図。DB が止まっても地図へのリンクは出す)・管理画面「QR のリンク集」(`admin/qr-links.php`。足す・直す・止める・消す・上下で並べ替え)・
  中身 `lib/qr-links.php`(表 `km_qr_links`。CREATE だけ)。URL は https の絶対 URL か、サイトの中の道(`/` で始まり `//` ではない)だけ。よそのサイトは新しいタブで、行き先を小さく見せる。
- 確かめ: 公開ページを PHP の組み込みサーバーで開いた(DB なし = 地図だけ出る)。並べた見た目は仮のリンクを入れてスマホの幅で見た。管理画面は開けないので見ていない。check.php 2218 件。

### 2026-10-24 — お知らせ: 最初の画面に PHP の脆弱性の報告(2026-10-24、利用者の指示)

- 仕組み: `lib/notices.php`(お知らせの中身。DB を使わない)・`notice.php`(くわしいページと一覧)。公開の地図の「はじめに」の先頭に、載せる期間(until)の中のお知らせを出す。
  読んだかは手元(localStorage の km-notices-read)。**「はじめに」を読んだことのある人にも、まだ読んでいないお知らせがあれば 1 回だけ出す**(Main/welcome.js)。日付は日本時間で比べる。設定の「はじめに」の欄に「お知らせ」の一覧へのリンク。
- 報告文(id php-rce-2026-10。11/30 まで最初の画面に出す): このページの「PHP の RCE」の記録にある事実だけ —— 公表(10/21・CVE-2026-109618/109619・CVSS 9.2)、
  入口で止めたつなぎ・止めて待った・10/22 に 8.4.27 へ更新、確かめた範囲で跡は見つからなかった、利用者の操作は要らない。
- 確かめ: notice.php を PHP の組み込みサーバーで開いて中身を見た。「はじめに」は、初めて・読んだことがあるが未読のお知らせあり・読んだあと の 3 つを確かめた。check.php 2211 件。

### 2026-10-23 — 一時地点の経路の不具合と、表示調整の既定を Web へ(2026-10-23、利用者の報告。版 23)

- 調べたファイル: `kosenmap_map+event.json`(イベント「高専祭 2026」・一時地点 35・接続 35)。**接続の項目名が a / b になっていた** ——
  版 22 のリリース版で R8 が `MapEventPlaceLink` の項目名を縮めた(proguard-rules.pro に載せ忘れ)。Website の取り込みが接続を読めず、
  一番近い地点(部屋・出入口も含む)を案内先にしたため、1F のゴミ箱が女子トイレ、外のゴミ箱が体育館の出入口へ案内されていた。
- 直したこと: proguard に `MapEventPlaceLink`・`LearningSample`・`LearningSyncState` を足した(**学習の記録も a〜i で送っていて、サーバーが断っていた**)。
  古い名前(a / b / c…)も読む(`@SerializedName(alternate)`。端末に残った同期の状態を失わない)。Website の取り込みも a / b を読む。
  つないでいない一時地点は、道・階段を先に案内先にする。`web-event-<番号>` のイベントは作り直さず、その Website のイベントを更新する。
  いただいたファイルで試すと、35 か所すべてがアプリでつないだ道を案内先にした。
- 一時地点の名前が企画の番号(「1」〜「21」)なら、その番号のポスターとスケジュールを一時地点に付ける。Web の吹き出しは部屋と同じ形(km-detail)にし、
  名前はポスターの見出し(地図の札は 7 文字以上なら 6 文字 + …)・備考はポスターの説明。「予定」の一覧から一時地点へ移れる。
- 表示調整の既定: **それまでアプリだけが読み、Web の地図には効いていなかった**。対応の付く項目(大きさ・名前の出し方・層・道・距離・種類ごとの形/大きさ/文字/出始める倍率/出す種類)を
  Web の既定にした(`km_display_defaults_for_web` → index.php → map-style.js。閲覧者が変えた項目が先・「既定に戻す」も配った既定)。色・色分け・初めの倍率は Web に同じ設定が無い。
- check.php 2173 件。

### 2026-10-23 — 教室の企画の番号の見せ方(2026-10-23、利用者の報告「教室のノードに企画番号を設定したが、うまく表示されない」。版 24)

- 調べたこと: `kosenmap_ffffmap.json` の 33 の教室に番号(21〜57)があり、Website の取り込みも 33 件を正しく読む(番号の流れは壊れていない)。
  足りなかったのは見せ方 —— Web は詳細に「企画 22」を 1 行出すだけで札は部屋の名前のまま、アプリは番号をどこにも出していなかった。
  **アプリの部屋の写真(api/node-extras.php)は地点に直接置いたポスターしか返さず、番号で置いたポスターがアプリに出なかった**。
- 直したこと(Web): イベント中、番号の教室は一時地点と同じ見せ方 —— 札と吹き出しの名前は番号のポスターの見出し(札は 7 文字以上なら縮める)・備考はポスターの説明・
  普段の名前を残す。ポスターが無ければ「22 第二実習室」。検索の候補は「[1F] 見出し(部屋の名前)」。
- 教室に付けたイベント中の名前が番号(2E の「32」など)なら、その番号も付ける(`km_map_projects_with_aliases`。ポスター・スケジュール・アプリの写真)。
- 直したこと(アプリ): イベント中は札の名前の前に番号(`eventProjectNode`)。詳細に「企画: 22」。写真の欄に番号のポスターが出る(サーバー側の直し)。
- check.php 2177 件・単体試験(両フレーバー)。Web の見せ方は app.js の関数を取り出して Node で試した(試しの作りは消えていたので、ブラウザでは見ていない)。

### 2026-10-23 — 見た目の直し 3 つ(2026-10-23、利用者の指摘)

- イベントの案内の帯(.km-event-banner)は出さない(検索の窓・チップと重なっていた)。案内の文・くわしく・記録の設定は「ダウンロード・設定」の中(#km-event-section)。
- 種類のチップ: PC は検索の窓の右隣(右は画面の端まで。見切れていた)。スマホは窓の下で折り返す(2 行ぶん --km-top-offset に足した)。
- 管理画面の地図編集の階のボタン(card-tools … btn-group)が右へはみ出していた。つなげた帯をやめ、折り返す並び(d-flex flex-wrap・mw-100)にした。
- 試しの地図で PC とスマホの幅を見た。管理画面は開けないので見ていない。check.php 2206 件。
- (2026-10-24 追記)種類でまとめた一覧(.km-category-sheet)が引き出しの下まで使わず小さかった: 前の検索の欄の .km-category-list(高さ 30vh)と
  .km-category-item(左右へ振り分け = 名前が右寄せ)が効いていた。引き出しの中では打ち消し、名前は左寄せ(.km-result-text)。

### 2026-10-23 — 公開の地図を Google マップ風に・企画 32 のエラー・出発地の不具合(2026-10-23、利用者の指示。版 26)

- 企画 32 のエラー: 本番(ito4.jp)の公開側は正しかった(2E にイベント中の名前「32」から番号 32・ポスター 34 が付く)。
  **管理画面のポスターとスケジュールが表の番号だけを見ていた**ので「この番号の地点が地図にありません」と出た。`km_map_projects_for_admin`(全部のイベントの名前の番号も入れる)にした。
- 出発地・目的地が反応しない: 一時地点のつなぎ先は道・階段で、`setAsLocation` の `isRoutableNode` が黙って断っていた。経路の端を**地点の id**で持つようにし、
  一時地点はつなぎ先で断らない。片方だけ選んだら経路の欄を開いて、もう片方を尋ねる。
- 出発・到着の印は札より前の層(`kmRoutePins` 680)、経路の線は点より前(`kmRouteLine` 610)。経路の途中で階を移っても拡大率を戻さない(寄せるのは引いた直後の 1 回だけ)。
- 流れを「場所を検索(一致率順。Main/search-rank.js)→ 左の引き出しに詳細(Main/km-sheet.js)→ 経路」に。候補のアイコンはその場所のポスター。
  表示調整・履歴・予定・ポスターの拡大も左の引き出し(スマホは下から)。上のバーを外し、⚙️・🖼️ は左下、ログインはダウンロード・設定の中(展示の PC は右上の小さな欄)。
- 共有: 場所 `/?room=`・一時地点 `/?poi=`・経路 `/?from=&to=`(端が一時地点なら `fromPoi=`/`toPoi=`)。navigator.share が無ければ写す。
- ポスター: 引き出しと一覧のページから、結びつけた地点・一時地点の全部へ移れる。♡(表 `km_poster_likes`・口 `api/poster-like.php`・管理画面で 0 に戻す・プライバシーポリシーに追記。
  端末の印は押すときだけ作り、URL に載せない)。nginx の km_api に poster-like を足した。
- 外の一時地点を部屋に戻した(Web の `poiNodeType`・アプリの `eventPlaceNodeType`)。
- 確かめ: check.php 2206 件・JS の試験(search-rank を足した)・単体試験 882 件(両フレーバー)。いただいた書き出しの試しの地図で、検索の候補・左の引き出し・一時地点からの経路・
  階を移っても拡大率が 1.5 のまま・印の層・共有のリンク(一時地点の番号)・ポスターの引き出し・スマホの下からのシートを見た。♡ の送信は試しの地図に口が無いので、PHP の試し(SQLite)だけ。

### 2026-10-23 — 一時地点を部屋の地点と同じ振る舞いに・外は建物(2026-10-23、利用者の指示「一時ノードは Room ノードのような動作に。外は Facility で」。版 25)

- Web(公開の地図): 一時地点を絵文字の印ではなく、**部屋(外は建物)の地点と同じマーカー**で描く(`createPoiMarker`。buildFloorMarkers で同じ種類の束に入れる)。
  形・色・大きさ・札・表示調整の種類と倍率・外で拡大したときに建物名を薄くする・札の重なりの間引きも部屋/建物と同じ。吹き出しの種類は「部屋」「施設」、模擬店などは「分類」に出す。
  地図編集の画面は今までどおりの印(地点の札と取り違えないため)。
- 番号を付けた部屋の札(ポスターが無いとき「28 ファーストペンギンファブ」)は縮めない(縮めるのはポスターの見出しだけ)。検索の候補の括弧は部屋の名前。
- アプリ: 一時地点の種類を、外は facility・それ以外は room(`eventPlaceNodeType`)。外の一時地点への案内は建物と同じく、つないだ道から(`resolveRouteEndpoint` が直接の線を先に使う)。
  外の一時地点は経路の網に入らないので、「繋がっていない道」の赤には出ない。
- 確かめ: いただいた書き出しで公開の地図の試し(Node の静的配信 + 同梱データ)を作り、外の一時地点(模擬店 1〜20・受付・ゴミ箱など)が建物の地点として描かれ、2F の一時地点が部屋として描かれることを見た。
  check.php 2181 件・単体試験 882 件(両フレーバー)。

### 2026-10-23 — イベントの取り込みで企画の番号も入れる(2026-10-23、利用者の報告「まだ表示されない・取り込むと地点が 35 で明らかに少ない」。サーバーだけ)

- 原因: イベントモードの取り込み(lib/map-event-import.php)は、ファイルからイベントだけを置き場に入れ、**地点に振った企画の番号を捨てていた**。
  番号は「アプリの地図を取り込む」(app-map-sync)でしか入らず、イベントの取り込みだけを使うと km_map_node_projects が空のまま ——
  Web は番号を知らず、「イベントの地点だけ」では教室ごと隠れていた。35 はイベントの一時地点の数。
- 直したこと: 置き場に番号も入れ(`km_map_event_import_projects_of_map`)、取り込みの画面に「企画の番号も入れ替える(番号のある地点 33・番号 36 個)」を既定で印つきで出す。
  取り込むと表を丸ごと入れ替える(地図の取り込みと同じ)。一覧の「地点」は「一時地点」と書き分けた。
- いただいたファイル(15:06 の書き出し)で試すと、番号のある地点 33・番号 36 個・Website に無い地点 0。check.php 2180 件。

### 2026-10-23 — 段 F: 一時地点の編集・繋がっていない道・Web とアプリのイベントの往復(2026-10-23、利用者の指示。版 22)

- 一時地点(イベントの臨時の地点): 管理アプリの「整列」「ノード編集」「移動」「削除」で扱える(イベントを編集中のとき)。
  「結合(道の定義)」で道・階段・出入口へつなげる(`MapEvent.placeLinks`。基本の線には入れない)。「結合解除(道)」で外せる。来場者の経路はこの線も使う(`linesForDisplay`)。
- 繋がっていない道: 管理マップで、経路の本体(いちばん大きい網)から行けない線を赤の破線、地点を赤い輪で描く(`RouteReachability.kt`。経路探索と同じ繋ぎ方)。
  イベントを編集中はその通行止めを効かせて調べる —— 通行止めの向こうが赤くなれば本当に通れない。つないでいない一時地点も赤くなる。
- Web の地図編集: アプリから取り込んだイベントも直せる(直した時刻を表 `km_map_event_web_edits` に残し、取り込み直す前に「Web で直した内容は置き換わる」と知らせる)。
  「表示しない」を足した(`event.hidden.toggle`)。札に「企画 A-1」「イベント中は出さない」を出す。
- Web → アプリ: 配信の折り込みに、臨時の名前・出さない地点・イベントの地点だけ・つなぎ先(→ 一時地点の線)も入れる。
  管理アプリの設定のイベントの「Website から取り込む」(新 `api/map-events.php`・管理者だけ・nginx の km_api に足した)で、Web で直したイベントをアプリへ戻す
  (同じ uuid は入れ替え。スタッフ限定の一時地点と立入禁止の階は手元のものを残す)。アプリ → Web の取り込みは、アプリでつないだ先を案内先にする。
- 来場者向けの説明: `server/guide/visitor-guide.md`(docs には status.md と plan.md 以外の .md を置かない決まりのため)。
- 手元の試しの作りで、地図編集の「表示しない」の入り切り(公開の地図にも反映)と札を確かめた。check.php 2165 件・単体試験(RouteReachabilityTest・MapEventSyncTest)。Pixel は未確認。- **段 E: 企画の番号・スケジュール・イベント中の表示・学習の協力者・バックアップのパスワード・評価の切り替え(2026-10-23、利用者の指示。版 21)**:
- 企画の番号: 管理アプリの地点の編集で「企画の番号」(英数字と - _ の 16 字まで・1 地点に 10 個まで・全角は半角/大文字にそろえる。`ProjectNumbers.kt`)を振る。
  Website は表 `km_map_node_projects`(新・`lib/map-node-projects.php`)。地図の取り込みで丸ごと入れ替わり、配信で戻る。
- スケジュール: 管理画面「スケジュール」(新・`admin/schedule.php`・表 `km_event_schedule`・`lib/event-schedule.php`)。1 行 = 催し(番号・名前・団体・説明・始まり・終わり・一般に出す)。
  場所は番号から引く(番号の地点全部)。公開の地図は「一般に出す」の行だけ・今日より前に終わった行は出さない。地点の詳細に「企画」と「この場所の予定」(開催中に印)、左下に「予定」の一覧(`Main/event-schedule.js`)。
- ポスター: 「ポスター・広告」で企画の番号を入れると、その番号の地点全部に出す(地点の指定より先)。表 `km_poster_projects`。番号の地点が無いものは広告にしない。
- イベント中は「イベントの地点」だけを出す: 企画の番号がある・イベント中の名前を付けた・臨時の地点・トイレ・階段・出入口。ほかの部屋は出さない(経路は通れる・区画の色は残す)。
  イベントごとに切り替え(アプリ: 設定のイベントの「出す地点」・`MapEvent.onlyEventNodes`、Web: 表 `km_map_event_options`・イベントの画面の「期間中はイベントの地点だけを出す」)。**行・項目が無いイベントは ON**(配備したら、工事のように部屋を隠したくないイベントは OFF にする)。
- 学習の協力者: Logto の API 権限 **`learning:wifi:write`**(利用者が作ってロールに付ける)。管理アプリを開け、学習の収集と送信・地点の追加と編集(手元。配信は管理者)。
  教員室・研究室は見える(配信の段 `member`・名前は無し)。学習データを消せるのは自分が送った分だけ(`uploaded_by`)。クラウドバックアップは自分の分だけ見る・戻す・消す(置いた人ごとに 20 件)。管理ビルドでも「マップを取得」が出る(原本を持たないため)。
- バックアップ: クラウドバックアップに Wi-Fi 学習も含められる(戻すときは「ノードと学習」/「ノードだけ」。学習は今の学習データに無い地点だけ足してサーバーへも送る)。
  サーバーとファイルの書き出しの両方にパスワードを付けられる(`BackupCrypto.kt`: PBKDF2-SHA256 21 万回 → AES-GCM。サーバーは包みのまま預かり開けない。表 `km_map_backup_meta` に「学習あり」「🔒」の印)。
- 精度評価: 設定「精度評価を使う」(既定 OFF)。OFF のあいだは道具「精度評価」・「評価」の結果・評価の CSV を出さず、サーバーの評価も取りに行かない。今までの評価は残す。
- 手元の試しの作りで、スケジュールの追加・一般に出す切り替え・地点の詳細の「この場所の予定」・「予定」の一覧・イベント中に企画の無い部屋が隠れること・番号のポスターを確かめた。
  Pixel はつながっていなかったので実機では未確認(単体試験 EventProjectsTest・BackupCryptoTest・LearnerRoleTest)。check.php 2160 件。
- **配備前の控えが落ちた**(2026-10-23): `tar: uploads/map-backups: Cannot open: Permission denied`。PHP が `uploads/map-backups` を 0750(グループ www-data)で作り、kmops が開けなかった。
  host-setup.sh の確認はファイルだけを見ており、開けないディレクトリの中は数えないので「読めないもの 0 件」と言っていた。
  → PHP が uploads の下に作るディレクトリ(map-backups・learning-backups・panoramas)を 0755 に。host-setup.sh が開けないディレクトリも見つけ、`--fix` でグループと g+rx を直す。
  **配備の前に `.\host-setup.ps1 -Fix` を流す**(今あるディレクトリを直してから控えを取る)。
- 手元の PHP に `php.ini` を作った(`C:\Users\itota\Documents\php\php.ini`。curl fileinfo gd intl mbstring openssl pdo_sqlite)。update-containers.ps1 の自己検査が拡張の無さで exit 2 になっていた。

### 2026-10-23 — 段 D: 地図の履歴とブックマーク(2026-10-23、利用者の指示。版 20)

ブックマーク・開いた場所・検索した言葉・経路・表示調整。ふだんは端末の中だけ、サインインしていれば `api/user-map-data.php`(表 `km_user_map_data`・退会で消える)で揃える。混ぜ方は項目ごとに新しい方(Kotlin・JS・PHP で同じ。check.php と UserMapDataTest)。
経路の確かめ: 開いたとき「前回の経路」・サインイン時に経路があれば「アカウントに残すか / この端末だけ」・全部消すとき・ログアウトのとき。Web は `Main/user-history.js`(展示の PC では使わない)。利用規約 第4条・プライバシーポリシーに追記(教員室の決まりがアプリにも効くことも直した)。
Pixel と手元の試しの作りで、☆・履歴の面・3 つの確かめ・前回の経路を確かめた。**同期は配備後に確かめる**。check.php 2137 件

### 2026-10-23 — 段 B・C: 色分けを手で決める・イベントで隠した地点の色分け・表示調整の既定を配る(2026-10-23、利用者の指示。版 19)

- 隠した地点: 区画の種と壁は、イベントで隠した地点も含めて作る(以前は抜いていたので、隠した部屋が名前なしの色になって隠したことが分かり、隠した壁の線まで消えて塗りが漏れた)。
  隠した部屋はふつうの部屋の色で塗るが、押しても開かない(`AreaSeed.tappable`)。
- 手で決める: 管理アプリの道具「区画の色」と地点の編集で、区画の種類(自動・部屋・廊下・階段・建物・道・塗らない)と色(8 色。色覚の型ごとの見え方でも互いに ΔE 5 以上・AreaPaletteTest)を選ぶ。
  地点の無い区画には「区画の印」(種類 `area`。来場者には描かない・検索と経路にも出ない)を置く。漏れ・建物の外には当てない。
  Website は表 `km_map_node_area`(`lib/map-node-area.php`)。区画の印は km_map_nodes に入れず、この表に階と位置ごと持つ。取り込みで表を丸ごと入れ替え、配信で地点に足して戻す。Web の地図には出さない。
  Pixel で、部屋の地点に「淡い紫」を付けて一般の地図に出ることを確かめ、元に戻した。
- 表示調整の既定を配る: 管理アプリの表示調整の「この表示を既定として配る」「配るのをやめる」→ `api/display-defaults.php`(管理者。読みは誰でも)・表 `km_display_defaults`。
  一般のアプリは地図を取得・更新したときに読み、**利用者が変えていない項目だけ**(まだ無い・前に配られた値のまま)に当てはめる。やめたら配られた値のままの項目をアプリの既定へ戻す(`DisplayDefaults.kt`)。
  配るのは表示調整の画面の項目(大きさ・名前の出し方・重ねるもの・色分け・初期の倍率・しきい値・種類ごとの大きさ/形/色/名前/倍率)。管理用(学習済みの見せ方・区画の確かめ)は配らない。nginx の km_api に足した(**nginx の入れ直しが要る**)。check.php 2126 件

### 2026-10-23 — 段 A: 2 重接続・学習のログ・地図編集の札の切り替え・バックアップの画面(2026-10-23、利用者の指示。版 18)

- 道の 2 重接続: 管理アプリの「結合」と「壁を引く」で、向きを問わず同じ 2 点の線があれば断る(`MapEditTools.kt` hasLineBetween)。
  今ある重複線は前からの診断「重複した接続」と修理で 1 本にできる。Web は前から断っていた。
- Wi-Fi 学習のログ(管理ビルド・`LearningLog.kt`): logcat の tag `KosenLearn` と、学習の帯の「ログ」(見る・写す・消す)。
  **Pixel 7a で測った結果: スキャンの結果は約 5.6 秒ごとにしか届かない**(17 回で 5.5〜6.0 秒。アプリは 1 秒ごとに頼むが、スキャン中に重ねて頼んでも 1 回分)。
  平均の回数が 20 回なので、1 地点に約 110 秒かかる。アプリの処理は 1 回 9〜89 ms、登録は 19 ms、サーバーへの送信は 266 ms で、ほとんど待ち時間はスキャンそのもの。
- 管理画面の地図編集: 地点の札の「ID(UUID)」「座標」「先生の名前」をスイッチで切り替える(既定は全部 ON・閲覧者の手元に覚える)。
- **バックアップの画面**(`admin/backups.php`。利用者の指示「サーバーでもバックアップデータを見れるように」):
  地図のバックアップ(管理アプリの「サーバーにバックアップ」)の一覧・中身の内訳・ダウンロード・消す。
  **Wi-Fi の学習データ(と精度の評価)をサーバーから消すときは、消す前の中身を控えに取り、取れなければ消さない**(`lib/learning-backup.php`・表 `km_learning_backups`・uploads/learning-backups/。30 件)。控えは一覧・内訳・ダウンロード・**戻す**。check.php 2116 件

### 2026-10-22 — Android 一般向け: AR 認識と Wi-Fi の短時間認識(2026-10-22、利用者の指示)

版 12(1000012)。受け箱へ送った(公開は利用者)。
- **札・QR で現在地を合わせる**(`RoomScanner.kt`・`RoomRecognition.kt`): 地図の右上「札・QR」。部屋の QR(Website の `?room=`)か札の部屋番号(地点の subtitle。全角・横棒の揺れ・横棒の抜けを吸収、2 回続けて当たったら決める)→ 選んだ地点に粒子を撒き直す(`fixPositionAtNode`。σ 3 m)。
  文字は ML Kit 日本語(**Play サービス版**。バンドル版は APK が 72.9 MB になったのでやめた。モデルは入れたときに取る)。
- **AR 案内**(`ArGuide.kt`・`ArGuideOverlay.kt`): 経路があるとき左上「AR 案内」。カメラの映像の上に、経路に沿って 4 m 先へ向かう矢印(方角 − カメラの方角)・到着までの距離・階を移る案内。AR 測位モードの映像を全画面で使う(面は 1 つだけ)。札・QR を読む間は AR を止める(カメラは 1 つ)。
- **AR 測位を分かりやすく**: 初めて使う前の説明(映像は送らない・電池)、設定「AR と札・QR の使い方」(端末が対応しているかも出す)。
- **Wi-Fi の短時間認識**: 一般向けにも「Wi-Fiスキャン間隔」「短間隔Wi-Fiスキャンを許可」を出し、「Wi-Fi の短時間認識の手順」(Android の制限の状態・ビルド番号 7 回 → 開発者向けオプション → Wi-Fi スキャン スロットリングをオフ・開発者向けオプションを開くボタン)。スイッチを入れても Android の制限が残っていれば手順を出す。
- **管理アプリの Wi-Fi 学習が遅い件**: 学習の帯に「1 回 X 秒・処理 Y ms」(スキャンの結果が届く間隔と、アプリの処理の時間)を出すようにした。原因の見立ては下の報告(端末のスキャンそのものが 1 回 1〜3 秒)。
- 単体試験(新しく 4 本)と既存 824 件・両フレーバーのデバッグ版とリリース版。
- **Pixel 7a(Android 17)で確かめて版 13(1000013)**(同日。受け箱へ送った・公開は利用者。v12 は `Test\Old\release-v12-20261022\`):
  - 確かめたこと: 設定の Wi-Fi の項目と手順の画面・AR の使い方、札・QR(画面の QR → 管-115、札「管-116」だけ → 管-116、1F に合わせて地図を寄せる)、AR の説明 → AR 測位(深度あり)、経路 → AR 案内(カメラの映像・矢印・「9 m 先の階段・エレベーターで 2F へ」・距離)。
  - 直したこと:
    - Android 17 で開発者向けオプションが有効かの判定が外れ、デバイス情報を開いた → 判定をやめ、手順 1・2 の 2 つのボタンにした。
    - 管理アプリの診断の枠が「AR 案内」を覆った → 枠を下げた。
    - 札・QR の「文字を読む準備中」が消えなかった → 読めたら消す・失敗はログへ。
    - **AR 案内のカメラが真っ黒**: 下地の黒が映像(GLSurfaceView は奥の面)を覆っていた → カメラがあるときは塗らない。
      それでも黒かった本当の原因は、小さな枠と全画面で GLSurfaceView が作り直されて **GL の文脈が替わり、ARCore の update() が失敗し続けた**こと(案内を閉じても小さな枠が黒く、AR の測位も止まっていた。AR を OFF → ON で戻った)。
      → 道案内の開け閉めで ARCore を止めて動かし直す(MapScreen の DisposableEffect の鍵に `arGuideOpen`)。update() の失敗はログ(KosenAr)へ 1 回。
    - 「方角を測っています…」が明るい映像の上で読めない → 暗い下地。
  - 気づいたこと(今回は直していない):
    - 気圧の基準がずれると、1F にいても「5F」と見立てて地図の階が勝手に戻った(札・QR で 1F に合わせると直る)。
    - 1号棟 中央階段 → B階段 の経路が 2F を回り、道順に「引き返す」が出る(1F の通路がつながっていない見込み)。

### 2026-10-22 — Android 地図の見やすさ 段 1: ボタンと横画面(2026-10-22、利用者が挙げた課題)

版 14(1000014)。受け箱へ送った(公開は利用者。v13 は `Test\Old\release-v13-20261022\`)。計画は段 1〜4(段 2 色分けと部屋の区画・段 3 部屋の写真といいね・段 4 階段の矢印)。
- 「表示調整」「検索」: 文字の色を指定していなかったため、暗い表示で白い丸に薄い灰色の文字だった → `MapChrome.kt` の `MapPillButton`(昼は白地・濃い文字、夜は濃紺・白い文字、縁・影・アイコン・高さ 48dp)。
- 階のボタン: `FloorRailButton`(白地・濃い文字・2dp の縁。選んだ階は #1D4ED8 と白い内側の輪)。「この階にいる」と建物の階の帯もそろえた。
- 横画面: バーと時計・ナビの帯を隠す(`TopBarController.kt`)。上の端から引き下ろすか、上の真ん中の「︾」を押すとバーが地図の上に重なって出て、4 秒で隠れる。
  `activity_main.xml` を重ねる形(FrameLayout)にし、縦では中身の上をバーの高さだけ空ける。
  横では階のボタンを 44dp・縦に送れるように、地点の詳細は右のパネル(戻るで閉じる)、表示調整は高さの 70% まで、診断の枠はカメラの枠の右隣。
- Pixel 7a で縦・横、明るい表示・暗い表示を撮って確かめた。左の黒い帯はカメラ穴の余白(118px)。

### 2026-10-22 — Android 地図の見やすさ 段 2: 場所ごとの色分けと部屋の区画(2026-10-22)

版 15(1000015)。受け箱へ送った(公開は利用者。v14 は `Test\Old\release-v14-20261022\`)。データ作りは要らない。
- `AreaSegmentation.kt`: 下絵の線で閉じた区画に番号を振る。壁 = 暗い画素 + データの壁の線、1px 太らせて切れ目を塞ぐ。中の地点で種類を決める(部屋 1 つ → 部屋・階段 → 階段・道 → 廊下)。
  画像の端に届く区画は外、広すぎる区画は漏れとして塗らない。地点の無い広い区画(中庭など、1.5% 超)も塗らない。屋内の「○号棟」の札は、部屋の地点がある区画では数えない。
- 色(`AreaPalette`): 部屋 #CDE7F7・廊下 #FBF3C4・階段 #E3B9A6・名前なし #E4E7EB・建物 #C8D2DC・外の道 #F4E1BE。
  1 型・2 型・3 型の見え方(Machado 2009)に直しても、となり合う種類の ΔE76 は 12 以上(AreaPaletteTest)。凡例に名前を添える。
- 点に当たらなければ、押した区画の部屋・建物の詳細を開き、その部屋を空色で重ねる。表示調整に「場所を色分けする」と凡例。
  管理アプリの「区画の確かめ」では、漏れ=赤・名前なし=橙・部屋が 2 つ=紫で目立たせる。
- 作るのは `Dispatchers.Default` で 1 回、3 枚まで覚えておく(`AreaRendering.kt`)。
- Pixel で 1F〜5F・外を確かめた。1F・2F に漏れは無い。紫は、1 つの枠に部屋の地点が 2 つある所(壁の線を足せば分かれる)。
  実際の f1_r でも 60 区画以上に分かれ、漏れが無いことを単体試験で確かめた。
- 横画面で表示調整を開いている間は、診断の枠を出さない(パネルの上に重なっていた)。

### 2026-10-22 — Android 地図の見やすさ 段 3・4: 部屋の写真といいね・階段の矢印(2026-10-22)

版 16(1000016)。受け箱へ送った(公開は利用者。v15 は `Test\Old\release-v15-20261022\`)。**Website の配備が要る**(下)。
- 段 3 写真といいね:
  - 口 `api/node-extras.php`(新)。地図のアクセスコード(`km_learning_code_allowed`)で、その地点のポスター(写真)といいねの数を返し、いいねを付け外しする。
  - `lib/node-extras.php`(新)。写真は Web の公開ページと同じ決まり(`km_posters_public`・教員室/研究室は出さない)。
    いいねは表 `km_node_likes`(端末の印・送り元はどちらも鍵つきの印。1 端末 1 地点 1 つ・送り元ごとに 1 時間 60 まで)。
  - nginx の km_api に足した。管理画面「ポスター・広告」に「部屋のいいね」(数と 0 に戻す)。利用規約 第 7 条・プライバシーポリシーに、いいねを書いた。
  - アプリは `NodeExtras.kt`。部屋の詳細に写真の横並び(押すと大きく見る・リンクは http(s) だけ)と ♥ いいね。サーバーに口が無い間・コードで地図を取っていない端末では欄を出さない。
- 段 4 階段の矢印(`StairGuide.kt`):
  - 行ける階: 地図の階段に「▲上行き 2〜5F」「▼下行き 1F」、エレベーターは「⇅ 1〜5F」を出す。同じ接続 ID の階段から数える。倍率 1.2 以上で出す。
  - 経路で進む向き: 経路が通る階段に「▲ 2F へ上る」「▼ 1F へ下りる」「エレベーターで 3F へ」、着いた階に「3F から到着」。
  - 上り口の向き: 管理アプリの地点の編集(階段)で「上り口の向き」「下り口の向き」を 8 方向から選ぶ。地図に矢印と「上行き」「下行き」を出す。
    Website は表 `km_map_node_stairs`(新・`lib/map-node-stairs.php`。ALTER を要らなくするため km_map_nodes の列にしない)。
    アプリの地図を取り込むと入り、配信で戻る。取り込み画面の「変わる地点」の数には入らない。
  - 色は上 = 朱 #D55E00・下 = 青 #0072B2。▲▼ と文字を必ず添える。
- Pixel で、階段の札(1F・2F)と経路の札(1F「▲ 2F へ上る」・2F「1F から到着」)を確かめた。上り口の矢印は、まだ向きを入れた階段が無いので実機では見ていない(単体試験 StairGuideTest)。check.php 2084 件。
- **配備したら確かめること**: 部屋の詳細に、ポスターを置いた地点の写真が出ること・いいねの付け外し。

### 2026-10-22 — Android: 外 → 建物の中で地図を見失う不具合と、アプリへの配信の厳格化(2026-10-22、利用者の指示。版 17)

- 見失う原因: 外の全体図(1600×1200)・校舎(1600×1200、別の座標)・建物の中(600×600)は座標が別なのに、外で見ていた位置と倍率のまま中を開いていた。
  自動で追う設定では、外の座標の現在地へ寄せ直していた。方角で回すと画像の左上を中心に回っていた。
- 直し方(`MapViewport.kt`): 座標の枠(外・校舎・建物ごと)が変わったら図の全体が入る倍率にし、入口などを真ん中に出す(「外へ戻る」はその建物を真ん中に)。
  寄せるのは同じ枠で図の中にある現在地だけ。回すときは画面の真ん中を中心に。**どう動かしても図の端(画面の短い辺の 1/4)は画面に残す**。
  管理アプリの編集画面も、真ん中が新しい図の外なら全体を出す。Pixel で外 → 第二体育館 → 引っぱり過ぎ → 外へ戻るを確かめた。単体試験 MapViewportTest
- 厳格化: アプリへの配信(api/app-map.php)を Web と同じ決まりにした(lib/node-visibility.php)。中身の段を作り直した:
  public(教員室・研究室と名前とスタッフ限定を落とす)・event(運営。教員室と名前を落とす)・member(組織メンバー。名前は**先生が許可した地点だけ**、段の名前に許可の組の印)・editor(管理者 = アプリで地図を直す人。全部、名前は公開設定の錠に従う)。
  教員室・研究室は地点・線・精度評価・イベントの名前の差し替えごと落とす(Wi-Fi の学習は残す)。写真といいね(api/node-extras.php)も教員室・研究室には出さない。作り置きの形は 2 に上げた。
  アプリ側(`NodeVisibility.kt`)も多層防御で、組織メンバーと管理ビルドの管理者でなければ教員室・研究室と名前を出さない(以前はイベント運営にも名前を出していた)。ログアウトで落とす中身にも教員室を足した。
  版 17 のアプリは以前の段(visitor など)で受け取った地図を取り直す。**版 16 以前のアプリの手元の地図は、次に地図の版を上げたときに置き換わる**(管理画面で配信し直す)。check.php 2108 件

### 2026-10-22 — 教員室・研究室と氏名の表示を厳格に(2026-10-22、利用者の指示「権限チェックを厳格化。例外なし」「先生が申請し管理者が承認。利用規約にも」)

**Web の地図だけ**(アプリは今のまま)。
組織メンバーでなければ教員室・研究室(種類 2 か名前に「教員室」「研究室」)を地点ごと出さない。組織メンバーには出し、担当とそのメモは**先生が申請し管理者が承認した地点だけ**(同じ地点の先生が全員)。
取り下げはすぐ・割り当ての解除・権限の取り消し・退会で消える。テスト用・お試し・パスワード・管理者のサインインも例外なし(地図編集だけ全部)。
`lib/node-visibility.php`(新)・`lib/map-data.php`・`lib/staff-nodes.php`(showNames・表 `km_staff_node_consents`)・account.php・admin/staff-nodes.php・利用規約 第 8 条(以降を送った)・プライバシーポリシー。
氏名の錠(hidden / password / public)とお試しの「教員名を見せる」は Web に効かなくなった(前者はアプリに効く)。氏名のパスワード欄は出さない。
**見つけたこと: 教員室のメモに先生の名前が入っていて、以前は誰にでも出ていた**(いまは許可が無ければ出さない)。試しの作りで一般/組織メンバー/申請中/承認/2 人目/取り下げ/地図編集と、承認ボタン・管理者の印でも出ないことを確かめた。check.php 2062 件。[15](15-staff-org.ipynb) 段 H

### 2026-10-22 — 一覧のページは押したら拡大(2026-10-22、利用者の指示「画像をクリックして飛ばすより、クリックして拡大表示して飛ぶほうがいい」)

`/posters.php` の画像を押すと、地図と同じ見た目の大きく見る幕(`.km-poster-viewer`)を開き、幕の中の「地図で見る」「くわしく見る ↗」から進む(`Main/posters-page.js`。中身はカードから textContent で写す・ボタンは写すので数える印もそのまま)。
画像のリンクには数える印を付けない(押しても幕が開くだけ)。Ctrl / ⌘ / 中ボタンと、JS が動かないときは、これまでどおり画像のリンク(地図 → URL)へ進む。× ・幕の外・Esc で閉じて押した画像へ戻る。
地図の地点の詳細は前から「押すと拡大」だったので変えていない。試しの作りで、拡大・閉じ方・スマホの幅を確かめた。check.php 2047 件
- **テーブル管理の外部接続(同日、利用者の指示「接続するユーザー、パスワードを自動で作成。権限は最小限」)**: 利用者の判断で **HTTPS の API キー**(MariaDB の利用者は作らない。Main に CREATE USER が無く、3306 番も外に開いていない)。
- `admin/tables.php` の「外部接続」で表と名前とできること(読む・足す・直す・消す。**既定は読む + 足す**)を選ぶと、ユーザー名(`kmx_` + 16 桁)とキー(48 桁)を作る。**キーは作った直後の 1 回だけ出す**。DB には SHA-256 だけ(表 `km_table_api_keys`)。取り消しは行を残して `revoked_at`。
- 口は `api/table.php`(Basic 認証か `Bearer <ID>:<キー>`。GET 一覧・1 行 / POST 足す / PATCH 直す / DELETE 消す)。1 つの接続は 1 つの kmt_ 表だけ。表を削除・接続を取り消すとすぐ 401。Cookie を使わない。書き込みは操作の記録(`table.api_write`)。nginx の km_api に足した(**nginx の入れ直しが要る**)。
- check.php 2018 件。手元の試しで、広告の当たり/外れ・閉じたあとの知らせ(PC・スマホの幅)・規約の第 7 条・外部接続の作成(キーは 1 回だけ)・読む/足す(Basic と Bearer)・直す/消す/ほかの表/id の列は断る・取り消しで 401 を確かめた

### 2026-10-22 — 修正版を当てた(2026-10-22、利用者)

`update-containers.ps1` の 2 で web(`php:8.4-apache@sha256:56e7dab…` = PHP 8.4.27)と phpmyadmin を当て、`php -v` を確かめた。
**つなぎ(nginx の `$km_php_shield_multipart` と 2 つの if・check.php の節)は同日に外した**(配備 → reverse-proxy の restart が要る)。
残すもの: メンテナンス中の画面・`session.upload_progress.enabled = 0`。破られた跡は 10-21 の確認で見つからなかった(書ける場所・設定・web の記録・nginx の POST)

### 2026-10-22 — 止めたままでは修正版を当てられなかった(2026-10-22、利用者の指摘)

①控え(`host-backup.sh`)が「mariadb が動いていません」で止まり、
`update-containers.ps1` の 2 が最初の控えで必ず終わった。②`check-updates.sh` と `update-containers.ps1` の Get-HostState が `docker compose ps -q`(動いているものだけ)で、
止めたサービスは比べられず「変化なし」と出た。→ ①止まっている DB(mariadb・postgres)は**控えのあいだだけ `docker compose start` で起こし、終わったら(失敗しても)止め直す**
(web などは起こさない)。②どちらも `ps -a`(止まっているものも)にし、一覧に「止まっています」を添える。docker の代わりの台本で、起こす → healthy を待つ → 取る → 止め直すを確かめた。check.php 2048 件。
**直した版はホストに送るまで効かない** —— 先に `.\deploy-to-host.ps1 -Yes`(控えなし)で送ってから `update-containers.ps1` を流す

### 2026-10-21 — ポスター・広告と地点の URL(2026-10-21、利用者の指示)

管理画面「データベース → ポスター・広告」(`admin/posters.php`・`lib/posters.php`)。
- **出し方は 3 段階**: 表示しない(既定)/ イベント中のみ(イベントモードのどれかが有効で期間内)/ 常に表示。`km_settings` の `poster_display_mode`。
- ポスター 1 枚 = 画像・見出し・説明・URL・地点・並び順・出す/止める(表 `km_posters`)。画像は**ファイルか URL**。保存の前にプレビュー(ファイルはブラウザの中で読む・URL はサーバーが取りに行って data: で返す `admin/api/poster-preview.php`)。
- **URL の画像も取り込んでこちらに置く**(公開ページの CSP の img-src は 'self' と data: のまま。来場者の端末を外部へ繋がない)。取りに行く先は公開のアドレスだけ(私的な網・127/8・169.254 などを断る。名前を引いた先を固定し、リダイレクトは自分で辿って確かめる)。PNG / JPEG / GIF / WebP、8MB・縦横 4096px まで。SVG・PDF は断る。
- 公開ページ: 地点の詳細にポスター(押すと大きく)、ポスターのある地点の名前に 🖼️ と輪。右上の 🖼️ から一覧のページ `/posters.php`(画像を押すと `/?room=<地点>&poster=<番号>` で地図のその地点へ移って詳細を開く。地点の無いポスターは URL を開く)。テスト用アカウントでも 🖼️ は出す。
  画像は `api/poster-image.php`(いま出しているもので止めていないものだけ。nginx の km_api で回数を数える)。地図の錠が掛かっていれば、一覧のページは地点の名前と地図への移動を出さない。
- **地点の URL**(表 `km_map_node_links`): 地点の詳細にリンクを 1 本(出し方の 3 段階とは関係なく出る)。地図の取り込みでは消えない。
- 表は管理画面を開いたときに作る(公開の経路では作らない。無ければ「何も無い」)。check.php 1983 件。手元の試し(PHP 組み込みサーバー + SQLite の代わり)で、足す・直す(画像そのまま)・消す(画像も消える)・URL の取り込みとプレビュー(127.0.0.1・192.168.x は断る)・3 段階の出し入れ・地図への移動・スマホの幅を確かめた。
  **配備は PHP・JS・CSS に加えて nginx**(km_api の正規表現に poster-image を足した)

### 2026-10-21 — 広告は 10%・チュートリアルの終わりに(2026-10-21 の 3 回目、利用者の指示)

- **広告 = 地点に置いていないポスター**(`isAd`)。チュートリアルが終わったとき(最後まで・スキップ・Esc)に **10% の確率**(`KM_POSTER_AD_CHANCE`。全部同じ)で 1 件を出す(`Main/tutorial-end.js`。tutorial.js が `km-tutorial-finished` を出す)。出し方の 3 段階は今までどおり掛かる。
- 広告を閉じたら(出なかったときはすぐ)「🎉 チュートリアルが終わりました」: 本文(管理画面で変える)・「このページのアンケートを募集中です。こちら」と QR(URL は管理画面。空ならテスト用アカウントの画面のもの。どちらも空なら出さない)・「スマホでもマップを見ることができます」と地図の QR(出す/出さないを選べる)。
- **利用規約に第 7 条(ポスター・広告の表示)を足した**(確率は定数から書く・情報を集めない・外部の広告配信事業者へ送らない)。以降の条を 1 つずつ送り、改定日を 2026-10-21 に。
- **同日の直し(利用者の指示「10% だが、表示しないわけではない。1 枚なら 100%、2 枚なら 50%」)**: 広告があれば**必ず 1 件**を出し、どれにするかを重みで選ぶ。全部同じ重み(`KM_POSTER_AD_WEIGHT` = 10%)なので割合は 1 / 件数(`km_poster_ad_share`)。管理画面に「いまは N 件で、それぞれ X%」。規約の第 7 条も直した。
- **同日、表示回数とリンクの人数を数える**(利用者の指示): 表 `km_poster_stats`(ポスター × 日 の impressions / clicks。**数だけ**で IP・利用者・端末は置かない)。`api/poster-event.php`(POST・CSRF・セッションでリンクは 1 回・広告の表示は 60 秒に 1 回・km_api で回数を数える・数えたかを外に教えず 204)。`Main/poster-stats.js` が `a[data-km-poster-link]` の押下(地図の詳細・大きく見る幕・広告・一覧のページ)と広告の表示を送る。**同じ端末は 1 人**(localStorage に覚えて 2 度目を送らない)。管理画面の一覧に「表示 N 回 / 7 日・リンク N 人 / 7 日」。規約 第 7 条とプライバシーポリシー 2. に「数だけ記録」を書いた。ポスターを消すと数も消える。
  手元の試しで、広告 2 件で 199 回中 95 / 104(毎回どちらかが出る)・2 度目の押下は送らない・一覧のページの押下・管理画面の数・トークン無しは 403 を確かめた。check.php 2026 件。**nginx の入れ直しが要る**(km_api に poster-event を足した)
- **広告をまとめて取り込む(同日、利用者の指示「高専祭の模擬店のページ 1〜58 から自動で広告を作る」「今後も使うので機能に」)**:
管理画面「ポスター・広告」の「まとめて取り込む」。URL の型紙(`%N` に番号。既定 `https://kosensai.ichinoseki.ac.jp/shops/%N/detail`)と番号の範囲(既定 1〜58。**0 は使わない**)。
- `lib/poster-import.php`: ページを `km_poster_fetch_remote`(公開のアドレスだけ)で読み、`Dom\HTMLDocument` で `article` の No.・名前(h2)・コメント(「コメント」の次の p)・ポスター画像
  (`/_next/image?url=…` の中の元の画像)を取る → **地点なしのポスター(広告)**。リンクはそのページ、並び順は 1000 + 番号。
  同じページ(link_url)が既にあれば直すだけ(画像は URL が変わったときだけ。出し入れ・並び順・地点は残す)。404 は「無い番号」で飛ばす。
- `admin/api/poster-import.php`(POST・CSRF・1 回に 1 つ・セッションを先に閉じる・操作の記録 `poster.import`)と `admin/assets/js/poster-import.js`(1 つずつ・0.4 秒あける・止められる・結果の一覧)。
- 利用規約 第 7 条に「**広告は収益化していません**」(利用者の指示)。
- 手元で 1〜58 を本物の lib で読み、58 件とも名前・ポスター画像が取れた(画像は 509×743〜2480×3509 の WebP。コメントが空の店は 10 件)。
  試しの作り(PHP 組み込みサーバー + SQLite)で、57〜59(59 は 404 で飛ばす)と 1〜58 の通しを流した。check.php 2046 件。
  この PC は Norton が TLS を差し替えるので、手元の試しだけ証明書の束を足した(本番は関係ない)

### 2026-10-21 — 階と平面図の差し替え・追加・削除(2026-10-21、利用者の指示)

管理画面「データベース → 階と平面図」(`admin/map-floors.php`・`lib/map-floors.php`)。
- 階ごとに画像の差し替え(今と新しい画像を並べてプレビュー・縦横の比が座標の範囲と違えば知らせる)・**元の画像に戻す**(組み込みの階)・名前と並び順・消す(地点がある階は「中の地点と線も消す」に印が要る。1F は最初に開く階なので消せない)。
- 階を足す: **校舎の階**(公開ページの右の階の並びに出る)か **建物の中**(屋外の建物の名前の手がかりで「中へ」に出る。表 `km_map_floor_custom`)。座標の範囲は画像の大きさ。地点は地図編集で置く。
- **zip でまとめて差し替え**: 名前から階を当てる(id・名前・元の画像の名前。`１階.png`・`1F.png`・`bldg_library_1f.png` など)→ 表で確かめて差し替える。本番の PHP に zip の拡張が無いので**中央ディレクトリを自分で読む**(`lib/zip-read.php`。格納と deflate・CP932 の名前・暗号化/zip64 は断る・CRC・数と大きさの上限・zip 爆弾は gzinflate の上限で止める)。置き場は `uploads/floor-staging/`(1 日で片付ける)。
- **画像は uploads/ に置く**(本番の src は読み取り専用で、配備に上書きされる)。`km_map_floors.svg_path` に `upload:floor_<32 桁>.<拡張子>`。組み込みの画像は残る。
- 公開ページ: 右の階の並びを地図データの校舎の階から作る(無ければ組み込みの 6 つ)。**階の無い地点とその線は出さない**。「中へ」は今ある組み込みの建物の階と、足した建物の中。畳んだ取っ手は建物の中では名前を出す(以前は id)。
- アプリの地図の取り込みは、足した階の座標の範囲を 1600×1200 で上書きしない。**アプリには反映されない**(アプリの画像はアプリの中。足した階の地点はアプリへの配信で「知らない階」として外れる)。
- 画像の確かめはポスターと共通にした(`lib/raster-image.php`)。check.php 2002 件。手元の試し(PHP 組み込みサーバー + SQLite)で、Windows の「送る → 圧縮」で作った zip(`１階.png` など)の当て込みと差し替え・縦横の比の知らせ・元の画像に戻す・階の追加(校舎の階と建物の中)・地点のある階の削除(印なしは断る)・公開ページの階の並びと「中へ」・地図編集の階のボタンを確かめた

### 2026-10-21 — PHP の RCE(CVE-2026-109618 / 109619)のつなぎ(2026-10-21、利用者の指示「修正プランと対策」「全部止める」)

PHP 本体に認証なしの RCE が 2 つ(CVSS v4.0 9.2)。修正版は 10/21 14:00 UTC(日本時間 23:00)。詳細が漏れ、攻撃の道具が出回っている。
web(php:8.4-apache・PHP 8.4.26)と phpMyAdmin が対象と見る。手順と、破られていないかの確かめ方は [05](05-containers.ipynb) の該当節。
- **つなぎ(10-21〜22。外した)**: nginx が multipart の要求を 443(公開・管理・API)と 8281(phpMyAdmin)の server の直下で 403 にする(`$km_php_shield_multipart`)。
  止まる間は、ファイル管理・ポスター・階の画像・地図の取り込み・APK・プロフィールの画像・アプリのアカウント画像・パノラマ・AR・phpMyAdmin の取り込みが使えない(JS の FormData は 0 件で、ほかの画面と API は止まらない)。
  `99-limits.ini` に `session.upload_progress.enabled = 0`(使っていない。修正版の後も残す)。check.php 2029 件。
  **配備: `-Backup -Yes` → reverse-proxy と web の restart**(利用者)
- **修正版まで止めて待つ(同日、利用者の判断)**: 入口(nginx)と certbot だけを動かし、ほかは `docker stop`。止め方・戻し方は [05](05-containers.ipynb) §10-1b。
  **メンテナンス中の画面**(利用者の指示): `nginx/km/maintenance.conf` を 443 の本体と auth の server が読み込み、nginx 自身が出す 502(転送先が居ない)だけを
  `nginx/km/maintenance.html` の 503 にする(504・429・相手が返す 5xx では出さない)。外のものを読まない 1 枚の HTML(CSS は中・JS なし・CSP は default-src 'none')。
  管理用のホストでも公開側へ飛ばさない(`$km_leave_admin_host` の例外)。配備の荷に必ず入れる(`deploy-to-host.ps1` の mustContain)。check.php 2035 件。**この画面はつなぎを外した後も残す**
- **まだ**: 手元の `Downloaded/php-8.4.24…`(試しの作りと check.php 用)を 8.4.27 に替える

### 2026-10-19 — サインインと通知を 443 番に寄せる(2026-10-19、利用者の指示。学校の回線は 443 番以外を通さない。解除は難しい)

- それまで: サインインは `ito4.jp:3001`、チャットと死活監視は `ito4.jp:6001` で、**学内からは誰もサインインできなかった**(展示の PC・教職員・学内 Wi-Fi の管理アプリ)
- サインイン: nginx に `auth.<ドメイン>` の 443 番の server(中身は 3001 と同じ。確認コード欄の差し込みも)。切り替えは **.env の `KM_AUTH_DOMAIN`**(compose.vps.yaml の LOGTO_ENDPOINT と Logto の ENDPOINT を同じ導き方に。書かなければ :3001)。
  PHP は `km_site_url('logto-core')` を LOGTO_ENDPOINT に従わせた(発行元 iss もここから)。host-cert.sh status が証明書に auth の名前があるかを見る
- 通知: 管理画面の 443 番の `/ws/app/` → Soketi の `/app/`(Origin は管理画面だけ・公開側のホストでは 404・/apps/ は外に出さない)。pusher-js は `wsPath: '/ws'`。CSP も合わせた。**配備した時点で効く**
- 管理アプリ **v10(1000010)**: 入口の既定を `https://auth.<ドメイン>` に。試験 812 件 × 2、署名は v9 と同じ。受け箱へ送った(**公開は 10-4 の切り替えのあと**。前に公開するとサインインできない)
- 3001・6001 の入口は戻せるよう移行の間だけ残す(docs/14 §10-7)
- 手順は [14](14-domain-ito4.ipynb) §10(DNS → 配備 `-Action up -Backup` → 証明書 → `.env` の切り替え → 確かめる)。check.php 1924 件。**nginx の設定は手元で読み込ませて確かめていない**(docker が無い)
- **2026-10-19 に利用者が本番で切り替え、正常に移行できた。**
- **同日、3001・6001 の入口を外した**(利用者の指示): nginx の server 2 つ・compose.vps.yaml と compose.yaml の publish。Logto の入口の既定は `https://auth.<KM_DOMAIN>`(.env に KM_AUTH_DOMAIN が無くても :3001 に戻らない)。
  ローカル環境は compose.local.yaml の別名と host-local.sh の証明書に auth.<ドメイン> を足した。check.php は「3001 と 6001 を待ち受けない・publish しない」を見張る(1926 件)。**配備は -Action up**。戻し方は docs/14 §10-6

### 2026-10-19 — スマホで初回の操作案内が崩れていたのを直した(2026-10-19、利用者の指摘)

手元の試し(スマホの幅)で通して 3 つ見つけた。
(1) 案内開始のあと検索の欄が閉じると、6 段目(カテゴリー検索)の欄が画面の外へ出て、**枠も吹き出しも外に置かれ、暗い幕だけが残った**。
(2) 欄の下に置いた吹き出しが、次に押す欄(目的地・案内開始)を覆った。(3) 欄を押すと出るキーボードの裏に吹き出しが隠れる(見える範囲を window.innerHeight で測っていた)。
`Main/tutorial.js`: 見える範囲は visual viewport で測り、外にある所は見えないとみなす。検索の欄の中の所が見えなければ「検索」を指して開いてもらう(6 段目は文も替える)。
吹き出しは必ず見える範囲に収め(狭いときは本文だけ巻物)、キーボードの出し入れで置き直す。スマホで検索の欄の中を指すときは上に置く。PC の置き方は変えていない。check.php 1943 件

### 2026-10-19 — テスト用アカウントは係の人の合言葉でだけログアウトできる(2026-10-19、利用者の指示「テストユーザーのときは簡単にログアウトできないように」)

`sign-out.php` がテスト用アカウントなら合言葉を確かめる(Logto から出す前。ボタンを隠すだけにしない)。5 回続けて外すと 10 分受け付けない。合言葉が決まっていなければ出さない。
合言葉は管理画面「テスト用アカウント」で決める(`km_settings` にハッシュだけ)。地図の画面は、押せばすぐ出るボタンの代わりに閉じた「係の人」の欄(合言葉と ログアウト)。
**同日、PC で「係の人」の欄が「テスト中」の札の後ろに回り、右端からはみ出した**(利用者の指摘)。上のバーは #ui-layer(1000)の中で、その外の札(1200)より手前に出られない。浮かせるのをやめ、バーの中で横に開くようにした(スマホでは折り返す)。1366・1024・820・375 の幅で確かめた。
アカウントの画面(表示名・パスワード・退会・サインアウト)はテスト用アカウントでは使えない。確かめられないとき(DB の不調)は普段どおり出す。check.php 1939 件。見た目は手元の見本で確かめた(ライト・ダーク)
**同日、テスト用アカウントでは「ダウンロード・設定」を開けないようにした**(利用者の指示)。⚙️ も引き出しも書かない(APK・アカウント設定・お問い合わせへの導線をページに残さない)。
操作の案内は「操作方法」の札から開ける。スマホでもログインの欄は上のバーに残り、折り返して収まる(375 の幅ではみ出さないことを確かめた)。普段の画面の ⚙️ と引き出しはそのまま。check.php 1948 件

### 2026-10-19 — phpMyAdmin の環境保管領域(2026-10-19、利用者の指示)

「環境保管領域が完全に設定されていません」を消す。
DB `phpmyadmin`(像の `sql/create_tables.sql` の 19 表)と制御用の利用者 `pma`(`phpmyadmin.*` の読み書きだけ・`REQUIRE SSL`)。
`docker/phpmyadmin/config.user.inc.php` は `.env` の `KM_PMA_CONTROLPASS` があるときだけ `controluser`・`pmadb`・表の名前を入れる(無ければ今までどおり)。
パスワードはホストで作って .env へ(画面・リポジトリ・コマンドの引数に出さない)。手順は [05](05-containers.ipynb) §1-2(配備 → 🔴 のセル → 🟢 で確かめる)。
控え(host-backup.sh)には入らない(本体の DB だけを取る)。同日、利用者が本番で作った
- **同日、ログイン後の全画面が TypeError になった**: 「設定 → 機能」の保存で `LoginCookieValidity` に空の文字列が入り、phpMyAdmin 5.2.3 が型の違いで止まった(保存した設定はログインのたびに読まれ、セッションにも覚えられる)。
  設定を 2 段階認証だけ残して空にし、セッションを消して直った(docs/05 §1-2 のセル。写しは `km_userconfig_backup`)。`UserprefsDisallow` でこの項目を利用者ごとの設定から外した(有効期間は 30 分に固定)。check.php 1927 件

### 2026-10-16 — コンテナの更新を番号で選んで進める(2026-10-16、利用者の指示「Node と Logto 等の上げ方を簡略化。選択肢を設けて実行」)

- `scripts/update-containers.ps1`: 1 更新を調べる / 2 修正版を当てる(Node・PHP・MariaDB・PostgreSQL など。版はそのまま、一覧から番号で)/ 3 Logto を次の版へ / 4 いまの様子。
  digest はホストでレジストリに聞き、手元の compose / Dockerfile のその 1 行だけを書き換える → check.php(落ちたら戻す)→ `yes` → `deploy-to-host.ps1 -Backup`(控えは配備の前)→ pull / build --pull / Logto の移行(一覧を見せてからもう一度 `yes`)→ 全体の `up -d`。
  タグの無い固定(nginx・mailpit・certbot・postfix)とメジャー版は扱わない。Logto を飛ばすときは `skip` と打たせる。[05](05-containers.ipynb) §0
- 手元では `-DryRun`(繋がない・書き換えない)で 2 と 3 を最後まで流し、写しのファイルで書き換え(1 行だけ・大きさと BOM はそのまま・戻せる)を確かめた。**本番では一度も流していない**
- **直した(同日、利用者の指摘「1.44.0 なのに最新と表示された」)**: いまの版を**手元の** compose.yaml から読んでいた。途中で止めた回のあと手元は 1.45.0・本番は 1.44.0 になり、「最新です」と出た。
  いまの版は**本番で動いているコンテナ**から読む(`Get-HostState`)。手元と違えば両方を出して知らせる。修正版の一覧も本番と比べ、手元だけ新しいものを「☆ 本番は古いまま」で出して当てられるようにした。
  やめたとき・配備できなかったときの既定は「手元を戻す」(Logto は本番と同じ版へ)。本番の出力を模した試しで、5.1 と 7 の両方で確かめた
- **間違っていたら止まる・揺れはやり直す(同日、利用者の指示「compose.yaml の入力が間違っていたときに自動で停止か、リトライ」)**:
  配備の `yes` より前に、手元の形(`名前[:タグ]@sha256:64桁`)・本番の .env での `docker compose config -q`(一時フォルダに写して。置き場は汚さない)・像がレジストリにあるか、を見る。
  駄目なら何も送らずに止まり、直して `r` で確かめ直せる。問い合わせ・pull・build は 10 秒・20 秒待って計 3 回やり直す。駄目なら `up -d` せず止まり、`b` で手元と本番を元に戻せる。
  `up -d` のあとは全部の起動を見届け(restarting・unhealthy・異常終了)、駄目ならログを出して止まる。Logto の移行はやり直さない。
  試しで: 形の誤り 2 種・config の誤り・無い像・やり直し(2 回目で通る/3 回とも駄目)・起動待ち(ssh の一時切断を含む)・再起動の繰り返しを確かめた。
  ホストへ送る sh は文法を確かめ、heredoc で写した compose が元と 1 字も違わないことを確かめた。**本番の docker での `config -q` はまだ通していない**(→ 11-06 に本番の docker で `config -q` を通した)

### 2026-10-16 — どの PS1 も `-Help` でオプションとその意味を出す(2026-10-16、利用者の指示)

- 共通の `scripts/km-help.ps1` が、各スクリプトの先頭のコメント(.SYNOPSIS・.PARAMETER・param() の中のコメント)と param() の書き方(型・既定値・選べる値・必須)を読んで出す。説明を二重に書かない。
  同じ中身を Android の `scripts/` と GitHub の控え 2 つ(`scripts/`・`tools/`)にも置いた(check.php が食い違いを見張る)
- 対象: Website の `scripts/*.ps1`・`src/scripts/js/run-all.ps1`、Android の `scripts/*.ps1`、控えにしかない道具(push-github・sync-from-*・update-private-names)。計 27 本。
  読み込む部品(km-ssh.ps1・backup-lib.ps1)は param を持たないので、直に叩いたときだけ `-Help` を見る(. で読むと何もしない)。
  必須の値があるもの(ar-blur-faces・ar-realityscan-prep・new-map-qr)は `-Help` を別の組にして、`-Help` だけで必須の値を聞かれないようにした
- 説明の無かったオプション 45 個に意味を書き足した。先頭の説明が無かった Android の 4 本に .SYNOPSIS を足した
- PS1 以外で手元で動かすもの: `ar-depth-cloud.py`(`-Help`・`-h`・`--help`。見出しも日本語)、`check.php`(`-Help`。終わり方の番号も出す)。ホストで動く .sh は対象外
- 確かめ: 全部の `-Help` を 7 と 5.1 で流した(説明の抜けなし・終了 0)。普段の呼び方(new-map-qr -Code … -MatrixOnly、deploy -WhatIfOnly、update-containers -DryRun)が変わらないこと、
  必須の値は -Help 無しなら今まで通り必須なことも確かめた。check.php 1912 件
- `Test/scripts/check-php-api (1).ps1` は check-php-api.ps1 の古い写し(行き先が使わなくなった校内 LAN のまま)だったので、同日 `Test/Old/duplicate-scripts-20261016/` へ退避した(GitHub の控えも `Old/` の同じ名前へ)

### 2026-10-15 — 自動の「始めから」・イベント中の来場者の記録・配備の前のバックアップ(2026-10-15、利用者の指示)

- テスト用アカウント: 触られないまま設定の分(既定 3 分・0 で使わない)が過ぎたら、15 秒の知らせ(「このまま使う」)のあと自動で「始めから」。1 度も触られていない画面は戻さない。記録に「始めから:idle」
- イベントモード中は、ログインの有無に関わらず最初の画面に「情報を収集するのを許可します」(**チェック済み**)の帯。決めるまでは送らずに溜め、外して OK なら捨てて送らない(イベントごとに覚え、帯の「記録の設定」で変えられる)。
  記録のアカウント欄は `event:<イベントの番号>` で、誰かは残さない。管理画面で止められる・記録を「テスト用 / イベント中の来場者」で絞れる
- **イベントの案内の帯がトップバーの下に潜って見えていなかった**(トップバーを absolute にした 09-05 から)。トップバーの下へ置き直した
- `deploy-to-host.ps1 -Backup` は**配備の前に**控えを取る(以前は配備と後片付けのあと)。取れなければ何も送らずに止める(終了コード 3)。[02](02-deploy.ipynb) も直した
- 手元の試しで、許可の帯(チェック済み)・決める前は送らない・外すと送らない・帯の位置・自動の「始めから」(1 分)を確かめた

### 2026-10-15 — 初回の操作案内・イベント中だけ地図を開ける・非表示の反転(2026-10-15、利用者の指示)

- `Main/tutorial.js`: 初めて開いたとき(「はじめに」を閉じてから・地図が出てから)、押す所を光らせて矢印付きの吹き出しで順に案内する
  (検索 → 出発地「ここを押して、出発地を決めてください」→ 目的地 → 案内開始 → 階 → カテゴリー → 地図の点)。言われた操作をすると次へ進む。
  幕は見た目だけで、上から押せる。QR から来た人・錠のときは出さない。テスト用アカウントは来場者ごと。「ダウンロード・設定」と操作方法の札から出し直せる。手元の試し(PC・スマホの幅)で確かめた
- 地図データ公開設定に「イベント中だけ、パスワード無しで表示する」(`eventOpensMap`。既定は出さない)。地図の錠だけに効き、教職員氏名の錠は変えない。有効なイベントが確かめられなければ開けない(`km_map_event_opens_view`)
- 管理アプリ v9(1000009): 編集中のイベントがあるとき、ツールに「非表示を反転（この階）」。この階の部屋・施設・階段・出入口の「出さない」を入れ替える(もう一度で元に戻る。確かめの画面で数を出す)。
  管理マップに「出さない」地点を斜線で出すようにした。試験は両フレーバーで 812 件。受け箱へ送った

### 2026-10-14 — イベントモード: 管理アプリで作って Website で出す(2026-10-14、利用者の指示)

今回の会期はアプリを配らない。[07](07-map-qr.ipynb) §5。
アプリ(v7 = 1000007)の `MapEvent` に名前の上書き・出さないノードを足し、ノードの編集画面に「イベント『…』では」の欄と部屋の URL。
Website は管理画面「イベントモード」で書き出しを取り込む(新しいイベントは無効のまま・中身は丸ごと入れ替え・表 `km_map_event_hidden` / `km_map_event_sources`)。
公開ページは名前を置き換え・出さない地点を描かず候補にも出さない・案内の帯の既定は「イベントモード中…」・`/?room=<id>` でその部屋を開く(手元の試しで画面を確かめた)。
**まだ**: 本番へ配備・管理アプリを Pixel に入れて実物のイベントで取り込み(利用者)。管理画面の取り込みの画面は手元では開けていない(Logto の門番があるため)

### 2026-10-14 — QR から来た人には「はじめに」を出さない(2026-10-14、利用者の指示)

`Main/welcome.js` が `<meta name="km-focus-room">` を見て開かない(読んだ印も付けない)。手元の試しで確かめた

### 2026-10-14 — 名前の札の引き出し線(2026-10-14、利用者の提案)

重なった札は「上下にずらす → 点の横・下に付ける → 点から離して線で結ぶ」の順に置く。
線はほかの札を横切らせず、札で別の点を隠さない。屋外は置けない札を出さない(前と同じ)・**建物の中は消さずに元の位置で重ねる**(前は建物の中はずらしもしなかった)。
Website は `Main/label-layout.js`(検査 `scripts/js/label-layout.test.js`。線は札の `::after`)、アプリは `LabelLayout.kt`(点 → 線 → 札の順に層を分けて描く)。同じ手順・同じ数(check.php が突き合わせる)。
Website は手元の試しで画面を確かめた。アプリは試験だけ(実機の見た目はまだ)
- **管理アプリ v8(1000008、2026-10-14)**: 編集ツールに「結合解除（道）」(道の線をタップすると 2 点の結合を外す。同じ 2 点の線はまとめて・壁は対象外・↶ で戻せる)。
Wi-Fi 学習を**ノードの無い所でも**(地点の名前を `pt-` で始め、地図のノードは増やさない。近くの前の学習地点には寄せて積み重ねる。管理マップに輪と十字で出し、「削除」で消せる。
参照切れとして捨てていた診断・最適化・取り込み・リセットの取り消しは残すように直した)。試験は両フレーバーで 809 件。
利用者の指示「版を上げたら Pull も」(= apk-push)で受け箱へ送った。公開するかは利用者
- **テスト用アカウント(展示の PC。2026-10-15、利用者の指示)**: Web 版の展示が決まり、PC で出す。`lib/test-mode.php`・管理画面「テスト用アカウント」(`admin/test-mode.php`。ユーザー管理の一覧からも印を付けられる)。
印の付いたアカウントで公開ページを開くと、右上に「🧪 テスト中」の札(アンケートの QR と URL。https だけ)と操作方法(管理画面で書き換え。1 行 1 項目)・「↺ 始めから」を出す。どちらも「－」で見出しだけにできる。
記録するのは検索の語(出発地・目的地の欄)・カテゴリー・検索から選んだ場所・経路(出発地・目的地・条件。引けなかったことも)・開いた部屋の詳細・階の切り替え(`km_test_events`)。
**印の無い人の操作は送らない**(`Main/test-mode.js` は印のあるときだけ読み、app.js はそれが無ければ何もしない)。API `api/test-events.php` はセッションの印(開くたびに立て直す・8 時間)と CSRF を見る。
「始めから」で来場者の区切り(visit)を新しくし、表示調整・経路の条件の覚えを消して開き直す。管理画面でまとめ・新しい順・CSV(BOM 付き)・全部消す。
手元の試し(1600×900)で、札の表示・記録の中身(全種類・トークン付き)・「－」・「始めから」を確かめた。管理画面は手元では開けていない(Logto の門番)。
**まだ**: 配備(nginx に入口を足したので reverse-proxy の再起動も)・テスト用アカウントを作って印を付ける・アンケートの URL と操作方法を入れる(利用者)

### 2026-10-12 — ビルド元の更新(2026-10-12、利用者の指示)

知らせ「ビルド元が更新されています」に合わせ、`docker/php/Dockerfile` の `php:8.4-apache` と
`docker/soketi/Dockerfile` の `node:24-trixie-slim` の digest を書き換えた(どちらも 10-06 作成。PHP 8.4.26・Node 24.21.0 のまま土台の Debian だけ新しい)。
composer:2・aloware/soketi は変化なし。10-12 に本番で配備 → `build --pull web soketi` → `up -d` 済み(利用者)

### 2026-10-12 — 更新の知らせのメールを読みやすく(2026-10-12、利用者の指示)

1 件 1 行(「いま: … → 登録されている中身: …」)をやめ、
件数・番号・何が起きたかの一言・「いま使っているもの」「新しく出たもの」を別の行に。当て方も digest の書き換えから書く(`lib/update-notice.php`)

### 2026-10-12 — Logto の確認コードの自動入力(2026-10-12、利用者の希望)

Logto 1.44.0 のコード欄は `autocomplete="off"`(CSS では変えられない)。
nginx(3001)が Logto の HTML に `nginx/km/logto-otp-autocomplete.js` を差し込み、1 つ目の枠に `one-time-code` を付ける。[04](04-host-jobs-and-mail.ipynb) §6。
10-12 に本番へ反映(利用者)。Logto の HTML に 1 回だけ差し込まれ、スクリプトは 200・`application/javascript` で読まれ、CSP の違反なし(ブラウザで実測)。
Pixel(Chrome・Gmail)では候補が出なかった(10-12、利用者)。SignIn のメールの形を変えても Gmail はコードを拾わなかった。

### 2026-10-12 — メールのテンプレートを 9 種類そろえ、verify.php を片付け(2026-10-12、利用者の指示)

正本 `docs/logto-email-templates.json`(コードは本文に 1 回・単独の行・件名に `(Verification Code)`・URL なし)。
`src/verify.php` は `Old/src/` へ退避し、nginx の location も外した。web の healthcheck は `src/healthz.php`(DB なし・外には nginx が 404)。
**まだ**: Console へ 9 種類を貼る(利用者)→ 配備 `-Action up`(healthcheck が変わるので web の作り直しが要る)。iPhone・Mac の Safari での実測(Safari と Apple の「メール」/ Pixel の Chrome と Gmail)・SignIn のメールの形の試し(Console は利用者)

### 2026-10-09 — アプリの配布を GitHub の受け箱から(2026-10-09)

PC の `scripts/apk-push.ps1` が非公開の `itotakusub/kosenmap-apk` に APK 2 つと latest.json を置き、
本番ホストの root の cron(`scripts/host-apk-inbox.sh`。cron 版 9・5 分ごと)が取って web が検め(SHA-256・パッケージ名・版・**署名の鍵が今と同じ**)、
受け箱に入れて管理者へメール。管理画面「アプリの受け箱」で押して公開(開いただけでは公開しない・取り消し・7 日間は前の版に戻す)。[02](02-deploy.ipynb) §5。
**まだ**: 利用者の準備(リポジトリ・読むだけのトークン・`apk-inbox.local.conf`・配備 `-Action up`・cron 版 9)と本番での通し

### 2026-10-09 — 深さから部屋の点群(2026-10-09)

PC の `scripts/ar-depth-cloud.py`(Blender の中でも、Blender なしでも)が、深さ・カメラの位置・重ね方から色付きの点群(PLY)・真上の画像・床と天井の高さを作る。[20](20-indoor-positioning.ipynb) §18。
アプリは前と同じ・0.1 秒より古い深さを保存しない(10/8 に同じ深さが 85 枚続いた)。1F の実測で床 −1.43 m・天井 +1.32 m を出せたが、壁は 20 cm ほどぼやける(部屋ごとの撮り直しはまだ)。
本物の deface でのぼかしは 10/9 に利用者が流して正しく動いた(写真 212 枚の明るさは元と同じ)。「黒い画像」は深さの PNG の見え方で、壊れていなかった

### 2026-10-08 — 部屋の中の測位(2026-10-08)

歩ける所に「通路→部屋の線・部屋と出入口のまわり(`room_radius_m`)・学習地点のまわり 2 m」を足し、部屋の中は通路へ寄せない。
3F の 1-3 で現在地が階段のそばに出た件(指紋の候補が「歩ける所から遠い」と外されていた)。[20](20-indoor-positioning.ipynb) §15

### 2026-10-08 — AR の跡の直し(2026-10-08)

位置の飛び(0.1 秒で 6〜14 m)を切れ目にし、追跡切れと合わせた区間ごとに重ねる。方位のずれを外れに強くした。[20](20-indoor-positioning.ipynb) §16

### 2026-10-08 — RealityScan 用の位置データ(2026-10-08)

端末が重ね方(alignment)を記録に書き足し、PC の `scripts/ar-realityscan-prep.ps1` が東・北・上(m)の Trajectory・地上点・GPS(EXIF)を作る。[20](20-indoor-positioning.ipynb) §17。
RealityScan での読み込みは利用者が試す(ローカル座標がマップウィザードで通るかは未確認)- **まだ**: 学校の中を歩いて 4 地点以上に印を付けた実測(地点を動かす候補・北の候補)。深度の確からしさの画像は入れていない。ぼかしは偽の deface で流れだけ確かめた(本物の deface はまだ)

### 2026-10-07 — Pixel 7a で確かめた(2026-10-07)

版 2 の上書き(データを残す)・学習データの同期・AR の追跡(軌跡・方位・深度)・印を付ける・結果の画面・地図で見る・前の記録(サーバーの一覧も)・
画像 15 枚と深度 15 枚を本番へ送り、送った分を端末から消す。**直した**: AR ボタンが階の列に重なって押せない(左上へ移した)・
地点から外れて押すと何も出ず印が付かない(拾う範囲を 2 倍・外れたら知らせる)・撮れないときに黙る(理由をログと帯に)・地図で見るで跡の所へ動かない・下の帯が階のボタンにかかる

### 2026-10-06 — 2026-10-05 夜の依頼の残り(2026-10-06、利用者の指示)

すべて検証機(192.168.3.107)で確かめた。Android は Pixel 7a と LDPlayer で確かめた。
- **管理画面**:
  - タスクマネージャーの間隔を 1〜10 秒で選べる(前の読み込みが終わってから次を出す)
  - サービス監視の「今後の拡張」は、かんばん・進捗表のタスクの分類「サービス監視」から出す(`km_tasks.topic`)
- **訪問者**(`/admin/visitors.php`):
  - 記録: nginx が公開のページだけを JSON で書く(`run/visitlog/visit-日付.jsonl`)。クエリは書かない
  - 閲覧者の種別: PHP が `X-KM-Viewer` で渡す(サインインした人は鍵つきの印)
  - まとめと位置はブラウザ: ipwho.is → だめなら api.country.is。**スイッチを入れたときだけ**引く
    (結果はそのブラウザの IndexedDB `km-visitors` / `geo` に 30 日。サーバーには送らない)
  - **色分け**(2026-10-08、利用者の指示): 危険 = 赤・怪しい = 黄。判定もブラウザ(`assets/js/visitors-threats.js`。画面の下に「危険リスト」で一覧)。
    きっかけは 10/6〜8 の RedTail(`libredtail-http`、CVE-2024-4577 を狙う POST /)99 件 —— 本番は Linux の mod_php で当たらず、
    200 は普通のトップページ、マイニングのプロセス・新しいファイルなし(2026-10-08 に確かめた)。
    外国は行の色ではなく場所の札にし、チェックボックス「外国からの接続を表示」で表示・非表示(既定は表示)
  - **点数**(2026-10-08 の 2 回目、利用者の指示): ① ISP/ASN ② User-Agent ③ IP 種別 ④ アクセス頻度 ⑤ HTTP ステータス ⑥ URL・メソッド ⑦ 過去の挙動 を足し、
    60 点以上 = 危険・25 点以上 = 怪しい。前の「同じ送り元が危険なら全行を赤」は、同じ回線の普通の閲覧まで赤にした(試験の curl と利用者の Chrome)ので、⑦ の 20 点だけにした。
    ① は ipwho.is の `connection`(ASN・組織)。⑦ の「前にも危険」は IndexedDB `km-visitors` / `flags` に 30 日。
    「信頼する」(自分の回線など)はそのブラウザの localStorage だけ(IP をリポジトリに書かない)
  - CSV の書き出し(訪問・送り元。UTF-8 BOM・式に読まれる値の頭に `'`)、最近の訪問は 10・50・100・200 件、国・地域別は ▷ で地域・都市を開く
  - **世界地図と BAN**(2026-10-08 の 3 回目、利用者の指示): 本書(`Website/index.html`)の `card text-white bg-primary bg-gradient border-primary mb-4`
    (Sales Value)を写した。地図は本書と同じ jsVectorMap 1.5.3 を `src/admin/vendor/jsvectormap/` に置いた(CSP で CDN を読めない。中身は本書の SRI と同じことを check.php が見る)。
    小さなグラフは ApexCharts(style 属性を書くので CSP に掛かる)の代わりに SVG(`assets/js/visitors-world.js`)。
    国ごとの訪問 = 黄〜橙・BAN 中の IP の国 = 赤・国ごと拒否 = 黒。BAN は `scripts/host-stats.sh` が 1 分ごとに `run/hoststats/bans.json` へ
    (国の拒否の国と落とした数・fail2ban の牢ごとの IP と数・22 番の DROP)、`admin/api/visitor-bans.php` が組み直さずに渡す。
    BAN 中の IP が訪問の記録に出た回数と、回数制限(429)で断った訪問の数もカードに出す。
    jsVectorMap は読み込み中だと DOMContentLoaded まで描かないので、`onLoaded` で塗る
  - 日別・国・地域別・ページ別・世界地図のカードは折りたためる(AdminLTE の card-collapse)
  - **国の拒否の反転**(2026-10-08 の 4 回目、利用者の指示「特定の国以外は拒否」): `geoblock.local.conf` の `DENY_INVERT=yes` で、
    `DENY_COUNTRIES` に書いた国だけを通す(`ip saddr != @deny4`)。同時に、こちらからつないだ通信の戻り(`ct state established,related`)を
    国で落とさないようにした —— prerouting は戻りの包みも通るので、反転すると外国のサーバー(一覧の取得・更新・メールの送り先)との通信が戻らなくなる。
    Let's Encrypt の確認は海外からも 80 番に来るので、反転するなら `EXEMPT_PORTS=22 80`(無ければ apply が注意)。
    通す国の IPv4 の一覧が空なら当てない。IPv6 の一覧が無ければ外からの IPv6 は全部落とす。訪問者の地図は通す国以外を黒にする
  - 30 日で消す(cron 版 8)
  - 踏んだ罠 2 つ:
    - 書き先に変数を使うと、nginx は `root` のディレクトリがあるかを確かめる。無いと 1 行も書かない → `root /var/empty`
    - 置き場の持ち主を 101 にしたあとは、コンテナの root でも `chmod` できない(FOWNER が無い) → いったん root に戻してから付ける
- **ノードのクラウドバックアップ**(`api/map-backup.php`):
  - 管理者だけ・新しい方から 20 件
  - 置く → 一覧 → 戻す(SHA-256 で照合)→ 消す を確かめた
- **ストリートビュー**(`api/panorama.php`):
  - 写真は中身のハッシュで呼ぶ(一覧を受け取った人だけが取れる)
  - 配信には、配る地図の地点の分だけ載せる(チェックサムの外)
  - 管理版は地点から写真を足せる。簡単な 360° 表示(横ドラッグ)
  - 設定から事前に取れる(従量制の回線では取らない)。機内モードでも開けた
- **メモリを減らす**:
  - アプリの地図を段ごとに作り置きし、readfile で流す
    - 実測: 毎回組み立てると 16ms・+5.5MB → 作り置きを流すと 1ms・+0.5MB
    - 実際の口で: 1 回目 0.38 秒 → 2 回目から 0.036 秒
  - 公開の地図(E1)は**見送りを提案**: 表に更新時刻が無く、変わったことを確実に見分けられない
- **国単位のアクセス拒否**(`scripts/host-geoblock.sh`):
  - nftables の prerouting(-150)で落とす。設定 `geoblock.local.conf` はホストにだけ置く
  - 検証機の使い捨てのコンテナの中で、当てる・照合・当て直し・外すを確かめた。ホストに当てるのは利用者の sudo
- **文書**:
  - [17 Cloudflare の移行計画](17-cloudflare.ipynb)(書くだけ)
  - [18 写真から部屋の形を作る(調査)](18-room-shape-research.ipynb)
  - 04 に時刻表(版 8)・訪問者・国の拒否
- **本番に当てるには**(利用者):
  1. `free -h` で空きを見る
  2. `.\deploy-to-host.ps1 -Action up -Yes`(reverse-proxy と web の作り直しも入る)
  3. `sudo sh scripts/host-updates-setup.sh --fix`(cron 版 8)
  4. タスクマネージャーの IP のファイルを置いて reload
  5. (使うなら)`geoblock.local.conf` を置いて `host-geoblock.sh apply`

### 2026-10-06 — 現在地の測位の再構築(2026-10-06 夜、利用者の指示)

詳しくは [20](20-indoor-positioning.ipynb)。
- **形**: 全方式の候補 → 相互検証(外れ値・信頼度)→ 3D 空間(地図の壁・歩ける線)→ 確率分布 → 粒子フィルタ(本番)。EKF は比較用に並走。
  固定の割合で混ぜるのをやめた。「ハイブリッド法」はこの構成全体を指す(設定の値はそのまま)
- **方式**: 三辺測量 + 重み付き最小二乗(壁の数で補正)・AP 加重重心・指紋 + 加重 KNN・Wi-Fi RTT(対応端末と AP のとき)・
  PDR・AR 測位モード(任意。ARCore の Motion と Depth)。比較用に普通の三辺測量・KNN・単純重心・機械学習回帰(端末で学習)
- **パラメータ 29 項目**(2026-10-08 に `room_radius_m` を足した): 管理アプリで変える前に確認 → 全員に配る(`api/positioning-params.php`・表 `km_positioning_params`)。地図に内訳を表示
- **比較実験**: 評価の登録で A〜M と EKF を同時に記録。評価の画面に平均・RMSE・95%・最大・計算時間・ぶれ、CSV に構成ごとの行
- **確かめた**: JVM の試験(両フレーバー全件)・check.php・APK の組み立て。**実機はまだ**(LDPlayer: 表示と確認ダイアログ / Pixel 7a: RTT・AR・歩いての比較。許可が要る)
- **本番(利用者)**: サーバーの配備と nginx の読み直し・リリース版の APK の配布

### 2026-10-06 — 学習データはサーバーに置く

(同じ夜、利用者の指示): 学習 1 回ごとの記録を `api/learning.php` に置き、アプリが変わり分を落として端末で合わせる。それまで本番の学習データは 0 行で、一般のアプリに届いていなかった。評価も置く(管理アプリだけ)。[20](20-indoor-positioning.ipynb) §10
- **アプリの版は 1 → 2 → 3**(`app/version.properties`。リリース版の組み立てが成功したら上がる)。
Android が比べる内部の番号は **1000000 + 版**(2026-10-07。配っていた APK が日時の番号 400837 で、2 では上書き・自動更新ができなかった)。
**版 2 のリリース版を作った(2026-10-07)**: Android の `release/2/`(一般用 `KosenMap-2.apk`・管理用 `KosenMap-admin-2.apk`。内部 1000002・配布中と同じ鍵)。
配布は利用者: 管理画面「ダウンロード」で、版番号の欄は空け(APK から読む)、表示名に「2」

### 2026-10-06 — AR 実測

(同じ夜、利用者の指示「ARCore で地図の誤差を直す」): 管理アプリで地点に印を付けながら歩き、実測区間・北・動かす地点・壁の食い違いの候補を出す。
反映は管理者が選んで確認してから(元に戻せる)。計算は端末(`positioning/ArSurvey.kt`)。[20](20-indoor-positioning.ipynb) §12

### 2026-10-06 — AR の画像を保存

(同じ夜、利用者の決定): 「画像も保存」で撮った画像・姿勢・深度をサーバー(管理者だけ。`api/ar-capture.php`)へ。
管理画面「AR の撮影」で撮影ごとの zip(COLMAP の既知の姿勢つき)。**人が写ったら PC でぼかす**(`scripts/ar-blur-faces.ps1`。deface)。
毎日の控えには画像を入れない。[20](20-indoor-positioning.ipynb) §13
- **本物の MariaDB で確かめた**: 本番ホストの使い捨ての MariaDB 11.4(本番と切り離し・後片付け済み)で、学習・パラメータ 56 件と AR の置き場 31 件がすべて通過。[20](20-indoor-positioning.ipynb) §14

### 2026-10-06 — 診断(2026-10-05)の反映(2026-10-06、利用者の指示)

詳しくは [19](19-security-fixes-2026-10-06.ipynb)。
- **直した**:
  - W-54: 見取り図の口も、仮アカウントを確かめ直す。検証機で、取り消した後は 403
  - A-35: サーバーが断ったら、端末の氏名を落とす
  - A-35-offline(Pixel 7a の実機で見つけた): 停止の後に圏外で起動すると管理者で開いた → 起動時の復元が断られたら「オフラインで入る」の記録も消す
  - OPcache: 設定の書き換えをすぐ効かせる
  - 配布物の版番号: APK の中から読む
  - イメージの digest を更新(Logto は据え置き)
  - okio を 3.9.1 に
  - check.php: 組ごとに例外を受け止める
  - push 前の検査: メール検出を広げ、`-ScanAll` を直した
- **確かめた**:
  - Logto・Soketi の脆弱性の件数
  - JWKS の alg(ES384)
  - `X-Real-IP` の上書き
  - dash に pipefail は無い
  - かんばん・進捗表・予定表・地図の変換を読んだ(問題なし)
  - CRITICAL の CVE は、どれも当たらない条件だった
  - 実機(LDPlayer・Pixel 7a): 手順 A(取り込みで ranking は false のまま)・B(権限を外すと氏名 0)・C(停止中のトークン更新は 400 → 氏名 0)・A-31(「明日まで」)・自動アップデート(一般・管理)・オフラインで入る
- **利用者の決定(2026-10-06)**:
  - Logto 1.44.0 に上げる → compose を 1.44.0(digest 固定)に。検証機で移行・起動を確認。本番は docs/05 §5-2 の順(移行は戻せない)
  - 署名の鍵は入れ替えない(今のまま)
  - W-44c は今のまま・W-52c は運用(権限を外したら地図の版を上げる)・E1 は見送り
  - W-45 は承認制: 管理画面「ランキングの語」で選んで、毎月「今月の一覧を公開」した語だけが公開の一覧に出る
- **本番に反映した(2026-10-06 16:00 ごろ。利用者の指示で Claude が実行・sudo を除く。検証機が応答しなくなったため)**:
  - 控え → Logto 1.44.0(移行 2 つ)→ 像の更新 → web・soketi の作り直し → 全サービス healthy・自己検査 1160 件。手順と確認は [19](19-security-fixes-2026-10-06.ipynb) §4
  - **`-Action up` は像を作り直さない**(`docker compose build --pull web soketi` が別に要る)
- **残り(利用者)**:
  - ~~W-55~~ **済(2026-10-08)**: GitHub Support が PR #1〜#6 と参照の無いコミットを消した。書き換え前の 8 本が引けない(422)ことを確かめた
  - ~~sudo: `host-updates-setup.sh --fix`(cron 版 8)・(使うなら)geoblock~~ **済**(11-07 には cron 版 13)
  - ~~本番で動いたままの Mailpit を片付けるか~~ **済**(11-07 の本番で mailpit は動いていない)
  - ~~A-35-offline を直した APK の配布(署名)~~ **済**(その後の版を受け箱から配っている。11-07 は版 50)

### 2026-10-05 — 2026-10-05 夜の依頼のうち、済んだもの(利用者の指示)

- **スクリプト**: 既定でプルリクエストの説明を作らない(`-PullRequest` で作る)。`*.local.conf` を GitHub の控えへ写さない
- **管理画面**:
  - お知らせを ✗ で閉じる
  - 「次フェーズの作業」をかんばんから取る
  - 地図の配信の字を直す
  - ファイルの共有リンク(`/share.php`)
  - チャート: 一番高い棒の数字が切れるのを直し、検索語と場所を足す
  - お試しリンクの「有効・無効」「表の読み取り専用」
  - 「マップへ」ボタン
- **Docker**:
  - ログの回転(10MB × 3)
  - スワップの規則: nginx・MariaDB・web・Postgres・Soketi はメモリのみ。ほかは 2 倍まで
  - OOM の順
  - Apache の `/server-status`(中からだけ)
- **タスクマネージャー**(`/admin/taskmgr.php`):
  - 中身: Glances(ホスト全体だけ・`monitor` の網・約 80MB)+ `host-stats.sh`(コンテナ別。cron 版 7・1 分ごと)
  - 変換はブラウザで行う
  - **IP の制限は `nginx/km/taskmgr-allow.local.conf`(ホストにだけ置く)。無ければ誰も入れない**
- **使用率の警告**: `host-resource-alert.sh`(cron 版 7・5 分ごと)。検証機の Mailpit に届くのを確かめた
- **本番に当てるには**(利用者):
  1. `free -h` で空きを見る
  2. `.\deploy-to-host.ps1 -Action up -Yes`
  3. web の作り直し(Dockerfile を変えた)
  4. `sudo sh scripts/host-updates-setup.sh --fix`(cron 版 7)
  5. タスクマネージャーの IP のファイルを置いて reload
- **残り** → 2026-10-06 に済ませた(次の節)

### 2026-10-05 — LAN の検証機に機密なしのコピー(2026-10-05、利用者の指示)

- 本番の配備は利用者が済ませた(15 項目)。続けて LAN の検証機に**機密なしのコピー**を立てた(名前は sslip.io、`KM_ENV=local`)
- 持ち込んだのは **MariaDB だけ**(2026-10-04 の控え)。`.env`・`config/*.local.php`・Logto・`uploads/` は持ち込まない
- 秘密は `host-local.sh init --fresh-secrets` で新しく作った(空のキーだけを乱数で埋める)
- 取り込んだ直後に `scripts/local-sanitize.sh` を流した:
  - 氏名 → 「教員A001」の連番。地点のメモ → 空
  - 記録・申請・利用者の表など 24 表 → 空
  - FAQ のメール → ダミー
- 手元の氏名一覧とメールの形で突き合わせて **0 件**
- 8 サービスが healthy、再起動は 0 回
- 残り:
  - 空の Logto の初期設定(Console は利用者)
  - 地図の配信の発行
  - 手順は docs/13 §4-2。check の組 `local-copy`

### 2026-10-05 — アプリの 15 項目(2026-10-05、利用者の指示)

- **北と距離の補正を全員の端末に配る**: 新しい表 `km_map_calibration`(初回に自動で作る)・`api/map-calibration.php`(書きは管理者のトークン)。
  地図の配信(`api/app-map.php`)に `calibration` を載せる(重みと同じくチェックサムの外)。check の組 `map-calibration`
- **アプリの自動アップデート**: 配布物に管理用の枠 `apk_admin`(ダウンロードに管理者のトークンが要る)。APK は**版番号が必須**、SHA-256 はサーバーで取る
  (`km_distributables` に列 `version_code`・`sha256` を初回に足す)。`api/app-update.php?flavor=visitor|admin`。check の組 `app-update`。
  アプリは版番号(ビルドの日時から作る)が大きいときだけ知らせ、SHA-256 と**署名が同じか**を確かめてからインストール画面を出す
- nginx: `map-calibration`・`app-update` を km_api の絞りに入れた(配備で nginx を読み直す)
- 見送り: ストリートビュー(別の計画)、証明書の検査を緩める項目(入れない)

### 2026-10-02 — 1 台のルーターの複数 BSSID(2026-10-02、利用者の指示)

- アプリはルーターの地点に BSSID を**カンマ区切りで複数**送る。`km_map_nodes.bssid` は 32 字(1 件)だったので、
  地図の取り込み(`km_app_map_sync_apply`)の前に **255 字へ自動で広げる**(取引の前。広げられなければ入るだけの件数に切って取り込みは止めない)。
  `scripts/migrate-map-app-schema.sql` も 255 字に。check の組 `router-bssids`

---

## 記録 2026 年 9 月(新しい順)

### 2026-09-30 — お試しの閲覧リンク・受信テスト(2026-09-30、利用者の指示)

- **お試しの閲覧リンク**(管理画面「お試しの閲覧リンク」`admin/guest-links.php`・`lib/map-guest.php`・公開側 `guest.php`)。
  期限(1/3/7/14 日)と台数を決めて発行し、開いてボタンを押したブラウザだけが期限まで**教職員と同じ範囲**(地図の錠を通る・教職員氏名)を見られる。
  リンクは発行した画面で 1 回だけ見せ、表には要約(sha256)だけ。GET では使わない(メッセージアプリの下見対策)。取り消すと次に地図を開いたときに外れる。
  教職員氏名が「常に隠す」のときは、管理画面の「教職員には見せる」を ON にしないと出ない(画面に注意を出す)。
  nginx に `location = /guest.php`(クエリをログに残さない・no-referrer・回数制限)を足した → **配備で nginx も入れ替わる**。表は初回に自動で作られる
- **Android の Wi-Fi 学習:**
  - 標高が空だった原因は、管理マップが設定「補助情報の使用」(既定 OFF)のときしか気圧センサーを読んでいなかったこと。学習では設定に関係なく読むようにした。GPS の標高は階の見分けに使えない精度なので記録しない
  - 「登録できるWi-Fi観測データがありません」は、地点を選ぶと電波の平均を空にするのに、次のスキャンが最大 30 秒後だったため。
    選んだらすぐ 1 回スキャンし、1 回届くまで登録ボタンを「スキャン待ち」にした。Android の回数制限が外れている端末では、アプリの 30 秒の下限も外す
  - **受信テスト**(設定 → システム → 受信テスト。初めて権限がそろったときは自動): 権限・位置情報・Wi-Fi、Android のスキャン制限(設定値と実測 5 回)、
    スキャン結果が学習に使えるか、気圧(標高)、GPS(標高・海抜・速さ・衛星)、動きのセンサー。結果はコピーできる

### 2026-09-30 — 仮アカウントと、気圧の階の判定(2026-09-30 の 2 回目、利用者の指示)

- **お試しの閲覧は仮アカウントで入る。** リンクを開いた人が名前(と所属)を入れて仮アカウントを作る(**MariaDB の `km_map_guest_accounts` にだけ**。Logto には作らない)。
  「使える台数」は作れる仮アカウントの数。作った画面で**再入場コード**(8 文字)を 1 回だけ見せ、同じリンクとコードで入り直せる(台数は減らない。総当たりは錠 `guest` で絞る)。
  管理画面に、誰が・いつ作り・最後にいつ見たかが出て、人ごとに止められる。名前と所属はリンクの期限の 30 日後に消える(ポリシー 8 節)。表は初回に自動で作られる
- **気圧の階の判定を、その日の最初の地点との差で見る**(Android、`FloorAltitudeAnchor.kt`)。
  基準はその日の最初に階が確かに分かった地点(Wi-Fi で階が決まったとき・学習の登録)。そのあと Wi-Fi で階が確かめられるたびに引き継ぎ、2 時間確かめられなければ使わない。
  階の高さは学習データの「同じ日の中の階の差」から測る(日をまたいだ絶対値は混ぜない)。学習の登録で、別の日の標高は平均せずに置き換える。
  ルーターの地点の無い地図でも、学習データの近さで Wi-Fi から階を決めるようにした

### 2026-09-30 — 仮アカウントの教員名は人ごとに許す(2026-09-30 の 3 回目、利用者の指示)

- 仮アカウントを作っただけでは**地図の錠を通るだけ**。教員名は、管理画面の一覧で「教員名を見せる」にした仮アカウントだけに出る(列 `names_allowed`、既定 0)。
  既にある表には初回に列を足す。許す・隠すは、相手が地図を開き直したときに効く(`km_map_guest_verify` が表から読み直す)。記録は `guest.account_names_allow` / `_deny`(番号だけ)
- 配備先の check で `nginx に /guest.php の location がある` が FAIL になっていたのを直した(配備先には `nginx/` が無いので SKIP)

### 2026-09-25 — 公開ページの操作と SSH ログインの知らせ(2026-09-25、利用者の指示)

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

### 2026-09-25 — 経路の条件と重み(2026-09-25、利用者の指示)

| 条件 | 扱い | 中身 |
|---|---|---|
| A 屋内優先 | **既定** | 出入り 1 回 3m → 15m、屋外の道 1.3 倍。中に道があれば中を通る |
| B 部屋を通り抜けない | **既定** | 部屋は出発地・目的地のときだけ。それで道が無くなれば通り抜けを許す(経路を消さない) |
| C 階の移動を減らす | 利用者の設定 | 1 層ぶんの重み × 2。Website は検索パネル、アプリは設定の「マップ設定」 |
| D 雨の日 | 利用者の設定 | 屋外の道をさらに × 3 |

重みの表は **Website `Main/dijkstra.js` の `KM_ROUTE_WEIGHTS` と アプリ `RouteSearch.kt` の `RouteWeights` の2か所**で、
`check.php` の `route-weights` が値を突き合わせる。**管理アプリでは設定「経路の重み(管理)」で値を変えて試せる**
(管理ビルドの管理者だけに出る。一般ビルドは手元の値を読まない)。

**一般の既定として配る道(2026-09-25 に作った):**

```
管理アプリ(設定「この重みを一般の既定として配る」)
  └─ POST api/route-weights.php(管理者のトークン。タイムラインに残る)─▶ km_settings 'route_weights'
        ├─▶ Website: lib/map-data.php が routeWeights(画素に直す)を載せる → すぐ効く
        └─▶ アプリ: api/app-map.php の応答(地図が最新でも)に routeWeights → 次に「マップを取得/更新」したとき
```

- 単位はアプリと同じ(メートルと倍率)で 1 か所に持つ(`lib/route-weights.php`)。範囲外・型違いは断る(倍率は 1 以上)
- **配っていなければ何も載せず**、各自の既定で動く。管理アプリの「配るのをやめる」で戻せる
- 読み(GET)は誰でもできる(重みは秘密ではない)。nginx の km_api で回数を絞る
- 一般ビルドは配られた重みだけを使う(手元の値は読まない)。管理ビルドは手元の値が勝つ(「配っている重みを読み込む」で揃えられる)
- 地図の版は上げなくてよい(重みは地図の外に載せ、チェックサムの対象外)

**本番でやること:** 配備だけ(表は km_settings を使うので作らない)。確かめ方: `https://ito4.jp/api/route-weights.php` を開くと、いま配っている値が見える。

### 2026-09-25 — 管理マップの建物の階(2026-09-25)

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

### 2026-09-25 — 診断(2026-09-25)の所見を直した

[16-security-review-2026-09-25](16-security-review-2026-09-25.ipynb) の W-44〜W-53 と、Android の報告書の A-29〜A-34 を同じ日に直した(**§5.1 が一覧の正本**)。
check.php 1449 件・JS・Android 627 件×2 すべて通過。**本番はまだ**(配備と、§5.1 の「本番で確かめること」は利用者が行う)。

- **公開リポジトリの実名と回線の IP を消した**(W-53・W-47)。push 前の検査(`push-github.ps1`)が、公開の IPv4 と、
  リポジトリの外の氏名の一覧(`~\.kosenmap\private-names.txt`。`update-private-names.ps1` がダンプから作る)と照合して止める。
  `-CheckOnly -ScanAll` で全ファイルを見られる。**履歴の書き換えは force push の前に利用者へ確かめる**
- ランキング: 重複・実在しない地点を数えない、語の表に上限、管理画面「ランキングの語」で語を外せる(W-44・W-45)
- 地図のパスワードを替えたら、前の解除は効かない(W-46)。**配備のあと、解除済みの人も一度だけ入れ直し**
- 退会した人の ID を監査ログの detail とチャットからも消す(W-48)。IP の保存期限: 監査ログ 90 日・解除の試行 1 日(W-49、`lib/privacy-retention.php`)。
  プライバシーポリシーの 8 節を直し、改定日を 2026-09-25 にした
- 期限の切れた ID トークンは取り直してから教職員を判定する(W-50・W-51)。**運用: 取り消しは管理画面で、Console で組織から直接外さない**(docs/15 §4)
- 配信に中身の段(`contentLevel`)を載せ、アプリの `haveLevel` と違えば同じ版でも送り直す(W-52・A-32)

### 2026-09-24 — 建物の平面図は「1 枚 = 1 つの階」へ(2026-09-24、利用者の指示)

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

### 2026-09-22 — 地図まわりの追加(2026-09-22、利用者の要望)

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

---

## 2026-09-02 時点の記録(以下)

**この文書は「いまどうなっているか」の記録**。手順は取扱説明書(`docs/*.ipynb`)と、以前の手順書(`Old/docs/`)に
あり、ここでは**状態と残作業**だけを書く。

> **§1〜§5 の数字は 2026-08-28 にホストで実測した値**(それ以降は測り直していない)。
> §6 の残作業だけを 2026-09-02 に見直した。**残りの全体像は `plan.md` の
> §残っていること** —— 会期後でよいもの(第4段)も含めてそちらに集めてある。
> **ドメインと利用者は上の「いま」を見ること**(この節の `ito8795.com`・`km` は当時の値)。

---

### 一行で

**本番 `https://ito8795.com` は10サービスすべて healthy で稼働中。**
メールは送信サーバーまで組み上がり、**逆引き(PTR)だけが未解決**。
管理画面はこの日に全面停止したが、**復旧済み**。

---

### 1. 本番(163.43.218.158 / `/opt/kosenmap`)

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

#### 公開しているポート

`reverse-proxy` だけが外に出ている。**80 / 443 / 3001 / 3002 / 6001 / 8025 / 8281。**

- `3001`(Logto)と `6001`(Soketi)は**利用者のブラウザが直接つなぐので必須**
- `3002` / `8025` / `8281`(Logto Console / Mailpit / phpMyAdmin)は
  **nginx 側の IP 制限 + Logto のログイン判定**の後ろ。判定は `proxy_pass` の手前
- **`3306` は公開していない。** `mailserver` の `587` も公開していない

---

### 2. 認証(Logto)

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

#### ~~`staff:event:access` が存在しない~~ → 追加済み(2026-09-02)

コードには運営スタッフ区分がある(`LOGTO_STAFF_PERMISSION`)。イベントの通行止めを
通過でき、スタッフ限定の地点を見られる。**スコープは `https://ito8795.com/api` へ
登録済み。**`read:organization` / `read:user` も同じリソースに入った。

> **`read:organization` と `read:user` は Management API には効かない。**
> それらは独自リソースのスコープで、**Management API は別のリソース**(下記)。
> ここを取り違えて 2026-08-28 に管理画面を全面停止させた。
> **「読み取り専用の m2m ができた」と読まないこと。**

#### 「読み取り専用の m2m」は Logto では作れない

Management API リソースのスコープは **`all` の1つだけ**で、読み取りだけを切り出せない。
独自リソースに `read:user` を置いても、**それは別のリソースなので Management API では 403**。

そのため `LOGTO_M2M_READONLY_*` は**空**で運用している(空なら `default` に落ちる)。
`kosenmap-readonly` のアプリ・ロール・リソースは**削除済み**。

---

### 3. この日に起きた障害(記録)

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

### 4. メール

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

#### 判定基準(先に決めてある)

次のどれかなら粘らず**送信サービスへ移る**。切り替えは `.env` の数行と Logto の設定だけ。

- 逆引きを設定できない / Gmail で迷惑メール扱い / SPF・DKIM・DMARC のどれかが pass しない
- mail-tester が 8 点未満

---

### 5. 検証環境(192.168.3.29 / `C:\dev\windows-dev-stack`)

校内 LAN。8サービス稼働、メールは **Mailpit のまま**(外へ配送しない)。
本番とは独立していることを確認済み。

**到達性(Gmail に届くか)はここでは測れない。** 送信元 IP も逆引きも本番と別物。

---

### 6. 残作業

| # | 内容 | 状態 |
|---|---|---|
| 1 | DKIM の TXT 登録 | **完了** |
| 2 | ~~逆引き(PTR)~~ | **不要と判明**(2026-08-29)。**PTR 未設定のまま Gmail に届き、迷惑メールにも入らなかった。** `mail-server.md` の判定基準「逆引きを設定できないなら送信サービスへ移る」は発動しない |
| 3 | Logto の後片付け・停止アカウント確認 | **完了** |
| 4 | 本番配備の許可 | **決着**(配備は利用者が実行する。下記) |
| 5 | 人数集計の作りの判断 | **決着・実装済み**(2026-08-29) |
| 6 | Android 再ビルド(`resource = https://ito8795.com/api`) | **コード変更は不要**(下記) |
| 7 | **配信中の地図** | **済み(以後の作りで置き換え。2026-11-07 に見直し)**: 配信の版・期限は管理画面から上げる(11-02 の版 48「地図の配信の版も簡単に」・期限は会期の終わりに合わせる)。以下は当時の記録 —— **要やり直し。**手元は `revision 10` / 期限 `2026-09-29` に更新済みだが、**その実行は `-UploadToHost` の欠陥を直す前**なので本番へ届いたか不明。切れたことが見えるよう管理画面には出してある(下記) |
| 8 | Logto に `staff:event:access` が無い | **完了**(2026-09-02)。`read:organization` / `read:user` も追加された。**ただしその2つは Management API には効かない** —— 独自リソース `https://ito8795.com/api` のスコープであり、Management API は `all` 1つしか持たない別のリソース(§2) |
| 9 | 1.0.5 の変更一式の配備 | **配備済み**(2026-09-03)。`host-setup.ps1 -Fix` まで完走し、所有権・PHP からの読み書き・見取り図6枚・自己検査(380件)・`nginx -t` すべて OK |
| 10 | Logto の版 | **`1.42.0` に固定済み。**`docker compose ps` で確認(`ghcr.io/logto-io/logto:1.42.0`、healthy) |

#### 配信中の地図について(#7)

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

#### 1.0.5 で溜まっている変更(#9)

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

#### 決着した設計判断(#5)

**公開の口から Logto を切り離した。**
`km_user_stats_directory()` に `$mayRefresh` を足し、`api/app-stats.php` は
**ログイン済みの POST のときだけ**更新を許す。未ログインはキャッシュを読むだけで、
切れていても Logto を叩かず `stale = true` を返す。応答に `refreshedAt` も足した。

読み取り専用の m2m を作る案は**Logto の仕様上できない**(Management API のスコープは
`all` の1つだけ)。権限ではなく**経路**で分けたので、readonly 資格情報より強い。
併せて組織一覧の二重取得を1回にまとめた。

#### #6 について

`app/build.gradle.kts:43` は既に `LOGTO_API_RESOURCE = "https://ito8795.com/api"`。
**残っているのは再ビルドと実機確認だけ**で、コードを直す作業ではない。

#### 配備について(#4)

**決着:配備は利用者が実行する。** `deploy-to-host.ps1` は自動許可の対象外で、
そこを緩めるより「コマンドを示して止まる」方を運用の既定にした。
溜まっている変更は #9 を参照。

---

### 7. 測り方の落とし穴(実際に踏んだもの)

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