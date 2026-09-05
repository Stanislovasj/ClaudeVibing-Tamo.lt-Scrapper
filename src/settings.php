<?php

session_start();

require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/Tabs.php';

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// Pirmas paleidimas - config.php dar nesukurtas, nukreipiam į web diegimo vediklį
// vietoj to, kad rodytume vartotojui pliką klaidos pranešimą.
if (!is_file(__DIR__ . '/config.php') && is_file(__DIR__ . '/install.php')) {
    header('Location: install.php');
    exit;
}

try {
    Config::get();
    Db::ensurePinsSeeded('999999');
} catch (Throwable $e) {
    http_response_code(500);
    echo '<p style="font-family:sans-serif;color:#b00">' . h($e->getMessage()) . '</p>';
    exit;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['settings_ok']);
    header('Location: settings.php');
    exit;
}

$error = null;
$notice = null;

// SVARBU: settings.php naudoja SAVO ('settings') PIN raktą, visiškai nepriklausomą nuo
// dashboard'e naudojamo skyrių ('section') PIN rakto - vieno atrakinimas nesuteikia prieigos prie kito.
if (!empty($_SESSION['settings_ok'])) {
    // --- Jau atrakinta - tvarkom veiksmus ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_login':
                $id = trim($_POST['id'] ?? '');
                $vardas = trim($_POST['vardas'] ?? ''); // nebūtinas - žr. paaiškinimą prie lauko
                $username = trim($_POST['username'] ?? '');
                $password = (string) ($_POST['password'] ?? '');
                if ($id === '' || $username === '') {
                    $error = 'ID ir Tamo prisijungimo vardas yra privalomi.';
                } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
                    $error = 'ID gali turėti tik lotyniškas raides, skaičius, "-" arba "_" (be tarpų).';
                } else {
                    if ($password === '') {
                        $existing = Db::getLogin($id);
                        if ($existing === null) {
                            $error = 'Naujam prisijungimui slaptažodis privalomas.';
                        } else {
                            $password = $existing['password'];
                        }
                    }
                    if ($error === null) {
                        Db::upsertLogin($id, $vardas, $username, $password);
                        // Post/Redirect/Get - grįžtam į paprastą nustatymų sąrašą (uždarom redagavimo formą).
                        header('Location: settings.php?saved=1&stab=logins');
                        exit;
                    }
                }
                break;

            case 'delete_login':
                $deletedVardai = Db::deleteLoginCascade($_POST['id'] ?? '');
                $notice = empty($deletedVardai)
                    ? 'Prisijungimas pašalintas.'
                    : 'Prisijungimas ir jo duomenys pašalinti (' . implode(', ', $deletedVardai) . ').';
                break;

            case 'change_settings_pin':
            case 'change_section_pin':
                $kind = $_POST['action'] === 'change_settings_pin' ? 'settings' : 'section';
                $newPin = (string) ($_POST['new_pin'] ?? '');
                $newPin2 = (string) ($_POST['new_pin2'] ?? '');
                if (!preg_match('/^\d{4,10}$/', $newPin)) {
                    $error = 'PIN turi būti sudarytas iš 4-10 skaitmenų.';
                } elseif ($newPin !== $newPin2) {
                    $error = 'Įvesti PIN kodai nesutampa.';
                } else {
                    Db::setPin($kind, $newPin);
                    $notice = ($kind === 'settings' ? 'Nustatymų' : 'Skyrių') . ' PIN kodas pakeistas.';
                }
                break;

            case 'save_restricted':
                $ids = $_POST['restricted'] ?? [];
                Db::setRestrictedTabs(is_array($ids) ? array_values($ids) : []);
                $notice = 'Apsaugoti skyriai išsaugoti.';
                break;

            case 'save_theme':
                Db::setTheme($_POST['theme'] ?? 'tablet');
                $notice = 'Šablonas pakeistas.';
                break;
        }
    }
} else {
    // --- Neatrakinta - tikrinam PIN ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
        if (Db::verifyPin('settings', $_POST['pin'])) {
            $_SESSION['settings_ok'] = true;
            header('Location: settings.php');
            exit;
        }
        $error = 'Neteisingas PIN kodas.';
    }
}

if ($notice === null && $error === null && isset($_GET['saved'])) {
    $notice = 'Prisijungimas išsaugotas. Duomenys atsiras dashboard\'e po kito atnaujinimo.';
}

$unlocked = !empty($_SESSION['settings_ok']);
$logins = $unlocked ? Db::listLogins() : [];
$restrictedTabs = $unlocked ? Db::getRestrictedTabs() : [];
$currentTheme = $unlocked ? Db::getTheme() : 'tablet';
$editId = $_GET['edit'] ?? null;
$editLogin = $editId !== null ? Db::getLogin($editId) : null;

const SETTINGS_TABS = [
    ['id' => 'logins', 'label' => 'Prisijungimai'],
    ['id' => 'settings_pin', 'label' => 'Nustatymų PIN'],
    ['id' => 'section_pin', 'label' => 'Skyrių PIN'],
    ['id' => 'restricted', 'label' => 'Apsaugoti skyriai'],
    ['id' => 'theme', 'label' => 'Šablonas'],
];
$stabIds = array_column(SETTINGS_TABS, 'id');
$activeStab = $_GET['stab'] ?? SETTINGS_TABS[0]['id'];
if (!in_array($activeStab, $stabIds, true)) {
    $activeStab = SETTINGS_TABS[0]['id'];
}
if ($editLogin !== null) {
    $activeStab = 'logins'; // redaguojant prisijungimą, visada rodom "Prisijungimai" tabą
}

?><!doctype html>
<html lang="lt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Nustatymai - Tamo</title>
<style>
  :root {
    color-scheme: light dark;
    --bg: #ffffff; --fg: #16161a; --muted: #6b7280; --line: #d7d9e0;
    --accent: #4f46e5; --accent-fg: #ffffff; --accent-soft: #eef0fe;
    --header-bg: #4f46e5; --header-fg: #ffffff;
    --btn-bg: rgba(255,255,255,.14); --btn-fg: #ffffff; --btn-border: rgba(255,255,255,.55);
  }
  * { box-sizing: border-box; }
  body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; background: var(--bg); color: var(--fg); font-size: 18px; line-height: 1.4; }
  a { color: inherit; }
  header { background: var(--header-bg); color: var(--header-fg); padding: 14px 16px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
  header h1 { font-size: 19px; margin: 0; font-weight: 800; }
  header .spacer { flex: 1 1 auto; }
  header a.hbtn {
    font-size: 15px; font-weight: 700; padding: 8px 14px; border: 2px solid var(--btn-border);
    border-radius: 999px; background: var(--btn-bg); color: var(--btn-fg); text-decoration: none;
  }
  main { max-width: 720px; margin: 0 auto; padding: 0 0 60px; }
  section { margin-bottom: 32px; }
  h2 { font-size: 17px; margin: 0 0 12px; color: var(--accent); }

  /* --- CSS-only skirtukai (tabs), be JavaScript: paslėpti radio + label + bendras sibling selector. --- */
  /* SVARBU: ">" (tik tiesioginis vaikas) - kitaip ši taisyklė paslėptų ir "Šablonas" tabo
     viduje esančius theme radio mygtukus (jie irgi type="radio", bet ne tabo perjungikliai). */
  .tabs > input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
  .tab-bar {
    display: flex; flex-wrap: wrap; gap: 0;
    border-bottom: 2px solid var(--line);
    position: sticky; top: 0; background: var(--bg); z-index: 1;
  }
  .tab-bar label {
    display: block; padding: 14px 10px; font-size: 15px; font-weight: 600;
    border-right: 1px solid var(--line); cursor: pointer; user-select: none;
    flex: 1 1 auto; text-align: center; min-width: 100px;
  }
  .tab-bar label:last-child { border-right: none; }
  .tab-panel { display: none; padding: 20px 16px 0; }
  <?php foreach (SETTINGS_TABS as $t): ?>
  #stab-<?= $t['id'] ?>:checked ~ .tab-bar label[for="stab-<?= $t['id'] ?>"] { background: var(--accent); color: var(--accent-fg); }
  #stab-<?= $t['id'] ?>:checked ~ #spanel-<?= $t['id'] ?> { display: block; }
  <?php endforeach; ?>
  .card { border: 2px solid var(--line); border-radius: 10px; padding: 16px; margin-bottom: 14px; }
  label { display: block; font-size: 14px; font-weight: 600; margin: 10px 0 4px; }
  input[type="text"], input[type="password"] {
    width: 100%; font-size: 16px; padding: 10px; border: 2px solid var(--line); border-radius: 8px;
    background: var(--bg); color: var(--fg);
  }
  .btn {
    display: inline-block; font-size: 15px; font-weight: 700; padding: 10px 18px; margin-top: 12px;
    border: 2px solid var(--accent); border-radius: 8px; background: var(--accent); color: var(--accent-fg);
    cursor: pointer; text-decoration: none;
  }
  .btn.secondary { background: var(--bg); color: var(--accent); }
  .btn.danger { background: #fff; color: #c81e4a; border-color: #c81e4a; }
  .login-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--line); }
  .login-row:last-child { border-bottom: none; }
  .login-row .who { font-weight: 700; }
  .login-row .sub { font-size: 13px; color: var(--muted); }
  .login-row .actions { display: flex; gap: 8px; flex-shrink: 0; }
  .login-row .actions form { display: inline; }
  .login-row .actions button, .login-row .actions a {
    font-size: 13px; font-weight: 700; padding: 6px 10px; border-radius: 6px; border: 2px solid var(--line);
    background: var(--bg); color: var(--fg); cursor: pointer; text-decoration: none;
  }
  .notice { padding: 12px 16px; border-radius: 8px; font-size: 15px; margin-bottom: 16px; }
  .notice.ok { background: #e7f8ee; color: #0a7a3d; }
  .notice.err { background: #fdecef; color: #c81e4a; }
  .hint { font-size: 13px; color: var(--muted); margin-top: 4px; }
  .tab-check { display: flex; align-items: center; gap: 8px; padding: 8px 0; font-size: 16px; }
  .tab-check input { width: 22px; height: 22px; }
  .theme-option { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; font-weight: 400; }
  .theme-option input { width: 22px; height: 22px; flex-shrink: 0; margin-top: 2px; }
  .theme-option span { font-size: 15px; }

  .pin-gate { max-width: 360px; margin: 60px auto; text-align: center; padding: 0 16px; }
  .pin-gate .icon { font-size: 48px; }
  .pin-gate input[type="password"] {
    font-size: 24px; letter-spacing: 6px; text-align: center; margin-top: 16px;
  }

  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #0e0e12; --fg: #f2f2f5; --muted: #9a9aa5; --line: #2c2c35;
      --accent: #818cf8; --accent-fg: #0e0e12; --accent-soft: #1f2049;
      --header-bg: #312e81; --header-fg: #ffffff;
      --btn-bg: rgba(255,255,255,.12); --btn-fg: #ffffff; --btn-border: rgba(255,255,255,.4);
    }
    .notice.ok { background: #0c2a1a; color: #4ade80; }
    .notice.err { background: #3a1420; color: #fb7185; }
    .btn.danger { background: transparent; color: #fb7185; border-color: #fb7185; }
  }
</style>
</head>
<body>
<header>
  <h1>⚙ Nustatymai</h1>
  <div class="spacer"></div>
  <a class="hbtn" href="index.php">← Dashboard</a>
  <?php if ($unlocked): ?><a class="hbtn" href="settings.php?logout=1">Atsijungti</a><?php endif; ?>
</header>

<main>

<?php if (!$unlocked): ?>

  <div class="pin-gate">
    <div class="icon">🔒</div>
    <p>Įveskite PIN kodą, kad pasiektumėte nustatymus.</p>
    <?php if ($error): ?><div class="notice err"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="password" inputmode="numeric" pattern="[0-9]*" name="pin" placeholder="PIN kodas" autofocus>
      <div><button class="btn" type="submit" style="width:100%">Atrakinti</button></div>
    </form>
    <p class="hint">Pirminis PIN: 999999 (pakeiskite jį žemiau, kai atsirakinsite).</p>
  </div>

<?php else: ?>

  <?php if ($notice): ?><div class="notice ok"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice err"><?= h($error) ?></div><?php endif; ?>

  <div class="tabs">
    <?php foreach (SETTINGS_TABS as $t): ?>
      <input type="radio" name="stab" id="stab-<?= $t['id'] ?>" <?= $t['id'] === $activeStab ? 'checked' : '' ?>>
    <?php endforeach; ?>

    <div class="tab-bar">
      <?php foreach (SETTINGS_TABS as $t): ?>
        <label for="stab-<?= $t['id'] ?>"><?= h($t['label']) ?></label>
      <?php endforeach; ?>
    </div>

  <div class="tab-panel" id="spanel-logins">
  <section>
    <h2>Tamo prisijungimai</h2>
    <div class="card">
      <?php if (empty($logins)): ?>
        <p class="hint">Dar nepridėta nė vieno prisijungimo.</p>
      <?php else: ?>
        <?php foreach ($logins as $l): ?>
          <div class="login-row">
            <div>
              <div class="who"><?= h($l['vardas']) ?></div>
              <div class="sub">ID: <?= h($l['id']) ?> &middot; Tamo vartotojas: <?= h($l['username']) ?></div>
            </div>
            <div class="actions">
              <a href="settings.php?edit=<?= urlencode($l['id']) ?>#login-form">Keisti</a>
              <form method="post" onsubmit="return confirm('Ištrinti šį prisijungimą IR visus jo mokinio duomenis (tvarkaraštį, pažymius, pranešimus ir t.t.)? Šio veiksmo atšaukti negalima.');">
                <input type="hidden" name="action" value="delete_login">
                <input type="hidden" name="id" value="<?= h($l['id']) ?>">
                <button type="submit">Ištrinti</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="card" id="login-form">
      <strong><?= $editLogin ? 'Keisti prisijungimą' : 'Pridėti vaiką / prisijungimą' ?></strong>
      <form method="post">
        <input type="hidden" name="action" value="save_login">
        <label>ID (trumpas, be tarpų, pvz. "sunus")</label>
        <input type="text" name="id" value="<?= h($editLogin['id'] ?? '') ?>" <?= $editLogin ? 'readonly' : '' ?> required>

        <label>Vardas (nebūtina)</label>
        <input type="text" name="vardas" value="<?= h($editLogin['vardas'] ?? '') ?>">
        <p class="hint">
          Galite palikti tuščią - vaiko(-ų) vardas paprastai automatiškai atpažįstamas iš pačios
          Tamo paskyros po pirmo atnaujinimo (net jei paskyroje kelis vaikus mato tas pats
          prisijungimas). Šį lauką pildykite tik jei norite iš anksto priskirti savo pavadinimą,
          arba jei automatinis atpažinimas dėl kokių nors priežasčių nepavyktų.
        </p>

        <label>Tamo prisijungimo vardas</label>
        <input type="text" name="username" value="<?= h($editLogin['username'] ?? '') ?>" required>

        <label>Tamo slaptažodis<?= $editLogin ? ' (palikite tuščią, jei nekeičiate)' : '' ?></label>
        <input type="password" name="password" <?= $editLogin ? '' : 'required' ?>>

        <button class="btn" type="submit"><?= $editLogin ? 'Išsaugoti pakeitimus' : 'Pridėti' ?></button>
        <?php if ($editLogin): ?><a class="btn secondary" href="settings.php">Atšaukti</a><?php endif; ?>
      </form>
    </div>
  </section>
  </div>

  <div class="tab-panel" id="spanel-settings_pin">
  <section>
    <h2>Nustatymų PIN kodas</h2>
    <div class="card">
      <p class="hint">Šis PIN saugo prieigą prie šito puslapio (settings.php).</p>
      <form method="post">
        <input type="hidden" name="action" value="change_settings_pin">
        <label>Naujas PIN (4-10 skaitmenys)</label>
        <input type="password" inputmode="numeric" pattern="[0-9]*" name="new_pin" required>
        <label>Pakartokite naują PIN</label>
        <input type="password" inputmode="numeric" pattern="[0-9]*" name="new_pin2" required>
        <button class="btn" type="submit">Keisti nustatymų PIN</button>
      </form>
    </div>
  </section>
  </div>

  <div class="tab-panel" id="spanel-section_pin">
  <section>
    <h2>Skyrių PIN kodas</h2>
    <div class="card">
      <p class="hint">
        Šis PIN saugo apačioje pažymėtus dashboard'o skyrius. Jis <strong>skiriasi</strong> nuo
        nustatymų PIN kodo - atrakinęs skyrių dashboard'e, vartotojas VIS TIEK negalės patekti
        į šiuos nustatymus be nustatymų PIN kodo.
      </p>
      <form method="post">
        <input type="hidden" name="action" value="change_section_pin">
        <label>Naujas PIN (4-10 skaitmenys)</label>
        <input type="password" inputmode="numeric" pattern="[0-9]*" name="new_pin" required>
        <label>Pakartokite naują PIN</label>
        <input type="password" inputmode="numeric" pattern="[0-9]*" name="new_pin2" required>
        <button class="btn" type="submit">Keisti skyrių PIN</button>
      </form>
    </div>
  </section>
  </div>

  <div class="tab-panel" id="spanel-restricted">
  <section>
    <h2>Apsaugoti skyriai</h2>
    <div class="card">
      <p class="hint">Pažymėti skyriai dashboard'e bus paslėpti, kol neįvedamas skyrių PIN kodas.</p>
      <form method="post">
        <input type="hidden" name="action" value="save_restricted">
        <?php foreach (TABS as $t): ?>
          <label class="tab-check">
            <input type="checkbox" name="restricted[]" value="<?= h($t['id']) ?>" <?= in_array($t['id'], $restrictedTabs, true) ? 'checked' : '' ?>>
            <?= h($t['label']) ?>
          </label>
        <?php endforeach; ?>
        <button class="btn" type="submit">Išsaugoti</button>
      </form>
    </div>
  </section>
  </div>

  <div class="tab-panel" id="spanel-theme">
  <section>
    <h2>Šablonas</h2>
    <div class="card">
      <p class="hint">Pasirinkite dashboard'o dizainą pagal įrenginį.</p>
      <form method="post">
        <input type="hidden" name="action" value="save_theme">
        <label class="theme-option">
          <input type="radio" name="theme" value="tablet" <?= $currentTheme === 'tablet' ? 'checked' : '' ?>>
          <span>
            <strong>Planšetė</strong> - spalvota (indigo akcentai, ryškesnis kontrastas, patogu liesti pirštu)
          </span>
        </label>
        <label class="theme-option">
          <input type="radio" name="theme" value="epaper" <?= $currentTheme === 'epaper' ? 'checked' : '' ?>>
          <span>
            <strong>E-paper</strong> - juoda/balta, be spalvų, maksimalus kontrastas (tinka lėtai
            atsinaujinantiems e-skaitytuvams)
          </span>
        </label>
        <button class="btn" type="submit">Išsaugoti šabloną</button>
      </form>
    </div>
  </section>
  </div>

  </div>

<?php endif; ?>

</main>
</body>
</html>
