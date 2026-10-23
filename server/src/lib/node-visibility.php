<?php

declare(strict_types=1);

/**
 * 教員室・研究室の地点と、先生の名前・備考を**誰に出すか**(2026-10-22、利用者の指示「ノード表示の権限チェックを厳格化」)。
 *
 * | 見る人 | 教員室・研究室の地点 | 先生の名前(担当)・備考(メモ) |
 * |---|---|---|
 * | 組織メンバー(教職員)でない | **出さない**(地点ごと消す) | 出さない |
 * | 組織メンバー | 出す | **先生が許可した地点だけ**出す |
 *
 * - **例外は無い。** テスト用アカウント・お試しの閲覧リンク・地図のパスワード・イベント運営・管理者のサインインでも、
 *   組織メンバーでなければ上の 1 行目。全部見えるのは管理画面の地図編集だけ(km_map_data の $forAdmin)
 * - 組織メンバーかは km_map_teacher_session()(index.php が ID トークンの organization_roles を確かめて立てる。最長 1 時間)
 * - 先生の許可は**先生が申請し、管理者が承認したとき**だけ付く(lib/staff-nodes.php の showNames)。取り下げはすぐ効く
 * - 地点に割り当てた先生が**全員**許可しているときだけ「許可あり」(同じ部屋の別の先生の名前を、その人の許可なしに出さない)。
 *   割り当てが無い地点は許可なし
 * - **Android アプリへの配信も同じ決まり**(2026-10-22、利用者の指示「Android 側にも厳格化」。lib/app-map.php の中身の段)。
 *   アプリで全部が届くのは、地図を編集する管理者だけ(Web の地図編集と同じ扱い)
 *
 * 公開ページの経路から呼ぶので、**表を作らない**。読めなければ「許可なし」(閉じる側に倒す)。
 */

/** 教員室・研究室を見分ける言葉。**種類 2 か名前のどちらかに含めば**該当(利用者の指示「両方」) */
const KM_RESTRICTED_NODE_WORDS = ['教員室', '研究室'];

/** 先生の許可を持つ表(lib/staff-nodes.php が作る) */
const KM_STAFF_NODE_CONSENT_TABLE = 'km_staff_node_consents';

/**
 * 教員室・研究室の地点か。**DB を使わない**(scripts/check.php が試す)。
 *
 * @param array<string, mixed> $node km_map_nodes の行(name・title・type2 のどれかがあればよい)
 */
function km_map_node_is_restricted(array $node): bool
{
    foreach (['type2', 'name', 'title'] as $key) {
        $value = (string) ($node[$key] ?? '');
        if ($value === '') {
            continue;
        }
        foreach (KM_RESTRICTED_NODE_WORDS as $word) {
            if (str_contains($value, $word)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * 先生の許可がある地点(id => true)。**割り当てた先生が全員**許可している地点だけ。
 * 表が無い・読めないときは空(誰の名前も出さない)。
 *
 * @return array<string, true>
 */
function km_staff_node_consented_ids(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            'SELECT a.node_id
               FROM km_staff_node_assignments a
               LEFT JOIN ' . KM_STAFF_NODE_CONSENT_TABLE . ' c ON c.node_id = a.node_id AND c.user_id = a.user_id
              GROUP BY a.node_id
             HAVING COUNT(*) = COUNT(c.user_id)'
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $exception) {
        return [];
    }
    $ids = [];
    foreach ($rows as $id) {
        $ids[(string) $id] = true;
    }

    return $ids;
}

/**
 * 先生の許可がある地点の **uuid**(アプリの地図は uuid で地点を指す)。並べて返す。
 * 読めないときは空(誰の名前も出さない)。
 *
 * @return list<string>
 */
function km_staff_node_consented_uuids(PDO $pdo): array
{
    $ids = km_staff_node_consented_ids($pdo);
    if ($ids === []) {
        return [];
    }
    try {
        $rows = $pdo->query('SELECT id, uuid FROM km_map_nodes')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        return [];
    }
    $uuids = [];
    foreach ($rows as $row) {
        $uuid = (string) ($row['uuid'] ?? '');
        if ($uuid !== '' && isset($ids[(string) $row['id']])) {
            $uuids[$uuid] = true;
        }
    }
    $uuids = array_keys($uuids);
    sort($uuids, SORT_STRING);

    return array_map('strval', $uuids);
}

/**
 * 公開ページで、その地点をどう出すか。**DB を使わない**(判定の表。scripts/check.php が試す)。
 *
 * @return array{show:bool, names:bool} show = 地点を出す / names = 担当とメモを出す
 */
function km_map_node_visibility(bool $restricted, bool $member, bool $consented, bool $eventHidesNames): array
{
    if ($restricted && !$member) {
        return ['show' => false, 'names' => false];
    }

    return ['show' => true, 'names' => $member && $consented && !$eventHidesNames];
}
