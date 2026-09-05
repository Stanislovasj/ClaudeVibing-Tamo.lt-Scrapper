<?php
/**
 * Nukopijuokite šį failą į config.php ir įrašykite savo duomenis.
 * config.php NETURĖTŲ būti pasiekiamas per naršyklę (žr. lib/.htaccess) ir
 * NETURĖTŲ būti dedamas į git/viešą repo, nes jame yra Tamo slaptažodžiai.
 */

return [
    // Vienas ar keli moksleiviai, kurių duomenys bus renkami.
    // 'id' turi būti unikalus, trumpas, be tarpų/lietuviškų raidžių (naudojamas DB raktuose ir URL).
    'mokiniai' => [
        [
            'id' => 'vardenis',
            'vardas' => 'Vardenis Pavardenis', // rodomas dashboard'e
            'username' => 'JUSU_TAMO_VARDAS',
            'password' => 'JUSU_TAMO_SLAPTAZODIS',
        ],
        // Antram moksleiviui tiesiog pridėkite dar vieną elementą:
        // [
        //     'id' => 'brolis',
        //     'vardas' => 'Brolis Brolaitis',
        //     'username' => 'KITAS_TAMO_VARDAS',
        //     'password' => 'KITAS_SLAPTAZODIS',
        // ],
    ],

    // MySQL/MariaDB (XAMPP numatytieji: host=localhost, user=root, password='')
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'tamo',
        'user' => 'root',
        'pass' => '',
    ],

    // Kiek pranešimų puslapių (po 20-30 žinučių) patikrinti kiekvieno scrapinimo metu
    // ieškant naujų, dar neišsaugotų pranešimų.
    'pranesimu_puslapiai' => 1,

    // true = taip pat parsiųsti pilną naujų pranešimų tekstą (papildomi HTTP request'ai)
    'parsiusti_pranesimu_tekstus' => true,

    // Nebūtina: jei nurodyta, api.php reikalaus šito tokeno (kaip ?token=... arba
    // X-Api-Token header'io) - naudinga jei namų tinkle yra ir kitų įrenginių.
    // Home Assistant RESTful sensoriuje: pridėti "headers: {X-Api-Token: 'jusu_tokenas'}".
    'api_token' => null,

    // Tikras PHP CLI (komandinės eilutės) kelias - naudojamas index.php "Atnaujinti dabar" mygtuko.
    // SVARBU: PHP_BINARY čia NETINKA, nes po Apache (mod_php) ji rodo į patį Apache/httpd
    // binarą, o ne į CLI php - jei paliktumėte PHP_BINARY, mygtukas bandytų paleisti dar
    // vieną Apache instanciją. Windows: 'C:\\xampp\\php\\php.exe'
    // Linux: '/usr/bin/php' (patikrinkite terminale komanda "which php")
    'php_cli_path' => 'C:\\xampp\\php\\php.exe',
];
