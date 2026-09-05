<?php
/**
 * Paleidžia cli/scrape.php FONE (neblokuojantis) ir iškart grąžina JSON atsakymą.
 * Naudojama index.php "Atnaujinti dabar" mygtuko - progreso juosta tada pati atsiklausia
 * scrape_status.php, kol scrapinimas vyksta fone.
 */

require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Db.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    respond(403, ['started' => false, 'error' => 'Leidžiama tik iš localhost']);
}

try {
    $status = Db::getScrapeStatus();
    if ($status['running']) {
        respond(200, ['started' => true, 'already_running' => true]);
    }

    $cfg = Config::get();
    $phpBin = $cfg['php_cli_path'] ?? null;
    if ($phpBin === null || !is_file($phpBin) || stripos(basename($phpBin), 'php') === false) {
        respond(500, ['started' => false, 'error' => "Neteisingas 'php_cli_path' config.php faile."]);
    }

    // Iškart pažymim "running", kad frontend'o pirmas poll'as (kuris gali nuskristi anksčiau
    // nei fone paleistas scrape.php spėtų pats parašyti savo būseną) nepamatytų "running: false".
    Db::setScrapeStatus(true, 'Pradedama...');

    $script = __DIR__ . '/cli/scrape.php';
    if (PHP_OS_FAMILY === 'Windows') {
        // "start /B" atskiria procesą nuo šito PHP request'o, kad jis toliau veiktų fone
        // net kai šis HTTP atsakymas jau grąžintas.
        $cmd = 'start /B "" ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' > NUL 2>&1';
    } else {
        // Linux/macOS: gale esantis "&" atskiria procesą nuo šio request'o (popen() paleidžia
        // per /bin/sh -c, kuris tai supranta) - toliau veiks fone net baigus HTTP atsakymą.
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
    }
    $handle = popen($cmd, 'r');
    if ($handle !== false) {
        pclose($handle);
    }

    respond(200, ['started' => true]);
} catch (Throwable $e) {
    respond(500, ['started' => false, 'error' => $e->getMessage()]);
}
