# WebCarrier BBS: Sysop-Handbuch

Version 1.6.0

Für Betreiber der Mailbox: Installation, Einrichtung, Backend, Menüs, Screens, Dateiimport, Doors und Betrieb.

## Inhalt

1. Was WebCarrier BBS ist
2. Voraussetzungen
3. Installation
4. Nach der Installation
5. Webserver: Apache und nginx
6. Grundbegriffe: Nodes, Level, Zeit, Ratio
7. Das Sysop-Backend
8. Menüs und Befehle
9. Screens, Pipe-Codes und Makros
10. Dateien: Bereiche, Freigabe, Import
11. Doors
12. Sprache und Texte anpassen
13. Datensicherung und Update
14. Sicherheit und Datenschutz
15. Fehlersuche
16. Lizenz

## 1. Was WebCarrier BBS ist

WebCarrier BBS ist eine Mailbox im Stil der frühen Neunziger, die als Website läuft. Anrufer öffnen deine Domain, sehen einen DOS-Bildschirm mit 80×25 Zeichen, drücken eine Taste, hören die Wähltöne und landen im Login. Bedient wird danach nur mit der Tastatur.

Das Terminal ist ein Canvas im Browser mit dem IBM-VGA-Zeichensatz. Die Mailbox selbst läuft in PHP auf dem Server: Jeder Tastendruck bzw. jede eingegebene Zeile geht an den Server, der mit ANSI-Ausgabe antwortet und dem Terminal mitteilt, welche Eingabe als Nächstes kommt. Eigene ANSI-Screens, Farben und Menüs funktionieren deshalb wie bei RemoteAccess oder PCBoard.

Telnet, SSH oder echte Modemanrufe gibt es nicht. WebCarrier BBS ist eine reine Website und läuft auf normalem Webspace.

## 2. Voraussetzungen

| Bestandteil | Mindestens | Hinweis |
|---|---|---|
| PHP | 8.1 | 8.2 oder 8.3 empfohlen |
| PDO | mit SQLite oder MySQL | SQLite braucht keinerlei Einrichtung |
| mbstring | ja | bei fast jedem Hoster aktiv |
| ZipArchive | optional | liest FILE_ID.DIZ aus ZIP-Dateien |
| Datenbank | SQLite 3 oder MySQL 5.7 / MariaDB 10.3 | |
| Webserver | Apache mit .htaccess oder nginx | für nginx siehe Abschnitt 5 |

Speicherplatz brauchst du vor allem für die Dateibereiche. Die Software selbst ist kleiner als 1 MB.

## 3. Installation

1. Lade die gewünschte Version als ZIP herunter ([webcarrier-bbs.de](https://webcarrier-bbs.de) oder GitHub unter „Releases“) und entpacke sie. Lade den Inhalt des Ordners `webcarrierbbs-<version>` per FTP auf deinen Webspace, entweder in das Hauptverzeichnis der Domain oder in einen Unterordner wie `/bbs/`.
2. Stelle sicher, dass die Ordner `core/` und `data/` (mit allen Unterordnern) für PHP beschreibbar sind. Bei den meisten Hostern ist das automatisch so. Falls nicht, setze per FTP die Rechte auf 775 oder 755.
3. Rufe im Browser `https://deine-domain.de/install/` auf (bzw. `/bbs/install/`).
4. Der Installer startet auf Deutsch, wenn dein Browser Deutsch bevorzugt, sonst auf Englisch. Oben rechts kannst du die Sprache umschalten. Er prüft den Server, alle Punkte außer ZipArchive müssen grün sein.
5. Trag ein: Name der Mailbox, Ort, Sprache, deinen Sysop-Handle und ein Passwort mit mindestens 8 Zeichen. Als Sprache der Mailbox ist die Sprache des Installers vorausgewählt.
6. Wähle die Datenbank. **SQLite** ist die einfachste Wahl: Die Datenbank liegt dann als Datei mit Zufallsnamen in `data/`. **MySQL** brauchst du nur, wenn du lieber eine vorhandene Datenbank deines Hosters nutzt. Das Tabellenpräfix (Standard `cb_`) erlaubt mehrere Installationen in einer Datenbank.
7. Klick auf „Installieren“ bzw. „Install“. Der Installer legt Tabellen, Level, Bereiche, Menüs, Begrüßungsnachricht, Oneliner und die Standard-Screens an und schreibt `core/config.php`.

Danach kannst du dich im Terminal mit deinem Sysop-Handle einloggen.

Schlägt die Installation mit MySQL mittendrin fehl, räumt der Installer die angelegten Tabellen wieder ab, du kannst es also direkt noch einmal versuchen.

## 4. Nach der Installation

Bevor du die Box bekannt machst:

1. **Ordner `install` löschen.** Er verweigert zwar eine zweite Installation, gehört aber nicht auf einen Live-Server.
2. **Impressum und Datenschutzerklärung eintragen:** Backend, Menüpunkt „Impressum und Datenschutz“. Für deutsche Betreiber gilt für das Impressum § 5 DDG. Die Texte erscheinen im Terminal über die Menüpunkte I und X und zusätzlich als normale Webseiten, die unter dem Terminal verlinkt sind. Lass diese Links eingeschaltet, sonst ist das Impressum ohne Login nicht erreichbar.
3. **Einstellungen prüfen:** Anzahl Nodes, Zeitzone, Standard-Modemgeschwindigkeit, Upload-Regeln.
4. **Willkommens-Screen anpassen:** `welcome` ist der erste Bildschirm, den Anrufer sehen. Zeichne einen eigenen mit PabloDraw oder Moebius und lade ihn im Backend unter „Screens“ hoch.

## 5. Webserver: Apache und nginx

### Apache

Mitgelieferte `.htaccess`-Dateien sperren `core/` (mit den mitgelieferten Screen-Vorlagen in `core/defaults/`), `data/`, `lang/` und `doors/` gegen direkte Aufrufe und verhindern Verzeichnislisten. Du musst nichts tun, solange dein Hoster `.htaccess` erlaubt (AllowOverride).

### nginx

nginx liest keine `.htaccess`. Ergänze in deinem `server`-Block unbedingt diese Regeln, sonst wären Datenbank und Dateien direkt abrufbar:

```nginx
location ~ ^/(core|data|lang|doors)/ {
    deny all;
    return 404;
}
location ~ /\. {
    deny all;
}
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
```

Liegt die Box in einem Unterordner, ergänze den Pfad entsprechend, zum Beispiel `^/bbs/(core|data|...)/`.

### Upload-Größe

Wie groß Uploads sein dürfen, bestimmt das kleinste von drei Limits: die Einstellung im Backend, `upload_max_filesize` und `post_max_size` in der PHP-Konfiguration deines Hosters. Das Backend zeigt dir in den Einstellungen das tatsächlich gültige PHP-Limit an.

## 6. Grundbegriffe: Nodes, Level, Zeit, Ratio

**Nodes** sind die „Telefonleitungen“ deiner Box. In den Einstellungen legst du fest, wie viele Anrufer gleichzeitig online sein dürfen (Standard 4). Sind alle belegt, bekommt der nächste Anrufer die Meldung, dass alle Leitungen besetzt sind. Eine Node wird frei, wenn sich jemand ausloggt, den Browser-Tab schließt oder zu lange nichts eingibt (Einstellung „Auflegen nach Minuten ohne Eingabe“). Wer im Editor schreibt oder gerade eine Datei hochlädt, behält seine Node, auch wenn das länger dauert.

**Level** steuern alles, was ein User darf. Jeder User hat einen Wert von 0 bis 255. Mitgeliefert sind:

| Level | Name | Minuten pro Tag | Download pro Tag |
|---|---|---|---|
| 10 | Neuer User | 30 | 2048 KB |
| 20 | Mitglied | 60 | 10240 KB |
| 50 | Stammgast | 120 | unbegrenzt |
| 100 | Co-Sysop | 240 | unbegrenzt |
| 255 | Sysop | unbegrenzt | unbegrenzt |

Ein User bekommt die Grenzen der höchsten Levelzeile, die nicht über seinem eigenen Level liegt. Ein User mit Level 30 bekommt also die Werte von Level 20. Neue User bekommen das Level aus der Einstellung „Level für neue User“. Hochstufen (früher nach dem Validierungsanruf) machst du in der Userverwaltung.

**Zeitlimit:** Die Minuten pro Tag zählen über alle Anrufe eines Tages. Ist die Zeit um, wird aufgelegt. 0 bedeutet unbegrenzt. In der Userverwaltung kannst du Zeit und Downloadzähler für heute zurücksetzen.

**Ratio:** Ein Wert von 3 bedeutet drei Downloads pro Upload. Wer mehr laden will, muss erst etwas hochladen. 0 schaltet die Ratio ab. Der Sysop ist von Ratio und Downloadlimit ausgenommen.

Zugriffsrechte für Bereiche funktionieren ebenfalls über Level: Jeder Nachrichtenbereich hat ein Lese- und ein Schreiblevel, jeder Dateibereich ein Zugriffs- und ein Upload-Level. Menüs und einzelne Menüpunkte haben ein Mindestlevel.

## 7. Das Sysop-Backend

Erreichbar unter `/sysop/`. Einloggen darf jeder Account, dessen Level mindestens dem Wert „Level mit Sysop-Rechten“ entspricht (Standard 255). Das Backend funktioniert auch auf dem Handy.

Beim Speichern der Einstellungen prüft das Backend zwei Werte: Der Sysop-Level muss zwischen 1 und deinem eigenen Level liegen (sonst wäre jeder Sysop oder du würdest dich selbst aussperren), und der Level für neue User muss darunter liegen. Ungültige Werte werden korrigiert und du bekommst einen Hinweis.

| Bereich | Wofür |
|---|---|
| Übersicht | Meldungen, belegte Nodes mit Trennen und Rundruf, Kennzahlen, letzte Ereignisse, Systeminfos (siehe unten) |
| Statistik | Anrufe, neue User, Uploads und Downloads im Verlauf, Bestenlisten (siehe unten) |
| Einstellungen | Name, Sprache, Zeitzone, Nodes, Neuanmeldung, Terminal, Uploads, Update-Prüfung |
| Impressum und Datenschutz | Rechtstexte als reiner Text |
| User | Anlegen, suchen, bearbeiten, Level ändern, sperren, Passwort setzen, löschen, neue User freischalten |
| Level | Zeit, Downloadlimit und Ratio je Level |
| Sperrliste | Gesperrte Handles und Wörter (siehe unten) |
| Nachrichtenbereiche | Anlegen, umbenennen, Rechte, Reihenfolge, löschen |
| Nachrichten | Öffentliche Nachrichten lesen, löschen und in andere Bereiche verschieben. Private Post wird hier bewusst nicht angezeigt |
| Oneliner | Moderieren |
| Dateibereiche | Anlegen, Rechte, Uploads erlauben |
| Dateien | Uploads freigeben, umbenennen, Beschreibungen ändern, verschieben, löschen, Dateien per Browser hochladen |
| Dateiimport | Viele Dateien auf einmal aus `data/import` übernehmen |
| Menüs | Menüstruktur und Hotkeys bearbeiten |
| Screens | ANSI- und Text-Screens hochladen, bearbeiten, mit Vorschau |
| Texte | Eigene Fassungen der Terminaltexte, siehe Abschnitt 12 |
| Doors | Installierte Doors, ins Menü aufnehmen, Spielstände zurücksetzen, fehlerhafte Dateien, siehe Abschnitt 11 |
| Log | Logins, Uploads, Downloads, Änderungen |
| Backup | Sicherung als ZIP herunterladen, siehe Abschnitt 13 |

### Übersicht

Ganz oben stehen die **Meldungen**, sortiert nach Wichtigkeit. Vor jedem Titel steht die Art:

| Art | Beispiele |
|---|---|
| Sicherheit | Der Ordner `install` liegt noch auf dem Server, ein abgebrochenes Update wurde aufgeräumt |
| Aufgabe | Impressum oder Datenschutzerklärung leer, Uploads warten auf deine Freigabe, neue User warten auf Freischaltung, Door-Dateien konnten nicht geladen werden |
| Update | Neue Version verfügbar, Ergebnis des letzten Updates (einmalig) |
| Hinweis | Noch kein Backup oder letztes Backup älter als 30 Tage, Update-Prüfung ausgeschaltet |
| Neuigkeit | Meldungen des Projekts, nur bei eingeschalteter Update-Prüfung |

Hinweise und Neuigkeiten kannst du mit „Ausblenden“ wegklicken. Sicherheitsmeldungen, Aufgaben und Updates lassen sich nicht ausblenden, sie verschwinden, sobald die Ursache behoben ist. Gibt es nichts zu melden, steht dort, dass alles in Ordnung ist.

Unter den Nodes steht der Kasten **„Nachricht des Sysops“**. Was du dort einträgst, sieht jeder Anrufer nach dem Login unter der Zeile mit Restzeit und Post, mit „Nachricht von“ und deinem Namen darüber. Erlaubt sind bis zu 3 Zeilen mit je 76 Zeichen, Pipe-Codes für Farben wie `|14` zählen dabei nicht mit. `|CL`, `|CR` und andere Steuercodes werden entfernt. Ist eine Zeile zu lang oder sind es mehr als 3 Zeilen, wird nichts gespeichert und du bekommst einen Hinweis, dein Text bleibt im Feld stehen. Mit „Anzeigen bis“ legst du optional einen Tag fest, bis einschließlich dem die Nachricht erscheint. Danach sehen Anrufer sie nicht mehr, im Backend bleibt sie mit dem Hinweis „abgelaufen“ stehen, bis du sie änderst oder mit „Nachricht entfernen“ löschst. Jede Änderung steht im Log. Ohne Nachricht sieht die Begrüßung aus wie immer.

Darunter folgen die belegten Nodes. Neben jeder Node steht der Knopf „Trennen“, deine eigene Sitzung im Terminal ist davon ausgenommen. Unter der Liste schickst du mit „Rundruf an alle Nodes“ eine Zeile an alle, die gerade online sind. Getrennte Anrufer sehen „Der Sysop hat die Verbindung getrennt.“, ein Rundruf erscheint beim Anrufer gelb mit einem Klingelton. Dann folgen die Kennzahlen (User, Anrufe heute, gerade online, öffentliche Nachrichten, Dateien, wartende Uploads), die letzten Ereignisse aus dem Log und der Kasten „System“ mit Version, PHP-Version, Datenbank, letztem Backup und dem Stand der Update-Prüfung. Unter den Kennzahlen führt ein Link zur Statistik. Auf dem Handy steht alles untereinander, auf breiten Bildschirmen stehen Kennzahlen und System in einer schmalen Spalte rechts.

### Statistik

Die Seite „Statistik“ zeigt als Balken die Anrufe pro Tag der letzten 30 Tage sowie neue User, Uploads und Downloads pro Monat der letzten 12 Monate. Neben jedem Balken steht die Zahl. Darunter folgen drei Bestenlisten mit je zehn Einträgen: die meistgeladenen Dateien, die fleißigsten Schreiber und die häufigsten Anrufer. Sysops und User, die auf ihre Freischaltung warten, stehen nicht in den Bestenlisten. Tage und Monate richten sich nach der Zeitzone aus den Einstellungen.

Uploads und Downloads zählt die Statistik aus dem Log. Leerst du das Log, sind auch diese Zahlen weg, deshalb fragt die Seite „Log“ vorher noch einmal nach. Anrufe und neue User kommen aus eigenen Tabellen und bleiben erhalten.

### Userverwaltung

Unter „User“ legst du neue Accounts direkt an: Handle, Ort, Passwort (zweimal, mindestens 6 Zeichen) und Level. Für den Handle gelten dieselben Regeln wie bei der Neuanmeldung im Terminal: 3 bis 20 Zeichen, eindeutig, und die reservierten Namen NEW, Sysop und All sind gesperrt, bei deutscher Sprache der Box zusätzlich NEU und Alle. Das gilt auch, wenn du einen User umbenennst.

Level vergeben kannst du höchstens bis zu deinem eigenen. User mit einem höheren Level als deinem lassen sich nicht bearbeiten. Das betrifft vor allem Co-Sysops, wenn du den Sysop-Level niedriger als 255 eingestellt hast. Dein eigenes Level kannst du nicht ändern.

**Passwort vergessen:** Anrufer können ihr Passwort nicht selbst zurücksetzen, weil die Box keine E-Mail-Adressen speichert. Das machst du in der Userverwaltung unter „Neues Passwort“.

### Freischaltung neuer User

In den Einstellungen gibt es den Abschnitt „Neuanmeldung“ mit drei Schaltern: ob sich neue User überhaupt anmelden dürfen, welches Level sie bekommen und ob sie **freigeschaltet werden müssen**. Ab Werk ist die Freischaltung aus, dann verhält sich die Box wie gewohnt.

Ist sie an, läuft die Neuanmeldung ganz normal, am Ende sieht der Anrufer aber den Screen `pending` („Danke für deine Anmeldung, der Sysop prüft deinen Zugang“), und nach einem Tastendruck wird aufgelegt. Die Zeit zählt dabei nicht gegen sein Tageslimit. Bis zur Freischaltung landet er auch bei jedem weiteren Login nach dem Passwort wieder auf diesem Screen. Wartende User erscheinen nicht in der Userliste, nicht bei „Letzte Anrufer“ und können keine private Post bekommen.

Bist du gerade im Terminal eingeloggt, bekommst du bei jeder neuen Anmeldung sofort eine Benachrichtigung. In der Übersicht steht die Aufgabe „x neue User warten auf Freischaltung“ mit Link auf die gefilterte Userliste. Dort schaltest du einzeln frei (mit Auswahl des Levels, vorbelegt mit dem Level für neue User) oder löschst den Account, über die Häkchen auch für mehrere auf einmal. Im Terminal geht das im Sysop-Menü mit der Taste N.

Schaltest du die Freischaltung wieder aus, bleiben bereits wartende User wartend, bis du sie freischaltest oder löschst. Die Einstellungsseite weist dann darauf hin.

Handles, die auf der Sperrliste stehen, kann sich bei der Neuanmeldung niemand geben (siehe nächster Abschnitt).

### Sperrliste

Unter „Sperrliste“ trägst du zwei Listen ein, jeweils ein Eintrag pro Zeile. Groß- und Kleinschreibung spielt keine Rolle, doppelte Einträge werden beim Speichern entfernt.

**Gesperrte Handles:** Ein Eintrag gilt für den ganzen Handle, `*` steht für beliebige Zeichen. `admin*` sperrt also Admin, Administrator und admin2, `*sysop*` jeden Handle, in dem „sysop“ vorkommt. Bei der Neuanmeldung bekommt der Anrufer dieselbe Meldung wie bei einem vergebenen Handle und erfährt nicht, dass der Name gesperrt ist. Legst du im Backend einen User mit gesperrtem Handle an oder benennst ihn so um, wird er trotzdem gespeichert, du bekommst nur eine Warnung.

**Gesperrte Wörter:** Ein Eintrag gilt nur für ganze Wörter. `arsch` trifft „Arsch“, aber nicht „Barschfilet“. Mit `*` am Ende trifft ein Eintrag auch Wörter, die so beginnen: `spam*` trifft „Spammer“. Geprüft werden Oneliner, Betreffs, Nachrichten, private Post, der Wohnort bei Anmeldung und in den Einstellungen sowie Upload-Beschreibungen. Der Anrufer sieht, welches Wort nicht erlaubt ist, und kann neu eingeben. Bei einer Nachricht landet er mit seinem Text wieder im Editor, `/L` zeigt den Text. Ein Upload mit gesperrtem Wort in der Beschreibung wird angenommen, wartet aber immer auf deine Freigabe, auch wenn Uploads sonst automatisch freigegeben werden. Das Log nennt das Wort.

Für Sysops gilt die Sperrliste nicht. Vorhandene User, deren Handle auf die Liste passt, zeigt die Seite unter der Liste an. Sie werden nicht automatisch gesperrt, das entscheidest du selbst.

### Nachrichten

Die Liste unter „Nachrichten“ zeigt die letzten 200 öffentlichen Nachrichten, auf Wunsch nur eines Bereichs. Über die Häkchen links (das Häkchen im Tabellenkopf wählt alle) löschst du mehrere Nachrichten auf einmal oder verschiebst sie in einen anderen Bereich, etwa wenn jemand im falschen Bereich geschrieben hat. In der Einzelansicht einer Nachricht geht beides auch direkt. Private Post lässt sich hier weder sehen noch verschieben.

## 8. Menüs und Befehle

Jedes Menü ist eine Liste von Hotkeys. Anrufer starten nach dem Login im Menü `main`. Ein Menüpunkt besteht aus Taste, Text, Befehl, Daten, Mindestlevel und Sortierung. Als Taste geht jedes Zeichen, auch Umlaute.

Ein Menü wird automatisch als zweispaltige Liste mit Titelleiste gezeichnet. Trägst du beim Menü einen Screen-Namen ein, wird stattdessen dieser ANSI-Screen gezeigt, dann musst du die Hotkeys im Screen selbst darstellen. Die Taste `?` ist reserviert und zeigt das Menü erneut (für User im Expertenmodus, die nur die Eingabezeile sehen).

| Befehl | Daten | Funktion |
|---|---|---|
| MENU | Menüname | Wechselt in ein anderes Menü |
| SCREEN | Screen-Name | Zeigt einen Screen, danach „Enter weiter“ |
| LEGAL | `impressum` oder `privacy` | Zeigt Impressum oder Datenschutzerklärung |
| MSG_AREA | | Nachrichtenbereich wählen |
| MSG_READ | | Nachrichten im aktuellen Bereich lesen |
| MSG_NEW | | Neue Nachrichten aus allen Bereichen |
| MSG_POST | | Nachricht im aktuellen Bereich schreiben |
| MSG_MAIL | | Private Post lesen |
| MSG_SEND | | Private Nachricht senden |
| FILE_AREA | | Dateibereich wählen |
| FILE_LIST | | Dateien des aktuellen Bereichs |
| FILE_NEW | | Neue Dateien seit dem letzten Anruf |
| FILE_SEARCH | | Dateien nach Name und Beschreibung suchen |
| FILE_DOWNLOAD | | Datei herunterladen |
| FILE_UPLOAD | | Datei hochladen |
| ONELINERS | | Oneliner lesen und schreiben |
| LASTCALLERS | | Letzte 15 Anrufer |
| WHO | | Belegung aller Nodes |
| USERLIST | | Liste aller User |
| USERINFO | | Statistik des Anrufers |
| SETTINGS | | Ort, Passwort, Modemgeschwindigkeit, Expertenmodus |
| PAGE | | Sysop rufen: Ist ein Sysop online, wird er benachrichtigt, sonst kann der Anrufer eine Nachricht hinterlassen |
| COMMENT | | Private Nachricht direkt an den Sysop |
| DOOR | Door-Id | Startet eine Door |
| DOORTOP | | Bestenlisten der Doors (siehe Abschnitt 11) |
| SYSOP | | Sysop-Menü im Terminal, nur für User mit Sysop-Level |
| LOGOFF | | Ausloggen mit Rückfrage |

Beispiel: Ein neues Bulletin „Termine“ anlegen. Unter „Screens“ einen Text-Screen `termine` anlegen und füllen, dann im Menü `bull` einen Punkt mit Taste `3`, Text „Termine“, Befehl `SCREEN`, Daten `termine` ergänzen.

### Sysop-Menü im Terminal

Im Hauptmenü gibt es den Punkt `!` „Sysop-Menü“. Ihn sehen und nutzen nur User, deren Level mindestens dem Wert „Level mit Sysop-Rechten“ entspricht. Die Tasten sind in beiden Sprachen gleich:

| Taste | Funktion |
|---|---|
| N | Neue User prüfen: zuerst alle, die auf Freischaltung warten, danach alle mit dem Level für neue User. Pro User: F freischalten (fragt das Level, Enter nimmt das Level für neue User), H hochstufen (Enter nimmt das nächsthöhere vorhandene Level), S sperren, L löschen (nur wartende User, mit Rückfrage), W weiter, Q Ende |
| U | Wartende Uploads: Name, Bereich, Uploader, Größe und Beschreibung. F freigeben, L löschen (Datei und Eintrag), W weiter, Q Ende |
| E | User bearbeiten: Handle eingeben, dann L Level setzen, S Sperre umschalten, Z Zeit und Downloadzähler für heute zurücksetzen, P neues Passwort (verdeckt, zweimal), Q zurück |
| T | User trennen: zeigt die belegten Nodes und fragt die Nummer ab |
| R | Rundruf an alle anderen Nodes |
| C | Chat mit einer Node |
| Q | Zurück ins Hauptmenü |

Es gelten dieselben Grenzen wie im Backend: Level vergibst du höchstens bis zu deinem eigenen, User mit höherem Level als deinem kannst du nicht bearbeiten, deinen eigenen Account kannst du weder sperren noch im Level ändern, und deine eigene Node kannst du nicht trennen. Jede Aktion steht im Log.

Im Nachrichtenleser hat ein Sysop bei öffentlichen Nachrichten zusätzlich die Taste M. Sie zeigt die Nachrichtenbereiche, fragt eine Nummer ab und verschiebt die Nachricht dorthin. Danach geht es mit der nächsten Nachricht weiter.

### Chat, Rundruf und Sysop rufen

Webspace kann keine dauerhaften Verbindungen halten. Das Terminal fragt deshalb alle 10 Sekunden nach, ob etwas für seine Node da ist, im Chat alle 1,5 Sekunden. Ein Rundruf oder eine Benachrichtigung kommt also mit bis zu 10 Sekunden Verzögerung an. Was der Anrufer gerade tippt, bleibt dabei erhalten.

**Rundruf:** Im Sysop-Menü die Taste R oder in der Übersicht des Backends. Die Zeile erscheint bei allen anderen Nodes gelb als „*** Nachricht vom Sysop: …“ mit einem Klingelton.

**Chat:** Im Sysop-Menü die Taste C und die Nodenummer. Beim Anrufer erscheint „Der Sysop hat sich zugeschaltet. /Q beendet den Chat.“ Jede Zeile, die einer von euch mit Enter abschickt, landet bei der anderen Seite, die Zeilen der Gegenseite erscheinen hellcyan mit Handle davor. `/Q` auf einer der beiden Seiten beendet den Chat, beide kommen ins Hauptmenü. Legt eine Seite auf, bekommt die andere die Meldung, dass der Chat beendet ist. Schreibt der Anrufer gerade eine Nachricht im Editor oder lädt eine Datei hoch, startet der Chat erst, wenn er damit fertig ist.

**Sysop rufen:** Wählt ein Anrufer „Sysop rufen“ und du bist im Terminal eingeloggt, bekommst du „*** Handle auf Node n ruft dich. Sysop-Menü, Taste C zum Chatten.“ mit Klingelton, und der Anrufer erfährt, dass du benachrichtigt wurdest. Ist kein Sysop online, kann er wie bisher eine Nachricht hinterlassen.

## 9. Screens, Pipe-Codes und Makros

Screens liegen in `data/screens/` und werden im Backend verwaltet. Es gibt zwei Arten:

**ANSI-Screens (.ans):** Echte ANSI-Dateien im Zeichensatz CP437, wie sie PabloDraw, Moebius oder TheDraw erzeugen. Ein SAUCE-Block am Dateiende wird automatisch ignoriert. Achte darauf, dass Zeilen nicht breiter als 80 Zeichen sind. Ein Screen sollte höchstens 24 Zeilen hoch sein, damit die Eingabezeile noch darunter passt.

**Text-Screens (.txt):** Normale UTF-8-Textdateien mit Pipe-Codes für Farben. Die kannst du direkt im Backend schreiben.

| Code | Wirkung |
|---|---|
| `\|00` bis `\|15` | Textfarbe (DOS-Farbnummern) |
| `\|16` bis `\|23` | Hintergrundfarbe |
| `\|CL` | Bildschirm löschen |
| `\|CR` | Neue Zeile |
| `\|\|` | Ein echter senkrechter Strich |

Die DOS-Farben: 0 Schwarz, 1 Blau, 2 Grün, 3 Cyan, 4 Rot, 5 Magenta, 6 Braun, 7 Hellgrau, 8 Dunkelgrau, 9 Hellblau, 10 Hellgrün, 11 Hellcyan, 12 Hellrot, 13 Hellmagenta, 14 Gelb, 15 Weiß.

**Makros** funktionieren in beiden Screen-Arten und werden beim Anzeigen ersetzt:

| Makro | Inhalt |
|---|---|
| `@BBSNAME@` | Name der Mailbox |
| `@SYSOP@` | Sysop-Name |
| `@BBSLOC@` | Standort der Mailbox |
| `@USER@` | Handle des Anrufers |
| `@USERLOC@` | Ort des Anrufers |
| `@CALLS@` | Anzahl seiner Anrufe |
| `@LASTCALL@` | Datum seines vorherigen Anrufs |
| `@TIMELEFT@` | Restzeit in Minuten |
| `@LEVEL@`, `@LEVELNAME@` | Level und Levelname |
| `@NODE@`, `@NODES@` | Aktuelle Node, Anzahl Nodes |
| `@DATE@`, `@TIME@` | Datum und Uhrzeit |
| `@USERS@`, `@MSGS@`, `@FILES@`, `@TOTALCALLS@` | Statistik der Box |
| `@VERSION@` | Version von WebCarrier BBS |
| `@DOORTOP@` | Kasten mit dem Spitzenreiter jeder Door, mehrzeilig, leer ohne Einträge (siehe Abschnitt 11) |
| `@SYSOPMSG@` | Die gültige Nachricht des Sysops ohne Kopfzeile, mehrzeilig, sonst leer (siehe Abschnitt 7) |

Für saubere Rahmen in ANSI-Screens kannst du Breite und Ausrichtung festlegen: `@BBSNAME:40C@` füllt den Namen auf genau 40 Zeichen auf und zentriert ihn. `L` ist linksbündig, `R` rechtsbündig. Längere Werte werden auf die Breite gekürzt.

`@DOORTOP@` und `@SYSOPMSG@` liefern mehrere Zeilen. Setz sie an den Anfang einer eigenen Zeile, eine Breite wie `:40` wirkt bei ihnen nicht.

Makros werden nur in Screens und in den Sprachtexten ersetzt. Was Anrufer eintippen (Betreff, Oneliner usw.), erscheint immer wörtlich, `@SYSOP@` in einem Betreff bleibt also `@SYSOP@`.

**Besondere Screen-Namen:**

| Name | Wann |
|---|---|
| `welcome` | Nach dem Verbinden, vor dem Login |
| `newuser` | Zu Beginn der Neuanmeldung |
| `logon` | Direkt nach dem Login |
| `logoff` | Beim Ausloggen |
| `pending` | Für User, die auf Freischaltung warten, nach der Anmeldung und bei jedem Login (siehe Abschnitt 7) |

Fehlt einer davon, zeigt die Box einen einfachen Standardtext. Die Vorschau im Backend benutzt denselben Renderer wie das Terminal, du siehst also genau, was Anrufer sehen.

Die mitgelieferten Vorlagen liegen in `core/defaults/de/` und `core/defaults/en/`. Der Installer kopiert sie nach `data/screens/`, neue Vorlagen späterer Versionen kopiert das Update dorthin, sofern es noch keinen Screen mit dem Namen gibt. Deine eigenen Screens werden nie überschrieben.

## 10. Dateien: Bereiche, Freigabe, Import

Dateien liegen in `data/files/<Bereichsnummer>/`. Die Datenbank kennt Name, Größe, Beschreibung, Uploader und Downloadzähler.

**Uploads von Anrufern** sind nur in Bereichen möglich, bei denen Uploads eingeschaltet sind, und nur ab dem Upload-Level. Erlaubte Dateitypen und Maximalgröße stellst du in den Einstellungen ein. Dateien, die der Webserver ausführen oder als Seite ausliefern könnte (php in allen Varianten, phtml, phar, cgi, pl, asp, jsp, shtml, html, js, svg, .htaccess), werden immer abgelehnt, auch wenn du die Endung erlaubst. Dasselbe gilt für Doppelendungen wie `datei.php.zip`. Enthält ein ZIP eine FILE_ID.DIZ, bietet die Box sie als Beschreibung an.

Standardmäßig sind Uploads erst nach deiner Freigabe sichtbar. Wartende Uploads siehst du in der Übersicht und unter „Dateien“. Prüf sie, bevor du sie freigibst: Du bist als Betreiber für die Inhalte verantwortlich, die du anbietest.

**Dateien verwalten:** Unter „Dateien“ wählst du einen Bereich und siehst seine Dateien. Über die Häkchen links kannst du mehrere Dateien auf einmal freigeben, löschen (mit Rückfrage) oder in einen anderen Bereich verschieben. Beim Verschieben wandert die Datei auf der Platte nach `data/files/<neuer Bereich>/`. Gibt es im Zielbereich schon eine Datei mit dem Namen, wird sie übersprungen und du bekommst eine Meldung.

Über „Bearbeiten“ änderst du Name und Beschreibung einer Datei. Für den Namen gelten dieselben Regeln wie beim Upload: Buchstaben, Ziffern, Punkt, Binde- und Unterstrich, alles andere wird zum Unterstrich. Endungen wie php oder html sind nicht erlaubt, und ein Name, den es im Bereich schon gibt, wird abgelehnt. Die Datei wird auf der Platte mit umbenannt.

Lässt sich eine Datei nicht von der Platte löschen (etwa wegen fehlender Rechte), bleibt sie auch in der Liste und du bekommst eine Meldung. Beim Löschen eines ganzen Dateibereichs bleibt der Bereich dann ebenfalls erhalten.

**Massenimport:** Für ganze Sammlungen, etwa eine Shareware-CD wie Kirk's Comm Disc:

1. Lade die Dateien per FTP nach `data/import/`, gern in Unterordner, zum Beispiel `data/import/RA/` und `data/import/FIDO/`.
2. Liegt in einem Ordner eine `FILES.BBS` oder `DESCRIPT.ION`, werden die Beschreibungen daraus übernommen. Fortsetzungszeilen (eingerückt) werden angehängt. Sonst wird die FILE_ID.DIZ aus ZIP-Dateien gelesen.
3. Im Backend unter „Dateiimport“ ordnest du jedem Ordner einen Dateibereich zu oder überspringst ihn.
4. „Import starten“. Die Dateien werden verschoben (oder kopiert, wenn du den Haken entfernst) und sind sofort freigegeben. Doppelte Namen im selben Bereich werden übersprungen.

Dateien wie `index.html`, `FILES.BBS` und versteckte Dateien werden beim Import ignoriert. Bei sehr großen Sammlungen importiere lieber in mehreren Durchgängen, falls dein Hoster Skripte nach kurzer Zeit abbricht.

## 11. Doors

Doors sind kleine Programme innerhalb der Mailbox, früher meist Spiele. Bei WebCarrier BBS ist eine Door eine PHP-Datei im Ordner `doors/`. Mitgeliefert ist `hilo.php`, ein Zahlenratespiel mit Bestenliste.

Wie Doors funktionieren und wie man eigene schreibt, erklärt ausführlich das [Door-Handbuch](DOOR_GUIDE.md), mit einem vollständigen Beispiel. Hier folgt nur ein kurzer Überblick.

Eine Door-Datei definiert eine Klasse, die das Interface `CarrierDoor` erfüllt, und gibt ihre Registrierung zurück:

```php
<?php
if (!class_exists('MeineDoor')) {
    final class MeineDoor implements CarrierDoor
    {
        public function start(Engine $e): void
        {
            $e->cls();
            $e->bar('Meine Door');
            $e->write('|14Hallo ' . cb_esc($e->user['handle']) . '!|07');   // Pipe-Codes erlaubt, Makros nicht
            $e->nl(2);
            $e->line(20, '|07Wie heißt dein Hund? |15');
        }

        public function input(Engine $e, string $v): void
        {
            $e->write('|10Schöner Name: ' . cb_esc($v) . '|07');
            $e->nl();
            $e->leaveDoor();                  // zurück ins Menü
        }
    }
}
return ['id' => 'hund', 'name' => 'Hundenamen', 'class' => 'MeineDoor',
        'description' => 'Fragt nach dem Hund.', 'version' => '1.0.0'];
```

### Doors im Backend

Lade die Door-Datei per FTP in den Ordner `doors/`. Sie erscheint danach im Backend unter „Doors“ mit Name, Id, Beschreibung, Version und dem Menü, in dem sie steht. „Ins Menü aufnehmen“ legt sie mit der nächsten freien Taste (zuerst 1 bis 9, dann A bis Z ohne Q) ins Menü `doors`. Als Mindestlevel bekommt sie das niedrigste Level der Doors, die dort schon stehen, sonst das Level für neue User. Gibt es das Menü `doors` nicht mehr, wird es angelegt, samt Punkt im Hauptmenü. Text, Taste und Level änderst du danach im Menü-Editor. „Aus dem Menü nehmen“ entfernt alle Menüpunkte der Door, die Datei bleibt liegen.

„Spielstände zurücksetzen“ löscht nach einer Rückfrage alles, was die Door dauerhaft gespeichert hat, und ihre Einträge in der Bestenliste der Box.

### Bestenlisten

Doors können pro Anrufer einen Wert melden, etwa die wenigsten Versuche bei Hi-Lo. Jede Door hat ihre eigene Liste, Doors werden nie miteinander verglichen. Enthält ein Menü mindestens einen Punkt mit dem Befehl DOOR, zeigt die Box zwischen Titelleiste und Menüpunkten einen Kasten mit dem Spitzenreiter jeder Door: Name, Handle und Bestwert, höchstens 8 Doors, die mit dem jüngsten Eintrag zuerst. Unten im Rahmen steht die Taste des Punkts mit dem Befehl DOORTOP, im Menü `doors` ist das `B`. Ohne Einträge erscheint kein Kasten. Gezeigt werden nur installierte Doors, die in einem Menüpunkt stehen, den der Anrufer mit seinem Level erreicht. Für eigene Screens gibt es denselben Kasten als Makro `@DOORTOP@`.

DOORTOP zeigt für jede Door mit Einträgen die ersten drei, nach Namen sortiert und mit 1 bis 9 wählbar, bei mehr als 9 Doors seitenweise mit N und P. Eine Ziffer öffnet die ersten zehn dieser Door mit Datum, die eigene Zeile ist hervorgehoben. Steht der Anrufer weiter hinten, folgt darunter sein Platz. Gesperrte User und User, die auf die Freischaltung warten, stehen in keiner Liste.

Auf der Seite „Doors“ im Backend siehst du pro Door die Zahl der Einträge und die ersten drei. Neue Installationen haben den Punkt „B Bestenliste“ im Menü `doors`, beim Update auf 1.6.0 wird er dort ergänzt (ist B belegt, die nächste freie Taste). Gibt es das Menü `doors` nicht, legst du den Punkt bei Bedarf im Menü-Editor selbst an.

Eine Door-Datei, die sich nicht laden lässt, legt die Mailbox nicht lahm. Sie steht im Backend unter „Fehlerhafte Dateien“ mit dem Grund, etwa einem PHP-Fehler mit Zeilennummer, einer ungültigen Id oder einer Id, die schon eine andere Datei benutzt. Die Übersicht zeigt dazu eine Aufgabe. Bricht eine Door während des Spiels mit einem Fehler ab, landet der Anrufer mit einer kurzen Meldung im Menü und der Fehler steht im Log.

Doors laufen als PHP-Code mit allen Rechten der Mailbox. Installiere nur Doors aus Quellen, denen du vertraust. Doors für WebCarrier BBS sammelt [webcarrier-bbs.de/doors](https://webcarrier-bbs.de/doors).

Als Vorlage für eigene Doors eignet sich `hilo.php`: Die Datei ist kurz, bringt ihre Texte in Deutsch und Englisch selbst mit und nutzt Bestenliste und Spielstand.

Wichtige Methoden der Engine für Doors:

| Methode | Zweck |
|---|---|
| `write($text)`, `nl($n)`, `cls()`, `bar($links, $rechts)` | Ausgabe mit Pipe-Codes, ohne Makros |
| `L($key, ...$args)`, `say($key, ...$args)` | Text aus der Sprachdatei holen bzw. ausgeben, mit Makros und `{1}`, `{2}` … |
| `hot($tasten, $prompt)` | Auf eine Taste warten, `''` = beliebige Taste |
| `line($max, $prompt, $maske)` | Eine Zeile einlesen |
| `yn($prompt, $standardJa)` und `yes($v, $standardJa)` | Ja/Nein-Frage |
| `wait($ms, $prompt)` | Ausgabe kurz stehen lassen (0 bis 5000 ms), dann ohne Taste weiter mit `input()` und `''`. Doors für ältere Versionen prüfen vorher mit `method_exists($e, 'wait')` und machen sonst direkt weiter |
| `&doorState()` | Array, das bis zum Verlassen der Door erhalten bleibt |
| `doorGet($k)`, `doorSet($k, $v)`, `doorAll($k)` | Dauerhafte Daten pro User, etwa Highscores |
| `doorScore($wert, $label)`, `doorScoreClear()` | Wert des Anrufers in der Bestenliste der Box melden bzw. entfernen, ab 1.6.0. Reihenfolge über `'score' => 'high'` oder `'low'` in der Registrierung |
| `leaveDoor()` | Door beenden, zurück ins Menü |
| `$e->user` | Datensatz des Anrufers |

Die Id besteht nur aus Kleinbuchstaben und Ziffern, höchstens 30 Zeichen. Am Ende von `start()` und `input()` muss eine Methode aufgerufen werden, die eine Eingabe erwartet (`hot`, `line`, `yn`, `pause`, `wait`), sonst landet der Anrufer im Menü. Text von Anrufern immer mit `cb_esc()` ausgeben, damit eingegebene Pipe-Codes nicht als Farben wirken.

## 12. Sprache und Texte anpassen

Alle Texte der Mailbox stehen in `lang/de.php` und `lang/en.php`. Fehlt ein Text in einer Sprache, wird der englische verwendet. Die Sprache stellst du in den Einstellungen um. Menütexte liegen in der Datenbank und werden im Menü-Editor geändert.

### Eigene Texte im Backend

Jeden Text, den Anrufer im Terminal sehen, kannst du unter „Texte“ ändern, ohne eine Datei anzufassen. Oben wählst du die Sprache, suchst nach Schlüssel oder Text und zeigst auf Wunsch nur die geänderten Texte. Neben jedem Text steht der Standard aus der Sprachdatei, darunter eine Vorschau in der Terminalschrift. Pipe-Codes für Farben funktionieren wie in Screens, Zeilenumbrüche im Eingabefeld werden zu `|CR`. Platzhalter wie `{1}` füllt die Mailbox mit Namen oder Zahlen.

Gespeichert werden nur Texte, die vom Standard abweichen, und zwar in der Datenbank. Sie überstehen deshalb jedes Update. Ein leeres Feld oder „Auf Standard zurücksetzen“ stellt den Standard wieder her. Fehlt ein Platzhalter des Standards oder wird eine Zeile länger als 79 Zeichen, bekommst du eine Warnung, gespeichert wird trotzdem. Kennt eine neue Version einen Schlüssel nicht mehr, steht dein Text unten unter „Eigene Texte, die nicht mehr verwendet werden“ und kann dort entfernt werden.

Eigene Texte gelten pro Sprache. Änderst du einen deutschen Text, bleibt der englische unverändert.

### Weitere Sprachen

Eine weitere Sprache legst du an, indem du `lang/en.php` kopierst, übersetzt und zum Beispiel als `lang/nl.php` speicherst. Sie erscheint danach automatisch in den Einstellungen zur Auswahl. Die Tasten für Ja und Nein (`key_yes`, `key_no`) gehören mit in die Sprachdatei. Das Backend selbst gibt es auf Deutsch und Englisch.

## 13. Datensicherung und Update

**Backup im Backend:** Unter „Backup“ erzeugt ein Klick ein ZIP und lädt es herunter. Der Dateiname enthält Datum und Uhrzeit, zum Beispiel `webcarrierbbs-backup-2026-10-07-213000.zip`. Im Archiv stecken:

- `core/config.php`, also auch die Zugangsdaten zur Datenbank. Bewahre das Backup deshalb sicher auf.
- `data/screens/` mit allen Screens.
- Die Datenbank: bei SQLite eine konsistente Kopie der Datenbankdatei (auch während Anrufer online sind), bei MySQL ein SQL-Dump aller Tabellen der Box als `database.sql`.
- Auf Wunsch die Dateibereiche (`data/files/`). Die Seite zeigt dir, wie groß sie gerade sind. Große Backups können am Zeitlimit deines Hosters scheitern, dann sicherst du `data/files/` besser per FTP.

Das Archiv ist aufgebaut wie die Installation selbst. **Wiederherstellen:** WebCarrier BBS frisch hochladen und das Archiv in denselben Ordner entpacken, der Installer wird dann nicht gebraucht. Bei MySQL vorher `database.sql` in die Datenbank importieren, zum Beispiel mit phpMyAdmin.

Fehlt auf deinem Webspace die PHP-Erweiterung ZipArchive, kann das Backend kein Backup erstellen und erklärt dir stattdessen die Sicherung per FTP.

**Sichern per FTP:** Du brauchst drei Dinge: `core/config.php`, den kompletten Ordner `data/` (bei SQLite liegt dort auch die Datenbank) und bei MySQL einen Export der Datenbank. Bei SQLite die Sicherung am besten machen, während niemand online ist.

### Update-Prüfung

Die Box kann selbst nachsehen, ob es eine neue Version gibt. Die Prüfung ist ab Werk **ausgeschaltet**, auch nach einem Update von einer älteren Version. Einschalten kannst du sie unter „Einstellungen“ im Abschnitt „Updates“ oder direkt über die Meldung in der Übersicht.

Bei eingeschalteter Prüfung fragt die Box höchstens alle 12 Stunden, wenn du die Übersicht öffnest, die Datei `https://webcarrier-bbs.de/update.json` ab. Gesendet wird nur diese Anfrage mit der Kennung „WebCarrierBBS“, ohne Versionsnummer und ohne Daten deiner Box. Nach spätestens drei Sekunden wird aufgegeben, ist der Server nicht erreichbar, zeigt der Kasten „System“ nur „Prüfung fehlgeschlagen“. Mit „Jetzt prüfen“ stößt du die Prüfung von Hand an. Das verschiebt die nächste automatische Prüfung nicht, sie folgt weiter 12 Stunden nach der letzten automatischen. Eine Meldung zu einer neuen Version bleibt stehen, bis du die Version überspringst oder einspielst, auch wenn eine spätere Prüfung fehlschlägt.

Gibt es eine neuere Version, erscheint in der Übersicht die Meldung „Update: Version x ist verfügbar“ mit Datum und Änderungen. Du kannst sie mit „Diese Version überspringen“ ausblenden. Erscheint später eine noch neuere Version, wird sie wieder angezeigt. Ältere oder gleiche Versionen werden nie angeboten.

### Update per Knopf

Mit „Jetzt aktualisieren“ läuft das Update in einem Rutsch, auch auf normalem Webspace mit 30 Sekunden Zeitlimit:

1. Prüfen, ob alle Dateien beschreibbar sind und genug Platz frei ist.
2. Backup von Datenbank, `core/config.php` und Screens nach `data/backups/vor-update-<version>-<datum>.zip`. Die letzten drei dieser Sicherungen bleiben liegen, ältere werden gelöscht. Klappt das Backup nicht, gibt es kein Update.
3. Wartungsmodus: Anrufer sehen statt des Terminals die Meldung, dass die Box gerade aktualisiert wird, laufende Anrufe werden beendet. Das Backend bleibt bedienbar.
4. Herunterladen des Pakets und Prüfen von Größe, SHA-256-Prüfsumme und digitaler Signatur. Nur Pakete, die mit dem Schlüssel des Projekts signiert sind, werden installiert.
5. Entpacken und prüfen, ob das Paket wirklich die angekündigte Version enthält.
6. Kopieren der neuen Dateien. Jede Datei, die ersetzt wird, wird vorher gesichert. Nie angefasst werden `core/config.php`, der Ordner `data/` und der Ordner `install/`. Eigene Dateien, die es im Paket nicht gibt, etwa eigene Doors, bleiben liegen.
7. Abschluss mit dem neuen Code: Datenbankänderungen anwenden, Wartungsmodus beenden, aufräumen. In der Übersicht erscheint einmal die Meldung, dass das Update abgeschlossen ist.

**Wenn etwas schiefgeht:** Jeder Abbruch stellt den alten Stand wieder her, beendet den Wartungsmodus, schreibt den Grund ins Log und zeigt ihn einmal in der Übersicht. Bricht PHP mitten im Update hart ab (etwa durch einen Absturz des Servers), bleibt der Wartungsmodus zunächst stehen. Spätestens 15 Minuten später räumt die Box beim nächsten Aufruf auf, stellt die alten Dateien wieder her und meldet das in der Übersicht als Sicherheitsmeldung.

**Wann es nur von Hand geht:** Statt des Knopfs zeigt die Meldung einen Hinweis mit Link zu dieser Anleitung, wenn die neue Version eine höhere PHP-Version braucht, das Update ausdrücklich nur von Hand eingespielt werden soll, die PHP-Erweiterungen ZipArchive oder sodium fehlen, die Box aus einem Git-Checkout läuft (Ordner `.git` im Hauptordner) oder PHP nicht alle Dateien überschreiben darf. Der Hinweis nennt jeweils den Grund.

### Update von Hand

Sicherung machen, dann alle Dateien der neuen Version hochladen, außer `core/config.php` und dem Ordner `data/`. Den Ordner `install/` danach wieder löschen.

Bringt eine neue Version Änderungen an der Datenbank mit, werden sie beim ersten Aufruf nach dem Update automatisch angewendet, egal ob über das Terminal oder das Backend. Du musst dafür nichts tun. Welchen Stand die Datenbank hat, steht in der Einstellung `db_version`. Bricht ein Update mittendrin ab, wird der fehlende Schritt beim nächsten Aufruf wiederholt. Gerade deshalb vorher die Sicherung machen.

## 14. Sicherheit und Datenschutz

- Passwörter werden mit `password_hash()` gespeichert. Niemand, auch nicht du, kann sie lesen.
- Nach 5 falschen Passwörtern innerhalb von 15 Minuten ist der Account für den Rest dieser 15 Minuten gesperrt, im Terminal und im Backend. Die Fehlversuche stehen im Log.
- Alle Formulare und Terminal-Anfragen sind gegen Cross-Site-Request-Forgery geschützt, alle Datenbankabfragen laufen über Prepared Statements.
- Downloads laufen über Einmal-Links, die nur für den angemeldeten Anrufer und zehn Minuten gelten. Dateien im Ordner `data/` sind direkt nicht erreichbar.
- Die Box speichert keine IP-Adressen und keine E-Mail-Adressen. Gespeichert werden Handle, Ort, Passwort-Hash, Nutzungsstatistik, Nachrichten, Uploads und das Ereignislog. Es wird nur ein technisch notwendiges Session-Cookie gesetzt. Externe Schriften oder Dienste werden nicht geladen. Die Zugriffslogs des Webservers bei deinem Hoster sind davon unabhängig und gehören mit in die Datenschutzerklärung.
- Private Post ist im Backend nicht einsehbar. Mit Sysop-Level kannst du sie im Terminal aber lesen und löschen. Schreib in die Datenschutzerklärung, wie du damit umgehst.
- Das Log lässt sich im Backend jederzeit leeren. Damit sind auch die Upload- und Download-Zahlen der Statistik weg.

## 15. Fehlersuche

| Problem | Lösung |
|---|---|
| Installer meldet „core/ writable“ oder „data/ writable“ rot | Rechte der Ordner per FTP auf 775 setzen |
| Terminal zeigt „NO CARRIER“ direkt nach CONNECT | PHP-Fehlerlog des Hosters prüfen. Meist fehlende Rechte auf `data/` oder falsche Datenbankdaten |
| Immer „Alle Leitungen sind besetzt“ | Mehr Nodes einstellen. Verwaiste Nodes werden nach der Idle-Zeit plus zwei Minuten automatisch frei |
| Login meldet „Zu viele Fehlversuche“ | 15 Minuten warten. Als Sysop kannst du die Sperre sofort aufheben, indem du im Backend das Log leerst. Dabei gehen auch die Upload- und Download-Zahlen der Statistik verloren |
| Upload schlägt fehl | Dateityp erlaubt? PHP-Limit in den Einstellungen ansehen und ggf. beim Hoster erhöhen |
| Umlaute in ANSI-Screens kaputt | Screen muss in CP437 gespeichert sein, nicht in UTF-8. Für UTF-8-Text einen .txt-Screen nehmen |
| Kein Ton beim Wählen | Einstellung „Modem- und Klingeltöne“ prüfen. Browser spielen Töne erst nach dem ersten Tastendruck ab |
| ANSI-Screens nach dem Hochladen verschoben, Zeilen beginnen nicht links | Die Datei hat ihre CRLF-Zeilenenden verloren (z. B. durch Git oder FTP im ASCII-Modus). `.ans`-Dateien immer binär übertragen |
| Seite lädt, aber Bildschirm bleibt schwarz | JavaScript aktiv? Werbeblocker testweise ausschalten |

## 16. Lizenz

WebCarrier BBS ist freie Software unter der GNU Affero General Public License, Version 3 oder neuer (AGPL-3.0-or-later). Copyright (C) 2026 Christoph Scheel, [chrisscheel.de](https://chrisscheel.de). Der vollständige Lizenztext steht in `LICENSE`.

Was das für dich als Betreiber bedeutet:

- Du darfst die Box kostenlos betreiben, verändern und weitergeben.
- Unter dem Terminal und im Backend steht ein Link zum Quellcode. Der Link „Quellcode (AGPL)“ bleibt auch sichtbar, wenn du die Impressums-Links ausschaltest. Lass ihn drin.
- Betreibst du eine **veränderte** Version öffentlich, musst du deinen Anrufern den geänderten Quellcode zugänglich machen. Stell ihn dafür in ein eigenes Repository und trag dessen Adresse in `core/bootstrap.php` bei `CB_SOURCE_URL` ein. Eigene Screens, Menüs und Texte in der Datenbank zählen nicht dazu, eigene Doors im Ordner `doors/` schon.
- Copyright-Hinweise in den Dateien und die Dateien `LICENSE` und `NOTICE` müssen erhalten bleiben.

Die mitgelieferte VGA-Schrift fällt nicht unter die AGPL, Details stehen in `NOTICE`.
