<?php
/**
 * Web diegimo vediklis. Padeda kitiems vartotojams sukonfigūruoti config.php ir
 * duomenų bazę be rankinio failų redagavimo ar mysql komandinės eilutės.
 *
 * Naudojimas: nukopijuokite VISUS šio projekto failus į XAMPP htdocs katalogą,
 * tada naršyklėje atsidarykite http://localhost/<jusu_katalogas>/install.php
 *
 * Saugumas: kai config.php jau sukurtas, šis skriptas savaime atsisako veikti
 * (žr. žemiau) - taip apsaugoma nuo atsitiktinio pakartotinio duomenų bazės
 * konfigūracijos perrašymo veikiančioje sistemoje. Baigę diegimą, šį failą
 * galite tiesiog ištrinti.
 */

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    die('Diegimo vediklis pasiekiamas tik iš localhost (paleiskite naršyklę tame pačiame kompiuteryje, kuriame veikia XAMPP).');
}

$configPath = __DIR__ . '/config.php';
$examplePath = __DIR__ . '/config.example.php';
$schemaPath = __DIR__ . '/schema.sql';

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function guessPhpCliPath(): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $candidates = [
            'C:\\xampp\\php\\php.exe',
            dirname(PHP_BINARY) . '\\php.exe',
        ];
    } else {
        // Linux/macOS: PATH-e esantis "php" dažniausiai yra CLI binaras net jei šis
        // puslapis rodomas per mod_php/PHP-FPM (kurio PHP_BINARY rodytų į apache2/php-fpm).
        $candidates = [
            trim((string) @shell_exec('command -v php 2>/dev/null')),
            '/usr/bin/php',
            '/usr/local/bin/php',
            dirname(PHP_BINARY) . '/php',
        ];
    }
    foreach ($candidates as $c) {
        if ($c !== '' && is_file($c)) {
            return $c;
        }
    }
    return PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php';
}

$errors = [];
$done = false;

// --- Jei config.php JAU egzistuoja - atsisakom veikti (apsauga nuo perrašymo). ---
$alreadyInstalled = is_file($configPath);

// --- Reikalavimų patikra ---
$checks = [
    'PHP versija >= 8.1' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO MySQL plėtinys (pdo_mysql)' => extension_loaded('pdo_mysql'),
    'DOM plėtinys (dom)' => extension_loaded('dom'),
    'cURL plėtinys (curl)' => extension_loaded('curl'),
    'config.example.php rastas' => is_file($examplePath),
    'schema.sql rastas' => is_file($schemaPath),
    'Galima rašyti į šį katalogą' => is_writable(__DIR__),
];
$checksOk = !in_array(false, $checks, true);

if (!$alreadyInstalled && $checksOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = (int) ($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? 'tamo');
    $dbUser = trim($_POST['db_user'] ?? 'root');
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $phpCliPath = trim($_POST['php_cli_path'] ?? '');
    $apiToken = trim($_POST['api_token'] ?? '');
    $pranesimuPuslapiai = max(1, (int) ($_POST['pranesimu_puslapiai'] ?? 1));
    $parsiustiTekstus = isset($_POST['parsiusti_tekstus']);

    if ($dbName === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
        $errors[] = 'Duomenų bazės pavadinimas gali turėti tik raides, skaičius ir "_".';
    }
    if ($dbUser === '') {
        $errors[] = 'Nurodykite MySQL vartotoją.';
    }
    if ($phpCliPath === '' || !is_file($phpCliPath) || stripos(basename($phpCliPath), 'php') === false) {
        $errors[] = 'Nurodytas PHP CLI kelias neteisingas arba failas neegzistuoja: ' . h($phpCliPath);
    }

    if (empty($errors)) {
        try {
            // 1) Prisijungiam BE dbname ir sukuriam duomenų bazę, jei jos dar nėra.
            $pdo = new PDO(
                "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            // 2) Prisijungiam PRIE tos duomenų bazės ir importuojam schemą.
            $pdo = new PDO(
                "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $sql = file_get_contents($schemaPath);
            $lines = preg_split('/\r\n|\r|\n/', $sql);
            $clean = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                    continue;
                }
                $clean[] = $line;
            }
            $sql = implode("\n", $clean);

            foreach (explode(';', $sql) as $statement) {
                $statement = trim($statement);
                if ($statement === '') {
                    continue;
                }
                // CREATE DATABASE / USE jau atlikta aukščiau su vartotojo nurodytu pavadinimu.
                if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $statement)) {
                    continue;
                }
                $pdo->exec($statement);
            }

            // 3) Sugeneruojam config.php.
            $config = [
                'mokiniai' => [], // prisijungimai tvarkomi per settings.php, čia tik legacy fallback
                'db' => [
                    'host' => $dbHost,
                    'port' => $dbPort,
                    'name' => $dbName,
                    'user' => $dbUser,
                    'pass' => $dbPass,
                ],
                'pranesimu_puslapiai' => $pranesimuPuslapiai,
                'parsiusti_pranesimu_tekstus' => $parsiustiTekstus,
                'api_token' => $apiToken !== '' ? $apiToken : null,
                'php_cli_path' => $phpCliPath,
            ];

            $exported = var_export($config, true);
            $php = "<?php\n\n/**\n * Sugeneruota automatiškai per install.php " . date('Y-m-d H:i') . ".\n"
                . " * Prisijungimus (vaikus) tvarkykite per settings.php, ne čia.\n */\n\nreturn "
                . $exported . ";\n";

            if (file_put_contents($configPath, $php) === false) {
                throw new RuntimeException('Nepavyko įrašyti config.php - patikrinkite katalogo teises.');
            }

            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Klaida diegiant: ' . $e->getMessage();
        }
    }
}

$defaultPhpCliPath = guessPhpCliPath();

?><!doctype html>
<html lang="lt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diegimas - Tamo</title>
<style>
  :root {
    color-scheme: light dark;
    --bg: #ffffff; --fg: #16161a; --muted: #6b7280; --line: #d7d9e0;
    --accent: #4f46e5; --accent-fg: #ffffff; --ok: #0a7a3d; --ok-bg: #e7f8ee;
    --err: #c81e4a; --err-bg: #fdecef;
  }
  * { box-sizing: border-box; }
  body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; background: var(--bg); color: var(--fg); line-height: 1.5; }
  header { background: var(--accent); color: var(--accent-fg); padding: 20px 16px; }
  header h1 { margin: 0; font-size: 22px; }
  header p { margin: 6px 0 0; opacity: .9; font-size: 14px; }
  main { max-width: 640px; margin: 0 auto; padding: 24px 16px 60px; }
  .card { border: 2px solid var(--line); border-radius: 10px; padding: 18px; margin-bottom: 20px; }
  h2 { font-size: 17px; color: var(--accent); margin: 0 0 12px; }
  label { display: block; font-weight: 600; font-size: 14px; margin: 12px 0 4px; }
  input[type="text"], input[type="number"], input[type="password"] {
    width: 100%; font-size: 15px; padding: 9px; border: 2px solid var(--line); border-radius: 8px;
    background: var(--bg); color: var(--fg);
  }
  .checkline { display: flex; align-items: center; gap: 8px; margin-top: 14px; font-weight: 400; font-size: 15px; }
  .checkline input { width: 20px; height: 20px; }
  .hint { font-size: 13px; color: var(--muted); margin-top: 4px; }
  .btn {
    display: inline-block; font-size: 15px; font-weight: 700; padding: 11px 20px; margin-top: 18px;
    border: 2px solid var(--accent); border-radius: 8px; background: var(--accent); color: var(--accent-fg);
    cursor: pointer; text-decoration: none;
  }
  ul.checklist { list-style: none; padding: 0; margin: 0; }
  ul.checklist li { padding: 6px 0; font-size: 15px; }
  .yes { color: var(--ok); font-weight: 700; }
  .no { color: var(--err); font-weight: 700; }
  .notice { padding: 12px 16px; border-radius: 8px; font-size: 15px; margin-bottom: 16px; }
  .notice.ok { background: var(--ok-bg); color: var(--ok); }
  .notice.err { background: var(--err-bg); color: var(--err); }
  a.next { display: inline-block; margin: 6px 12px 0 0; }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #0e0e12; --fg: #f2f2f5; --muted: #9a9aa5; --line: #2c2c35;
      --accent: #818cf8; --accent-fg: #0e0e12; --ok: #4ade80; --ok-bg: #0c2a1a;
      --err: #fb7185; --err-bg: #3a1420;
    }
  }
</style>
</head>
<body>
<header>
  <h1>⚙ Tamo diegimo vediklis</h1>
  <p>Vienkartinė sąranka: duomenų bazė + config.php</p>
</header>
<main>

<?php if ($alreadyInstalled): ?>

  <div class="notice ok">config.php jau sukonfigūruotas - diegimas jau atliktas anksčiau.</div>
  <div class="card">
    <p>Jei norite diegti iš naujo (pvz. pakeisti DB duomenis), pirmiausia ištrinkite arba
    pervadinkite esamą <code>config.php</code> faile esantį katalogą, tada perkraukite šį puslapį.</p>
    <a class="next btn" href="index.php">Eiti į dashboard'ą</a>
    <a class="next btn" style="background:var(--bg);color:var(--accent)" href="settings.php">Eiti į nustatymus</a>
  </div>

<?php elseif ($done): ?>

  <div class="notice ok">Diegimas sėkmingai baigtas! config.php ir duomenų bazė sukurti.</div>
  <div class="card">
    <h2>Tolimesni žingsniai</h2>
    <ol>
      <li>Atsidarykite <strong>Nustatymai</strong> (pradinis PIN kodas: <strong>999999</strong>,
          pakeiskite jį po pirmo prisijungimo) ir pridėkite savo Tamo prisijungimo duomenis.</li>
      <?php $scrapePath = __DIR__ . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'scrape.php'; ?>
      <li>Paleiskite pirmą duomenų surinkimą rankiniu būdu (arba paspauskite ⟳ dashboard'e):<br>
          <code><?= h($config['php_cli_path'] ?? '') ?> "<?= h($scrapePath) ?>"</code></li>
      <?php if (PHP_OS_FAMILY === 'Windows'): ?>
      <li>Sukurkite periodinę Windows Task Scheduler užduotį, kuri šį skriptą paleistų kas
          20-30 min (Action = PHP CLI kelias, Arguments = <code>cli\scrape.php</code> kelias kabutėse).</li>
      <?php else: ?>
      <li>Sukurkite periodinę cron užduotį (<code>crontab -e</code>), kuri šį skriptą paleistų kas
          20-30 min, pvz.:<br>
          <code>*/30 * * * * <?= h($config['php_cli_path'] ?? '') ?> <?= h($scrapePath) ?> &gt; /dev/null 2&gt;&amp;1</code></li>
      <?php endif; ?>
      <li>Saugumo sumetimais dabar galite ištrinti šį failą (<code>install.php</code>).</li>
    </ol>
    <a class="next btn" href="index.php">Eiti į dashboard'ą</a>
    <a class="next btn" style="background:var(--bg);color:var(--accent)" href="settings.php">Eiti į nustatymus</a>
  </div>

<?php else: ?>

  <div class="card">
    <h2>1. Reikalavimų patikra</h2>
    <ul class="checklist">
      <?php foreach ($checks as $label => $ok): ?>
        <li><span class="<?= $ok ? 'yes' : 'no' ?>"><?= $ok ? '✔' : '✘' ?></span> <?= h($label) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$checksOk): ?>
      <p class="hint" style="color:var(--err)">Ne visi reikalavimai įvykdyti - ištaisykite aukščiau
      pažymėtus punktus ir perkraukite šį puslapį.</p>
    <?php endif; ?>
  </div>

  <?php if ($checksOk): ?>
    <?php foreach ($errors as $e): ?>
      <div class="notice err"><?= h($e) ?></div>
    <?php endforeach; ?>

    <form method="post" class="card">
      <h2>2. Duomenų bazė (MySQL / MariaDB)</h2>
      <label>Host</label>
      <input type="text" name="db_host" value="<?= h($_POST['db_host'] ?? 'localhost') ?>">
      <label>Portas</label>
      <input type="number" name="db_port" value="<?= h($_POST['db_port'] ?? '3306') ?>">
      <label>Duomenų bazės pavadinimas</label>
      <input type="text" name="db_name" value="<?= h($_POST['db_name'] ?? 'tamo') ?>">
      <p class="hint">Jei tokios duomenų bazės dar nėra - ji bus sukurta automatiškai.</p>
      <label>Vartotojas</label>
      <input type="text" name="db_user" value="<?= h($_POST['db_user'] ?? 'root') ?>">
      <label>Slaptažodis</label>
      <input type="password" name="db_pass" value="<?= h($_POST['db_pass'] ?? '') ?>">
      <p class="hint">XAMPP numatytieji: root / (tuščias slaptažodis).</p>

      <h2 style="margin-top:22px">3. PHP CLI kelias</h2>
      <label>PHP komandinės eilutės (CLI) kelias</label>
      <input type="text" name="php_cli_path" value="<?= h($_POST['php_cli_path'] ?? $defaultPhpCliPath) ?>">
      <p class="hint">
        Naudojamas dashboard'o "Atnaujinti dabar" mygtukui fone paleisti scrapinimą.
        SVARBU: tai turi būti CLI PHP dvejetainis failas, o NE tas pats binaras, kuris rodo
        šį puslapį (Apache/httpd arba PHP-FPM). Pvz. Windows:
        <code>C:\xampp\php\php.exe</code>, Linux: <code>/usr/bin/php</code>
        (patikrinkite terminale komanda <code>which php</code>).
      </p>

      <h2 style="margin-top:22px">4. Papildomi nustatymai</h2>
      <label>Home Assistant API tokenas (nebūtina)</label>
      <input type="text" name="api_token" value="<?= h($_POST['api_token'] ?? '') ?>">
      <p class="hint">Jei paliksite tuščią, api.php veiks be autentifikacijos (tinka tik namų tinkle).</p>

      <label>Pranešimų puslapių tikrinti kas atnaujinimą</label>
      <input type="number" name="pranesimu_puslapiai" min="1" value="<?= h($_POST['pranesimu_puslapiai'] ?? '1') ?>">

      <label class="checkline">
        <input type="checkbox" name="parsiusti_tekstus" <?= !isset($_POST['db_host']) || isset($_POST['parsiusti_tekstus']) ? 'checked' : '' ?>>
        Parsiųsti pilną naujų pranešimų tekstą
      </label>

      <button class="btn" type="submit">Diegti</button>
    </form>
  <?php endif; ?>

<?php endif; ?>

</main>
</body>
</html>
