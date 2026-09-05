<?php
/**
 * Paprastas JSON endpoint'as, skirtas Home Assistant (RESTful sensor / rest_command)
 * ar bet kokiam kitam įrankiui, kuris nori nuskaityti jau surinktus (background scripto)
 * duomenis be tiesioginio prisijungimo prie Tamo.
 *
 * GET /api.php?mokinys=<id>            - vieno moksleivio duomenys
 * GET /api.php                          - visų config.php nurodytų moksleivių sąrašas + duomenys
 *
 * Jei config.php nustatytas 'api_token', reikalaujama arba ?token=..., arba X-Api-Token header'io.
 */

require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Db.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, mixed $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

try {
    $cfg = Config::get();
} catch (Throwable $e) {
    respond(500, ['error' => $e->getMessage()]);
}

$requiredToken = $cfg['api_token'] ?? null;
if ($requiredToken !== null) {
    $given = $_GET['token'] ?? ($_SERVER['HTTP_X_API_TOKEN'] ?? null);
    if (!hash_equals((string) $requiredToken, (string) $given)) {
        respond(403, ['error' => 'Neteisingas arba trūkstamas tokenas']);
    }
}

function studentPayload(array $mokinys): array
{
    $snapshots = Db::loadSnapshots($mokinys['id']);
    $lastRun = Db::lastRun($mokinys['id']);
    $unread = 0;
    foreach (Db::loadMessages($mokinys['id'], 200) as $m) {
        if (!$m['perskaityta']) {
            $unread++;
        }
    }
    $vidurkis = $snapshots['pusmeciai']['payload']['vidurkis']['vidurkiu'] ?? null;

    return [
        'id' => $mokinys['id'],
        'vardas' => $mokinys['vardas'],
        'paskutinis_scrapinimas' => $lastRun,
        'bendras_vidurkis' => $vidurkis,
        'neperskaityti_pranesimai' => $unread,
        'tvarkarastis' => $snapshots['tvarkarastis']['payload'] ?? null,
        'dienynas' => $snapshots['dienynas']['payload'] ?? null,
        'pamokos' => $snapshots['pamokos']['payload'] ?? null,
        'namu_darbai' => $snapshots['namu_darbai']['payload'] ?? null,
        'atsiskaitomieji_darbai' => $snapshots['atsiskaitomieji_darbai']['payload'] ?? null,
        'pastabos' => $snapshots['pastabos']['payload'] ?? null,
        'pusmeciai' => $snapshots['pusmeciai']['payload'] ?? null,
    ];
}

// Moksleivių sąrašas: pirmenybė DB (students - realiai atrasti vaikai, žr. TamoScraper::raskVaikus()),
// jei dar nebuvo nė vieno scrapinimo, naudojam config.php "mokiniai" kaip pradinį sąrašą.
try {
    $dbStudents = Db::loadStudents();
} catch (Throwable $e) {
    $dbStudents = [];
}
$mokiniai = !empty($dbStudents)
    ? array_map(fn($s) => ['id' => $s['id'], 'vardas' => $s['vardas']], $dbStudents)
    : array_map(fn($m) => ['id' => $m['id'], 'vardas' => $m['vardas']], Config::mokiniai());

try {
    if (isset($_GET['mokinys'])) {
        $m = null;
        foreach ($mokiniai as $cand) {
            if ($cand['id'] === $_GET['mokinys']) {
                $m = $cand;
                break;
            }
        }
        if ($m === null) {
            respond(404, ['error' => 'Nežinomas mokinio id']);
        }
        respond(200, studentPayload($m));
    }

    $out = [];
    foreach ($mokiniai as $m) {
        $out[] = studentPayload($m);
    }
    respond(200, ['mokiniai' => $out]);
} catch (Throwable $e) {
    respond(500, ['error' => $e->getMessage()]);
}
