# WebCarrier BBS: Door-Handbuch

Wie Doors funktionieren und wie du eigene schreibst. Für PHP-Entwickler, die WebCarrier BBS um Spiele oder kleine Programme erweitern wollen. Wie eine fertige Door in die Menüs der Box eingebunden wird, steht im Sysop-Handbuch.

## Inhalt

1. Was eine Door ist
2. Aufbau einer Door-Datei
3. Ablauf: start() und input()
4. Ausgabe
5. Eingaben
6. Zustand und gespeicherte Daten
7. Texte und Sprachen
8. Zeit, Unterbrechungen und Fehler
9. Beispiel: ein Gästebuch
10. Referenz
11. Checkliste

## 1. Was eine Door ist

In den Mailboxen der Neunziger waren Doors eigenständige Programme, an die die Box den Anrufer für eine Weile übergab, meist Spiele wie Legend of the Red Dragon oder TradeWars. Bei WebCarrier BBS ist eine Door eine PHP-Klasse in einer Datei im Ordner `doors/`. Mitgeliefert ist `doors/hilo.php`, ein Zahlenratespiel mit Bestenliste. Die Datei ist bewusst kurz gehalten und eignet sich neben dem Beispiel in Abschnitt 9 als Vorlage für eigene Doors.

Eine Door läuft innerhalb der Mailbox-Engine. Sie gibt Text mit Pipe-Codes aus, fragt Tasten oder Zeilen ab und kann Daten pro User oder für die ganze Door speichern. Um Verbindung, Zeitlimit, Zeichensatz und Darstellung im Terminal kümmert sich die Engine.

Wichtig für das Verständnis: Das Terminal schickt jede Eingabe als eigene Anfrage an den Server. Zwischen zwei Eingaben läuft kein PHP-Code, die Door „wartet“ also nicht auf eine Taste. Stattdessen sagt sie der Engine, welche Eingabe sie als Nächstes erwartet, und wird mit dieser Eingabe erneut aufgerufen.

## 2. Aufbau einer Door-Datei

Eine Door-Datei definiert eine Klasse, die das Interface `CarrierDoor` erfüllt, und gibt am Ende ihre Registrierung als Array zurück:

```php
<?php
declare(strict_types=1);

if (!class_exists('MeineDoor')) {
    final class MeineDoor implements CarrierDoor
    {
        public function start(Engine $e): void
        {
            $e->cls();
            $e->bar('Meine Door');
            $e->nl();
            $e->hot('', '|07Eine Taste beendet die Door.');
        }

        public function input(Engine $e, string $v): void
        {
            $e->leaveDoor();
        }
    }
}

return [
    'id' => 'meinedoor',
    'name' => 'Meine Door',
    'class' => 'MeineDoor',
    'description' => 'Ein kurzer Satz, was die Door macht.',
    'version' => '1.0.0',
];
```

| Schlüssel | Bedeutung |
|---|---|
| `id` | Eindeutige Kennung, nur Kleinbuchstaben und Ziffern, höchstens 30 Zeichen. Unter dieser Id speichert die Door ihre Daten, und sie steht als Daten im Menüpunkt. Später nicht mehr ändern, sonst sind die gespeicherten Daten weg. |
| `name` | Name für Anzeigen, etwa „Wer ist online“ (dort steht „Spielt <name>“), die Seite „Doors“ im Backend und als Text des Menüpunkts, wenn der Sysop die Door mit „Ins Menü aufnehmen“ einbindet |
| `class` | Name der Klasse |
| `description` | Beschreibung für die Seite „Doors“ im Backend (optional) |
| `version` | Versionsnummer der Door, erscheint auf der Seite „Doors“ im Backend (optional) |

Einige Regeln für die Datei:

- Die Engine lädt alle Dateien in `doors/`, sobald irgendeine Door gebraucht wird, und das Backend lädt sie für seine Doors-Seite. Die Datei darf deshalb beim Laden nichts ausgeben und nichts tun außer Klasse definieren und Array zurückgeben.
- Lässt sich eine Datei nicht laden, etwa wegen eines Syntaxfehlers, einer fehlenden oder ungültigen Angabe in der Registrierung oder einer Id, die schon eine andere Datei benutzt, überspringt die Box sie. Der Sysop sieht sie im Backend unter „Fehlerhafte Dateien“ mit dem Grund. Bei doppelter Id gewinnt die Datei, die im Alphabet zuerst kommt.
- Die Abfrage `if (!class_exists(...))` verhindert einen Fehler, falls die Datei in einer Anfrage zweimal geladen wird.
- Klassennamen gelten für die ganze Box. Nimm einen Namen, der mit großer Wahrscheinlichkeit einmalig ist, etwa mit dem Namen der Door vorneweg. Benutzen zwei Doors denselben Klassennamen, startet wegen der Abfrage oben die falsche Klasse, ohne die Abfrage bricht PHP mit „Cannot declare class“ ab. Diesen Fehler kann die Box nicht abfangen, dann funktioniert keine Door mehr, bis die Datei entfernt ist.
- Die Datei ist UTF-8. Zeichen, die es im Zeichensatz CP437 nicht gibt, erscheinen im Terminal als `?`.

## 3. Ablauf: start() und input()

```php
interface CarrierDoor
{
    /** Called once when the user enters the door. */
    public function start(Engine $e): void;

    /** Called with every input while the door is active. */
    public function input(Engine $e, string $v): void;
}
```

1. Der Anrufer wählt den Menüpunkt. Die Engine merkt sich die Door, setzt den Zustand der Door zurück und ruft `start()` auf.
2. `start()` gibt etwas aus und ruft am Ende **genau eine** Methode auf, die eine Eingabe anfordert: `hot()`, `line()`, `yn()`, `pause()`, `wait()` oder `editor()`.
3. Die Antwort geht an das Terminal. Der Anrufer drückt eine Taste oder tippt eine Zeile.
4. Die Engine ruft `input()` mit dieser Eingabe auf. Auch `input()` endet mit genau einer Eingabeanforderung oder mit `leaveDoor()`.
5. Schritt 3 und 4 wiederholen sich, bis die Door `leaveDoor()` aufruft. Dann landet der Anrufer wieder in dem Menü, aus dem er die Door gestartet hat.

Fordert `start()` oder `input()` keine Eingabe an und ruft auch nicht `leaveDoor()` auf, zeigt die Engine das Menü. Die Door ist dann praktisch beendet, ohne dass sie es merkt.

Bei jedem Aufruf entsteht eine **neue Instanz** der Klasse. Eigenschaften der Klasse (`$this->...`) überleben deshalb keinen Tastendruck. Alles, was von einem Aufruf zum nächsten erhalten bleiben soll, gehört in `doorState()` (siehe Abschnitt 6).

**`pause()`** zeigt „Enter weiter“ und ruft danach `input()` mit `"\r"` auf. Bis Version 1.4 führte `pause()` in einer Door zurück ins Menü. Soll die Door auch auf älteren Versionen laufen, nimm für „Taste drücken“ `hot('', $prompt, true)`, das liefert ebenfalls `"\r"`.

## 4. Ausgabe

Text gibst du mit `$e->write()` aus, Zeilenumbrüche mit `$e->nl()`. Farben setzt du mit Pipe-Codes:

| Code | Wirkung |
|---|---|
| `\|00` bis `\|15` | Textfarbe (DOS-Farbnummern) |
| `\|16` bis `\|23` | Hintergrundfarbe |
| `\|CL` | Bildschirm löschen |
| `\|CR` | Neue Zeile |
| `\|\|` | Ein echter senkrechter Strich |

Die DOS-Farben: 0 Schwarz, 1 Blau, 2 Grün, 3 Cyan, 4 Rot, 5 Magenta, 6 Braun, 7 Hellgrau, 8 Dunkelgrau, 9 Hellblau, 10 Hellgrün, 11 Hellcyan, 12 Hellrot, 13 Hellmagenta, 14 Gelb, 15 Weiß. Die Engine wandelt UTF-8 automatisch in CP437 um.

Weitere Ausgabehilfen:

- `$e->cls()` löscht den Bildschirm und setzt die Farben zurück.
- `$e->bar('Titel', 'rechts')` zeichnet eine blaue Titelleiste über die volle Breite, so wie bei den Menüs der Box.
- `$e->rule()` zieht eine Trennlinie.
- `cb_pad($text, 20)` füllt Text auf eine feste Breite auf, `'R'` als drittes Argument richtet rechts aus, `'C'` zentriert. Gut für Tabellen wie eine Bestenliste.
- `cb_wrap($text, 70)` bricht langen Text in Zeilen um und gibt ein Array zurück.

**Text von Anrufern immer mit `cb_esc()` ausgeben.** Sonst wirken eingegebene Pipe-Codes als Farben, ein Name wie `|12Hacker` würde rot erscheinen. `cb_esc()` verdoppelt die Striche, sodass sie wörtlich erscheinen.

**Makros** wie `@USER@` oder `@BBSNAME@` ersetzt `write()` nicht. Willst du sie nutzen, schick den Text vorher durch `$e->macros()`. Eine Liste der Makros steht im Sysop-Handbuch im Abschnitt zu den Screens.

**Breite:** Das Terminal hat 80 Spalten. Halte jede Zeile bei höchstens 79 Zeichen, sonst bricht das Terminal selbst um und es entstehen Leerzeilen. Rechne bei Handles mit bis zu 20 Zeichen. Für einen ganzen Bildschirm stehen 24 Zeilen zur Verfügung, die letzte Zeile braucht die Eingabe.

**Eigene ANSI-Grafik:** Eine Door kann eine `.ans`-Datei aus ihrem eigenen Ordner zeigen, etwa ein Titelbild. Solche Dateien sind CP437 und gehen ohne Umwandlung ans Terminal: `$e->w->raw(cb_strip_sauce((string)file_get_contents(__DIR__ . '/meinedoor.ans')));`. Danach sind die Farben unbekannt, setze sie mit einem Pipe-Code neu. Screens des Sysops aus `data/screens/` zeigst du mit `$e->screen('name')`.

## 5. Eingaben

Am Ende jedes Aufrufs forderst du genau eine Eingabe an:

| Methode | Erwartet | Was in `input()` ankommt |
|---|---|---|
| `hot($tasten, $prompt)` | Eine Taste aus `$tasten` (Großbuchstaben), `''` heißt beliebige Taste, `"\r"` in der Liste erlaubt Enter | Die Taste als Großbuchstabe, Enter als `"\r"`. Bei `''` immer `"\r"` |
| `pause()` | Enter | Immer `"\r"` |
| `wait($ms, $prompt)` | Keine Taste: Die Ausgabe bleibt `$ms` Millisekunden stehen (0 bis 5000), dann geht es von selbst weiter | Immer `''` |
| `line($max, $prompt, $maske)` | Eine Zeile mit höchstens `$max` Zeichen, mit `$maske = true` verdeckt | Der eingegebene Text, ungefiltert |
| `yn($prompt, $standardJa)` | Ja oder Nein in der Sprache der Box (J/N oder Y/N), Enter nimmt den Standard | Die Taste, auswerten mit `$e->yes($v, $standardJa)` |
| `editor($zeilen, $breite, $max)` | Mehrzeiligen Text im Zeileneditor, `$zeilen` sind vorgegebene Zeilen | Der Text mit `"\n"` zwischen den Zeilen, `"\x00"` wenn der Anrufer abgebrochen oder nichts geschrieben hat |

Einige Dinge, die man gern vergisst:

- **Kurz warten mit `wait()`** (ab Version 1.5.0). Damit zeigt eine Door etwas an und macht nach einer Pause selbst weiter, etwa ein Schachspiel, das erst den Zug des Anrufers zeigt und eine Sekunde später den Zug des Computers. `$prompt` erscheint wie bei `hot()` ohne Zeilenumbruch. Tasten, die der Anrufer während der Pause drückt, werden verworfen. Nach Ablauf schickt das Terminal selbst eine leere Eingabe, `input()` bekommt also `''`. Diese automatische Eingabe zählt nicht als Aktivität: Wer nur noch zuschaut, wird nach der eingestellten Zeit ohne Eingabe trotzdem getrennt, auch wenn die Door mehrmals hintereinander wartet. Die Restzeit läuft während der Pause normal weiter. Soll die Door auch auf älteren Versionen laufen, prüfe vorher mit `method_exists($e, 'wait')` und mach sonst ohne Pause direkt weiter:

  ```php
  if (method_exists($e, 'wait')) {
      $e->wait(1500, '|08Der Computer denkt nach ...');
      return;                               // weiter in input() mit ''
  }
  $this->computerZug($e);                   // ältere Versionen: sofort
  ```
- **Großbuchstaben bei `hot()`.** Ab Version 1.5.0 kommt die Taste in `input()` immer groß an, auch wenn der Anrufer `j` drückt. Ältere Versionen liefern die Taste so, wie sie gedrückt wurde. Ein zusätzliches `$k = strtoupper($v);` schadet nicht und hält die Door mit älteren Versionen verträglich.
- **Zeilen säubern.** Für Text nimmst du `CP437::clean($v, $max)`. Das entfernt Steuerzeichen, kürzt auf `$max` Zeichen und schneidet Leerzeichen am Rand ab. Für Zahlen reicht `(int)trim($v)`, prüf aber den erlaubten Bereich.
- **Prompts** sind normaler Text mit Pipe-Codes. Endet der Prompt mit `|15`, erscheint die Eingabe des Anrufers weiß.
- **Mehrere Abfragen nacheinander**, etwa erst eine Taste und dann eine Zeile: Merk dir in `doorState()`, auf welche Eingabe du gerade wartest, und verzweige in `input()` danach (siehe Beispiel in Abschnitt 9).

## 6. Zustand und gespeicherte Daten

**Zustand während der Door:** `$e->doorState()` liefert ein Array **als Referenz**, das von Aufruf zu Aufruf erhalten bleibt, bis die Door verlassen wird. Das `&` beim Abholen nicht vergessen:

```php
$st = &$e->doorState();
$st['versuche'] = ($st['versuche'] ?? 0) + 1;
```

Beim Start der Door ist das Array leer. Es liegt in der Session des Anrufers und ist nach dem Auflegen weg.

**Dauerhafte Daten:** Für alles, was über einen Anruf hinaus erhalten bleiben soll, gibt es einen Speicher pro Door und User:

| Methode | Zweck |
|---|---|
| `$e->doorGet($k)` | Wert des Anrufers lesen, `null` wenn es keinen gibt |
| `$e->doorSet($k, $wert)` | Wert des Anrufers speichern (überschreibt den alten) |
| `$e->doorGet($k, $userId)`, `$e->doorSet($k, $wert, $userId)` | Dasselbe für einen anderen User |
| `$e->doorAll($k)` | Alle gespeicherten Werte dieses Schlüssels als Liste mit `user_id`, `v` und `handle` |

- Werte sind immer Strings. Zahlen speicherst du mit `(string)`, mehr als einen Wert als JSON.
- Schlüssel haben höchstens 30 Zeichen.
- **Daten der ganzen Door**, etwa eine gemeinsame Liste, speicherst du unter der User-Id `0`: `$e->doorSet('liste', $json, 0)`.
- In `doorAll()` ist `handle` leer, wenn der User inzwischen gelöscht wurde, und bei Daten unter der Id `0`. Prüf das vor der Ausgabe.
- Die Daten hängen an der `id` der Door. Wird ein User gelöscht, löscht die Box auch seine Door-Daten.

Eigene Tabellen in der Datenbank sind für Doors nicht vorgesehen. Für die allermeisten Fälle reicht der Speicher oben.

**Der Anrufer:** `$e->user` enthält den Datensatz des eingeloggten Anrufers als Array, unter anderem `id`, `handle`, `location`, `level` und `calls`. Behandle ihn als nur lesbar. Weitere Angaben bekommst du mit `$e->lvl()` (Level), `$e->isSysop()` und `$e->minutesLeft()` (Restzeit heute in Minuten).

## 7. Texte und Sprachen

Die Box läuft auf Deutsch oder Englisch, die aktuelle Sprache steht in `Lang::$code` (`de` oder `en`). Eine Door bringt ihre Texte **selbst mit**, am besten als Konstante in der Klasse:

```php
private const TEXTS = [
    'de' => ['title' => 'Gästebuch', 'saved' => '|10Danke, @USER@!|07'],
    'en' => ['title' => 'Guest book', 'saved' => '|10Thank you, @USER@!|07'],
];

private function t(Engine $e, string $key, string ...$args): string
{
    $s = $e->macros((self::TEXTS[Lang::$code] ?? self::TEXTS['en'])[$key]);
    foreach ($args as $i => $a) {
        $s = str_replace('{' . ($i + 1) . '}', $a, $s);
    }
    return $s;
}
```

Trag eigene Texte **nicht** in `lang/de.php` oder `lang/en.php` ein. Diese Dateien gehören zur Box und werden bei jedem Update ersetzt, deine Texte wären danach weg. Die mitgelieferte Door Hi-Lo macht es genauso und bringt ihre Texte in der eigenen Datei mit. Die Seite „Texte“ im Backend zeigt deshalb nur die Texte der Box, nicht die der Doors.

Auch die Tasten gehören zu den Texten, wenn sie zu einem Wort passen sollen, etwa `E` für „eintragen“ und `W` für „write“. Für Ja/Nein nimmst du `yn()`, das kennt die Tasten der Sprache schon.

Die Methode oben ersetzt die Makros, bevor die Argumente eingesetzt werden. So bleibt ein Name wie `@SYSOP@`, den ein Anrufer eintippt, wörtlich stehen. Argumente aus Eingaben von Anrufern trotzdem mit `cb_esc()` übergeben.

## 8. Zeit, Unterbrechungen und Fehler

**Zeitlimit und Untätigkeit:** Ist die Tageszeit des Anrufers um oder hat er zu lange nichts getippt, legt die Engine auf, bevor die Eingabe die Door erreicht. Die Door bekommt davon nichts mit.

**Unterbrechungen:** Der Sysop kann einen Anrufer trennen oder sich zu ihm in den Chat schalten. Ein Chat ersetzt die laufende Door, danach landet der Anrufer im Hauptmenü, die Door wird nicht fortgesetzt. Rundrufe und Benachrichtigungen erscheinen dagegen einfach als Zeile im Terminal, die Door läuft danach normal weiter.

Daraus folgt: **Speichere wichtige Fortschritte sofort** mit `doorSet()`, nicht erst beim Verlassen der Door. Bei einem Spiel also den Spielstand nach jedem Zug, nicht erst am Ende.

**Fehler:** Wirft die Door eine Exception oder einen PHP-Fehler, beendet die Engine die Door. Der Anrufer sieht „Die Door … hat einen Fehler gemeldet und wurde beendet.“ und landet nach Enter im Menü, die Fehlermeldung steht im Log der Box. Gespeichertes bleibt erhalten, der Zustand aus `doorState()` ist weg. Prüf Eingaben deshalb vorher, statt dich auf Exceptions zu verlassen. Gib nie selbst etwas mit `echo` aus und beende das Skript nie mit `exit`, beides zerstört die Antwort an das Terminal.

**Sicherheit:** Eine Door ist PHP-Code mit vollem Zugriff auf Server und Datenbank. Sysops installieren deshalb nur Doors aus Quellen, denen sie vertrauen. Als Entwickler: Eingaben nie ungeprüft an Dateifunktionen, Datenbankabfragen oder Shell-Befehle geben.

## 9. Beispiel: ein Gästebuch

Ein vollständiges Beispiel, das alles Wichtige zeigt: eigene Texte auf Deutsch und Englisch, Tasten und Zeilen abfragen, den Zustand zwischen zwei Eingaben, Daten der ganzen Door unter der Id `0` und einen Zähler pro User. Die Datei gehört nach `doors/gaestebuch.php`. Ein zweites, etwas längeres Beispiel ist das mitgelieferte Spiel `doors/hilo.php` mit Spielstand und Bestenliste.

```php
<?php
/**
 * Door for WebCarrier BBS: guest book.
 *
 * Copyright (C) 2026 Your Name
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

if (!class_exists('GaestebuchDoor')) {
    final class GaestebuchDoor implements CarrierDoor
    {
        private const TEXTS = [
            'de' => [
                'title' => 'Gästebuch',
                'empty' => '|08Noch keine Einträge. Sei der Erste!',
                'menu' => '|08[|15E|08]|07 eintragen |08[|15Q|08]|07 Ende|08: |15',
                'keys' => 'EQ',
                'ask' => '|07Dein Eintrag (Enter = abbrechen)|08: |15',
                'saved' => '|10Danke, @USER@! Dein Eintrag steht im Buch.|07',
                'count' => '|08Du hast schon {1} Eintrag/Einträge geschrieben.|07',
            ],
            'en' => [
                'title' => 'Guest book',
                'empty' => '|08No entries yet. Be the first!',
                'menu' => '|08[|15W|08]|07 write |08[|15Q|08]|07 quit|08: |15',
                'keys' => 'WQ',
                'ask' => '|07Your entry (Enter = cancel)|08: |15',
                'saved' => '|10Thank you, @USER@! Your entry is in the book.|07',
                'count' => '|08You have written {1} entry/entries so far.|07',
            ],
        ];

        /** Text in the language of the board, with macros, {1} {2} ... replaced afterwards. */
        private function t(Engine $e, string $key, string ...$args): string
        {
            $s = $e->macros((self::TEXTS[Lang::$code] ?? self::TEXTS['en'])[$key]);
            foreach ($args as $i => $a) {
                $s = str_replace('{' . ($i + 1) . '}', $a, $s);
            }
            return $s;
        }

        public function start(Engine $e): void
        {
            $this->showBook($e);
        }

        public function input(Engine $e, string $v): void
        {
            $st = &$e->doorState();
            if (($st['phase'] ?? '') === 'write') {
                $this->save($e, CP437::clean($v, 55));
                return;
            }
            $keys = $this->t($e, 'keys');
            if (strtoupper($v) === $keys[0]) {
                $st['phase'] = 'write';
                $e->nl();
                $e->line(55, $this->t($e, 'ask'));
                return;
            }
            $e->leaveDoor();
        }

        /** List of the last entries, $note (already formatted) above the prompt. */
        private function showBook(Engine $e, string $note = ''): void
        {
            $st = &$e->doorState();
            $st['phase'] = 'menu';
            $e->cls();
            $e->bar($this->t($e, 'title'));
            $e->nl();
            $entries = json_decode((string)$e->doorGet('entries', 0), true) ?: [];
            if (!$entries) {
                $e->write($this->t($e, 'empty'));
                $e->nl();
            }
            foreach (array_slice($entries, -15) as $x) {
                $e->write('|03' . date('d.m.', (int)$x['time']) . ' |11' . cb_esc(cb_pad($x['handle'], 16)) . '|07' . cb_esc($x['text']));
                $e->nl();
            }
            $e->nl();
            if ($note !== '') {
                $e->write($note);
                $e->nl(2);
            }
            $e->hot($this->t($e, 'keys'), $this->t($e, 'menu'));
        }

        private function save(Engine $e, string $text): void
        {
            $note = '';
            if ($text !== '') {
                // data of the whole door under user id 0, the counter per user
                $entries = json_decode((string)$e->doorGet('entries', 0), true) ?: [];
                $entries[] = ['handle' => $e->user['handle'], 'text' => $text, 'time' => time()];
                $e->doorSet('entries', json_encode(array_slice($entries, -100)), 0);
                $count = (int)$e->doorGet('count') + 1;
                $e->doorSet('count', (string)$count);
                $note = $this->t($e, 'saved') . '|CR' . $this->t($e, 'count', (string)$count);
            }
            $this->showBook($e, $note);
        }
    }
}

return [
    'id' => 'gaestebuch',
    'name' => 'Gästebuch',
    'class' => 'GaestebuchDoor',
    'description' => 'Ein Gästebuch für Anrufer, als Beispiel für eigene Doors.',
    'version' => '1.0.0',
];
```

So läuft es ab:

1. `start()` ruft `showBook()` auf. Die Methode setzt die Phase auf `menu`, zeigt die letzten 15 Einträge und fragt mit `hot()` die Tasten `E` und `Q` ab (auf Englisch `W` und `Q`).
2. Drückt der Anrufer `E`, setzt `input()` die Phase auf `write` und fragt mit `line()` den Eintrag ab. Jede andere erlaubte Taste beendet die Door mit `leaveDoor()`.
3. Kommt die Zeile an, erkennt `input()` an der Phase, dass ein Eintrag gemeint ist, und ruft `save()` auf. Ein leerer Eintrag bricht ab.
4. `save()` hängt den Eintrag an die gemeinsame Liste (User-Id `0`, als JSON, die letzten 100), erhöht den Zähler des Anrufers und zeigt die Liste mit einem Dank darunter wieder an.

Der Eintrag wird mit `cb_esc()` ausgegeben, ein `|12` aus einer Eingabe erscheint also wörtlich und färbt nichts ein.

## 10. Referenz

Die Methoden der Engine, die für Doors gedacht sind. `$e` ist das Engine-Objekt, das `start()` und `input()` übergeben bekommen.

| Methode | Zweck |
|---|---|
| `write($text)` | Text mit Pipe-Codes ausgeben, ohne Makros |
| `nl($anzahl = 1)` | Zeilenumbruch |
| `cls()` | Bildschirm löschen |
| `bar($links, $rechts = '')` | Titelleiste |
| `rule($farbe = 8)` | Trennlinie |
| `macros($text)` | Makros wie `@USER@` ersetzen |
| `screen($name)` | Screen aus `data/screens/` zeigen, `false` wenn es ihn nicht gibt |
| `w->raw($bytes)` | CP437-Bytes unverändert ausgeben, etwa eine ANSI-Datei |
| `hot($tasten, $prompt = '', $clear = false)` | Eine Taste abfragen, `$clear` löscht den Prompt nach dem Tastendruck |
| `line($max, $prompt, $maske = false)` | Eine Zeile abfragen |
| `yn($prompt, $standardJa)` | Ja/Nein abfragen |
| `yes($v, $standardJa)` | Antwort auf `yn()` auswerten |
| `pause()` | „Enter weiter“, danach `input()` mit `"\r"` |
| `wait($ms, $prompt = '')` | Ausgabe stehen lassen, nach `$ms` Millisekunden (0 bis 5000) ohne Taste weiter mit `input()` und `''`, ab 1.5.0 |
| `editor($zeilen, $breite = 75, $max = 200)` | Zeileneditor öffnen |
| `&doorState()` | Zustand bis zum Verlassen der Door |
| `doorGet($k, $userId = null)` | Gespeicherten Wert lesen |
| `doorSet($k, $v, $userId = null)` | Wert speichern |
| `doorAll($k)` | Alle Werte eines Schlüssels |
| `leaveDoor()` | Door beenden, zurück ins Menü |
| `act($text)` | Tätigkeit für „Wer ist online“ ändern (Standard: „Spielt <name>“) |
| `user` | Datensatz des Anrufers |
| `lvl()`, `isSysop()` | Level des Anrufers, Sysop ja oder nein |
| `minutesLeft()` | Restzeit heute in Minuten |

Hilfsfunktionen außerhalb der Engine:

| Funktion | Zweck |
|---|---|
| `cb_esc($text)` | Pipe-Codes in Text von Anrufern unwirksam machen |
| `cb_pad($text, $breite, 'L'/'R'/'C')` | Auf feste Breite auffüllen und ausrichten |
| `cb_wrap($text, $breite)` | Zeilenumbruch, gibt ein Array zurück |
| `cb_date($zeitstempel)` | Datum im Format der Box |
| `cb_strip_sauce($bytes)` | SAUCE-Block am Ende einer ANSI-Datei entfernen |
| `CP437::clean($text, $max)` | Eingabe säubern und kürzen |
| `Lang::$code` | Sprache der Box (`de` oder `en`) |

Andere öffentliche Methoden der Engine gehören zur Mailbox selbst und können sich zwischen Versionen ändern. Doors sollten sich auf die Methoden oben beschränken.

## 11. Checkliste

Bevor du eine Door weitergibst:

- [ ] Die Datei definiert die Klasse innerhalb von `if (!class_exists(...))` und gibt das Array mit `id`, `name`, `class`, `description` und `version` zurück.
- [ ] Die `id` ist eindeutig, besteht nur aus Kleinbuchstaben und Ziffern und ist höchstens 30 Zeichen lang. Der Klassenname ist einmalig.
- [ ] `start()` und jeder Weg durch `input()` enden mit genau einer Eingabeanforderung oder mit `leaveDoor()`.
- [ ] Kein `echo`, kein `exit`.
- [ ] Zeilen werden mit `CP437::clean()` gesäubert.
- [ ] Text von Anrufern geht durch `cb_esc()`.
- [ ] Alle Texte stehen in der Door selbst, auf Deutsch und Englisch, und keine Zeile ist breiter als 79 Zeichen.
- [ ] Wichtige Fortschritte werden sofort mit `doorSet()` gespeichert.
- [ ] Im Kopf der Datei stehen dein Name und die Lizenz der Door.

Eine Door im Ordner `doors/` wird Teil der Box. Wer eine Box öffentlich betreibt, muss den Quellcode samt eigener Doors nach der AGPL anbieten können, Näheres steht im Sysop-Handbuch im Abschnitt zur Lizenz.
