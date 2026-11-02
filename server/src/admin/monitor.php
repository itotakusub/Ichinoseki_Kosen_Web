<?php

declare(strict_types=1);

/*
 * サービス監視は 2026-11-02 にタスクマネージャーの「サービス」へまとめた(利用者の指示)。
 * 本体は Old/removed-2026-11-02/src/admin/monitor.php。ブックマークのためにここだけ残す。
 */
header('Location: ./taskmgr.php?tab=services', true, 301);
exit;
