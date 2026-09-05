<?php

session_start();

require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Db.php';

date_default_timezone_set('Europe/Vilnius');

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function fmtDate(?array $d): string
{
    if ($d === null || $d['y'] === null) {
        return '-';
    }
    return sprintf('%04d-%02d-%02d', $d['y'], $d['m'], $d['d']);
}

function fmtDateTime(?array $d): string
{
    if ($d === null || $d['y'] === null) {
        return '-';
    }
    return sprintf('%04d-%02d-%02d %02d:%02d', $d['y'], $d['m'], $d['d'], $d['h'] ?? 0, $d['min'] ?? 0);
}

/** Artimiausia mokyklos diena: rytoj, arba pirmadienis, jei rytoj būtų šeštadienis/sekmadienis. */
function nextSchoolDate(): array
{
    $d = new DateTime('tomorrow', new DateTimeZone('Europe/Vilnius'));
    while ((int) $d->format('N') >= 6) {
        $d->modify('+1 day');
    }
    return ['y' => (int) $d->format('Y'), 'm' => (int) $d->format('n'), 'd' => (int) $d->format('j'), 'w' => (int) $d->format('N')];
}

function isSameDate(?array $d, array $target): bool
{
    return $d !== null && $d['y'] !== null
        && (int) $d['y'] === $target['y'] && (int) $d['m'] === $target['m'] && (int) $d['d'] === $target['d'];
}

function renderLockBox(string $tabId, string $selectedId, ?string $pinError): void
{
    ?>
    <div class="locked-box">
      <div class="locked-icon">🔒</div>
      <p>Šis skyrius apsaugotas PIN kodu.</p>
      <?php if ($pinError): ?><p class="err"><?= h($pinError) ?></p><?php endif; ?>
      <form method="post" action="index.php" class="pin-form">
        <input type="hidden" name="mokinys" value="<?= h($selectedId) ?>">
        <input type="hidden" name="tab" value="<?= h($tabId) ?>">
        <input type="password" inputmode="numeric" pattern="[0-9]*" name="pin" placeholder="PIN kodas" autofocus>
        <button type="submit">Atrakinti</button>
      </form>
    </div>
    <?php
}

function orDash(mixed $v): string
{
    if ($v === null || $v === '') {
        return '-';
    }
    return (string) $v;
}

const SAVAITES_DIENOS = [1 => 'Pirmadienis', 2 => 'Antradienis', 3 => 'Trečiadienis', 4 => 'Ketvirtadienis', 5 => 'Penktadienis', 6 => 'Šeštadienis', 7 => 'Sekmadienis'];

require_once __DIR__ . '/lib/Tabs.php';

// Pirmas paleidimas - config.php dar nesukurtas, nukreipiam į web diegimo vediklį
// vietoj to, kad rodytume vartotojui pliką klaidos pranešimą.
if (!is_file(__DIR__ . '/config.php') && is_file(__DIR__ . '/install.php')) {
    header('Location: install.php');
    exit;
}

try {
    Config::get();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<p style="font-family:sans-serif;color:#b00">' . h($e->getMessage()) . '</p>';
    exit;
}

// Skyrių PIN užraktas - NEPRIKLAUSOMAS nuo settings.php PIN kodo (atskiras "section" raktas
// Db::pinKey() viduje). Atrakinus skyrių ČIA, settings.php lieka uzrakintas ir atvirkščiai.
// Atrakinimas galioja visą naršyklės sesiją (kol vartotojas paspaudžia "Užrakinti" arba
// uždaro naršyklę) - žr. $_GET['lock'] žemiau eksplicitiniam užrakinimui.
$pinError = null;
$restrictedTabs = [];
try {
    Db::ensurePinsSeeded('999999');

    if (isset($_GET['lock'])) {
        unset($_SESSION['section_ok']);
        $backTab = $_GET['tab'] ?? TABS[0]['id'];
        $backMokinys = $_GET['mokinys'] ?? '';
        header('Location: ?mokinys=' . urlencode($backMokinys) . '&tab=' . urlencode($backTab));
        exit;
    }

    if (isset($_POST['pin'])) {
        if (Db::verifyPin('section', $_POST['pin'])) {
            $_SESSION['section_ok'] = true;
            // Post/Redirect/Get - kad perkrovus puslapį PIN nebūtų siunčiamas pakartotinai.
            $backTab = $_POST['tab'] ?? TABS[0]['id'];
            $backMokinys = $_POST['mokinys'] ?? '';
            header('Location: ?mokinys=' . urlencode($backMokinys) . '&tab=' . urlencode($backTab));
            exit;
        } else {
            $pinError = 'Neteisingas PIN kodas.';
        }
    }
    $restrictedTabs = Db::getRestrictedTabs();
} catch (Throwable $e) {
    // DB dar nepasiekiama ar pan. - PIN funkcionalumas tiesiog neveiks, likusi dashboard dalis toliau bandys veikti.
}
$unlocked = !empty($_SESSION['section_ok']);

// "Atnaujinti dabar" nebe sinchroninis exec() - dabar tai JS mygtukas (žr. antraštėje), kuris
// paleidžia scrape_trigger.php (fone, neblokuojantis) ir rodo progreso juostą, tildama
// scrape_status.php per fetch() poll'inimą. Žr. <script> puslapio apačioje.

// Moksleivių sąrašas: pirmenybė DB (students lentelė - realiai atrasti vaikai po scrapinimo),
// kol dar nebuvo nė vieno paleidimo - naudojam config.php "mokiniai" kaip pradinį sąrašą.
try {
    $dbStudents = Db::loadStudents();
} catch (Throwable $e) {
    $dbStudents = [];
}
$mokiniai = !empty($dbStudents)
    ? array_map(fn($s) => ['id' => $s['id'], 'vardas' => $s['vardas']], $dbStudents)
    : array_map(fn($m) => ['id' => $m['id'], 'vardas' => $m['vardas']], Config::mokiniai());

// Dar nepridėtas nė vienas Tamo prisijungimas (nei DB, nei config.php) - vietoj klaidos
// rodom demo dashboard'ą su pavyzdiniu vardu, kad vartotojas iš karto matytų, kaip UI
// atrodys, ir aiškią instrukciją, kur pridėti savo paskyrą.
$isDemo = empty($mokiniai);
if ($isDemo) {
    $mokiniai = [['id' => 'demo', 'vardas' => 'Vardenis Pavardenis']];
}

$selectedId = $_GET['mokinys'] ?? $mokiniai[0]['id'];
$mokinys = null;
foreach ($mokiniai as $m) {
    if ($m['id'] === $selectedId) {
        $mokinys = $m;
        break;
    }
}
if ($mokinys === null) {
    $mokinys = $mokiniai[0];
}
$selectedId = $mokinys['id'];

$validTabIds = array_column(TABS, 'id');
$activeTab = $_GET['tab'] ?? TABS[0]['id'];
if (!in_array($activeTab, $validTabIds, true)) {
    $activeTab = TABS[0]['id'];
}

function tabUrl(string $studentId, string $tab): string
{
    return '?mokinys=' . urlencode($studentId) . '&tab=' . urlencode($tab);
}

try {
    $snapshots = Db::loadSnapshots($selectedId);
    $messages = Db::loadMessages($selectedId, 30);
    $lastRun = Db::lastRun($selectedId);
    $dbError = null;
} catch (Throwable $e) {
    $snapshots = [];
    $messages = [];
    $lastRun = null;
    $dbError = $e->getMessage();
}

function snap(array $snapshots, string $category): array
{
    return $snapshots[$category] ?? ['fetched_at' => null, 'payload' => null, 'error' => null];
}

$targetDate = nextSchoolDate();

$tvarkarastis = snap($snapshots, 'tvarkarastis');
$dienynas = snap($snapshots, 'dienynas');
$pusmeciai = snap($snapshots, 'pusmeciai');
$namuDarbai = snap($snapshots, 'namu_darbai');
$atsiskaitomieji = snap($snapshots, 'atsiskaitomieji_darbai');
$pastabos = snap($snapshots, 'pastabos');

try {
    $theme = Db::getTheme();
} catch (Throwable $e) {
    $theme = 'tablet';
}

?><!doctype html>
<html lang="lt" data-theme="<?= h($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Tamo - <?= h($mokinys['vardas']) ?></title>
<style>
  /* --- Bazė: dideli šriftai, spalvota bet plokščia (be šešėlių/animacijų) - tinka planšetėms ir e-paper. --- */
  :root {
    color-scheme: light dark;
    --bg: #ffffff; --fg: #16161a; --muted: #6b7280; --line: #d7d9e0;
    --accent: #4f46e5; --accent-fg: #ffffff; --accent-soft: #eef0fe;
    --header-bg: #4f46e5; --header-fg: #ffffff;
    --btn-bg: rgba(255,255,255,.14); --btn-fg: #ffffff; --btn-border: rgba(255,255,255,.55);
    --btn-active-bg: #ffffff; --btn-active-fg: #4f46e5;
    --badge-bg: #eef0fe; --badge-fg: #4f46e5; --unread: #e11d48;
    --warn: #c2410c; --warn-soft: #fb923c; --warn-fg: #1c1006;
  }

  /* --- E-paper šablonas: juoda/balta, aukštas kontrastas, be spalvų (pasirenkama settings.php). --- */
  :root[data-theme="epaper"] {
    --bg: #ffffff; --fg: #111111; --muted: #555555; --line: #000000;
    --accent: #111111; --accent-fg: #ffffff; --accent-soft: #eeeeee;
    --header-bg: #ffffff; --header-fg: #111111;
    --btn-bg: #ffffff; --btn-fg: #111111; --btn-border: #111111;
    --btn-active-bg: #111111; --btn-active-fg: #ffffff;
    --badge-bg: #eeeeee; --badge-fg: #111111; --unread: #111111;
    --warn: #000000; --warn-soft: #dddddd; --warn-fg: #000000;
  }
  html[data-theme="epaper"] header { border-bottom: 3px solid #000; }

  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    margin: 0; background: var(--bg); color: var(--fg);
    font-size: 18px; line-height: 1.4;
  }
  a { color: inherit; }
  header { background: var(--header-bg); color: var(--header-fg); padding: 10px 14px; }

  /* Viena eilutė: prekės ženklas + mokinių mygtukai + atnaujinimo ikonėlė. */
  .toolbar-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .brand { font-size: 17px; font-weight: 800; margin-right: 4px; white-space: nowrap; }
  .student-row { display: flex; flex-wrap: wrap; gap: 8px; flex: 1 1 auto; }
  .student-btn {
    display: inline-block; font-size: 15px; font-weight: 700;
    padding: 8px 14px; min-height: 40px; line-height: 24px;
    border: 2px solid var(--btn-border); border-radius: 999px;
    background: var(--btn-bg); color: var(--btn-fg);
    text-decoration: none; cursor: pointer; white-space: nowrap;
  }
  .student-btn.active { background: var(--btn-active-bg); color: var(--btn-active-fg); border-color: var(--btn-active-bg); }
  .refresh-icon-btn {
    flex: 0 0 auto; width: 40px; height: 40px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 19px; border: 2px solid var(--btn-border); color: var(--btn-fg);
    background: var(--btn-bg); text-decoration: none; cursor: pointer;
    font-family: inherit; padding: 0; -webkit-appearance: none; appearance: none;
  }
  .refresh-icon-btn:disabled { opacity: .5; cursor: default; }
  .meta { font-size: 13px; color: rgba(255,255,255,.85); margin-top: 6px; }

  main { max-width: 1000px; margin: 0 auto; padding: 0 0 40px; }

  /* --- CSS-only skirtukai (tabs), be JavaScript: paslėpti radio + label + bendras sibling selector. --- */
  .tabs > input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
  .tab-bar {
    display: flex; flex-wrap: wrap; gap: 0;
    border-bottom: 2px solid var(--line);
    position: sticky; top: 0; background: var(--bg); z-index: 1;
  }
  .tab-bar label {
    display: block; padding: 14px 16px; font-size: 16px; font-weight: 600;
    border-right: 1px solid var(--line); cursor: pointer; user-select: none;
    flex: 1 1 auto; text-align: center; min-width: 110px;
  }
  .tab-bar label:last-child { border-right: none; }
  .tab-panel { display: none; padding: 16px; }

  .hint-line { font-size: 14px; color: var(--muted); margin: 0 0 12px; }
  tr.hw-due td { background: var(--warn-soft); color: var(--warn-fg); font-weight: 700; }
  tr.hw-due td:first-child { border-left: 4px solid var(--warn); padding-left: 6px; }

  .locked-box { text-align: center; padding: 50px 16px; }
  .locked-icon { font-size: 40px; margin-bottom: 6px; }
  .pin-form { display: flex; gap: 8px; justify-content: center; margin-top: 14px; flex-wrap: wrap; }
  .pin-form input[type="password"] {
    font-size: 20px; letter-spacing: 4px; text-align: center; width: 160px;
    padding: 10px; border: 2px solid var(--line); border-radius: 8px; background: var(--bg); color: var(--fg);
  }
  .pin-form button {
    font-size: 16px; font-weight: 700; padding: 10px 18px; border-radius: 8px;
    border: 2px solid var(--accent); background: var(--accent); color: var(--accent-fg); cursor: pointer;
  }
  <?php foreach (TABS as $t): ?>
  #tab-<?= $t['id'] ?>:checked ~ .tab-bar label[for="tab-<?= $t['id'] ?>"] { background: var(--accent); color: var(--accent-fg); }
  #tab-<?= $t['id'] ?>:checked ~ #panel-<?= $t['id'] ?> { display: block; }
  <?php endforeach; ?>

  h2.section-title { font-size: 15px; margin: 0 0 10px; color: var(--muted); font-weight: 600; }
  .fetched { font-size: 13px; color: var(--muted); margin: 0 0 14px; }
  table { border-collapse: collapse; width: 100%; font-size: 16px; }
  th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
  th { font-weight: 700; color: var(--accent); border-bottom: 2px solid var(--accent); }
  .day-heading {
    font-size: 17px; font-weight: 700; margin: 18px 0 4px; padding-left: 10px;
    border-left: 5px solid var(--accent);
  }
  .day-heading:first-child { margin-top: 0; }
  .err { color: #c81e4a; font-size: 15px; font-weight: 600; }
  .empty { color: var(--muted); font-size: 15px; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 14px; font-weight: 700; background: var(--badge-bg); color: var(--badge-fg); }
  .msg { border-bottom: 1px solid var(--line); padding: 14px 0; }
  .msg:last-child { border-bottom: none; }
  .msg .subj { font-weight: 700; font-size: 17px; }
  .msg .sub { font-size: 14px; color: var(--muted); margin-top: 2px; }
  .msg .body-text { font-size: 15px; margin-top: 8px; white-space: pre-wrap; }
  .unread .subj::before { content: "● "; color: var(--unread); }
  pre.log { white-space: pre-wrap; font-size: 13px; background: #eee; color: #111; padding: 10px; border: 1px solid var(--line); border-radius: 6px; overflow-x: auto; }
  .notice { padding: 12px 16px; border-bottom: 2px solid var(--line); font-size: 15px; }
  .notice.err { background: #fdecef; }
  .notice.info { background: var(--accent-soft); }
  .notice.info a { color: var(--accent); font-weight: 700; }

  /* --- Progreso juosta scrapinimo metu (žr. scrape_trigger.php / scrape_status.php). --- */
  .scrape-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.55);
    display: flex; align-items: center; justify-content: center; z-index: 50; padding: 16px;
  }
  /* SVARBU: [hidden] turi laimėti prieš klasės display:flex aukščiau - be šito overlay rodytųsi
     visada, nepriklausomai nuo "hidden" atributo (abu turi tokį patį CSS specifiškumą). */
  .scrape-overlay[hidden] { display: none !important; }
  .scrape-box {
    background: var(--bg); color: var(--fg); border: 2px solid var(--line); border-radius: 12px;
    padding: 28px 24px; width: 320px; max-width: 100%; text-align: center;
  }
  .scrape-spinner {
    width: 40px; height: 40px; margin: 0 auto 16px; border-radius: 50%;
    border: 4px solid var(--line); border-top-color: var(--accent);
    animation: scrape-spin 0.9s linear infinite;
  }
  @keyframes scrape-spin { to { transform: rotate(360deg); } }
  #scrapeLabel { font-size: 15px; font-weight: 600; min-height: 20px; }
  .scrape-slow { margin-top: 14px; font-size: 13px; color: var(--muted); }
  /* E-paper: be animacijos - statiškas žiedas, tekstas lieka pagrindinis signalas. */
  html[data-theme="epaper"] .scrape-spinner { animation: none; border-top-color: var(--fg); }

  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #0e0e12; --fg: #f2f2f5; --muted: #9a9aa5; --line: #2c2c35;
      --accent: #818cf8; --accent-fg: #0e0e12; --accent-soft: #1f2049;
      --header-bg: #312e81; --header-fg: #ffffff;
      --btn-bg: rgba(255,255,255,.12); --btn-fg: #ffffff; --btn-border: rgba(255,255,255,.4);
      --btn-active-bg: #ffffff; --btn-active-fg: #312e81;
      --badge-bg: #1f2049; --badge-fg: #a5b4fc; --unread: #fb7185;
      --warn: #fb923c; --warn-soft: #c2410c; --warn-fg: #fff7ed;
    }
    pre.log { background: #1c1c1c; color: #f2f2f2; }
    .notice.err { background: #3a1420; }
    .notice.info { background: var(--accent-soft); }
  }
</style>
</head>
<body>
<header>
  <div class="toolbar-row">
    <span class="brand">Tamo</span>
    <div class="student-row">
      <?php foreach ($mokiniai as $m): ?>
        <a class="student-btn <?= $m['id'] === $selectedId ? 'active' : '' ?>" href="<?= h(tabUrl($m['id'], $activeTab)) ?>"><?= h($m['vardas']) ?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($unlocked): ?>
      <a class="refresh-icon-btn" title="Užrakinti apsaugotus skyrius" href="<?= h(tabUrl($selectedId, $activeTab)) ?>&lock=1">🔒</a>
    <?php endif; ?>
    <button type="button" class="refresh-icon-btn" title="Atnaujinti dabar" id="refreshBtn">⟳</button>
    <a class="refresh-icon-btn" title="Nustatymai" href="settings.php">⚙</a>
  </div>
  <div class="meta">
    <?php if ($lastRun): ?>
      Paskutinis scrapinimas: <?= h($lastRun['finished_at'] ?? $lastRun['started_at']) ?>
      (<?= $lastRun['status'] === 'ok' ? 'sėkmingai' : 'su klaidomis' ?>)
    <?php else: ?>
      Dar nebuvo paleista jokia scrapinimo užduotis.
    <?php endif; ?>
  </div>
</header>

<?php if ($isDemo): ?>
  <div class="notice info">
    Tai demonstracinis vaizdas su pavyzdiniu vardu - dar nepridėtas nė vienas Tamo
    prisijungimas. Eikite į <a href="settings.php">Nustatymus</a> ir pridėkite savo paskyrą,
    kad pamatytumėte tikrus duomenis.
  </div>
<?php endif; ?>
<?php if ($dbError): ?>
  <div class="notice err">DB klaida: <?= h($dbError) ?> - patikrinkite config.php ir ar importuota schema.sql.</div>
<?php endif; ?>
<?php if ($lastRun && $lastRun['status'] === 'error'): ?>
  <div class="notice err">Paskutinio scrapinimo klaida(-os): <?= h($lastRun['message']) ?></div>
<?php endif; ?>

<main>
<div class="tabs">
  <?php foreach (TABS as $i => $t): ?>
    <input type="radio" name="tab" id="tab-<?= $t['id'] ?>" <?= $t['id'] === $activeTab ? 'checked' : '' ?>>
  <?php endforeach; ?>

  <div class="tab-bar">
    <?php foreach (TABS as $t): ?>
      <label for="tab-<?= $t['id'] ?>"><?= h($t['label']) ?></label>
    <?php endforeach; ?>
  </div>

  <div class="tab-panel" id="panel-tvarkarastis">
    <?php if (in_array('tvarkarastis', $restrictedTabs, true) && !$unlocked): renderLockBox('tvarkarastis', $selectedId, $pinError); else: ?>
    <p class="fetched">Atnaujinta: <?= h($tvarkarastis['fetched_at']) ?></p>
    <?php if ($tvarkarastis['error']): ?>
      <p class="err"><?= h($tvarkarastis['error']) ?></p>
    <?php elseif (empty($tvarkarastis['payload'])): ?>
      <p class="empty">Duomenų dar nėra.</p>
    <?php else: ?>
      <?php foreach ($tvarkarastis['payload'] as $di => $diena): ?>
        <?php if (empty($diena)) continue; ?>
        <div class="day-heading"><?= SAVAITES_DIENOS[$di + 1] ?? ('Diena ' . ($di + 1)) ?></div>
        <table>
          <tr><th>#</th><th>Laikas</th><th>Dalykas</th><th>Mokytojas</th></tr>
          <?php foreach ($diena as $p): ?>
            <tr>
              <td><?= h($p['numeris'] ?? '') ?></td>
              <td><?= h($p['laikas'] ?? '') ?></td>
              <td><?= h($p['dalykas'] ?? '') ?></td>
              <td><?= h($p['mokytojas'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="tab-panel" id="panel-dienynas">
    <?php if (in_array('dienynas', $restrictedTabs, true) && !$unlocked): renderLockBox('dienynas', $selectedId, $pinError); else: ?>
    <p class="fetched">Atnaujinta: <?= h($dienynas['fetched_at']) ?></p>
    <?php if ($dienynas['error']): ?>
      <p class="err"><?= h($dienynas['error']) ?></p>
    <?php elseif (empty($dienynas['payload']['ivertinimai']) && empty($dienynas['payload']['lankomumas'])): ?>
      <p class="empty">Duomenų dar nėra.</p>
    <?php else: ?>
      <table>
        <tr><th>Diena</th><th>Dalykas</th><th>Pažymys</th><th>Tipas</th><th>Taisymo data</th></tr>
        <?php foreach (($dienynas['payload']['ivertinimai'] ?? []) as $iv): ?>
          <tr>
            <td><?= h(SAVAITES_DIENOS[$iv['data']['w']] ?? '') ?> (<?= (int) $iv['data']['d'] ?>)</td>
            <td><?= h($iv['dalykas']) ?></td>
            <td><span class="badge"><?= h($iv['ivertinimas']) ?></span></td>
            <td><?= h($iv['tipas']) ?></td>
            <td><?= $iv['taisymo data']['m'] !== null ? sprintf('%02d-%02d', $iv['taisymo data']['m'], $iv['taisymo data']['d']) : '-' ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php if (!empty($dienynas['payload']['lankomumas'])): ?>
        <p><strong>Lankomumas</strong></p>
        <table>
          <tr><th>Diena</th><th>Dalykas</th><th>Tipas</th></tr>
          <?php foreach ($dienynas['payload']['lankomumas'] as $l): ?>
            <tr>
              <td><?= h(SAVAITES_DIENOS[$l['data']['w']] ?? '') ?> (<?= (int) $l['data']['d'] ?>)</td>
              <td><?= h($l['dalykas']) ?></td>
              <td><?= h($l['tipas']) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="tab-panel" id="panel-pusmeciai">
    <?php if (in_array('pusmeciai', $restrictedTabs, true) && !$unlocked): renderLockBox('pusmeciai', $selectedId, $pinError); else: ?>
    <p class="fetched">Atnaujinta: <?= h($pusmeciai['fetched_at']) ?></p>
    <?php if ($pusmeciai['error']): ?>
      <p class="err"><?= h($pusmeciai['error']) ?></p>
    <?php elseif (empty($pusmeciai['payload']['dalykai'])): ?>
      <p class="empty">Duomenų dar nėra.</p>
    <?php else: $v = $pusmeciai['payload']['vidurkis']; ?>
      <p>
        Bendras vidurkis: <strong><?= orDash($v['vidurkiu'] ?? null) ?></strong> &middot;
        Pažymių vidurkis: <?= orDash($v['pazymiu'] ?? null) ?> &middot;
        Išvestų pažymių vidurkis: <?= orDash($v['isvestu pazymiu'] ?? null) ?>
      </p>
      <table>
        <tr><th>Dalykas</th><th>Mokytojai</th><th>Vidurkis</th><th>Išvesta</th></tr>
        <?php foreach ($pusmeciai['payload']['dalykai'] as $d): ?>
          <tr>
            <td><?= h($d['dalykas']) ?></td>
            <td><?= h(implode(', ', $d['mokytojai'] ?? [])) ?></td>
            <td><?= orDash($d['vidurkis'] ?? null) ?></td>
            <td><?= orDash($d['isvesta'] ?? null) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="tab-panel" id="panel-namu_darbai">
    <?php if (in_array('namu_darbai', $restrictedTabs, true) && !$unlocked): renderLockBox('namu_darbai', $selectedId, $pinError); else: ?>
    <p class="fetched">Atnaujinta: <?= h($namuDarbai['fetched_at']) ?></p>
    <?php if ($namuDarbai['error']): ?>
      <p class="err"><?= h($namuDarbai['error']) ?></p>
    <?php elseif (empty($namuDarbai['payload'])): ?>
      <p class="empty">Duomenų dar nėra.</p>
    <?php else: ?>
      <p class="hint-line">Paryškinta: namų darbai iki <strong><?= h(SAVAITES_DIENOS[$targetDate['w']]) ?> (<?= fmtDate($targetDate) ?>)</strong></p>
      <table>
        <tr><th>Dalykas</th><th>Namų darbas</th><th>Atlikimo data</th><th>Mokytojas</th></tr>
        <?php foreach ($namuDarbai['payload'] as $n): ?>
          <?php $dueDate = $n['atlikimo data'] ?? ($n['pamokos data'] ?? null); ?>
          <tr class="<?= isSameDate($dueDate, $targetDate) ? 'hw-due' : '' ?>">
            <td><?= h($n['dalykas'] ?? '') ?></td>
            <td><?= h($n['namu darbas'] ?? '') ?></td>
            <td><?= fmtDate($dueDate) ?></td>
            <td><?= h($n['mokytojas'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="tab-panel" id="panel-atsiskaitomieji">
    <?php if (in_array('atsiskaitomieji', $restrictedTabs, true) && !$unlocked): renderLockBox('atsiskaitomieji', $selectedId, $pinError); else: ?>
    <p class="fetched">Atnaujinta: <?= h($atsiskaitomieji['fetched_at']) ?></p>
    <?php if ($atsiskaitomieji['error']): ?>
      <p class="err"><?= h($atsiskaitomieji['error']) ?></p>
    <?php elseif (empty($atsiskaitomieji['payload'])): ?>
      <p class="empty">Duomenų dar nėra.</p>
    <?php else: ?>
      <table>
        <tr><th>Diena</th><th>Dalykas</th><th>Grupė</th><th>Tipas</th></tr>
        <?php foreach ($atsiskaitomieji['payload'] as $a): ?>
          <tr>
            <td><?= (int) $a['data']['d'] ?></td>
            <td><?= h($a['dalykas'] ?? '') ?></td>
            <td><?= h($a['grupe'] ?? '') ?></td>
            <td><?= h(implode(', ', $a['pilni tipai'] ?? [])) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="tab-panel" id="panel-pastabos">
    <?php if (in_array('pastabos', $restrictedTabs, true) && !$unlocked): renderLockBox('pastabos', $selectedId, $pinError); else: ?>
    <p class="fetched">Atnaujinta: <?= h($pastabos['fetched_at']) ?></p>
    <?php if ($pastabos['error']): ?>
      <p class="err"><?= h($pastabos['error']) ?></p>
    <?php elseif (empty($pastabos['payload'])): ?>
      <p class="empty">Duomenų dar nėra.</p>
    <?php else: ?>
      <table>
        <tr><th>Data</th><th>Tipas</th><th>Dalykas</th><th>Tekstas</th><th>Mokytojas</th></tr>
        <?php foreach ($pastabos['payload'] as $p): ?>
          <tr>
            <td><?= fmtDate($p['pamokos data'] ?? null) ?></td>
            <td><?= h($p['tipas'] ?? '') ?></td>
            <td><?= h($p['dalykas'] ?? '') ?></td>
            <td><?= h($p['tekstas'] ?? '') ?></td>
            <td><?= h($p['mokytojas'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="tab-panel" id="panel-pranesimai">
    <?php if (in_array('pranesimai', $restrictedTabs, true) && !$unlocked): renderLockBox('pranesimai', $selectedId, $pinError); else: ?>
    <?php if (empty($messages)): ?>
      <p class="empty">Pranešimų dar nėra.</p>
    <?php else: ?>
      <?php foreach ($messages as $m): ?>
        <div class="msg <?= $m['perskaityta'] ? '' : 'unread' ?>">
          <div class="subj"><?= h($m['tema']) ?></div>
          <div class="sub">
            <?= h($m['siuntejas']) ?> (<?= h($m['siuntejo_tipas']) ?>) &middot;
            <?= sprintf('%04d-%02d-%02d %02d:%02d', $m['data_y'], $m['data_m'], $m['data_d'], $m['data_h'], $m['data_min']) ?>
            <?= $m['perskaityta'] ? '' : ' &middot; <strong>neperskaityta</strong>' ?>
          </div>
          <?php if ($m['body_fetched']): ?>
            <div class="body-text"><?= h($m['tekstas']) ?></div>
          <?php else: ?>
            <div class="empty">Pilnas tekstas dar neparsiųstas (bus kito scrapinimo metu).</div>
          <?php endif; ?>
          <?php if (!empty($m['attachments'])): ?>
            <div class="sub">Priedai: <?= h(implode(', ', array_column($m['attachments'], 'pavadinimas'))) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>
  </div>

</div>
</main>

<div id="scrapeOverlay" class="scrape-overlay" hidden>
  <div class="scrape-box">
    <div class="scrape-spinner"></div>
    <div id="scrapeLabel">Duomenys kraunami...</div>
    <div id="scrapeSlow" class="scrape-slow" hidden>Užtrunka ilgiau nei įprasta - vis dar dirbama.</div>
  </div>
</div>

<script>
(function () {
  var btn = document.getElementById('refreshBtn');
  var overlay = document.getElementById('scrapeOverlay');
  var label = document.getElementById('scrapeLabel');
  var slowNotice = document.getElementById('scrapeSlow');
  var polling = false;

  // Apsauginiai limitai, kad progreso juosta niekada nesisuktų amžinai, net jei serveris
  // (žr. Db::getScrapeStatus "stale" aptikimą) dėl kokių nors priežasčių neatsukų būsenos pats:
  // ~150 x 1.2s = apie 3 min. maksimalus laukimas, ~8 x 2s = 16s tinklo klaidų tolerancija.
  var MAX_POLL_ATTEMPTS = 150;
  var MAX_ERROR_ATTEMPTS = 8;

  function giveUp(message) {
    label.textContent = message;
    setTimeout(function () {
      overlay.hidden = true;
      btn.disabled = false;
      polling = false;
    }, 4000);
  }

  function poll(attempt, errorAttempt) {
    if (attempt > MAX_POLL_ATTEMPTS) {
      giveUp('Neatsakoma per ilgai - bandykite dar kartą vėliau.');
      return;
    }
    fetch('scrape_status.php', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (s) {
        // Sąmoningai NErodom kiekvieno žingsnio (dalykas/vaikas) - tik paprasta "kraunama" būsena,
        // kol scrapinimas nesibaigė.
        if (attempt > 20) { slowNotice.hidden = false; }
        if (s.stale) {
          giveUp(s.label || 'Nutrūko netikėtai - bandykite dar kartą.');
          return;
        }
        if (s.running) {
          setTimeout(function () { poll(attempt + 1, 0); }, 1200);
        } else {
          label.textContent = s.label || 'Baigta';
          setTimeout(function () {
            window.location.href = window.location.pathname + window.location.search;
          }, 500);
        }
      })
      .catch(function () {
        if (errorAttempt >= MAX_ERROR_ATTEMPTS) {
          giveUp('Nepavyksta susisiekti su serveriu.');
          return;
        }
        setTimeout(function () { poll(attempt + 1, errorAttempt + 1); }, 2000);
      });
  }

  btn.addEventListener('click', function () {
    if (polling) { return; }
    polling = true;
    btn.disabled = true;
    overlay.hidden = false;
    slowNotice.hidden = true;
    label.textContent = 'Duomenys kraunami...';
    fetch('scrape_trigger.php', { method: 'POST', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.started) {
          giveUp('Klaida: ' + (res.error || 'nepavyko paleisti'));
          return;
        }
        poll(0, 0);
      })
      .catch(function () {
        giveUp('Nepavyko susisiekti su serveriu.');
      });
  });
})();
</script>
</body>
</html>
