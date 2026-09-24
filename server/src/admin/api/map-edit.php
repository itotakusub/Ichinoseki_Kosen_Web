<?php

declare(strict_types=1);

define('KM_ADMIN', true);
require dirname(__DIR__) . '/_inc/guard.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/map-edit.php';
require_once dirname(__DIR__, 2) . '/lib/map-events.php';
require_once dirname(__DIR__, 2) . '/lib/admin-log.php';

/**
 * 地図編集(admin/map-editor.php)の保存先。
 *
 * 操作は action で振り分ける(admin/tables.php と同じ形)。ファイルを操作ごとに割らないのは、
 * **認可と CSRF の判定を1箇所に集約しておきたい**ため。どれか1つの経路だけガードが
 * 抜ける、という事故を作らない。
 *
 * 認可は guard.php(fail closed)がやる。ブラウザ側の window.isAdmin は表示の出し分けに
 * しか使っておらず、**サーバーはそれを一切信用しない**。
 *
 * 旧 save_graph.php は認可がセッションの真偽値だけ・CSRF なし・メソッドチェックなし・
 * 中身の検証ゼロ・失敗時も HTTP 200 だった。ここではすべて作り直している。
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function km_map_edit_respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    km_map_edit_respond(['error' => 'POST で送信してください。'], 405);
}

// 本文を読む前に弾く(副作用の手前で止める)
if (!km_csrf_verify()) {
    km_map_edit_respond(['error' => 'セッションの有効期限が切れています。ページを再読み込みしてください。'], 403);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    km_map_edit_respond(['error' => 'リクエストの形式が不正です。'], 400);
}

$action = (string) ($input['action'] ?? '');
$str = static fn (string $key): string => (string) ($input[$key] ?? '');
// 数値以外を弾いてから変換する("abc" が 0 になるのを防ぐ)
$int = static function (string $key) use ($input): int {
    $value = $input[$key] ?? null;
    if (!is_numeric($value)) {
        km_map_edit_respond(['error' => "{$key} は数値で指定してください。"], 400);
    }
    return (int) $value;
};
/*
 * 座標は**丸めない。** 列は `decimal(10,2)`(2026-09-03 に int から変えた)。
 * Website が地図の正本になったので、丸めは往復のたびに積もる位置ずれになる。
 */
$coord = static function (string $key) use ($input): float {
    $value = $input[$key] ?? null;
    if (!is_numeric($value)) {
        km_map_edit_respond(['error' => "{$key} は数値で指定してください。"], 400);
    }
    return round((float) $value, 2);
};

try {
    $pdo = km_db();

    switch ($action) {
        case 'node.create':
            $id = km_map_node_create(
                $pdo,
                $str('floor'),
                $str('title'),
                $str('subtitle'),
                $str('type'),
                $coord('x'),
                $coord('y')
            );
            km_admin_log_record('content', 'map.node_create', $str('title') . ' (' . $str('floor') . ')');
            km_map_edit_respond(['success' => true, 'node' => km_map_node_find($pdo, $id)]);

            // km_map_edit_respond() は exit するので、ここから下へは落ちない
        case 'node.update':
            /*
             * **氏名は受け取らない。** ここが受け取った値をそのまま入れていたせいで、
             * 画面が読み込んだ null をそのまま送り返して氏名を空にした事故がある。
             * Website が正本へ戻った(2026-09-03)いまも、書くのは `node.occupant` だけ。
             */
            km_map_node_update($pdo, $str('id'), $str('title'), $str('subtitle'), $str('type'));
            km_admin_log_record('content', 'map.node_update', $str('title'));
            km_map_edit_respond(['success' => true, 'node' => km_map_node_find($pdo, $str('id'))]);

        case 'node.occupant':
            /*
             * 教職員氏名。**専用の操作にしてある** ——
             * 名前や種類と一緒に送れる形にすると、うっかり空で上書きできてしまう。
             */
            km_map_node_set_occupant($pdo, $str('id'), $input['occupantName'] ?? null);
            // **氏名そのものは記録に残さない。**誰の名前を入れたかは監査の目的ではない
            km_admin_log_record('content', 'map.node_occupant', $str('id'));
            km_map_edit_respond(['success' => true, 'node' => km_map_node_find($pdo, $str('id'))]);

        case 'node.move':
            $moveId = $str('id');
            $moveX = $coord('x');
            $moveY = $coord('y');
            km_map_node_move($pdo, $moveId, $moveX, $moveY);
            km_admin_log_record('content', 'map.node_move', "{$moveId} ({$moveX}, {$moveY})");
            km_map_edit_respond(['success' => true, 'node' => km_map_node_find($pdo, $moveId)]);

        case 'node.delete':
            $removedEdges = km_map_node_delete($pdo, $str('id'));
            km_admin_log_record('content', 'map.node_delete', $str('id'));
            km_map_edit_respond(['success' => true, 'removedEdges' => $removedEdges]);

        case 'edge.create':
            $distance = km_map_edge_create($pdo, $str('from'), $str('to'));
            km_admin_log_record('content', 'map.edge_create', $str('from') . ' ↔ ' . $str('to'));
            km_map_edit_respond(['success' => true, 'distance' => $distance]);

        case 'edge.delete':
            km_map_edge_delete($pdo, $str('from'), $str('to'));
            km_admin_log_record('content', 'map.edge_delete', $str('from') . ' ↔ ' . $str('to'));
            km_map_edit_respond(['success' => true]);

        case 'nodes.align':
            $ids = is_array($input['ids'] ?? null) ? $input['ids'] : [];
            $axis = $str('axis');
            $alignValue = $coord('value');
            $count = km_map_nodes_align($pdo, $ids, $axis, $alignValue);
            km_admin_log_record('content', 'map.nodes_align', "{$axis} = {$alignValue} ({$count}件)");
            km_map_edit_respond(['success' => true, 'count' => $count]);

        /*
         * ---- ここからイベントモードの重ね合わせ ----
         *
         * **恒久データ(km_map_nodes / km_map_edges)には一切触れない。**
         * すべて km_map_event_* テーブルへの読み書きで、イベントを消せば元に戻る。
         *
         * どの操作も event_id を要求する。**どのイベントに対する変更なのかが
         * 曖昧なまま保存されると、あとから外せなくなる**ため。
         */
        case 'event.closure.toggle': {
            $eventId = $int('eventId');
            $reason = $str('reason');
            if ($str('target') === 'node') {
                $nodeId = $str('node');
                $added = km_map_event_closure_toggle_node($pdo, $eventId, $nodeId, $reason);
                km_admin_log_record('content', 'map.event_closure', ($added ? '閉鎖 ' : '解除 ') . "地点 {$nodeId}");
                km_map_edit_respond(['success' => true, 'closed' => $added]);
            }
            $from = $str('from');
            $to = $str('to');
            $added = km_map_event_closure_toggle_edge($pdo, $eventId, $from, $to, $reason);
            km_admin_log_record('content', 'map.event_closure', ($added ? '閉鎖 ' : '解除 ') . "経路 {$from} ↔ {$to}");
            km_map_edit_respond(['success' => true, 'closed' => $added]);
        }

        case 'event.poi.create':
        case 'event.poi.update': {
            $eventId = $int('eventId');
            $poiId = $action === 'event.poi.update' ? $int('poiId') : null;
            $saved = km_map_event_poi_save($pdo, $eventId, $poiId, [
                'floor' => $str('floor'),
                'name' => $str('name'),
                'category' => $str('category'),
                'x' => $int('x'),
                'y' => $int('y'),
                'anchorNodeId' => $str('anchorNodeId'),
                'note' => $str('note'),
            ]);
            km_admin_log_record('content', 'map.event_poi', ($poiId === null ? '追加 ' : '更新 ') . $str('name'));
            km_map_edit_respond(['success' => true, 'id' => $saved]);
        }

        case 'event.poi.delete': {
            $eventId = $int('eventId');
            $poiId = $int('poiId');
            km_map_event_poi_delete($pdo, $eventId, $poiId);
            km_admin_log_record('content', 'map.event_poi', "削除 #{$poiId}");
            km_map_edit_respond(['success' => true]);
        }

        case 'event.alias.set': {
            $eventId = $int('eventId');
            $nodeId = $str('node');
            $alias = $str('alias');
            km_map_event_alias_set($pdo, $eventId, $nodeId, $alias);
            km_admin_log_record(
                'content',
                'map.event_alias',
                $alias === '' ? "解除 {$nodeId}" : "{$nodeId} → {$alias}"
            );
            km_map_edit_respond(['success' => true, 'alias' => $alias]);
        }

        default:
            km_map_edit_respond(['error' => '操作の指定が不正です。'], 400);
    }
} catch (InvalidArgumentException $exception) {
    // 入力が悪いだけ。利用者に見せてよい内容なのでそのまま返す
    km_map_edit_respond(['error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('map-edit.php ' . $action . ' failed: ' . $exception::class . ': ' . $exception->getMessage());
    km_map_edit_respond(['error' => '保存に失敗しました。'], 503);
}
