<?php

final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            $cfg = Config::get()['db'];
            $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return self::$pdo;
    }

    public static function saveSnapshot(string $studentId, string $category, mixed $payload): void
    {
        $stmt = self::conn()->prepare(
            'INSERT INTO snapshots (student_id, category, fetched_at, payload, error)
             VALUES (:sid, :cat, :fa, :payload, NULL)
             ON DUPLICATE KEY UPDATE fetched_at = :fa2, payload = :payload2, error = NULL'
        );
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'sid' => $studentId, 'cat' => $category, 'fa' => $now, 'payload' => $json,
            'fa2' => $now, 'payload2' => $json,
        ]);
    }

    public static function saveSnapshotError(string $studentId, string $category, string $error): void
    {
        $stmt = self::conn()->prepare(
            'INSERT INTO snapshots (student_id, category, fetched_at, payload, error)
             VALUES (:sid, :cat, :fa, :payload, :err)
             ON DUPLICATE KEY UPDATE error = :err2'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'sid' => $studentId, 'cat' => $category, 'fa' => $now, 'payload' => '{}',
            'err' => $error, 'err2' => $error,
        ]);
    }

    /** @return array<string, array{fetched_at:string, payload:mixed, error:?string}> */
    public static function loadSnapshots(string $studentId): array
    {
        $stmt = self::conn()->prepare('SELECT category, fetched_at, payload, error FROM snapshots WHERE student_id = :sid');
        $stmt->execute(['sid' => $studentId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['category']] = [
                'fetched_at' => $row['fetched_at'],
                'payload' => json_decode($row['payload'], true),
                'error' => $row['error'],
            ];
        }
        return $out;
    }

    public static function messageExists(string $studentId, int $id): bool
    {
        $stmt = self::conn()->prepare('SELECT 1 FROM messages WHERE student_id = :sid AND id = :id');
        $stmt->execute(['sid' => $studentId, 'id' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    public static function upsertMessage(string $studentId, array $m): void
    {
        $stmt = self::conn()->prepare(
            'INSERT INTO messages
                (student_id, id, tema, siuntejas, siuntejo_tipas,
                 data_y, data_m, data_d, data_h, data_min, data_s,
                 perskaityta, perskaitymo_y, perskaitymo_m, perskaitymo_d, perskaitymo_h, perskaitymo_min, perskaitymo_s,
                 turi_failu, fetched_at)
             VALUES
                (:sid, :id, :tema, :siuntejas, :siuntejo_tipas,
                 :dy, :dm, :dd, :dh, :dmin, :ds,
                 :perskaityta, :py, :pm, :pd, :ph, :pmin, :ps,
                 :turi_failu, :fetched_at)
             ON DUPLICATE KEY UPDATE
                perskaityta = :perskaityta2,
                perskaitymo_y = :py2, perskaitymo_m = :pm2, perskaitymo_d = :pd2,
                perskaitymo_h = :ph2, perskaitymo_min = :pmin2, perskaitymo_s = :ps2'
        );
        $d = $m['data'];
        $rd = $m['perskaitymo data'];
        $perskaityta = $rd !== null ? 1 : 0;
        $stmt->execute([
            'sid' => $studentId, 'id' => $m['id'], 'tema' => $m['tema'],
            'siuntejas' => $m['siuntejas'], 'siuntejo_tipas' => $m['siuntejo tipas'],
            'dy' => $d['y'], 'dm' => $d['m'], 'dd' => $d['d'], 'dh' => $d['h'], 'dmin' => $d['min'], 'ds' => $d['s'],
            'perskaityta' => $perskaityta,
            'py' => $rd['y'] ?? null, 'pm' => $rd['m'] ?? null, 'pd' => $rd['d'] ?? null,
            'ph' => $rd['h'] ?? null, 'pmin' => $rd['min'] ?? null, 'ps' => $rd['s'] ?? null,
            'turi_failu' => $m['turi prisegtu files'] ? 1 : 0,
            'fetched_at' => date('Y-m-d H:i:s'),
            'perskaityta2' => $perskaityta,
            'py2' => $rd['y'] ?? null, 'pm2' => $rd['m'] ?? null, 'pd2' => $rd['d'] ?? null,
            'ph2' => $rd['h'] ?? null, 'pmin2' => $rd['min'] ?? null, 'ps2' => $rd['s'] ?? null,
        ]);
    }

    public static function saveMessageBody(string $studentId, int $id, string $htmlTekstas, string $tekstas, array $attachments): void
    {
        $pdo = self::conn();
        $pdo->prepare(
            'UPDATE messages SET html_tekstas = :h, tekstas = :t, body_fetched = 1 WHERE student_id = :sid AND id = :id'
        )->execute(['h' => $htmlTekstas, 't' => $tekstas, 'sid' => $studentId, 'id' => $id]);

        $del = $pdo->prepare('DELETE FROM message_attachments WHERE student_id = :sid AND message_id = :id');
        $del->execute(['sid' => $studentId, 'id' => $id]);

        $ins = $pdo->prepare(
            'INSERT INTO message_attachments (student_id, message_id, pavadinimas, file_sid) VALUES (:sid, :id, :name, :fsid)'
        );
        foreach ($attachments as $a) {
            $ins->execute(['sid' => $studentId, 'id' => $id, 'name' => $a['pavadinimas'], 'fsid' => $a['id']]);
        }
    }

    /** @return array messages naujausi pirmi, su prisegtais failais */
    public static function loadMessages(string $studentId, int $limit = 50): array
    {
        $stmt = self::conn()->prepare(
            'SELECT * FROM messages WHERE student_id = :sid
             ORDER BY data_y DESC, data_m DESC, data_d DESC, data_h DESC, data_min DESC, data_s DESC
             LIMIT :lim'
        );
        $stmt->bindValue('sid', $studentId);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $messages = $stmt->fetchAll();

        $attStmt = self::conn()->prepare('SELECT pavadinimas, file_sid FROM message_attachments WHERE student_id = :sid AND message_id = :id');
        foreach ($messages as &$m) {
            $attStmt->execute(['sid' => $studentId, 'id' => $m['id']]);
            $m['attachments'] = $attStmt->fetchAll();
        }
        return $messages;
    }

    public static function startRun(string $studentId): int
    {
        $stmt = self::conn()->prepare('INSERT INTO run_log (student_id, started_at, status) VALUES (:sid, :now, "ok")');
        $stmt->execute(['sid' => $studentId, 'now' => date('Y-m-d H:i:s')]);
        return (int) self::conn()->lastInsertId();
    }

    public static function finishRun(int $runId, bool $ok, ?string $message = null): void
    {
        $stmt = self::conn()->prepare('UPDATE run_log SET finished_at = :now, status = :status, message = :msg WHERE id = :id');
        $stmt->execute([
            'now' => date('Y-m-d H:i:s'), 'status' => $ok ? 'ok' : 'error', 'msg' => $message, 'id' => $runId,
        ]);
    }

    public static function lastRun(string $studentId): ?array
    {
        $stmt = self::conn()->prepare('SELECT * FROM run_log WHERE student_id = :sid ORDER BY id DESC LIMIT 1');
        $stmt->execute(['sid' => $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function upsertStudent(string $id, string $loginId, string $vardas, ?string $mokykla): void
    {
        $stmt = self::conn()->prepare(
            'INSERT INTO students (id, login_id, vardas, mokykla, updated_at)
             VALUES (:id, :lid, :vardas, :mokykla, :now)
             ON DUPLICATE KEY UPDATE login_id = :lid2, vardas = :vardas2, mokykla = :mokykla2, updated_at = :now2'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'id' => $id, 'lid' => $loginId, 'vardas' => $vardas, 'mokykla' => $mokykla, 'now' => $now,
            'lid2' => $loginId, 'vardas2' => $vardas, 'mokykla2' => $mokykla, 'now2' => $now,
        ]);
    }

    /** @return array<int, array{id:string, login_id:string, vardas:string, mokykla:?string}> */
    public static function loadStudents(): array
    {
        return self::conn()->query('SELECT * FROM students ORDER BY vardas')->fetchAll();
    }

    // ------------------------------------------------------------------
    // Tamo prisijungimai (logins) - tvarkomi per settings.php
    // ------------------------------------------------------------------

    /** @return array<int, array{id:string, vardas:string, username:string, password:string}> */
    public static function listLogins(): array
    {
        return self::conn()->query('SELECT id, vardas, username, password FROM logins ORDER BY vardas')->fetchAll();
    }

    public static function getLogin(string $id): ?array
    {
        $stmt = self::conn()->prepare('SELECT id, vardas, username, password FROM logins WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function upsertLogin(string $id, string $vardas, string $username, string $password): void
    {
        $stmt = self::conn()->prepare(
            'INSERT INTO logins (id, vardas, username, password, created_at)
             VALUES (:id, :vardas, :username, :password, :now)
             ON DUPLICATE KEY UPDATE vardas = :vardas2, username = :username2, password = :password2'
        );
        $stmt->execute([
            'id' => $id, 'vardas' => $vardas, 'username' => $username, 'password' => $password, 'now' => date('Y-m-d H:i:s'),
            'vardas2' => $vardas, 'username2' => $username, 'password2' => $password,
        ]);
    }

    public static function deleteLogin(string $id): void
    {
        self::conn()->prepare('DELETE FROM logins WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return array<int, array{id:string, vardas:string}> mokiniai, atrasti būtent šiuo prisijungimu */
    public static function studentsForLogin(string $loginId): array
    {
        $stmt = self::conn()->prepare('SELECT id, vardas FROM students WHERE login_id = :lid');
        $stmt->execute(['lid' => $loginId]);
        return $stmt->fetchAll();
    }

    /** Ištrina VISUS su vienu mokiniu susijusius duomenis (snapshots/žinutės/žurnalas/pats įrašas). */
    public static function deleteStudentData(string $studentId): void
    {
        $pdo = self::conn();
        $pdo->prepare('DELETE FROM snapshots WHERE student_id = :sid')->execute(['sid' => $studentId]);
        $pdo->prepare('DELETE FROM messages WHERE student_id = :sid')->execute(['sid' => $studentId]); // FK -> message_attachments CASCADE
        $pdo->prepare('DELETE FROM run_log WHERE student_id = :sid')->execute(['sid' => $studentId]);
        $pdo->prepare('DELETE FROM students WHERE id = :sid')->execute(['sid' => $studentId]);
    }

    /**
     * Ištrina prisijungimą IR su juo susijusius mokinius bei jų duomenis.
     * SVARBU: jei tas pats vaikas pasiekiamas ir per kitą (likusį) prisijungimą (pvz. abiejų tėvų
     * paskyros mato tuos pačius vaikus), jo duomenys NETRINAMI, kad neišnyktų dar galiojančiam
     * prisijungimui - tikrinama pagal students.login_id.
     * @return string[] ištrintų mokinių vardai (tuščias masyvas, jei šis login'as neturėjo savo vaikų)
     */
    public static function deleteLoginCascade(string $loginId): array
    {
        $students = self::studentsForLogin($loginId);
        foreach ($students as $s) {
            self::deleteStudentData($s['id']);
        }
        self::deleteLogin($loginId);
        return array_column($students, 'vardas');
    }

    public static function loginCount(): int
    {
        return (int) self::conn()->query('SELECT COUNT(*) FROM logins')->fetchColumn();
    }

    /** Vienkartinis seed'as iš config.php "mokiniai" - tik jei "logins" lentelė dar visiškai tuščia. */
    public static function seedLoginsFromConfigIfEmpty(array $mokiniaiFromConfig): void
    {
        if (self::loginCount() > 0) {
            return;
        }
        foreach ($mokiniaiFromConfig as $m) {
            self::upsertLogin($m['id'], $m['vardas'], $m['username'], $m['password']);
        }
    }

    // ------------------------------------------------------------------
    // Nustatymai (PIN, uždaryti skyriai) - key/value lentelė
    // ------------------------------------------------------------------

    public static function getSetting(string $key, ?string $default = null): ?string
    {
        $stmt = self::conn()->prepare('SELECT `value` FROM settings WHERE `key` = :k');
        $stmt->execute(['k' => $key]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : $v;
    }

    public static function setSetting(string $key, string $value): void
    {
        $stmt = self::conn()->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = :v2'
        );
        $stmt->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
    }

    /**
     * Du NEPRIKLAUSOMI PIN kodai: 'settings' saugo prieigą prie settings.php (prisijungimų
     * valdymas ir t.t.), 'section' saugo prieigą prie dashboard'e uzrakintų skyrių. Atrakinus
     * vieną, kitas lieka uzrakintas - jie sąmoningai neturi bendro rakto.
     */
    private static function pinKey(string $kind): string
    {
        return $kind === 'settings' ? 'settings_pin_hash' : 'section_pin_hash';
    }

    public static function ensurePinsSeeded(string $defaultPin): void
    {
        foreach (['settings', 'section'] as $kind) {
            if (self::getSetting(self::pinKey($kind)) === null) {
                self::setSetting(self::pinKey($kind), password_hash($defaultPin, PASSWORD_DEFAULT));
            }
        }
    }

    public static function verifyPin(string $kind, string $pin): bool
    {
        $hash = self::getSetting(self::pinKey($kind));
        return $hash !== null && password_verify($pin, $hash);
    }

    public static function setPin(string $kind, string $newPin): void
    {
        self::setSetting(self::pinKey($kind), password_hash($newPin, PASSWORD_DEFAULT));
    }

    /** @return string[] tab id'ai, kuriuos reikia uzrakinti PIN kodu */
    public static function getRestrictedTabs(): array
    {
        $raw = self::getSetting('restricted_tabs', '[]');
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    public static function setRestrictedTabs(array $tabIds): void
    {
        self::setSetting('restricted_tabs', json_encode(array_values($tabIds)));
    }

    /** 'tablet' (spalvota) arba 'epaper' (juoda/balta, aukštas kontrastas, be spalvų). */
    public static function getTheme(): string
    {
        $v = self::getSetting('theme_mode', 'tablet');
        return $v === 'epaper' ? 'epaper' : 'tablet';
    }

    public static function setTheme(string $theme): void
    {
        self::setSetting('theme_mode', $theme === 'epaper' ? 'epaper' : 'tablet');
    }

    // ------------------------------------------------------------------
    // Scrapinimo eigos būsena (naudojama progreso juostai index.php)
    // ------------------------------------------------------------------

    public static function setScrapeStatus(bool $running, string $label): void
    {
        self::setSetting('scrape_status', json_encode([
            'running' => $running, 'label' => $label, 'updated_at' => date('c'),
        ]));
    }

    /**
     * @return array{running:bool, label:string, updated_at:?string, stale:bool}
     *
     * Jei "running" jau seniai (žr. STALE_SEKUNDES) nesulaukė jokio atnaujinimo - laikom, kad
     * fone paleistas procesas kažkodėl nutrūko nepasiekęs savo finally bloko (pvz. nepavyko jam
     * apskritai pasileisti), ir automatiškai "atsukam" būseną atgal į running=false. Be šito,
     * "Atnaujinti dabar" mygtukas galėtų likti amžinai užblokuotas (žr. scrape_trigger.php
     * "already_running" apsaugą), o progreso juosta index.php - amžinai besisukanti.
     */
    private const STALE_SEKUNDES = 120;

    public static function getScrapeStatus(): array
    {
        $raw = self::getSetting('scrape_status');
        $arr = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($arr)) {
            return ['running' => false, 'label' => '', 'updated_at' => null, 'stale' => false];
        }
        $running = (bool) ($arr['running'] ?? false);
        $label = (string) ($arr['label'] ?? '');
        $updatedAt = $arr['updated_at'] ?? null;
        $stale = false;

        if ($running && $updatedAt !== null) {
            $age = time() - strtotime($updatedAt);
            if ($age > self::STALE_SEKUNDES) {
                $running = false;
                $stale = true;
                $label = 'Nutrūko netikėtai (nebuvo atsako > ' . self::STALE_SEKUNDES . 's) - bandykite dar kartą.';
                self::setScrapeStatus(false, $label);
            }
        }

        return ['running' => $running, 'label' => $label, 'updated_at' => $updatedAt, 'stale' => $stale];
    }
}
