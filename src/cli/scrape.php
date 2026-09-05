<?php
/**
 * Background scriptas: prisijungia prie kiekvieno config.php nurodyto Tamo prisijungimo,
 * automatiškai atranda VISUS su juo susietus vaikus (tėvo/globėjo paskyra gali turėti kelis),
 * nuscrapina kiekvieno jų duomenis ir įrašo/atnaujina rezultatus DB (snapshots/messages/students).
 *
 * Paleidimas rankiniu būdu:
 *   Windows: C:\xampp\php\php.exe C:\xampp\htdocs\TamoForHomeAssitant\cli\scrape.php
 *   Linux:   php /var/www/tamo/cli/scrape.php
 *
 * Windows Task Scheduler: Action = "C:\xampp\php\php.exe"
 *                          Arguments = "C:\xampp\htdocs\TamoForHomeAssitant\cli\scrape.php"
 *
 * Linux cron (crontab -e), paleidžia kas 30 min (0-ą ir 30-ą kiekvienos valandos minutę):
 *   0,30 * * * * /usr/bin/php /var/www/tamo/cli/scrape.php > /dev/null 2>&1
 *
 * Rekomenduojamas periodas: kas 20-30 min (Tamo sesija baigiasi po 30-60 min neaktyvumo,
 * bet šis skriptas kiekvieną kartą prisijungia iš naujo, tai tai nesvarbu).
 */

require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/DomHelper.php';
require_once __DIR__ . '/../lib/TamoScraper.php';

date_default_timezone_set('Europe/Vilnius');

function log_line(string $s): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $s . PHP_EOL;
}

const KATEGORIJU_PAVADINIMAI = [
    'tvarkarastis' => 'Tvarkaraštis',
    'dienynas' => 'Pažymiai',
    'pamokos' => 'Pamokos',
    'namu_darbai' => 'Namų darbai',
    'atsiskaitomieji_darbai' => 'Atsiskaitymai',
    'pastabos' => 'Pastabos',
    'pusmeciai' => 'Pusmečiai',
];

/** Nuscrapina ir įrašo VISAS kategorijas vienam konkrečiam (jau aktyviam sesijoje) vaikui. */
function scrapeVienaVaika(TamoScraper $scraper, array $cfg, string $studentId, string $vardas): bool
{
    $errors = [];
    $categories = [
        'tvarkarastis' => fn() => $scraper->tvarkarastis(),
        'dienynas' => fn() => $scraper->dienynas(),
        'pamokos' => fn() => $scraper->pamokos(),
        'namu_darbai' => fn() => $scraper->namuDarbai(),
        'atsiskaitomieji_darbai' => fn() => $scraper->atsiskaitomiejiDarbai(),
        'pastabos' => fn() => $scraper->pastabos(),
        'pusmeciai' => fn() => $scraper->pusmeciai(),
    ];

    foreach ($categories as $name => $fn) {
        Db::setScrapeStatus(true, "{$vardas} - " . (KATEGORIJU_PAVADINIMAI[$name] ?? $name));
        try {
            $result = $fn();
            Db::saveSnapshot($studentId, $name, $result);
            log_line("    OK  {$name}");
        } catch (Throwable $e) {
            $errors[] = $name . ': ' . $e->getMessage();
            Db::saveSnapshotError($studentId, $name, $e->getMessage());
            log_line("    KLAIDA {$name}: " . $e->getMessage());
        }
    }
    Db::setScrapeStatus(true, "{$vardas} - Pranešimai");

    try {
        $puslapiai = (int) ($cfg['pranesimu_puslapiai'] ?? 1);
        $parsiustiTekstus = (bool) ($cfg['parsiusti_pranesimu_tekstus'] ?? true);
        $identification = null;
        $visiPranesimai = [];
        for ($p = 1; $p <= max(1, $puslapiai); $p++) {
            $res = $scraper->pranesimai($p, $identification);
            $identification = $res['id'];
            $visiPranesimai = array_merge($visiPranesimai, $res['pranesimai']);
        }
        foreach ($visiPranesimai as $m) {
            $isNew = !Db::messageExists($studentId, (int) $m['id']);
            Db::upsertMessage($studentId, $m);
            if ($isNew && $parsiustiTekstus) {
                try {
                    $body = $scraper->pranesimas((int) $m['id'], $identification);
                    Db::saveMessageBody(
                        $studentId, (int) $m['id'],
                        $body['html tekstas'], $body['tekstas'], $body['prisegti files']
                    );
                } catch (Throwable $e) {
                    log_line("    (nepavyko parsisiųsti pranešimo {$m['id']} teksto: " . $e->getMessage() . ')');
                }
            }
        }
        Db::saveSnapshot($studentId, 'pranesimai_paskutinis_run', ['fetched_at' => date('c'), 'kiekis' => count($visiPranesimai)]);
        log_line('    OK  pranesimai (' . count($visiPranesimai) . ')');
    } catch (Throwable $e) {
        $errors[] = 'pranesimai: ' . $e->getMessage();
        log_line('    KLAIDA pranesimai: ' . $e->getMessage());
    }

    return empty($errors) ? true : implode(' | ', $errors);
}

$cfg = Config::get();

$exitCode = 0;
$noLogins = false;
Db::setScrapeStatus(true, 'Pradedama...');

try {
    // "logins" DB lentelė yra pagrindinis šaltinis (tvarkoma per settings.php).
    // config.php "mokiniai" naudojamas TIK kaip vienkartinis seed'as, jei lentelė dar tuščia.
    Db::seedLoginsFromConfigIfEmpty(Config::mokiniai());
    $mokiniai = Db::listLogins();

    if (empty($mokiniai)) {
        $noLogins = true;
        $exitCode = 1;
        log_line('Nėra nė vieno prisijungimo (logins lentelė tuščia, config.php "mokiniai" irgi tuščias) - nėra ką scrapinti. Pridėkite per settings.php.');
    } else {
    foreach ($mokiniai as $mokinys) {
    log_line("=== Prisijungimas: {$mokinys['vardas']} ({$mokinys['id']}) ===");
    Db::setScrapeStatus(true, "Jungiamasi ({$mokinys['vardas']})...");

    try {
        $scraper = new TamoScraper();
        $scraper->login($mokinys['username'], $mokinys['password']);
        log_line('Prisijungta.');

        // Vardas prioritetas: 1) automatiškai rastas Tamo puslapyje (žr. TamoScraper::raskVaikus()),
        // 2) rankiniu būdu settings.php įvestas 'vardas' (nebūtinas atsarginis laukas),
        // 3) Tamo prisijungimo vartotojo vardas - kad niekada neliktų tuščio pavadinimo.
        $atsarginisVardas = ($mokinys['vardas'] !== '' ? $mokinys['vardas'] : null) ?? $mokinys['username'];

        $vaikai = $scraper->raskVaikus();
        if (empty($vaikai)) {
            // Paskyra be perjungiklio ir nepavyko automatiškai nuskaityti vardo - naudojam atsarginį.
            $vaikai = [[
                'kodas' => null, 'iraso_id' => null, 'istaigos_id' => null,
                'vardas' => $atsarginisVardas, 'mokykla' => null,
            ]];
        } else {
            log_line('Rasti susieti vaikai: ' . implode(', ', array_column($vaikai, 'vardas')));
        }

        foreach ($vaikai as $vaikas) {
            $studentId = $vaikas['iraso_id'] ?? $mokinys['id'];
            $vardas = $vaikas['vardas'] ?? $atsarginisVardas;
            log_line("  --- {$vardas} ({$studentId}) ---");

            if ($vaikas['iraso_id'] !== null) {
                try {
                    $scraper->pasirinktiVaika($vaikas['kodas'], $vaikas['iraso_id'], $vaikas['istaigos_id']);
                } catch (Throwable $e) {
                    log_line('    KLAIDA persijungiant prie vaiko: ' . $e->getMessage());
                    $exitCode = 1;
                    continue;
                }
            }

            Db::upsertStudent($studentId, $mokinys['id'], $vardas, $vaikas['mokykla']);

            $runId = Db::startRun($studentId);
            $result = scrapeVienaVaika($scraper, $cfg, $studentId, $vardas);
            if ($result === true) {
                Db::finishRun($runId, true);
            } else {
                Db::finishRun($runId, false, $result);
                $exitCode = 1;
            }
        }
    } catch (Throwable $e) {
        log_line('KRITINĖ KLAIDA (prisijungimas ar kt.): ' . $e->getMessage());
        $runId = Db::startRun($mokinys['id']);
        Db::finishRun($runId, false, $e->getMessage());
        $exitCode = 1;
    }
}
    }
} finally {
    // VISADA išvalom "running" būseną - net jei įvyktų nenumatyta fatalinė klaida aukščiau
    // (įskaitant atvejį, kai dar nepridėtas nė vienas prisijungimas), kad progreso juosta
    // index.php nepakabotų amžinai rodydama "vykdoma".
    $label = $noLogins
        ? 'Nepridėtas nė vienas Tamo prisijungimas - eikite į Nustatymus ir pridėkite paskyrą.'
        : ($exitCode === 0 ? 'Baigta sėkmingai' : 'Baigta su klaidomis');
    Db::setScrapeStatus(false, $label);
}

log_line('Baigta.');
exit($exitCode);
