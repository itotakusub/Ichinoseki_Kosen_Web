<?php

declare(strict_types=1);

/**
 * 汎用テーブル管理機能(admin/tables.php, admin/table-data.php)向けのライブラリ。
 *
 * 安全柵(フェーズ2/3計画で決めたものをそのまま実装):
 *   - 作成・削除・レコード操作の対象は `kmt_` で始まる名前のテーブルのみ
 *     (km_map_* 等の既存システムテーブルには一切触れない)
 *   - テーブル名・カラム名は db.php の km_is_valid_identifier() を通す
 *   - カラム型は許可リストのみ(自由な SQL 断片を受け付けない)
 *   - 削除は DROP せず、削除済みテーブル名を記録する小さなメタテーブルに記録するだけにして
 *     一覧・操作の対象から外す(「論理削除」。実削除は phpMyAdmin で明示的に DROP する運用)
 *   - レコード操作のカラム名は information_schema から取得した実在カラムとの
 *     突き合わせでのみ許可する(ユーザー入力のカラム名を直接 SQL に埋め込まない)
 *
 * テーブル名・カラム名は PDO のプレースホルダで束縛できない(識別子は値ではないため)。
 * そのため、上記の検証を通した文字列だけをバッククォートで囲んで埋め込む。
 *
 * 削除に RENAME TABLE (`kmt_trash_<日時>_<元の名前>` へ退避)を使わない理由:
 * MySQL/MariaDB の RENAME TABLE は「元のテーブルへの ALTER・DROP 権限」と「新しい名前への
 * CREATE・INSERT 権限」の両方を要求する。DB ユーザー `Main` には ALTER・DROP が付与されて
 * おらず(SELECT/INSERT/UPDATE/CREATE/DELETE のみ)、実際に本番DBで試したところ
 * "DROP, ALTER command denied" で失敗した。ALTER・DROP を新たに付与してもらう案もあるが、
 * 万一アプリ側にバグがあっても DB ユーザー自体が構造変更・削除を一切できない方が安全
 * (縦深防御)。そのため実テーブルには触れず、`km_table_manage_deleted` という小さな
 * メタテーブルに「削除済み」の印を付けるだけにした。物理的な DROP は今までどおり
 * phpMyAdmin から手動で行う。
 */

const KM_TABLE_PREFIX = 'kmt_';

/** 論理削除の印を記録するメタテーブル(km_ 接頭辞 = システムテーブル、ユーザー非公開)。 */
const KM_TABLE_META_TABLE = 'km_table_manage_deleted';

/** ユーザーが作成・削除・編集できる対象かどうか(接頭辞 + 識別子の形式)。 */
function km_table_is_managed_name(string $name): bool
{
    return km_is_valid_identifier($name) && str_starts_with($name, KM_TABLE_PREFIX);
}

function km_table_manage_ensure_meta_table(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS km_table_manage_deleted (
            table_name VARCHAR(41) NOT NULL PRIMARY KEY,
            deleted_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

function km_table_is_deleted(PDO $pdo, string $name): bool
{
    km_table_manage_ensure_meta_table($pdo);
    $stmt = $pdo->prepare('SELECT 1 FROM km_table_manage_deleted WHERE table_name = ?');
    $stmt->execute([$name]);

    return $stmt->fetchColumn() !== false;
}

/** カラム型の指定を許可リストと突き合わせ、正規化した SQL 型名を返す。不正なら例外。 */
function km_table_normalize_type(string $type): string
{
    $type = trim($type);

    if (preg_match('/^(INT|BIGINT|TEXT|DATE|DATETIME|BOOLEAN)$/i', $type, $m) === 1) {
        return strtoupper($m[1]);
    }
    if (preg_match('/^VARCHAR\((\d{1,3})\)$/i', $type, $m) === 1) {
        $length = (int) $m[1];
        if ($length < 1 || $length > 255) {
            throw new InvalidArgumentException('VARCHAR の長さは 1〜255 で指定してください。');
        }
        return "VARCHAR({$length})";
    }
    if (preg_match('/^DECIMAL\((\d{1,2}),(\d{1,2})\)$/i', $type, $m) === 1) {
        $precision = (int) $m[1];
        $scale = (int) $m[2];
        if ($precision < 1 || $precision > 30 || $scale < 0 || $scale > $precision) {
            throw new InvalidArgumentException('DECIMAL の桁数指定が不正です。');
        }
        return "DECIMAL({$precision},{$scale})";
    }

    throw new InvalidArgumentException("許可されていない型です: {$type}");
}

/** 1カラム分の CREATE TABLE 断片("`name` TYPE [NOT NULL]")を作る。不正なら例外。 */
function km_table_build_column_sql(string $name, string $type, bool $nullable): string
{
    if (!km_is_valid_identifier($name)) {
        throw new InvalidArgumentException("不正なカラム名です: {$name}");
    }
    if (strtolower($name) === 'id') {
        throw new InvalidArgumentException("'id' は自動採番の主キーとして予約されています。");
    }

    $sqlType = km_table_normalize_type($type);
    $nullSql = $nullable ? '' : ' NOT NULL';

    return sprintf('`%s` %s%s', $name, $sqlType, $nullSql);
}

function km_table_exists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$name]);
    return $stmt->fetchColumn() !== false;
}

/** kmt_ テーブルの一覧(行数概算・エンジン・更新日時つき)。論理削除済みは除く。 */
function km_table_list(PDO $pdo): array
{
    km_table_manage_ensure_meta_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, ENGINE AS engine, UPDATE_TIME AS updated_at
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?
         ORDER BY TABLE_NAME'
    );
    // "_" は LIKE のワイルドカードなので、ここでは粗く絞ってから
    // km_table_is_managed_name() で厳密に絞り直す。
    $stmt->execute([KM_TABLE_PREFIX . '%']);
    $rows = array_values(array_filter(
        $stmt->fetchAll(),
        static fn (array $row): bool => km_table_is_managed_name((string) $row['name'])
    ));

    $deletedNames = $pdo->query('SELECT table_name FROM km_table_manage_deleted')->fetchAll(PDO::FETCH_COLUMN);

    return array_values(array_filter(
        $rows,
        static fn (array $row): bool => !in_array($row['name'], $deletedNames, true)
    ));
}

/**
 * @param array<int, array{name: string, type: string, nullable: bool}> $columns
 */
function km_table_create(PDO $pdo, string $name, array $columns): void
{
    if (!km_table_is_managed_name($name)) {
        throw new InvalidArgumentException(
            'テーブル名は "' . KM_TABLE_PREFIX . '" で始まる英数字(半角小文字)にしてください。'
        );
    }
    if (km_table_exists($pdo, $name)) {
        throw new InvalidArgumentException("同名のテーブルが既に存在します: {$name}");
    }
    if ($columns === []) {
        throw new InvalidArgumentException('カラムを1つ以上指定してください。');
    }

    $columnSql = [];
    $seenNames = [];
    foreach ($columns as $column) {
        $colName = (string) ($column['name'] ?? '');
        $key = strtolower($colName);
        if (isset($seenNames[$key])) {
            throw new InvalidArgumentException("カラム名が重複しています: {$colName}");
        }
        $seenNames[$key] = true;
        $columnSql[] = km_table_build_column_sql(
            $colName,
            (string) ($column['type'] ?? ''),
            (bool) ($column['nullable'] ?? true)
        );
    }

    $sql = sprintf(
        'CREATE TABLE `%s` (`id` INT AUTO_INCREMENT PRIMARY KEY, %s) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        $name,
        implode(', ', $columnSql)
    );
    $pdo->exec($sql);
}

/**
 * 論理削除する: 物理的な DROP・RENAME は行わず、`km_table_manage_deleted` に印を付けて
 * 一覧・レコード操作の対象から外すだけ。データはそのまま残る。実際に消したい場合は
 * phpMyAdmin から明示的に DROP TABLE する運用にする。
 */
function km_table_soft_delete(PDO $pdo, string $name): void
{
    if (!km_table_is_managed_name($name)) {
        throw new InvalidArgumentException('管理対象外のテーブルです。');
    }
    if (!km_table_exists($pdo, $name)) {
        throw new InvalidArgumentException("テーブルが見つかりません: {$name}");
    }

    km_table_manage_ensure_meta_table($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO km_table_manage_deleted (table_name, deleted_at) VALUES (?, NOW())
         ON DUPLICATE KEY UPDATE deleted_at = NOW()'
    );
    $stmt->execute([$name]);
}

/** @return array<int, array{name: string, data_type: string, is_nullable: string, column_key: string}> */
function km_table_columns(PDO $pdo, string $name): array
{
    if (!km_table_is_managed_name($name) || !km_table_exists($pdo, $name) || km_table_is_deleted($pdo, $name)) {
        throw new InvalidArgumentException("管理対象のテーブルではありません: {$name}");
    }

    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME AS name, DATA_TYPE AS data_type, IS_NULLABLE AS is_nullable, COLUMN_KEY AS column_key
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([$name]);

    return $stmt->fetchAll();
}

function km_table_row_count(PDO $pdo, string $name): int
{
    km_table_columns($pdo, $name); // 存在・管理対象チェックを兼ねる
    return (int) $pdo->query("SELECT COUNT(*) FROM `{$name}`")->fetchColumn();
}

/** @return array{columns: array, rows: array} */
function km_table_rows(PDO $pdo, string $name, int $limit, int $offset): array
{
    $columns = km_table_columns($pdo, $name);

    $stmt = $pdo->prepare("SELECT * FROM `{$name}` ORDER BY `id` DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();

    return ['columns' => $columns, 'rows' => $stmt->fetchAll()];
}

/** @return array<int, string> id を除く編集可能カラム名(登録済みスキーマとの突き合わせ用)。 */
function km_table_editable_column_names(array $columns): array
{
    return array_values(array_map(
        static fn (array $c): string => (string) $c['name'],
        array_filter($columns, static fn (array $c): bool => strtolower((string) $c['name']) !== 'id')
    ));
}

/**
 * @param array<string, string> $values カラム名 => 入力値。この関数の内部でスキーマと
 *   突き合わせるため、$values に余分なキーが入っていても無視される(直接 SQL には使わない)。
 */
function km_table_row_insert(PDO $pdo, string $name, array $values): void
{
    $columns = km_table_columns($pdo, $name);
    $editableNames = km_table_editable_column_names($columns);

    $insertCols = [];
    $bind = [];
    foreach ($editableNames as $colName) {
        if (!array_key_exists($colName, $values)) {
            continue;
        }
        $insertCols[] = $colName;
        $bind[] = $values[$colName] === '' ? null : $values[$colName];
    }
    if ($insertCols === []) {
        throw new InvalidArgumentException('値が指定されていません。');
    }

    $columnList = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $insertCols));
    $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
    $stmt = $pdo->prepare("INSERT INTO `{$name}` ({$columnList}) VALUES ({$placeholders})");
    $stmt->execute($bind);
}

/** @param array<string, string> $values */
function km_table_row_update(PDO $pdo, string $name, int $id, array $values): void
{
    $columns = km_table_columns($pdo, $name);
    $editableNames = km_table_editable_column_names($columns);

    $setSql = [];
    $bind = [];
    foreach ($editableNames as $colName) {
        if (!array_key_exists($colName, $values)) {
            continue;
        }
        $setSql[] = "`{$colName}` = ?";
        $bind[] = $values[$colName] === '' ? null : $values[$colName];
    }
    if ($setSql === []) {
        throw new InvalidArgumentException('更新する値がありません。');
    }
    $bind[] = $id;

    $stmt = $pdo->prepare("UPDATE `{$name}` SET " . implode(', ', $setSql) . ' WHERE `id` = ?');
    $stmt->execute($bind);
}

function km_table_row_delete(PDO $pdo, string $name, int $id): void
{
    km_table_columns($pdo, $name); // 存在・管理対象チェックを兼ねる

    $stmt = $pdo->prepare("DELETE FROM `{$name}` WHERE `id` = ?");
    $stmt->execute([$id]);
}
