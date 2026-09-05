<?php

require_once __DIR__ . '/DomHelper.php';

class TamoException extends Exception {}
class TamoLoginException extends TamoException {}
class TamoNotFoundException extends TamoException {}

/**
 * PHP atitikmuo Python TamoAPI/scraper.py + main.py.
 * Naudoja cURL (su bendru cookie failu = imituoja requests.Session()) ir
 * DOMDocument/DOMXPath + DomHelper (imituoja BeautifulSoup) HTML parsinimui.
 *
 * Tamo puslapio struktūra gali keistis nepranešus (žr. originalaus projekto README),
 * todėl jei kuri nors funkcija nustoja veikti, klaida bus išmesta kaip TamoException
 * su aiškiu pranešimu ir scrape.php ją užfiksuos run_log lentelėje.
 */
class TamoScraper
{
    private string $cookieFile;
    /** @var array<int, FlatDoc> cache'as pagal spl_object_id(doc), kad next()/findNextTag() nebūtų O(n^2) */
    private array $flatCache = [];

    private const REGEX = [
        '/^(..):(..)/u',                                            // 0: hh:mm
        '/^.*, (.*?) \((.*) (..)\).*/u',                             // 1: "..., TIPAS (DD.MM)"
        '/^(....)-(..)-(..)/u',                                      // 2: yyyy-mm-dd
        '/^(....)-(..)-(..) (..):(..)/u',                            // 3: yyyy-mm-dd hh:mm
        '/^(....)-(..)-(..), (.*)/u',                                // 4: yyyy-mm-dd, savaitės diena
        '/^Grupė: (.*?)Dalykas: (.*)/u',                             // 5: "Grupė: X Dalykas: Y"
        '/^.*(....)-(..)-(..).*<div>(.*)<\/div>/u',                  // 6: pusmečio pažymio data-original-title
        '/^(....)-(..)-(..)T(..):(..):(..)/u',                       // 7: ISO data laikas (pranešimai)
    ];

    private static array $MENUO1 = [ // genityvas: "sausio", naudojama dienyne
        'sausio' => 1, 'vasario' => 2, 'kovo' => 3, 'balandžio' => 4, 'gegužės' => 5, 'birželio' => 6,
        'liepos' => 7, 'rugpjūčio' => 8, 'rugsėjo' => 9, 'spalio' => 10, 'lapkričio' => 11, 'gruodžio' => 12,
    ];

    private static array $MENUO2 = [ // vardininkas: "sausis", naudojama pamokų sąraše
        'sausis' => 1, 'vasaris' => 2, 'kovas' => 3, 'balandis' => 4, 'gegužė' => 5, 'birželis' => 6,
        'liepa' => 7, 'rugpjūtis' => 8, 'rugsėjis' => 9, 'spalis' => 10, 'lapkritis' => 11, 'gruodis' => 12,
    ];

    private static array $TIPAS = [
        'L' => 'Laboratorinis darbas', 'K' => 'Kontrolinis darbas', 'D' => 'Diktantas',
        'A' => 'Atsiskaitymas', 'S' => 'Savarankiškas darbas', 'T' => 'Testas',
        'PR' => 'Projektinis darbas', 'RA' => 'Rašinys', 'PD' => 'Praktinis darbas', 'TD' => 'Teorinis darbas',
    ];

    private static array $SAVAITES_DIENA = ['Pr' => 1, 'An' => 2, 'Tr' => 3, 'Kt' => 4, 'Pn' => 5, 'Št' => 6, 'Sk' => 7];

    private static array $SAVAITES_DIENA2 = [
        'pirmadienis' => 1, 'antradienis' => 2, 'trečiadienis' => 3, 'ketvirtadienis' => 4,
        'penktadienis' => 5, 'šeštadienis' => 6, 'sekmadienis' => 7,
    ];

    public function __construct()
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'tamo_cookie_');
    }

    public function __destruct()
    {
        if (is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    // ------------------------------------------------------------------
    // HTTP pagalbinės funkcijos
    // ------------------------------------------------------------------

    private function curlBase(string $url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
        ]);
        return $ch;
    }

    private function httpGet(string $url, array $headers = []): string
    {
        $ch = $this->curlBase($url);
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new TamoException("GET $url nepavyko: $err");
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            throw new TamoException("GET $url gražino HTTP $code");
        }
        return $body;
    }

    private function httpPost(string $url, array $data = [], array $headers = []): string
    {
        $ch = $this->curlBase($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new TamoException("POST $url nepavyko: $err");
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            throw new TamoException("POST $url gražino HTTP $code");
        }
        return $body;
    }

    /** @return array{code:int, json:mixed} */
    private function httpJson(string $url, array $headers = [], ?array $jsonBody = null): array
    {
        $ch = $this->curlBase($url);
        $baseHeaders = ['Accept: application/json'];
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
            $baseHeaders[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($baseHeaders, $headers));
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new TamoException("JSON request $url nepavyko: $err");
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'json' => json_decode($body, true)];
    }

    private function flat(DOMDocument $doc): FlatDoc
    {
        $id = spl_object_id($doc);
        if (!isset($this->flatCache[$id])) {
            $this->flatCache[$id] = DomHelper::flatten($doc);
        }
        return $this->flatCache[$id];
    }

    // ------------------------------------------------------------------
    // Prisijungimas
    // ------------------------------------------------------------------

    public function login(string $username, string $password, bool $check = true): void
    {
        $html = $this->httpGet('https://dienynas.tamo.lt/Prisijungimas/Login');
        $doc = DomHelper::loadHtml($html);
        $xp = DomHelper::xpath($doc);

        $data = [];
        foreach (DomHelper::findAllTag($xp, $doc, 'input') as $input) {
            $key = $input->getAttribute('id');
            if ($key === '') {
                $key = $input->getAttribute('name');
            }
            if ($key === '' || !$input->hasAttribute('value')) {
                continue;
            }
            $data[$key] = $input->getAttribute('value');
        }
        $data['UserName'] = $username;
        $data['Password'] = $password;

        $respHtml = $this->httpPost('https://dienynas.tamo.lt/?clickMode=True', $data);

        if ($check) {
            $doc2 = DomHelper::loadHtml($respHtml);
            $xp2 = DomHelper::xpath($doc2);
            $title = DomHelper::findTag($xp2, $doc2, 'title');
            if ($title !== null && mb_strpos(DomHelper::text($title), 'Prisijungimas') !== false) {
                throw new TamoLoginException('Neteisingi Tamo prisijungimo duomenys');
            }
        }
    }

    /**
     * Tėvo/globėjo (TEVGLO) prisijungimas gali turėti kelis susietus vaikus (skirtingas mokyklas).
     * Tamo tai rodo kaip "KeistiAplinka" nuorodų sąrašą dashboard'o viršuje (vaiko perjungiklis).
     * Grąžina kiekvieno rasto vaiko duomenis.
     *
     * Jei paskyra turi tik VIENĄ vaiką (nėra tikro perjungiklio su keliais įrašais), bandoma vis
     * tiek automatiškai nuskaityti vaiko vardą iš to paties "vardas + rodyklė" mygtuko, kuris
     * daugiavaikėse paskyrose atidaro persijungimo sąrašą (jis rodomas ir vienavaikėse paskyrose,
     * tik be pasirinkimų viduje). Jei net tai nepavyksta, grąžina tuščią masyvą - tuomet
     * cli/scrape.php naudoja config/DB įrašo 'vardas' lauką kaip paskutinį atsarginį variantą.
     *
     * @return array<int, array{kodas:?string, iraso_id:?string, istaigos_id:?string, vardas:?string, mokykla:?string}>
     */
    public function raskVaikus(): array
    {
        $doc = DomHelper::loadHtml($this->httpGet('https://dienynas.tamo.lt/'));
        $xp = DomHelper::xpath($doc);

        $vaikai = [];
        foreach ($xp->query("//a[contains(@href, 'KeistiAplinka')]") as $a) {
            $href = $a->getAttribute('href');
            if (!preg_match('/kodas=([^&]+)&irasoId=(\d+)&istaigosId=(\d+)/', $href, $m)) {
                continue;
            }
            $roleNameNodes = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' role_name ')]", $a);
            $mokyklaNodes = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' role_details ')]", $a);
            $vaikai[] = [
                'kodas' => $m[1],
                'iraso_id' => $m[2],
                'istaigos_id' => $m[3],
                'vardas' => $roleNameNodes->length ? trim($roleNameNodes->item(0)->textContent) : null,
                'mokykla' => $mokyklaNodes->length ? trim($mokyklaNodes->item(0)->textContent) : null,
            ];
        }

        if (empty($vaikai)) {
            $toggleNodes = $xp->query("//a[.//i[contains(@class, 'drop_down_icon')]]");
            if ($toggleNodes->length > 0) {
                $vardas = trim(preg_replace('/\s+/', ' ', $toggleNodes->item(0)->textContent));
                if ($vardas !== '') {
                    $vaikai[] = ['kodas' => null, 'iraso_id' => null, 'istaigos_id' => null, 'vardas' => $vardas, 'mokykla' => null];
                }
            }
        }

        return $vaikai;
    }

    /** Perjungia esamą (jau prisijungusią) sesiją prie konkretaus vaiko/aplinkos. */
    public function pasirinktiVaika(string $kodas, string $irasoId, string $istaigosId): void
    {
        $this->httpGet(
            "https://dienynas.tamo.lt/Prisijungimas/KeistiAplinka?kodas={$kodas}&irasoId={$irasoId}&istaigosId={$istaigosId}"
        );
    }

    // ------------------------------------------------------------------
    // Tvarkaraštis
    // ------------------------------------------------------------------

    public function tvarkarastis(?string $savaite = null): array
    {
        $url = $savaite === null
            ? 'https://dienynas.tamo.lt/TvarkarascioIrasas/MokinioTvarkarastis'
            : 'https://dienynas.tamo.lt/TvarkarascioIrasas/MokinioTvarkarastis?data=' . urlencode($savaite);
        $doc = DomHelper::loadHtml($this->httpGet($url));
        $xp = DomHelper::xpath($doc);

        $data = [];
        $tables = DomHelper::findAllByTagAndClasses($xp, $doc, 'table',
            ['full_width', 'form-horizontal', 'table', 'table-hover', 'table-responsive']);

        foreach ($tables as $table) {
            $temp = [];
            $trs = DomHelper::findAllTag($xp, $table, 'tr');
            // Pastaba: originalus Python kodas čia praleisdavo pirmą <tr> kaip antraštę ($i=1),
            // bet dabartiniame Tamo puslapyje šioje lentelėje antraštės eilutės nebėra - jei ją
            // praleistume, prarastume kiekvienos dienos PIRMĄ pamoką. Apdorojam visas eilutes;
            // realios ne-pamokos eilutės (antraštė, "Pamokų nėra") natūraliai atkrenta žemiau,
            // nes jų "laikas" tekstas neatitinka hh:mm formato.
            for ($i = 0; $i < count($trs); $i++) {
                $tds = DomHelper::findAllTag($xp, $trs[$i], 'td');
                $keys = ['numeris', 'laikas', 'pabaiga', 'dalykas', 'mokytojas'];
                $temper = [];
                foreach ($keys as $ki => $key) {
                    if (!isset($tds[$ki])) {
                        break;
                    }
                    $temper[$key] = DomHelper::text($tds[$ki]);
                }
                if (!isset($temper['laikas']) || !preg_match(self::REGEX[0], $temper['laikas'], $m)) {
                    continue; // "Pamokų nėra" arba nesuprantama eilutė
                }
                // Dabartiniame puslapyje "dalykas" langelyje kartu su dalyko pavadinimu (dažnai
                // atskiroje eilutėje viduje) būna įterptas ir mokytojo vardas be atskiro <td>.
                // Jei nėra atskiro "mokytojas" stulpelio, išskiriam jį iš "dalykas" teksto.
                if (isset($temper['dalykas']) && !isset($temper['mokytojas'])) {
                    $eilutes = array_values(array_filter(
                        array_map('trim', preg_split('/\r\n|\r|\n/', $temper['dalykas'])),
                        fn($l) => $l !== ''
                    ));
                    if (count($eilutes) >= 2) {
                        $temper['dalykas'] = $eilutes[0];
                        $temper['mokytojas'] = end($eilutes);
                    } elseif (count($eilutes) === 1) {
                        $temper['dalykas'] = $eilutes[0];
                    }
                }
                $temper['pradzia'] = ['h' => (int) $m[1], 'm' => (int) $m[2]];
                if (isset($temper['pabaiga']) && preg_match(self::REGEX[0], $temper['pabaiga'], $m2)) {
                    $temper['laikas'] .= ' - ' . $temper['pabaiga'];
                    $temper['pabaiga'] = ['h' => (int) $m2[1], 'm' => (int) $m2[2]];
                }
                $temp[] = $temper;
            }
            $data[] = $temp;
        }
        return $data;
    }

    // ------------------------------------------------------------------
    // Dienynas (pažymiai + lankomumas)
    // ------------------------------------------------------------------

    public function dienynas(?int $metai = null, ?int $menuo = null): array
    {
        if ($metai !== null && $menuo !== null) {
            $html = $this->httpPost('https://dienynas.tamo.lt/Pamoka/MokinioDienynasTable', [
                'metai' => (string) $metai, 'menuo' => (string) $menuo,
            ]);
        } else {
            $html = $this->httpGet('https://dienynas.tamo.lt/Pamoka/MokinioDienynas');
        }
        $doc = DomHelper::loadHtml($html);
        $xp = DomHelper::xpath($doc);

        $dienynoBlokai = DomHelper::findAllByClass($xp, $doc, 'dienynas');
        if (count($dienynoBlokai) < 2) {
            throw new TamoException('dienynas(): nerasta .dienynas lentelė - puslapio struktūra pasikeitė?');
        }
        $strippedPage = $dienynoBlokai[1];
        $day = self::$SAVAITES_DIENA[DomHelper::text(DomHelper::findTag($xp, $strippedPage, 'div'))] ?? null;

        $lankomumai = [];
        $ivertinimai = [];
        $trs = DomHelper::findAllTag($xp, $strippedPage, 'tr');
        // Apdorojam visas eilutes (ne nuo indekso 1) - žr. pastabą tvarkarastis() viduje.
        // Jei pirma eilutė vis dėlto yra antraštė, ji tiesiog neduos jokio ivertinimai/lankomumas
        // įrašo, nes antraštės langeliai neturės "data-original-title" atributo.
        for ($ti = 0; $ti < count($trs); $ti++) {
            $tds = DomHelper::findAllTag($xp, $trs[$ti], 'td');
            if (empty($tds)) {
                continue;
            }
            $dalykas = DomHelper::textNoNewline($tds[0]);
            for ($index = 0; $index < count($tds) - 1; $index++) {
                $td = $tds[$index + 1];
                $w = (($index + (int) $day) % 7) ?: 7;
                $dateArr = ['d' => $index + 1, 'w' => $w];

                if (!$td->hasAttribute('data-original-title')) {
                    continue;
                }
                $title = $td->getAttribute('data-original-title');
                if (!preg_match(self::REGEX[1], $title, $m)) {
                    $lankomumai[] = ['dalykas' => $dalykas, 'tipas' => DomHelper::text($td), 'data' => $dateArr];
                    continue;
                }
                $ivertinimas = DomHelper::text($td);
                if (str_contains($ivertinimas, "\n")) {
                    $parts = explode("\n", $ivertinimas);
                    if (count($parts) === 2) {
                        $ivertinimas = trim($parts[0]);
                        $lankomumai[] = ['dalykas' => $dalykas, 'tipas' => trim($parts[1]), 'data' => $dateArr];
                    }
                }
                $ivertinimai[] = [
                    'dalykas' => $dalykas,
                    'ivertinimas' => $ivertinimas,
                    'tipas' => $m[1],
                    'taisymo data' => ['m' => self::$MENUO1[$m[2]] ?? null, 'd' => (int) $m[3]],
                    'data' => $dateArr,
                ];
            }
        }
        return ['ivertinimai' => $ivertinimai, 'lankomumas' => $lankomumai];
    }

    // ------------------------------------------------------------------
    // Pamokos
    // ------------------------------------------------------------------

    public function pamokos(?int $metai = null, ?int $menesis = null, ?int $mmid = null): array
    {
        if ($mmid !== null) {
            $url = "https://dienynas.tamo.lt/Pamoka/MokinioPamokuPartial?moksloMetuMenesiaiId={$mmid}&krautiVisaMenesi=True";
        } elseif ($metai !== null && $menesis !== null) {
            $calc = ($metai - 2014) * 12 + $menesis + 5;
            $url = "https://dienynas.tamo.lt/Pamoka/MokinioPamokuPartial?moksloMetuMenesiaiId={$calc}&krautiVisaMenesi=True";
        } else {
            $doc0 = DomHelper::loadHtml($this->httpGet('https://dienynas.tamo.lt/Pamoka/Sarasas'));
            $xp0 = DomHelper::xpath($doc0);
            $url = null;
            foreach (array_reverse(DomHelper::findAllTag($xp0, $doc0, 'a')) as $a) {
                if (str_contains(DomHelper::text($a), 'Daugiau')) {
                    $url = 'https://dienynas.tamo.lt' . $a->getAttribute('href');
                    break;
                }
            }
            if ($url === null) {
                throw new TamoException("pamokos(): nerasta 'Daugiau' nuoroda");
            }
        }

        $doc = DomHelper::loadHtml($this->httpPost($url));
        $xp = DomHelper::xpath($doc);
        $flat = $this->flat($doc);
        $body = $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;

        $data = [];
        foreach (DomHelper::directChildren($body) as $i) {
            if (!DomHelper::hasClass($i, 'row')) {
                continue;
            }
            $headers = DomHelper::findAllByClass($xp, $i, 'f-header');
            if (count($headers) < 4) {
                continue;
            }
            [$rawMenuo, $rawDiena, $rawSav] = [$headers[1], $headers[2], $headers[3]];

            $innerDivs = DomHelper::directChildren($i, 'div');
            if (count($innerDivs) < 2) {
                continue;
            }
            foreach (DomHelper::directChildren($innerDivs[1]) as $j) {
                if (!DomHelper::hasClass($j, 'row')) {
                    continue;
                }
                $divChildren = DomHelper::directChildren($j, 'div');
                if (empty($divChildren)) {
                    continue;
                }
                $div = $divChildren[0];
                $fHeaderMatches = DomHelper::findAllByClass($xp, $div, 'f-header');
                $dalykas = DomHelper::text($fHeaderMatches[0] ?? null);
                $labelFirst = DomHelper::findTag($xp, $div, 'label');
                $mokytojas = DomHelper::text(DomHelper::next($flat, DomHelper::next($flat, $labelFirst)));

                $temp = [
                    'dalykas' => $dalykas,
                    'mokytojas' => $mokytojas,
                    'data' => [
                        'm' => self::$MENUO2[trim(DomHelper::text($rawMenuo))] ?? null,
                        'd' => (int) DomHelper::text($rawDiena),
                        'w' => self::$SAVAITES_DIENA[trim(DomHelper::text($rawSav))] ?? null,
                    ],
                    'ivertinimas' => null,
                    'tema' => null,
                    'klases darbas' => null,
                    'namu darbas' => null,
                ];

                $labels = DomHelper::findAllTag($xp, $div, 'label');
                for ($li = 1; $li < count($labels); $li++) {
                    $k = $labels[$li];
                    $string = DomHelper::text($k);
                    $mapKey = match ($string) {
                        'Įvertinimas:' => 'pazymys',
                        'Tema:' => 'tema',
                        'Namų darbas:' => 'namu darbas',
                        'Klasės darbas:' => 'klases darbas',
                        default => $string,
                    };
                    $temp[$mapKey] = DomHelper::text(DomHelper::findNextTag($flat, $k, 'div'));
                }
                $data[] = $temp;
            }
        }
        return $data;
    }

    // ------------------------------------------------------------------
    // Namų darbai
    // ------------------------------------------------------------------

    private function getDate(DOMXPath $xp, DOMElement $junk): array
    {
        $label = DomHelper::findTag($xp, $junk, 'label');
        $raw = DomHelper::textNoNewline($label);
        if (!preg_match(self::REGEX[4], $raw, $m)) {
            return ['y' => null, 'm' => null, 'd' => null, 'w' => null];
        }
        return [
            'y' => (int) $m[1], 'm' => (int) $m[2], 'd' => (int) $m[3],
            'w' => self::$SAVAITES_DIENA2[trim($m[4])] ?? null,
        ];
    }

    /**
     * Grąžina true tik tada, kai bloke buvo TIK dalyko/namų darbo tekstas (jokių žymelių/labels),
     * t.y. atitinka python `len(temp) == 1` sąlygą originaliame scraper.py.
     */
    private function getInfo(DOMXPath $xp, DOMDocument $doc, DOMElement $junk): array
    {
        $flat = $this->flat($doc);
        $temp = [
            'dalykas' => DomHelper::text(DomHelper::findNextTag($flat, $junk, 'div')),
            'failai' => [],
        ];

        foreach (DomHelper::findAllTag($xp, $junk, 'label') as $label) {
            $t = trim(DomHelper::text($label));
            $vNode = DomHelper::next($flat, DomHelper::next($flat, $label));
            $v = $vNode !== null ? trim($vNode->nodeValue ?? '') : '';

            switch ($t) {
                case 'Pamokos data:':
                    if (preg_match(self::REGEX[2], $v, $m)) {
                        $temp['pamokos data'] = ['y' => (int) $m[1], 'm' => (int) $m[2], 'd' => (int) $m[3], 'w' => null];
                    }
                    break;
                case 'Mokytojas(-a):':
                    $temp['mokytojas'] = $v;
                    break;
                case 'įvedė:':
                    if (preg_match(self::REGEX[3], $v, $m)) {
                        $temp['ivede'] = ['y' => (int) $m[1], 'm' => (int) $m[2], 'd' => (int) $m[3], 'h' => (int) $m[4], 'min' => (int) $m[5]];
                    }
                    break;
                case 'Failai:':
                    $parent = $label->parentNode;
                    if ($parent !== null) {
                        foreach (DomHelper::findAllTag($xp, $parent, 'a') as $a) {
                            $temp['failai'][] = [
                                'pavadinimas' => trim(DomHelper::text($a)),
                                'url' => 'https://dienynas.tamo.lt' . $a->getAttribute('href'),
                            ];
                        }
                    }
                    break;
                case 'Atlikimo data:':
                    if (preg_match(self::REGEX[2], $v, $m)) {
                        $temp['atlikimo data'] = ['y' => (int) $m[1], 'm' => (int) $m[2], 'd' => (int) $m[3], 'w' => null];
                    }
                    break;
            }
        }
        return $temp;
    }

    public function namuDarbai(?string $nuoData = null, ?string $ikiData = null, int $dalykoId = 0, int $metodas = 0): array
    {
        if ($nuoData === null || $ikiData === null) {
            $html = $this->httpGet('https://dienynas.tamo.lt/Darbai/NamuDarbai');
        } else {
            $html = $this->httpPost('https://dienynas.tamo.lt/Darbai/NamuDarbai', [
                'DateFilterMode' => $metodas, 'DataNuo' => $nuoData, 'DataIki' => $ikiData, 'DalykoId' => (string) $dalykoId,
            ]);
        }
        $doc = DomHelper::loadHtml($html);
        $xp = DomHelper::xpath($doc);

        $container = null;
        foreach (DomHelper::findAllByClass($xp, $doc, 'namu_darbai_content') as $c) {
            $container = $c;
            break;
        }
        if ($container === null) {
            return [];
        }

        $data = [];
        $currentDate = null;
        $currentInfo = null;
        foreach (DomHelper::findAllTag($xp, $container, 'div') as $i) {
            if (!$i->hasAttribute('class')) {
                continue;
            }
            if (DomHelper::hasClass($i, 'col-md-10')) {
                $currentDate = $this->getDate($xp, $i);
            } elseif (DomHelper::hasClass($i, 'col-md-13')) {
                $temp = $this->getInfo($xp, $doc, $i);
                $isTitleOnly = !isset($temp['pamokos data']) && !isset($temp['mokytojas'])
                    && !isset($temp['ivede']) && !isset($temp['atlikimo data']) && empty($temp['failai']);
                if ($isTitleOnly && strlen($temp['dalykas']) > 0) {
                    if ($currentInfo === null) {
                        continue;
                    }
                    $entry = ['namu darbas' => $temp['dalykas']];
                    $entry = array_merge($entry, $currentInfo);
                    $entry[$metodas === 0 ? 'atlikimo data' : 'pamokos data'] = $currentDate;
                    $data[] = $entry;
                } else {
                    $currentInfo = $temp;
                }
            }
        }
        return $data;
    }

    // ------------------------------------------------------------------
    // Atsiskaitomieji darbai
    // ------------------------------------------------------------------

    public function atsiskaitomiejiDarbai(?int $metai = null, ?int $menesis = null, ?int $mmid = null): array
    {
        if ($mmid !== null) {
            $html = $this->httpGet("https://dienynas.tamo.lt/Darbai/Atsiskaitymai?MoksloMetuMenesioId={$mmid}");
        } elseif ($metai !== null && $menesis !== null) {
            $calc = ($metai - 2014) * 12 + $menesis + 5;
            $html = $this->httpGet("https://dienynas.tamo.lt/Darbai/Atsiskaitymai?MoksloMetuMenesioId={$calc}");
        } else {
            $html = $this->httpGet('https://dienynas.tamo.lt/Darbai/Atsiskaitymai');
        }
        $doc = DomHelper::loadHtml($html);
        $xp = DomHelper::xpath($doc);

        $data = [];
        $trs = DomHelper::findAllTag($xp, $doc, 'tr');
        // Apdorojam visas eilutes - žr. pastabą tvarkarastis() viduje. Antraštės eilutė (jei yra)
        // natūraliai atkris žemiau, nes jos tekstas neatitiks "Grupė: X Dalykas: Y" formato.
        for ($ti = 0; $ti < count($trs); $ti++) {
            $tds = DomHelper::findAllTag($xp, $trs[$ti], 'td');
            if (empty($tds)) {
                continue;
            }
            if (!preg_match(self::REGEX[5], DomHelper::textNoNewline($tds[0]), $m)) {
                continue;
            }
            $grupe = trim($m[1]);
            $dalykas = trim($m[2]);
            for ($index = 0; $index < count($tds) - 1; $index++) {
                $cellText = DomHelper::text($tds[$index + 1]);
                // atitinka python REGEX[6].findall("(.+)", ...): kiekviena ne-tuščia eilutė = atskiras "tipas"
                $lines = preg_split('/\r\n|\r|\n/', $cellText);
                $t = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
                if (empty($t)) {
                    continue;
                }
                $pilniTipai = array_map(fn($code) => self::$TIPAS[$code] ?? $code, $t);
                $data[] = [
                    'dalykas' => $dalykas,
                    'grupe' => $grupe,
                    'tipai' => $t,
                    'pilni tipai' => $pilniTipai,
                    'data' => ['d' => $index + 1],
                ];
            }
        }
        return $data;
    }

    // ------------------------------------------------------------------
    // Pastabos / pagyrimai
    // ------------------------------------------------------------------

    public function pastabos(): array
    {
        $doc = DomHelper::loadHtml($this->httpGet('https://dienynas.tamo.lt/Pastabos/Mokiniams'));
        $xp = DomHelper::xpath($doc);

        $records = null;
        foreach (DomHelper::findAllByClass($xp, $doc, 'records') as $r) {
            $records = $r;
            break;
        }
        if ($records === null) {
            return [];
        }

        $data = [];
        $names = ['tipas', 'tekstas', 'dalykas', 'mokytojas'];
        foreach (DomHelper::findAllByClass($xp, $records, 'row') as $i) {
            $temp = [];
            $index = 0;
            $divs = array_values(array_slice(DomHelper::directChildren($i, 'div'), 1));
            if (empty($divs)) {
                continue;
            }
            foreach (array_slice($divs, 0, 2) as $j) {
                foreach (DomHelper::findAllTag($xp, $j, 'div') as $k) {
                    if (!isset($names[$index])) {
                        break;
                    }
                    $temp[$names[$index]] = DomHelper::text($k);
                    $index++;
                }
            }
            if (!isset($divs[2])) {
                continue;
            }
            $dateDivs = DomHelper::findAllTag($xp, $divs[2], 'div');
            if (count($dateDivs) < 2) {
                continue;
            }
            [$firstDate, $secondDate] = $dateDivs;

            if (preg_match(self::REGEX[2], DomHelper::text($firstDate), $fg)) {
                $temp['pamokos data'] = ['y' => (int) $fg[1], 'm' => (int) $fg[2], 'd' => (int) $fg[3]];
            }
            if (preg_match(self::REGEX[3], DomHelper::text($secondDate), $sg)) {
                $temp['irasymo data'] = ['y' => (int) $sg[1], 'm' => (int) $sg[2], 'd' => (int) $sg[3], 'h' => (int) $sg[4], 'min' => (int) $sg[5]];
            }
            $data[] = $temp;
        }
        return $data;
    }

    // ------------------------------------------------------------------
    // Pusmečiai / trimestrai
    // ------------------------------------------------------------------

    private function toFloatOrNull(string $raw): ?float
    {
        $raw = trim(str_replace(',', '.', $raw));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        return (float) $raw;
    }

    public function pusmeciai0(): array
    {
        $doc = DomHelper::loadHtml($this->httpGet('https://dienynas.tamo.lt/PeriodoVertinimas/MokinioVertinimai/0'));
        $xp = DomHelper::xpath($doc);

        $table = DomHelper::findAllByTagAndClasses($xp, $doc, 'table', ['c_main_table', 'wrap_text', 'c_block']);
        if (empty($table)) {
            throw new TamoException('pusmeciai0(): nerasta suvestinės lentelė');
        }
        $rows = DomHelper::findAllTag($xp, $table[0], 'tr');
        $rows = array_slice($rows, 2);
        if (empty($rows)) {
            throw new TamoException('pusmeciai0(): lentelė tuščia');
        }
        $lastTds = DomHelper::findAllTag($xp, $rows[count($rows) - 1], 'td');
        [$_, $pazymiu, $vidurkiu, $pagrIsvestu, $papIsvestu] = array_pad($lastTds, 5, null);

        $pagrText = DomHelper::text($pagrIsvestu);
        $papText = DomHelper::text($papIsvestu);
        $isvestuText = strlen($pagrText) >= strlen($papText) ? $pagrText : $papText;

        $data = ['vidurkis' => [
            'pazymiu' => $this->toFloatOrNull(DomHelper::text($pazymiu)),
            'vidurkiu' => $this->toFloatOrNull(DomHelper::text($vidurkiu)),
            'isvestu pazymiu' => $this->toFloatOrNull($isvestuText),
        ]];

        $dalykai = [];
        foreach (array_slice($rows, 0, -1) as $row) {
            $tds = DomHelper::findAllTag($xp, $row, 'td');
            if (count($tds) < 4) {
                continue;
            }
            $dalykasDivs = DomHelper::findAllTag($xp, $tds[0], 'div');
            if (empty($dalykasDivs)) {
                continue;
            }
            $dalykas = array_shift($dalykasDivs);
            $mokytojai = array_map(fn($d) => DomHelper::text($d), $dalykasDivs);

            $pirmas = $this->emptyToNull(DomHelper::text($tds[1]));
            $antras = $this->emptyToNull(DomHelper::text($tds[2]));
            $metinis = $this->emptyToNull(DomHelper::text($tds[3]));

            $dalykai[] = [
                'dalykas' => DomHelper::text($dalykas),
                'mokytojai' => $mokytojai,
                'pirmo pusmecio pazymys' => $pirmas,
                'antro pusmecio pazymys' => $antras,
                'metinis pazymys' => $metinis,
            ];
        }
        $data['dalykai'] = $dalykai;
        return $data;
    }

    private function emptyToNull(string $s): ?string
    {
        $s = str_replace(',', '.', $s);
        return $s === '' ? null : $s;
    }

    public function pusmeciai(?int $pusmecioId = null): array
    {
        if ($pusmecioId === 0) {
            return $this->pusmeciai0();
        }
        $url = $pusmecioId === null
            ? 'https://dienynas.tamo.lt/PeriodoVertinimas/MokinioVertinimai'
            : "https://dienynas.tamo.lt/PeriodoVertinimas/MokinioVertinimai/{$pusmecioId}";
        $doc = DomHelper::loadHtml($this->httpGet($url));
        $xp = DomHelper::xpath($doc);

        $nodes = $xp->query("//*[@id='c_main']");
        if ($nodes->length === 0) {
            throw new TamoException('pusmeciai(): nerastas #c_main blokas');
        }
        $table = DomHelper::findTag($xp, $nodes->item(0), 'table');
        if ($table === null) {
            throw new TamoException('pusmeciai(): nerasta lentelė #c_main viduje');
        }
        $rows = array_slice(DomHelper::findAllTag($xp, $table, 'tr'), 1);
        if (empty($rows)) {
            throw new TamoException('pusmeciai(): lentelė tuščia');
        }
        $lastTds = DomHelper::findAllTag($xp, $rows[count($rows) - 1], 'td');
        [$_, $pazymiu, $vidurkiu, $isvestu] = array_pad(array_slice($lastTds, 0, 4), 4, null);

        $data = ['vidurkis' => [
            'pazymiu' => $this->toFloatOrNull(DomHelper::text($pazymiu)),
            'vidurkiu' => $this->toFloatOrNull(DomHelper::text($vidurkiu)),
            'isvestu pazymiu' => $this->toFloatOrNull(DomHelper::text($isvestu)),
        ]];

        $dalykai = [];
        foreach (array_slice($rows, 0, -1) as $row) {
            $tds = DomHelper::findAllTag($xp, $row, 'td');
            if (count($tds) < 4) {
                continue;
            }
            $dalykasDivs = DomHelper::findAllTag($xp, $tds[0], 'div');
            if (empty($dalykasDivs)) {
                continue;
            }
            $dalykas = array_shift($dalykasDivs);
            $mokytojai = array_map(fn($d) => DomHelper::text($d), $dalykasDivs);

            $pazymiai = [];
            foreach (DomHelper::findAllTag($xp, $tds[1], 'div') as $j) {
                if (!$j->hasAttribute('data-original-title')) {
                    continue;
                }
                if (!preg_match(self::REGEX[6], $j->getAttribute('data-original-title'), $m)) {
                    continue;
                }
                $pazymiai[] = [
                    'ivertinimas' => DomHelper::text($j),
                    'data' => ['y' => (int) $m[1], 'm' => (int) $m[2], 'd' => (int) $m[3]],
                    'tipas' => trim($m[4]),
                ];
            }

            $vidurkis = $this->emptyToNull(DomHelper::text($tds[2]));
            $isvesta = $this->emptyToNull(DomHelper::text($tds[3]));

            $dalykai[] = [
                'dalykas' => DomHelper::text($dalykas),
                'mokytojai' => $mokytojai,
                'pazymiai' => $pazymiai,
                'vidurkis' => $vidurkis,
                'isvesta' => $isvesta,
            ];
        }
        $data['dalykai'] = $dalykai;
        return $data;
    }

    // ------------------------------------------------------------------
    // Pranešimai
    // ------------------------------------------------------------------

    private function getMessagingIdentification(): string
    {
        $this->httpGet('https://dienynas.tamo.lt/GoTo/Bendrauk');
        $res = $this->httpJson('https://api.tamo.lt/messaging/core/roles');
        if ($res['code'] !== 200 || !isset($res['json']['items'][0]['id'])) {
            throw new TamoException('Nepavyko gauti messaging identification (roles)');
        }
        return (string) $res['json']['items'][0]['id'];
    }

    public function pranesimai(int $puslapis = 1, ?string $identification = null): array
    {
        if ($identification === null) {
            $identification = $this->getMessagingIdentification();
        }
        $res = $this->httpJson(
            "https://api.tamo.lt/messaging/messages/received?orderDescending=true&searchTerm=&page={$puslapis}",
            ['x-selected-role: ' . $identification]
        );
        if ($res['code'] !== 200) {
            throw new TamoException("pranesimai(): HTTP {$res['code']}");
        }
        $raw = $res['json'];
        $data = [];
        foreach (($raw['items'] ?? []) as $i) {
            if (!preg_match(self::REGEX[7], $i['date'] ?? '', $fg)) {
                continue;
            }
            $temp = [
                'tema' => $i['subject'] ?? '',
                'data' => ['y' => (int) $fg[1], 'm' => (int) $fg[2], 'd' => (int) $fg[3], 'h' => (int) $fg[4], 'min' => (int) $fg[5], 's' => (int) $fg[6]],
                'siuntejas' => $i['senderPerson'] ?? null,
                'siuntejo tipas' => $i['senderPersonTitle'] ?? null,
                'turi prisegtu files' => (bool) ($i['hasAttachments'] ?? false),
                'id' => $i['id'],
                'perskaitymo data' => null,
            ];
            if (!empty($i['readDate']) && preg_match(self::REGEX[7], $i['readDate'], $sg)) {
                $temp['perskaitymo data'] = ['y' => (int) $sg[1], 'm' => (int) $sg[2], 'd' => (int) $sg[3], 'h' => (int) $sg[4], 'min' => (int) $sg[5], 's' => (int) $sg[6]];
            }
            $data[] = $temp;
        }
        return ['id' => $identification, 'pranesimai' => $data];
    }

    public function pranesimas(int|string $messageId, ?string $identification = null): array
    {
        if ($identification === null) {
            $identification = $this->getMessagingIdentification();
        }
        $res = $this->httpJson(
            "https://api.tamo.lt/messaging/messages/received/{$messageId}",
            ['x-selected-role: ' . $identification]
        );
        $raw = $res['json'];
        if (!isset($raw['item']['body'])) {
            throw new TamoNotFoundException("pranesimas(): žinutė {$messageId} nerasta");
        }
        $data = [
            'html tekstas' => $raw['item']['body'],
            'tekstas' => $raw['item']['bodyPlain'] ?? '',
        ];
        $attachments = [];
        foreach (($raw['attachments'] ?? []) as $i) {
            $attachments[] = ['pavadinimas' => $i['name'], 'id' => $i['sid']];
        }
        $data['prisegti files'] = $attachments;
        return $data;
    }

    public function fileUrl(string $fileId): array
    {
        $res = $this->httpJson('https://api.tamo.lt/files/filedownloadurl', [], ['fileSid' => $fileId]);
        if ($res['code'] === 404) {
            throw new TamoNotFoundException("fileUrl(): failas {$fileId} nerastas");
        }
        if ($res['code'] !== 200) {
            throw new TamoException("fileUrl(): HTTP {$res['code']}");
        }
        return $res['json'];
    }
}
