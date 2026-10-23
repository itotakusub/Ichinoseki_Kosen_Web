<?php

declare(strict_types=1);

/**
 * テーブル管理の表を外から読み書きする口(2026-10-21。lib/table-api.php)。
 *
 * 認証は**接続ごとのユーザー名(接続 ID)とキー**。管理画面「テーブル管理 → 外部接続」で作る。
 *   Basic 認証(curl -u <接続 ID>:<キー>)か、`Authorization: Bearer <接続 ID>:<キー>`
 *
 *   GET    ?table=kmt_…[&limit=100&offset=0]   行の一覧(新しい順)          … 「読む」
 *   GET    ?table=kmt_…&id=<番号>              1 行                          … 「読む」
 *   POST   ?table=kmt_…  {"values": {…}}       行を足す → {"id": 番号}        … 「足す」
 *   PATCH  ?table=kmt_…&id=<番号> {"values"}   行を直す                       … 「直す」
 *   DELETE ?table=kmt_…&id=<番号>              行を消す                       … 「消す」
 *
 * `table` は省いてもよい(接続は 1 つの表にしか繋がらない)。書くときは**その接続の表と同じ名前**でなければ断る。
 * Cookie は使わない(ブラウザのセッションに乗らないので CSRF の心配が無い)。CORS も付けない(サーバー同士・表計算から使う)。
 */

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/table-api.php';
require_once __DIR__ . '/../lib/admin-log.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'PUT') {
    $method = 'PATCH';
}
if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
    header('Allow: GET, POST, PATCH, PUT, DELETE');
    respond(['success' => false, 'message' => 'この操作は使えません。'], 405);
}

$credentials = km_table_api_credentials($_SERVER);
if ($credentials === null) {
    header('WWW-Authenticate: Basic realm="KosenMap table API", charset="UTF-8"');
    respond(['success' => false, 'message' => '接続 ID とキーが必要です(Basic 認証)。'], 401);
}

try {
    $pdo = km_db();
} catch (Throwable $exception) {
    error_log('api/table.php db failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '現在利用できません。'], 503);
}

$connection = km_table_api_authenticate($pdo, $credentials[0], $credentials[1]);
if ($connection === null) {
    header('WWW-Authenticate: Basic realm="KosenMap table API", charset="UTF-8"');
    respond(['success' => false, 'message' => '接続 ID かキーが違うか、取り消された接続です。'], 401);
}

$tableParam = $_GET['table'] ?? '';
if (!is_string($tableParam) || ($tableParam !== '' && $tableParam !== $connection['table'])) {
    respond(['success' => false, 'message' => 'この接続で使える表は ' . $connection['table'] . ' だけです。'], 403);
}
$table = $connection['table'];

$need = ['GET' => 'can_read', 'POST' => 'can_insert', 'PATCH' => 'can_update', 'DELETE' => 'can_delete'][$method];
if (!$connection[$need]) {
    respond(['success' => false, 'message' => 'この接続には「' . KM_TABLE_API_PERMISSIONS[$need] . '」の権限がありません。'], 403);
}

/** ?id= を読む。無い・形が違えば null */
$idParam = $_GET['id'] ?? null;
$id = is_string($idParam) && preg_match('/^[1-9]\d{0,9}$/', $idParam) === 1 ? (int) $idParam : null;

$findRow = static function () use ($pdo, $table, $id): ?array {
    $statement = $pdo->prepare("SELECT * FROM `{$table}` WHERE `id` = ?");
    $statement->execute([$id]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
};

try {
    if ($method === 'GET') {
        $columns = array_map(static fn (array $c): string => (string) $c['name'], km_table_columns($pdo, $table));
        if ($id !== null) {
            $row = $findRow();
            if ($row === null) {
                respond(['success' => false, 'message' => 'その行はありません。'], 404);
            }
            respond(['success' => true, 'table' => $table, 'columns' => $columns, 'row' => $row]);
        }
        $limitParam = $_GET['limit'] ?? '100';
        $offsetParam = $_GET['offset'] ?? '0';
        $limit = is_string($limitParam) && ctype_digit($limitParam) ? max(1, min(KM_TABLE_API_MAX_LIMIT, (int) $limitParam)) : 100;
        $offset = is_string($offsetParam) && ctype_digit($offsetParam) ? min(1_000_000, (int) $offsetParam) : 0;
        $result = km_table_rows($pdo, $table, $limit, $offset);
        respond([
            'success' => true,
            'table' => $table,
            'columns' => $columns,
            'total' => km_table_row_count($pdo, $table),
            'limit' => $limit,
            'offset' => $offset,
            'rows' => $result['rows'],
        ]);
    }

    if ($method === 'DELETE') {
        if ($id === null || $findRow() === null) {
            respond(['success' => false, 'message' => 'その行はありません(?id= で番号を指定してください)。'], 404);
        }
        km_table_row_delete($pdo, $table, $id);
        km_admin_log_record('table', 'table.api_write', $connection['label'] . ': ' . $table . ' #' . $id . ' を消した');
        respond(['success' => true, 'id' => $id]);
    }

    $input = km_api_json_body();
    $values = km_table_api_values($input['values'] ?? null);

    if ($method === 'POST') {
        km_table_row_insert($pdo, $table, $values);
        $newId = (int) $pdo->lastInsertId();
        km_admin_log_record('table', 'table.api_write', $connection['label'] . ': ' . $table . ' #' . $newId . ' を足した');
        respond(['success' => true, 'id' => $newId], 201);
    }

    // PATCH
    if ($id === null || $findRow() === null) {
        respond(['success' => false, 'message' => 'その行はありません(?id= で番号を指定してください)。'], 404);
    }
    km_table_row_update($pdo, $table, $id, $values);
    km_admin_log_record('table', 'table.api_write', $connection['label'] . ': ' . $table . ' #' . $id . ' を直した');
    respond(['success' => true, 'id' => $id]);
} catch (InvalidArgumentException $exception) {
    respond(['success' => false, 'message' => $exception->getMessage()], 400);
} catch (PDOException $exception) {
    // 型が合わない・必須の列が空など。中身(SQL の文面)は出さない
    error_log('api/table.php ' . $method . ' failed: ' . $exception->getMessage());
    respond(['success' => false, 'message' => '書き込めませんでした(値の型・必須の列を確かめてください)。'], 400);
} catch (Throwable $exception) {
    error_log('api/table.php ' . $method . ' failed: ' . $exception::class . ': ' . $exception->getMessage());
    respond(['success' => false, 'message' => '処理できませんでした。'], 500);
}
