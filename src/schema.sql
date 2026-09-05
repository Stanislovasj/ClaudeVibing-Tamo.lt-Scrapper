-- Tamo PHP versijos duomenų bazės schema (MySQL / MariaDB)
-- Importuoti per phpMyAdmin arba: mysql -u root < schema.sql

CREATE DATABASE IF NOT EXISTS tamo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tamo;

-- Tamo prisijungimai (vardas/slaptažodis). Tvarkomi per settings.php web sąsają - config.php
-- "mokiniai" masyvas naudojamas TIK kaip pradinis seed'as, jei ši lentelė dar tuščia.
CREATE TABLE IF NOT EXISTS logins (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    vardas VARCHAR(255) NOT NULL,
    username VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bendra key/value nustatymų lentelė: PIN hash'as, uždaryti (restricted) skyriai ir pan.
CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(64) NOT NULL PRIMARY KEY,
    `value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Faktiškai atrasti moksleiviai. Vienas "logins" įrašas (=vienas Tamo prisijungimas) gali
-- atstovauti KELIS vaikus, jei tai tėvo/globėjo (TEVGLO) paskyra, susietą su daugiau nei
-- vienu vaiku - background scriptas juos automatiškai atranda ir įrašo čia.
-- id = arba vaiko "irasoId" (jei paskyra turi kelis vaikus), arba "logins.id"
-- (jei paskyra turi tik vieną vaiką / neturi perjungiklio).
CREATE TABLE IF NOT EXISTS students (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    login_id VARCHAR(64) NOT NULL, -- logins.id, kurio prisijungimu buvo pasiektas
    vardas VARCHAR(255) NOT NULL,
    mokykla VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vieno moksleivio vienos "sekcijos" (kategorijos) paskutinio scrapinimo rezultatas, laikomas kaip JSON.
-- Kiekvienam (student_id, category) porai laikoma tik VIENA (naujausia) eilutė,
-- kurią background skriptas kiekvieną kartą perrašo (UPSERT).
CREATE TABLE IF NOT EXISTS snapshots (
    student_id VARCHAR(64) NOT NULL,
    category VARCHAR(64) NOT NULL,
    fetched_at DATETIME NOT NULL,
    payload LONGTEXT NOT NULL, -- JSON
    error TEXT NULL,
    PRIMARY KEY (student_id, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pranešimai (Bendrauk skiltis). Kaupiami inkrementaliai (naujus prideda, senų netrina),
-- kad puslapis galėtų atvaizduoti pilną tekstą be gyvos Tamo sesijos.
-- id yra Tamo suteiktas pranešimo id, kuris yra unikalus tik VIENOS paskyros ribose,
-- todėl primary key yra (student_id, id) pora.
CREATE TABLE IF NOT EXISTS messages (
    student_id VARCHAR(64) NOT NULL,
    id BIGINT NOT NULL,
    tema VARCHAR(512) NOT NULL,
    siuntejas VARCHAR(255) NULL,
    siuntejo_tipas VARCHAR(255) NULL,
    data_y INT NULL, data_m INT NULL, data_d INT NULL, data_h INT NULL, data_min INT NULL, data_s INT NULL,
    perskaityta TINYINT(1) NOT NULL DEFAULT 0,
    perskaitymo_y INT NULL, perskaitymo_m INT NULL, perskaitymo_d INT NULL,
    perskaitymo_h INT NULL, perskaitymo_min INT NULL, perskaitymo_s INT NULL,
    turi_failu TINYINT(1) NOT NULL DEFAULT 0,
    html_tekstas LONGTEXT NULL,
    tekstas LONGTEXT NULL,
    body_fetched TINYINT(1) NOT NULL DEFAULT 0, -- ar jau atsisiuntėme pilną turinį
    fetched_at DATETIME NOT NULL,
    PRIMARY KEY (student_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS message_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(64) NOT NULL,
    message_id BIGINT NOT NULL,
    pavadinimas VARCHAR(512) NOT NULL,
    file_sid VARCHAR(255) NOT NULL,
    file_url TEXT NULL,
    FOREIGN KEY (student_id, message_id) REFERENCES messages(student_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kiekvieno background scriptelio paleidimo (kiekvienam moksleiviui atskirai) istorija,
-- matoma dashboard'e diagnostikai (kada paskutinį kartą pavyko/nepavyko prisijungti ir pan).
CREATE TABLE IF NOT EXISTS run_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(64) NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    status ENUM('ok', 'error') NOT NULL DEFAULT 'ok',
    message TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
