-- Logto の Postgres まわりの手当て(2026-09-15)。docs/12-hardening-2026-09-15.ipynb のセルがここを読む。
--
-- 2 つの塊があり、**印の行(-- KM-…-BEGIN / -END)で切り出して**使う。印の名前を変えないこと。
-- どちらも Postgres の初期利用者(POSTGRES_USER = SUPERUSER)で流す。
--
--   KM-LOGTO-ROLE     … Logto 用の SUPERUSER でない利用者 logto_app を作り(あればパスワードを替え)、
--                       Logto の DB の持ち物を全部 logto_app へ移す。psql に -v pw=<英数字> を渡す
--   KM-ADMIN-POLICY   … Console(admin テナント)のパスワード方針と総当たりロックを default テナントに揃える
--
-- **何度流しても同じ結果になる。** 控えから戻したあと(持ち主が初期利用者に戻る)にもう一度流せばよい。

-- KM-LOGTO-ROLE-BEGIN
\set ON_ERROR_STOP on
--
-- ## なぜ
--
-- 以前は Logto が SUPERUSER(POSTGRES_USER)で繋いでいた。Logto に SQL を差し込める穴が 1 つ出れば
-- `COPY … TO PROGRAM` でコンテナの中のコマンドまで、`pg_read_file` でファイルまで届いた
-- (security-review-2026-09-14 の W-31・§7 の 3)。
--
-- ## どう動くのか
--
-- Logto のテーブルは RLS(行ごとの権限)が有効で、ポリシーは logto_tenant_* のロールに向いている。
-- **テーブルの持ち主は RLS を受けない**(FORCE ROW LEVEL SECURITY でない限り)。だから logto_app を
-- 全テーブルの持ち主にすれば、SUPERUSER でなくても今までと同じに読み書きできる。
-- 起動時の移行(alteration)が作る表・ポリシー・権限の付与も、持ち主なら通る。
--
-- 使い捨ての環境で本番の写しに流し、Logto 1.43.0 が起動して OIDC の設定を返すことを確かめた(2026-09-15)。
BEGIN;

SELECT NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'logto_app') AS km_need_role \gset
\if :km_need_role
CREATE ROLE logto_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'pw';
\else
ALTER ROLE logto_app WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'pw';
\endif

DO $$
DECLARE
    r record;
    forced int;
BEGIN
    EXECUTE format('ALTER DATABASE %I OWNER TO logto_app', current_database());
    ALTER SCHEMA public OWNER TO logto_app;

    -- 表・ビュー。**列に紐づいた連番は表と一緒に移る**ので、ここでは連番を扱わない
    FOR r IN
        SELECT c.oid::regclass AS name, c.relkind
        FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
          AND pg_get_userbyid(c.relowner) <> 'logto_app'
    LOOP
        EXECUTE format('ALTER %s %s OWNER TO logto_app',
            CASE r.relkind WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW' WHEN 'f' THEN 'FOREIGN TABLE' ELSE 'TABLE' END,
            r.name);
    END LOOP;

    -- 表に紐づいていない連番
    FOR r IN
        SELECT c.oid::regclass AS name
        FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind = 'S'
          AND pg_get_userbyid(c.relowner) <> 'logto_app'
          AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = c.oid AND d.deptype IN ('a', 'i'))
    LOOP
        EXECUTE format('ALTER SEQUENCE %s OWNER TO logto_app', r.name);
    END LOOP;

    -- 関数(トリガーの本体を含む)
    FOR r IN
        SELECT p.oid::regprocedure AS sig
        FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public' AND pg_get_userbyid(p.proowner) <> 'logto_app'
    LOOP
        EXECUTE format('ALTER ROUTINE %s OWNER TO logto_app', r.sig);
    END LOOP;

    -- 列挙型・ドメイン・単独の複合型(表の行の型と配列の型は表と一緒に移る)
    FOR r IN
        SELECT t.oid::regtype AS name, t.typtype
        FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
        WHERE n.nspname = 'public' AND t.typtype IN ('e', 'd', 'c') AND t.typelem = 0
          AND (t.typrelid = 0 OR (SELECT relkind FROM pg_class WHERE oid = t.typrelid) = 'c')
          AND pg_get_userbyid(t.typowner) <> 'logto_app'
    LOOP
        EXECUTE format('ALTER %s %s OWNER TO logto_app', CASE r.typtype WHEN 'd' THEN 'DOMAIN' ELSE 'TYPE' END, r.name);
    END LOOP;

    -- **FORCE の表があると、持ち主でも RLS を受けて何も読めなくなる。** そのときだけ BYPASSRLS を足す
    -- (SUPERUSER とは違い、コマンドの実行やファイルの読み書きには届かない)。1.43.0 の本番の写しでは 0 件だった
    SELECT count(*) INTO forced
    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public' AND c.relforcerowsecurity;
    IF forced > 0 THEN
        ALTER ROLE logto_app BYPASSRLS;
        RAISE NOTICE 'FORCE ROW LEVEL SECURITY の表が % 個あるので、logto_app に BYPASSRLS を付けました', forced;
    END IF;
END
$$;

COMMIT;

SELECT 'logto_app: super=' || rolsuper || ' createrole=' || rolcreaterole || ' createdb=' || rolcreatedb || ' bypassrls=' || rolbypassrls AS "利用者"
FROM pg_roles WHERE rolname = 'logto_app';
SELECT count(*) AS "持ち主が logto_app でない表"
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'S') AND pg_get_userbyid(c.relowner) <> 'logto_app';
-- KM-LOGTO-ROLE-END

-- KM-ADMIN-POLICY-BEGIN
\set ON_ERROR_STOP on
--
-- Console(admin テナント)は MFA こそ Mandatory だが、**パスワード方針も総当たりロックも空**だった
-- (security-review-2026-09-14 の W-01。2026-09-15 に本番で確認)。default テナントの値をそのまま写す:
--   パスワード … 12〜256 文字・漏えい照合・利用者情報と連番を断る
--   ロック     … 5 回失敗で 60 秒
-- 方針は**次にパスワードを決めるとき**から効く(今のパスワードはそのまま使える)。
UPDATE sign_in_experiences AS a
SET password_policy = d.password_policy,
    sentinel_policy = d.sentinel_policy
FROM sign_in_experiences AS d
WHERE a.tenant_id = 'admin' AND d.tenant_id = 'default';

SELECT tenant_id, mfa::text AS mfa, password_policy::text AS password_policy, sentinel_policy::text AS sentinel_policy
FROM sign_in_experiences ORDER BY tenant_id;
-- KM-ADMIN-POLICY-END
