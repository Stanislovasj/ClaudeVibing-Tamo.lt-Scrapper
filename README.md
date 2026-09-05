# Tamo dashboard (namų valdymo skydelis)

Neoficialus [tamo.lt](https://tamo.lt) (Lietuvos mokyklų el. dienyno) duomenų rinkimo ir
atvaizdavimo įrankis. Fone periodiškai nuskaito jūsų vaiko(-ų) tvarkaraštį, pažymius,
namų darbus, pusmečius, atsiskaitymus, pastabas ir pranešimus, ir juos parodo per savo
dashboard'ą - **be poreikio kaskart gyvai jungtis prie Tamo**. Skirta veikti planšetėje ar
e-paper ekrane, pakabintame namuose (pvz. virtuvėje), taip pat integruotis su Home Assistant.

## Kaip tai veikia

- **Fono scriptas** (`cli/scrape.php`), paleidžiamas periodiškai (Windows Task Scheduler
  arba Linux cron), prisijungia prie tamo.lt su jūsų nurodytais prisijungimo duomenimis,
  nuskaito visus duomenis ir įrašo į MySQL/MariaDB duomenų bazę.
- **Dashboard'as** (`index.php`) rodo tik jau surinktus (iš DB) duomenis - jis pats
  neprisijungia prie Tamo, tad atsidaro akimirksniu net jei tamo.lt lėtai veikia ar yra
  nepasiekiamas. CSS-only tabai (be JavaScript), tinka lėtai atsinaujinantiems ekranams.
- Vienas Tamo prisijungimas (pvz. tėvo/globėjo paskyra) gali automatiškai turėti **kelis
  susietus vaikus** - jie atrandami ir atskiriami automatiškai.
- **`settings.php`** - PIN kodu apsaugotas nustatymų puslapis: Tamo prisijungimų
  pridėjimas/redagavimas/trynimas, dviejų NEPRIKLAUSOMŲ PIN kodų valdymas (vienas
  nustatymams, kitas - pasirinktinai apsaugotiems dashboard'o skyriams), skyrių šablono
  (planšetė / e-paper) pasirinkimas.
- **`api.php`** - JSON endpoint'as Home Assistant RESTful sensoriams.

## Reikalavimai

- Apache/nginx + PHP 8.1+ + MySQL/MariaDB. Pagrindinis šio README diegimo scenarijus -
  Windows + [XAMPP](https://www.apachefriends.org/), bet programa pati (`install.php`,
  `scrape_trigger.php`, `cli/scrape.php`) veikia ir Linux aplinkoje - žr. pastabas
  žemiau (`php_cli_path`, cron).
- PHP plėtiniai: `pdo_mysql`, `dom`, `curl` (visi įtraukti į standartinį XAMPP)

## Diegimas

Yra du būdai - pasirinkite vieną.

### A) Rankinis diegimas + web vediklis (`install.php`) - rekomenduojama

1. Nukopijuokite **viso `src\` katalogo turinį** (ne patį `src` katalogą, o jo vidų) į
   `C:\xampp\htdocs\Tamo\` (arba kitą jūsų pasirinktą pavadinimą po
   `htdocs`).
2. Įsitikinkite, kad XAMPP Apache ir MySQL paleisti (XAMPP Control Panel).
3. Naršyklėje atsidarykite:
   `http://localhost/Tamo/install.php`
4. Vediklis patikrins reikalavimus, tada paprašys DB duomenų (numatytieji XAMPP:
   host=`localhost`, user=`root`, be slaptažodžio) ir `php.exe` (CLI) kelio. Paspaudus
   "Diegti" jis pats sukurs duomenų bazę, importuos lenteles ir sugeneruos `config.php`.
5. Baigę, galite ištrinti `install.php` (saugumo sumetimais - jis pats atsisako veikti
   antrą kartą, kai `config.php` jau yra, bet failo pašalinimas yra papildoma atsarga).

### B) Rankinis diegimas be vediklio

1. Nukopijuokite `src\` turinį taip pat, kaip A) 1-2 žingsniuose.
2. Nukopijuokite `config.example.php` → `config.php` ir įrašykite savo DB/`php_cli_path`
   duomenis rankiniu būdu.
3. Importuokite `schema.sql` (phpMyAdmin arba `mysql -u root < schema.sql`).
4. Atsidarykite `http://localhost/Tamo/settings.php` (pradinis PIN:
   `999999`) ir pridėkite Tamo prisijungimą(-us).

## Periodinis fono atnaujinimas (Task Scheduler / cron)

Kad duomenys atsinaujintų savaime (ne tik paspaudus ⟳ dashboard'e), sukurkite periodinę
užduotį, kuri paleistų `cli/scrape.php`.

**Windows (Task Scheduler):**

1. Atidarykite **Task Scheduler** → **Create Task...**
2. **General**: pavadinimas, pvz. `TamoScraper`.
3. **Triggers** → **New** → *Repeat task every*: `30 minutes`, *for a duration of*: `Indefinitely`.
4. **Actions** → **New** → *Program/script*: `C:\xampp\php\php.exe`,
   *Add arguments*: `"C:\xampp\htdocs\Tamo\cli\scrape.php"`.
5. Išsaugokite. Rankiniu būdu galite paleisti tą pačią komandą terminale, kad iš karto
   patikrintumėte, ar prisijungimas veikia.

**Linux (cron):** `crontab -e` ir įrašykite eilutę (paleidžia :00 ir :30 kiekvieną valandą):

```
0,30 * * * * /usr/bin/php /var/www/tamo/cli/scrape.php > /dev/null 2>&1
```

(kelius pakoreguokite pagal savo `php` binarą - `which php` - ir diegimo katalogą)

## Nustatymai po diegimo (`settings.php`)

- **Tamo prisijungimai** - pridėkite kiekvieną Tamo paskyrą (ID, nebūtinas vardas,
  Tamo vartotojo vardas, slaptažodis). Vaiko vardas paprastai atpažįstamas automatiškai
  po pirmo atnaujinimo, tad "Vardas" lauką galima palikti tuščią.
- **Nustatymų PIN kodas** - saugo prieigą prie `settings.php` (pradinis: `999999`).
- **Skyrių PIN kodas** - visiškai atskiras nuo nustatymų PIN - saugo pasirinktus
  dashboard'o skyrius (žr. žemiau). Vieno atrakinimas nesuteikia prieigos prie kito.
- **Apsaugoti skyriai** - pažymėti dashboard'o skyriai bus paslėpti, kol įvedamas
  skyrių PIN kodas; atrakinimas išlieka, kol paspaudžiamas 🔒 mygtukas dashboard'e.
- **Šablonas** - Planšetė (spalvota) arba E-paper (juoda/balta, maksimalus kontrastas).

## Home Assistant integracija

`api.php` grąžina JSON su visų (arba per `?mokinys=id` filtruoto vieno) moksleivio
duomenimis. Jei `config.php` (arba diegimo vediklyje) nurodėte `api_token`, RESTful
sensoriuje pridėkite antraštę:

```yaml
sensor:
  - platform: rest
    resource: http://<jusu-serveris>/Tamo/api.php
    headers:
      X-Api-Token: "jusu_tokenas"
    ...
```

## Saugumas

- `config.php`, `config.example.php`, `schema.sql`, `lib/` ir `cli/` katalogai
  užblokuoti nuo tiesioginės prieigos per naršyklę (`.htaccess`).
- `scrape_trigger.php`, `scrape_status.php` ir `install.php` veikia tik iš `localhost`.
- Niekada neplatinkite savo `config.php` (jame yra Tamo slaptažodžiai) - šiame pakete
  jo NĖRA, tik `config.example.php` šablonas.

## Šio paketo turinys

```
TamoSTE\
  README.md        - šis failas
  src\              - visas programos kodas, paruoštas kopijuoti į htdocs
    install.php     - web diegimo vediklis (žr. "Diegimas" A) aukščiau)
    config.example.php
    schema.sql
    index.php, settings.php, api.php, scrape_trigger.php, scrape_status.php
    lib\            - PHP klasės (Db, TamoScraper, DomHelper, Config, Tabs)
    cli\            - fono scrapinimo scriptas (scrape.php)
```
