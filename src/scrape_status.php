<?php
require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    echo json_encode(Db::getScrapeStatus());
} catch (Throwable $e) {
    echo json_encode(['running' => false, 'label' => '', 'updated_at' => null, 'error' => $e->getMessage()]);
}
