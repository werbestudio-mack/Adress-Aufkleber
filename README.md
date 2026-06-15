# LabelMaker – Adress-Aufkleber aus CSV

Webtool zum Erzeugen von Adressetiketten (Avery Zweckform 3481, 70 × 41 mm, 3 × 7 pro A4-Seite) als PDF aus einer CSV- oder Word-Datei.

## Voraussetzungen

- PHP 8.0 oder neuer (mit `mbstring`-Extension)
- [Composer](https://getcomposer.org/)
- Webserver (Apache, Nginx, oder PHPs eingebauter Dev-Server)

## Installation

```bash
git clone <repository-url>
cd Adress-Aufkleber
composer install
```

**Direkt ins aktuelle Verzeichnis klonen (kein Unterordner):**

```bash
git clone https://github.com/theandy/Adress-Aufkleber.git .
composer install
```

Den Webserver auf das Verzeichnis `public/` als Document Root zeigen lassen.

**Mit PHPs eingebautem Dev-Server (nur zum Testen):**

```bash
php -S localhost:8080 -t public/
```

Dann im Browser: [http://localhost:8080](http://localhost:8080)

## Verzeichnisstruktur

```
├── composer.json
├── fonts/                  # Eigene TTF/OTF-Schriften (optional)
├── public/
│   ├── assets/css/         # Eigenes Stylesheet
│   ├── vendor/             # FontAwesome (statisch, kein npm nötig)
│   ├── partials/           # Header/Footer
│   ├── index.php
│   └── make-labels.php
├── src/
│   ├── CsvUtil.php         # CSV-Einlesen & Encoding-Erkennung
│   └── LabelPdf.php        # PDF-Erzeugung mit TCPDF (Avery 3481)
└── var/
    ├── presets/            # Gespeicherte Presets (je eine .json-Datei)
    └── uploads/            # Temporäre Upload-Dateien (wird auto-erstellt)
```

## Presets

Presets werden als einfache JSON-Dateien in `var/presets/` gespeichert – keine Datenbank nötig. Das Verzeichnis wird beim ersten Speichern eines Presets automatisch angelegt.

## Schriften

Eigene Schriften können als Unterordner in `fonts/` abgelegt werden (z.B. `fonts/meine-schrift/MeineSchrift.ttf`). Sie erscheinen dann automatisch in der Schriftauswahl.
