# Ichinoseki_Kosen_Web — 高専マップ案内(Website とサーバー)

一関工業高等専門学校の校内案内地図の **Website・管理画面・API・サーバー構成**です。
公開ページ(地図と経路案内)、管理画面(地図の編集・利用者・配信)、Android アプリ向けの API、
それらを動かす Docker Compose 一式と運用の道具を含みます。

> Android アプリは別リポジトリ(`Ichinoseki_Kosen`)。地図の見せ方と経路の規則は両方で同じにしてあり、
> `server/src/scripts/check.php` がアプリの原本と突き合わせる箇所があります。

## 構成

```
server/
  src/            PHP 8(公開ページ・admin/・api/・lib/)と地図の JavaScript(Main/)
    scripts/      自己検査 check.php・JavaScript の検査(js/)・移行や運用の PHP
  docker/         コンテナの設定(PHP・MariaDB・phpMyAdmin など)
  nginx/          リバースプロキシと TLS の設定
  scripts/        ホストで使うシェル・PowerShell(配備・バックアップ・証明書・SSH の知らせ)
  docs/           取扱説明書(Jupyter Notebook)と状態の記録(status.md)
  compose.yaml    本体。compose.vps.yaml(本番)・compose.local.yaml(検証機)を重ねる
  .env.example    設定の見本。**本物の .env はこのリポジトリに入れない**
tools/            この控えを作る・出す道具
```

主な中身:

| 機能 | 場所 |
|---|---|
| 地図・経路案内(Leaflet)・表示調整 | `src/index.php` / `src/Main/` |
| 管理画面(AdminLTE)・地図の編集・教職員の担当地点の承認 | `src/admin/` |
| ログイン(Logto / OIDC)・組織ロール | `src/logto-client.php` / `src/lib/staff-org.php` |
| アプリ向けの配信・ランキング・統計 | `src/api/` |
| ホストの定期作業(更新確認・セキュリティ点検・バックアップ・証明書) | `scripts/host-*.sh` |
| SSH ログインの知らせ・切断と BAN | `scripts/ssh-login-notify*.sh` / `scripts/ssh-kick.sh` |

## 検査

```powershell
php server/src/scripts/check.php                 # PHP の自己検査(DB も Logto も要らない)
pwsh -File server/src/scripts/js/run-all.ps1     # 地図の JavaScript(node)
```

`src/vendor/` は入れていません。動かすときは `server/src` で `composer install`(`composer.lock` どおりに戻る)。

## この控えについて

- 正本は手元の作業フォルダ。`tools/sync-from-website.ps1` で `server/` へ写します
- **写さないもの:** `.env`・`*.local.php`・鍵と証明書・`src/uploads/`(教職員氏名を含みうる)・バックアップ・
  旧サイト(`Downloaded/`)・退避(`Old/`)・ログ
- **Notebook は実行結果を消して写します**(本番で走らせた結果にメールアドレスなどが残るため)
- 出すときは `tools/push-github.ps1`。出してはいけない名前・直書きの資格情報を検査し、当たったら止まります

```powershell
pwsh -File tools\sync-from-website.ps1
pwsh -File tools\push-github.ps1 -Message "変更の説明"
pwsh -File tools\push-github.ps1 -CheckOnly    # 検査だけ(出さない)
```

`push-github.ps1` は Android 側の控えと**同じ中身のファイル**です(**全部 stage してから**検査する)。

プルリクエストの説明は `docs/pull-requests/` に Markdown で残します。今日の説明が無ければ下書きを作り
(開いている PR の説明があればそこへ一言足し)、push のあとに開きます。

### 両方まとめて

```powershell
pwsh -File server\scripts\push-github-all.ps1 -Message "変更の説明"   # Android と Website を写して出す
pwsh -File server\scripts\push-github-all.ps1 -CheckOnly             # 写して検査するだけ
```

配備(`server\scripts\deploy-to-host.ps1`)も、**配備が済んだあとにこれを呼びます**(`-GitHub all|web|android|none`、既定は all)。
GitHub へ出せなくても配備の結果は変わりません。

## ライセンス

[GNU General Public License v3.0](LICENSE)

同梱しているサードパーティのもの(Leaflet・AdminLTE・composer の依存など)は、それぞれのライセンスに従います。
