# WebCarrier BBS: Sysop-Handbuch

Version 1.8.0

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

Ein User bekommt die Grenzen der höchsten Levelzeile, die nicht über seinem eigenen Level liegt. Ein User mit Level 30 bekommt also die Werte von Level 20. Neue User bekommen das Level aus der Einstellung „Level für neue User“. Hochstufen (früher nach dem Validierungsanruf) machst du in der Userverwaltung, oder die Box macht es nach deinen Regeln selbst (siehe „Automatisch hochstufen“ in Abschnitt 7).

**Zeitlimit:** Die Minuten pro Tag zählen über alle Anrufe eines Tages. Ist die Zeit um, wird aufgelegt. 0 bedeutet unbegrenzt. In der Userverwaltung kannst du Zeit und Downloadzähler für heute zurücksetzen.

**Ratio:** Ein Wert von 3 bedeutet drei Downloads pro Upload. Wer mehr laden will, muss erst etwas hochladen. 0 schaltet die Ratio ab. Der Sysop ist von Ratio und Downloadlimit ausgenommen.

Zugriffsrechte für Bereiche funktionieren ebenfalls über Level: Jeder Nachrichtenbereich hat ein Lese- und ein Schreiblevel, jeder Dateibereich ein Zugriffs- und ein Upload-Level. Menüs und einzelne Menüpunkte haben ein Mindestlevel.

## 7. Das Sysop-Backend

Erreichbar unter `/sysop/`. Einloggen darf jeder Account, dessen Level mindestens dem Wert „Level mit Sysop-Rechten“ entspricht (Standard 255). Hat der Account einen zweiten Faktor, folgt nach dem Passwort die Frage nach dem Code aus der App (siehe „Zweiter Faktor“ weiter unten). Das Backend funktioniert auch auf dem Handy.

Beim Speichern der Einstellungen prüft das Backend zwei Werte: Der Sysop-Level muss zwischen 1 und deinem eigenen Level liegen (sonst wäre jeder Sysop oder du würdest dich selbst aussperren), und der Level für neue User muss darunter liegen. Ungültige Werte werden korrigiert und du bekommst einen Hinweis.

| Bereich | Wofür |
|---|---|
| Übersicht | Meldungen, belegte Nodes mit Trennen und Rundruf, Kennzahlen, letzte Ereignisse, Systeminfos (siehe unten) |
| Statistik | Zahlenband mit den wichtigsten Werten, Diagramme zu Anrufen, Uhrzeiten, neuen Usern, Uploads und Downloads, Bestenlisten (siehe unten) |
| Einstellungen | Name, Sprache, Zeitzone, Nodes, Neuanmeldung, Terminal mit Röhren-Effekt und Hinweis im Browser-Tab, Adresse von WAnsiDraw, Uploads, Update-Prüfung, Kennung der Box |
| Impressum und Datenschutz | Rechtstexte als reiner Text |
| User | Anlegen, suchen, bearbeiten, Level ändern, sperren, Passwort setzen, löschen, neue User freischalten |
| Level | Zeit, Downloadlimit und Ratio je Level, Regeln zum automatischen Hochstufen (siehe unten) |
| Sperrliste | Gesperrte Handles und Wörter (siehe unten) |
| Fragebogen | Fragen an neue User bei der Anmeldung (siehe unten) |
| Nachrichtenbereiche | Anlegen, umbenennen, Rechte, Reihenfolge, löschen |
| Nachrichten | Öffentliche Nachrichten lesen, löschen und in andere Bereiche verschieben. Private Post wird hier bewusst nicht angezeigt |
| Oneliner | Oneliner und Zeilen des Treffpunkts moderieren |
| Umfragen | Umfragen anlegen, Ergebnis ansehen, beenden, löschen (siehe unten) |
| Dateibereiche | Anlegen, Rechte, Uploads erlauben |
| Dateien | Uploads freigeben, umbenennen, Beschreibungen ändern, verschieben, löschen, Dateien per Browser hochladen |
| Dateiimport | Viele Dateien auf einmal aus `data/import` übernehmen |
| Menüs | Menüstruktur und Hotkeys bearbeiten |
| Screens | ANSI- und Text-Screens hochladen, bearbeiten, mit Vorschau, in WAnsiDraw öffnen |
| Texte | Eigene Fassungen der Terminaltexte, siehe Abschnitt 12 |
| Doors | Installierte Doors, ins Menü aufnehmen, Spielstände zurücksetzen, fehlerhafte Dateien, siehe Abschnitt 11 |
| Log | Logins, Uploads, Downloads, Änderungen |
| Backup | Sicherung als ZIP herunterladen, siehe Abschnitt 13 |
| Mein Zugang | Zweiten Faktor für dein eigenes Konto einrichten und abschalten, Notfallcodes (siehe unten) |

### Übersicht

Ganz oben stehen die **Meldungen**, sortiert nach Wichtigkeit. Vor jedem Titel steht die Art:

| Art | Beispiele |
|---|---|
| Sicherheit | Der Ordner `install` liegt noch auf dem Server, ein abgebrochenes Update wurde aufgeräumt, der zweite Faktor deines Kontos wurde über die Datei `data/2fa_reset.txt` abgeschaltet |
| Aufgabe | Impressum oder Datenschutzerklärung leer, Uploads warten auf deine Freigabe, neue User warten auf Freischaltung, Door-Dateien konnten nicht geladen werden |
| Update | Neue Version verfügbar, Ergebnis des letzten Updates (einmalig) |
| Hinweis | Noch kein Backup oder letztes Backup älter als 30 Tage, Update-Prüfung ausgeschaltet, dein Konto hat keinen zweiten Faktor |
| Neuigkeit | Meldungen des Projekts, nur bei eingeschalteter Update-Prüfung |

Hinweise und Neuigkeiten kannst du mit „Ausblenden“ wegklicken. Der Hinweis auf den fehlenden zweiten Faktor gilt nur für dich und kommt nach 30 Tagen wieder. Sicherheitsmeldungen, Aufgaben und Updates lassen sich nicht ausblenden, sie verschwinden, sobald die Ursache behoben ist. Gibt es nichts zu melden, steht dort, dass alles in Ordnung ist.

Unter den Nodes steht der Kasten **„Nachricht des Sysops“**. Was du dort einträgst, sieht jeder Anrufer nach dem Login unter der Zeile mit Restzeit und Post, mit „Nachricht von“ und deinem Namen darüber. Erlaubt sind bis zu 3 Zeilen mit je 76 Zeichen, Pipe-Codes für Farben wie `|14` zählen dabei nicht mit. `|CL`, `|CR` und andere Steuercodes werden entfernt. Ist eine Zeile zu lang oder sind es mehr als 3 Zeilen, wird nichts gespeichert und du bekommst einen Hinweis, dein Text bleibt im Feld stehen. Mit „Anzeigen bis“ legst du optional einen Tag fest, bis einschließlich dem die Nachricht erscheint. Danach sehen Anrufer sie nicht mehr, im Backend bleibt sie mit dem Hinweis „abgelaufen“ stehen, bis du sie änderst oder mit „Nachricht entfernen“ löschst. Jede Änderung steht im Log. Ohne Nachricht sieht die Begrüßung aus wie immer.

Darunter folgen die belegten Nodes. Neben jeder Node steht der Knopf „Trennen“, deine eigene Sitzung im Terminal ist davon ausgenommen. Unter der Liste schickst du mit „Rundruf an alle Nodes“ eine Zeile an alle, die gerade online sind. Getrennte Anrufer sehen „Der Sysop hat die Verbindung getrennt.“, ein Rundruf erscheint beim Anrufer gelb mit einem Klingelton. Dann folgen die Kennzahlen (User, Anrufe heute, gerade online, öffentliche Nachrichten, Dateien, wartende Uploads), die letzten Ereignisse aus dem Log und der Kasten „System“ mit Version, PHP-Version, Datenbank, letztem Backup und dem Stand der Update-Prüfung. Unter den Kennzahlen führt ein Link zur Statistik. Auf dem Handy steht alles untereinander, auf breiten Bildschirmen stehen Kennzahlen und System in einer schmalen Spalte rechts.

### Statistik

Oben auf der Seite „Statistik“ steht ein Zahlenband mit den wichtigsten Werten: Anrufe heute, Anrufe der letzten 7 und der letzten 30 Tage, aktive User der letzten 30 Tage (verschiedene Anrufer), neue User, neue öffentliche Nachrichten sowie Uploads und Downloads der letzten 30 Tage. Unter jeder Zahl steht der Vergleich mit dem Zeitraum davor, etwa „+3 zu den 7 Tagen davor“, bei Gleichstand „wie den 7 Tagen davor“. Anrufe heute werden mit gestern verglichen. Bei den neuen Usern steht zusätzlich, wie viele davon noch auf ihre Freischaltung warten. Sysops und User, die auf ihre Freischaltung warten, zählen bei den Anrufen und den aktiven Usern nicht mit.

Darunter folgen Säulendiagramme, alle gleich hoch: Anrufe pro Tag (über die Links umschaltbar auf 7, 30 oder 90 Tage, Standard 30), Anrufe nach Uhrzeit über denselben Zeitraum, damit du siehst, wann deine Anrufer kommen, neue User pro Monat und Uploads und Downloads pro Monat der letzten 12 Monate (zwei Säulen nebeneinander, Downloads schraffiert). Rechts über jedem Diagramm stehen Höchstwert und Summe, jede Säule zeigt beim Darüberfahren Datum und Wert. Tage ohne Wert haben keine Säule, die Grundlinie läuft trotzdem durch. Ohne Werte bleibt das Diagramm gleich groß und zeigt einen Hinweis. Für Screenreader steht unter jedem Diagramm eine versteckte Tabelle mit allen Werten.

Ganz unten folgen drei Bestenlisten mit je zehn Einträgen: die meistgeladenen Dateien, die fleißigsten Schreiber und die häufigsten Anrufer. Sysops und User, die auf ihre Freischaltung warten, stehen nicht in den Bestenlisten. Tage, Stunden und Monate richten sich nach der Zeitzone aus den Einstellungen. Gezählt wird in der Datenbank, die Seite bleibt deshalb auch bei vielen tausend Anrufen schnell.

Uploads und Downloads zählt die Statistik aus dem Log. Leerst du das Log, sind auch diese Zahlen weg, deshalb fragt die Seite „Log“ vorher noch einmal nach. Anrufe und neue User kommen aus eigenen Tabellen und bleiben erhalten.

### Userverwaltung

Unter „User“ legst du neue Accounts direkt an: Handle, Ort, Passwort (zweimal, mindestens 6 Zeichen) und Level. Für den Handle gelten dieselben Regeln wie bei der Neuanmeldung im Terminal: 3 bis 20 Zeichen, eindeutig, und die reservierten Namen NEW, Sysop und All sind gesperrt, bei deutscher Sprache der Box zusätzlich NEU und Alle. Das gilt auch, wenn du einen User umbenennst.

Level vergeben kannst du höchstens bis zu deinem eigenen. User mit einem höheren Level als deinem lassen sich nicht bearbeiten. Das betrifft vor allem Co-Sysops, wenn du den Sysop-Level niedriger als 255 eingestellt hast. Dein eigenes Level kannst du nicht ändern.

**Über mich und Signatur:** Anrufer schreiben beides selbst unter Einstellungen im Terminal. Auf der Seite eines Users siehst du die Texte und kannst sie leeren, bearbeiten kannst du sie nicht. Das Leeren steht im Log.

**Passwort vergessen:** Anrufer können ihr Passwort nicht selbst zurücksetzen, weil die Box keine E-Mail-Adressen speichert. Das machst du in der Userverwaltung unter „Neues Passwort“.

Hat ein User bei der Anmeldung den Fragebogen beantwortet, stehen seine Antworten auf seiner Seite im Kasten „Fragebogen“. Hat ein anderes Konto einen zweiten Faktor, kannst du ihn dort abschalten, zum Beispiel wenn ein Co-Sysop sein Handy verloren hat.

### Automatisch hochstufen

Auf der Seite „Level“ steht unter der Leveltabelle der Abschnitt „Automatisch hochstufen“. Ab Werk ist er aus, dann ändert die Box kein Level von selbst. Schaltest du ihn ein, prüft die Box bei jedem Login deine Regeln, nachdem der Anruf gezählt wurde. Im Hintergrund passiert nichts, wer nicht anruft, wird auch nicht hochgestuft.

Du kannst bis zu 5 Regeln anlegen. Jede Regel hat ein Ausgangslevel („Ab Level“), ein neues Level und bis zu vier Bedingungen:

| Bedingung | Was gezählt wird |
|---|---|
| Anrufe | Alle Anrufe des Users, der aktuelle zählt schon mit |
| Tage seit der Anmeldung | Volle Tage seit dem Anlegen des Accounts |
| Beiträge | Öffentliche Nachrichten des Users, die es noch gibt. Private Post zählt nicht |
| Uploads | Freigegebene Dateien des Users. Wartende Uploads zählen nicht |

Wer mindestens das Ausgangslevel und weniger als das neue Level hat und alle Bedingungen erfüllt, bekommt das neue Level. Eine 0 heißt, dass diese Bedingung nicht geprüft wird, eine Regel ganz ohne Bedingung lässt sich nicht speichern. Das neue Level muss über dem Ausgangslevel und unter dem Level mit Sysop-Rechten liegen. Treffen mehrere Regeln zu, gilt das höchste neue Level. Das Ergebnis wird im selben Login noch einmal gegen die Regeln geprüft, ein User kann also in einem Anruf zwei Stufen nehmen, wenn er die Bedingungen beider Regeln erfüllt.

Es wird nur hochgestuft, nie herab. Gesperrte User, User, die auf ihre Freischaltung warten, und Sysops bleiben immer außen vor. Einzelne User nimmst du mit dem Haken **„Nicht automatisch hochstufen“** auf ihrer Userseite aus (in der Datenbank die Spalte `no_auto`). Änderst du ein Level von Hand, wird der Haken nicht von selbst gesetzt. Die Userseite zeigt dir aber einen Hinweis, wenn eine Regel den User beim nächsten Anruf weiter hochstufen würde.

Der Anrufer sieht nach dem Hochstufen in der Begrüßung „Glückwunsch, du bist jetzt Stammgast (Level 50).“ mit einem Klingelton. Zeit, Downloadlimit und Rechte des neuen Levels gelten sofort. Im Log steht zum Beispiel `Auto level 10 to 20 (rule 2)`, die Nummer ist die der Regel in der Tabelle.

Unter den Regeln steht eine **Vorschau**: wie viele und welche User beim nächsten Anruf hochgestuft würden. So siehst du vor dem Einschalten, was eine Regel bewirkt.

### Freischaltung neuer User

In den Einstellungen gibt es den Abschnitt „Neuanmeldung“ mit drei Schaltern: ob sich neue User überhaupt anmelden dürfen, welches Level sie bekommen und ob sie **freigeschaltet werden müssen**. Ab Werk ist die Freischaltung aus, dann verhält sich die Box wie gewohnt.

Ist sie an, läuft die Neuanmeldung ganz normal, am Ende sieht der Anrufer aber den Screen `pending` („Danke für deine Anmeldung, der Sysop prüft deinen Zugang“), und nach einem Tastendruck wird aufgelegt. Die Zeit zählt dabei nicht gegen sein Tageslimit. Bis zur Freischaltung landet er auch bei jedem weiteren Login nach dem Passwort wieder auf diesem Screen. Wartende User erscheinen nicht in der Userliste, nicht bei „Letzte Anrufer“ und können keine private Post bekommen.

Bist du gerade im Terminal eingeloggt, bekommst du bei jeder neuen Anmeldung sofort eine Benachrichtigung. In der Übersicht steht die Aufgabe „x neue User warten auf Freischaltung“ mit Link auf die gefilterte Userliste. Dort schaltest du einzeln frei (mit Auswahl des Levels, vorbelegt mit dem Level für neue User) oder löschst den Account, über die Häkchen auch für mehrere auf einmal. Im Terminal geht das im Sysop-Menü mit der Taste N.

Schaltest du die Freischaltung wieder aus, bleiben bereits wartende User wartend, bis du sie freischaltest oder löschst. Die Einstellungsseite weist dann darauf hin.

Handles, die auf der Sperrliste stehen, kann sich bei der Neuanmeldung niemand geben (siehe „Sperrliste“).

### Fragebogen bei der Anmeldung

Unter „Fragebogen“ stellst du neuen Usern bis zu 10 Fragen. Der Hauptschalter „Fragebogen bei der Anmeldung stellen“ ist ab Werk aus, und ohne aktive Frage bleibt er wirkungslos. Die Fragen kommen im Terminal nach Ort und Passwort unter der Überschrift „Ein paar Fragen des Sysops“, bevor das Konto angelegt wird. Wer schon einen Account hat, wird nicht nachträglich gefragt.

Jede Frage hat einen Text mit höchstens 70 Zeichen und eine Art:

| Art | Eingabe im Terminal |
|---|---|
| Freitext | Eine Zeile, Höchstlänge 1 bis 60 Zeichen (ab Werk 40). Läuft durch die Sperrliste für Wörter |
| Auswahl | 2 bis 8 Antworten mit je höchstens 30 Zeichen, der Anrufer wählt per Nummer |
| Ja oder Nein | J oder N, in englischen Boxen Y oder N |

Pflichtfragen dürfen nicht leer bleiben, freiwillige überspringt der Anrufer mit Enter („Enter = keine Angabe“). Die Reihenfolge änderst du mit „Hoch“ und „Runter“, inaktive Fragen werden nicht gestellt.

Gespeichert wird erst zusammen mit dem Konto. Bricht jemand mitten im Fragebogen ab, bleibt nichts übrig. Die Antworten siehst du auf der Userseite im Kasten „Fragebogen“ und in der Liste der Freischaltungen direkt unter dem wartenden User, damit du danach entscheiden kannst. Zu jeder Antwort ist der Wortlaut der Frage mitgespeichert. Änderst oder löschst du eine Frage später, bleiben alte Antworten deshalb lesbar. Einzelne Antworten löschst du auf der Userseite, das steht im Log. Mit dem User werden auch seine Antworten gelöscht.

**Datensparsamkeit:** Frage nur, was du für die Freischaltung wirklich brauchst. Die Antworten sieht nur die Sysop-Ebene. Fragen nach E-Mail-Adresse, Telefonnummer oder Geburtsdatum sind bewusst nicht vorbelegt. Stellst du solche Fragen trotzdem, gehören sie in deine Datenschutzerklärung.

### Sperrliste

Unter „Sperrliste“ trägst du zwei Listen ein, jeweils ein Eintrag pro Zeile. Groß- und Kleinschreibung spielt keine Rolle, doppelte Einträge werden beim Speichern entfernt.

**Gesperrte Handles:** Ein Eintrag gilt für den ganzen Handle, `*` steht für beliebige Zeichen. `admin*` sperrt also Admin, Administrator und admin2, `*sysop*` jeden Handle, in dem „sysop“ vorkommt. Bei der Neuanmeldung bekommt der Anrufer dieselbe Meldung wie bei einem vergebenen Handle und erfährt nicht, dass der Name gesperrt ist. Legst du im Backend einen User mit gesperrtem Handle an oder benennst ihn so um, wird er trotzdem gespeichert, du bekommst nur eine Warnung.

**Gesperrte Wörter:** Ein Eintrag gilt nur für ganze Wörter. `arsch` trifft „Arsch“, aber nicht „Barschfilet“. Mit `*` am Ende trifft ein Eintrag auch Wörter, die so beginnen: `spam*` trifft „Spammer“. Geprüft werden Oneliner, Betreffs, Nachrichten, private Post, der Wohnort bei Anmeldung und in den Einstellungen sowie Upload-Beschreibungen. Der Anrufer sieht, welches Wort nicht erlaubt ist, und kann neu eingeben. Bei einer Nachricht landet er mit seinem Text wieder im Editor, `/L` zeigt den Text. Ein Upload mit gesperrtem Wort in der Beschreibung wird angenommen, wartet aber immer auf deine Freigabe, auch wenn Uploads sonst automatisch freigegeben werden. Das Log nennt das Wort.

Für Sysops gilt die Sperrliste nicht. Vorhandene User, deren Handle auf die Liste passt, zeigt die Seite unter der Liste an. Sie werden nicht automatisch gesperrt, das entscheidest du selbst.

### Zweiter Faktor

Mit einem zweiten Faktor reicht ein gestohlenes Passwort nicht mehr, um in dein Backend zu kommen. Die Box benutzt das übliche Verfahren TOTP (RFC 6238, SHA1, 6 Ziffern, 30 Sekunden). Es funktioniert mit jeder gängigen Authenticator-App. Alles läuft auf der Box selbst, der QR-Code wird in deinem Browser gezeichnet und kein Dienst im Netz gefragt.

**Einrichten:** Unter „Mein Zugang“ richtet jeder Sysop den zweiten Faktor für sein eigenes Konto ein.

1. „Zweiten Faktor einrichten“ erzeugt ein Geheimnis und zeigt es als QR-Code und als Text in Vierergruppen. Der Link darunter öffnet eine Authenticator-App auf demselben Gerät.
2. Scanne den QR-Code mit der App oder tippe das Geheimnis ab. In der App erscheint die Box mit ihrem Namen als Aussteller und deinem Handle als Konto.
3. Tippe den Code ein, den die App anzeigt. Erst nach einem richtigen Code ist der zweite Faktor aktiv. Bis dahin ändert sich an deinem Login nichts.

**Notfallcodes:** Nach dem Aktivieren zeigt die Seite einmalig 10 Notfallcodes mit je 8 Zeichen. Jeder gilt einmal anstelle eines Codes aus der App. Schreib sie auf oder druck sie aus, sie werden nur in diesem Moment angezeigt und nur als Hash gespeichert. „Neue Notfallcodes erzeugen“ (mit deinem Passwort) macht die alten ungültig.

**Login im Backend:** Nach dem richtigen Passwort folgt ein zweites Formular „Code aus der App“. Angenommen werden der aktuelle Code, der vorige und der nächste, damit eine leicht falsch gehende Uhr nicht stört, und jeder Code nur einmal. Ein unbenutzter Notfallcode geht auch. Zwischen Passwort und Code hast du 5 Minuten, danach beginnt der Login von vorn. Falsche Codes zählen wie falsche Passwörter zur Sperre nach 5 Fehlversuchen in 15 Minuten.

**Login im Terminal:** Hat ein Konto den zweiten Faktor an, fragt die Box nach dem Passwort „Code aus der App:“. Nach drei falschen Codes wird aufgelegt. Das gilt für jedes Konto mit zweitem Faktor, praktisch also für die Sysops, denn nur sie können ihn im Backend einrichten.

**Abschalten:** Auf „Mein Zugang“ mit Passwort und einem gültigen Code. Den zweiten Faktor eines anderen Kontos schaltet ein Sysop auf dessen Userseite ab, den eigenen auf diesem Weg nicht.

**Notzugang, wenn das Handy weg ist:** Hast du auch keine Notfallcodes mehr, lege per FTP die Datei `data/2fa_reset.txt` an. Ihr Inhalt ist dein Handle, sonst nichts. Beim nächsten Login mit diesem Handle und dem richtigen Passwort im Backend schaltet die Box den zweiten Faktor ab, löscht die Datei und schreibt einen Logeintrag. Die Übersicht zeigt danach eine rote Meldung, bis du ihn neu eingerichtet hast. Ohne das richtige Passwort passiert nichts, und im Terminal wirkt die Datei nicht. Wer FTP-Zugang zu deinem Webspace hat, kann den zweiten Faktor also aushebeln, er hätte dort aber ohnehin Zugriff auf alles.

Im Log stehen Einrichten, Abschalten, benutzte Notfallcodes, der Notzugang über die Datei und falsche Codes. Das Geheimnis und die Codes selbst stehen nie im Log.

### Umfragen

Unter „Umfragen“ legst du neue Umfragen an: eine Frage mit höchstens 70 Zeichen, 2 bis 8 Antworten mit je höchstens 40 Zeichen und optional einen letzten Tag, bis einschließlich dem abgestimmt werden kann. Anrufer stimmen im Terminal mit dem Menübefehl POLLS ab (im Hauptmenü Taste V), einmal pro Umfrage und ohne die Stimme später ändern zu können. Das Ergebnis sehen sie nach ihrer eigenen Stimme oder wenn die Umfrage beendet ist. Laufende Umfragen, bei denen ein Anrufer noch nicht abgestimmt hat, stehen nach dem Login im Kasten „Neu seit deinem letzten Anruf“, bei jedem Anruf, bis er abgestimmt hat. Ist der Kasten abgeschaltet oder ruft jemand zum ersten Mal an, weist ihn wie bisher eine eigene Zeile der Begrüßung auf offene Umfragen hin.

Ein Klick auf die Frage zeigt das Ergebnis. Solange niemand abgestimmt hat, kannst du die Umfrage dort noch ändern. Danach lässt sie sich nur noch vorzeitig beenden oder mit allen Stimmen löschen. Jede Aktion steht im Log. Sysops stimmen ab wie alle anderen.

### Nachrichten

Die Liste unter „Nachrichten“ zeigt die letzten 200 öffentlichen Nachrichten, auf Wunsch nur eines Bereichs. Über die Häkchen links (das Häkchen im Tabellenkopf wählt alle) löschst du mehrere Nachrichten auf einmal oder verschiebst sie in einen anderen Bereich, etwa wenn jemand im falschen Bereich geschrieben hat. In der Einzelansicht einer Nachricht geht beides auch direkt. Private Post lässt sich hier weder sehen noch verschieben.

### Neues seit dem letzten Anruf

Nach dem Login, unter „Willkommen“ und der Restzeit, zeigt die Box einen Kasten „Neu seit deinem letzten Anruf am 09.10.26 um 21:14“. Er zählt, was seit dem letzten Anruf dazugekommen ist, und zeigt nur Zeilen mit einer Zahl über 0:

- neue öffentliche Nachrichten in Bereichen, die der Anrufer lesen darf, ohne seine eigenen
- neue freigegebene Dateien in Bereichen, die er sehen darf
- neue Mitglieder, ohne wartende und gesperrte
- neue Oneliner
- laufende Umfragen, an denen er noch nicht teilgenommen hat. Diese Zeile zählt alle offenen Umfragen ohne seine Stimme, auch ältere, und bleibt stehen, bis er abgestimmt hat oder die Umfrage endet
- neue Bestwerte anderer Anrufer in den Doors, die er erreichen kann

Ist nichts dazugekommen, steht dort ein einzelner Satz. Unter der Begrüßung führen N zu den neuen Nachrichten (wie der Befehl MSG_NEW) und F zu den neuen Dateien (wie FILE_NEW), Enter geht weiter. Die Tasten erscheinen nur, wenn es Neues gibt, und danach landet der Anrufer im Hauptmenü. Die Zeile mit ungelesener privater Post steht wie bisher darüber. Beim allerersten Anruf entfällt der Kasten.

In einem eigenen `logon`-Screen setzt du den Kasten mit dem Makro `@NEWSINCE@` selbst an die gewünschte Stelle (siehe Abschnitt 9). Die Begrüßung zeigt ihn dann nicht noch einmal. Abschalten lässt er sich in den Einstellungen mit „Neues seit dem letzten Anruf zeigen“, ab Werk ist er an.

### Hinweis im Browser-Tab

Viele Anrufer lassen die Box in einem Tab offen und arbeiten nebenher weiter. Ist der Tab mit dem Terminal nicht sichtbar und passiert etwas für den Anrufer, wechselt der Titel des Tabs jede Sekunde zwischen einem Hinweis wie „(!) Neue Nachricht“ und dem Namen der Box, und das kleine Symbol im Tab bekommt einen roten Punkt. Sobald der Tab wieder sichtbar ist, ist beides zurück.

| Hinweis | Auslöser |
|---|---|
| Chat mit dem Sysop | Du startest einen Chat, oder im Chat kommt eine Zeile |
| Neue Nachricht | Rundruf, Nachricht an die Node, private Post während des Anrufs, eine Zeile im Treffpunkt mit dem eigenen Handle, beim Sysop auch ein Anrufer, der ihn ruft |
| Du bist am Zug | Eine Door hat gewartet und fragt jetzt nach einer Taste |
| Gleich wird getrennt | Die letzten zwei Minuten der Tageszeit, oder eine Minute vor dem Auflegen wegen Untätigkeit |

Neue private Post während des Anrufs und die beiden Warnungen vor dem Trennen erscheinen dabei auch als Zeile im Terminal, mit Klingelton. Es gibt keine Benachrichtigungen des Betriebssystems, der Browser fragt nach keiner Berechtigung. Abschalten lässt sich der Hinweis in den Einstellungen mit „Hinweis im Browser-Tab“, ab Werk ist er an. Das Backend hat keine Seiten, die von selbst nachfragen, dort gibt es den Hinweis deshalb nicht.

### Röhren-Effekt

Unter Einstellungen, Abschnitt „Terminal“, gibt es den „Röhren-Effekt“ mit drei Stufen. Ab Werk ist er aus. Die Vorschau daneben zeigt die gewählte Stufe sofort.

| Stufe | Wirkung |
|---|---|
| Aus | Das Bild wie bisher |
| Leicht | Scanlines im Abstand der Pixelzeilen und eine leichte Abdunklung zu den Rändern. Keine Bewegung |
| Stark | Zusätzlich ein leichtes Glühen um helle Zeichen, kurzes Nachleuchten des vorigen Bildes (etwa 150 ms) und ein sehr schwaches Flimmern |

Der Text bleibt in jeder Stufe scharf und an seiner Stelle, es gibt keine Wölbung und keine Verzerrung. Hat ein Anrufer in seinem System „weniger Bewegung“ eingestellt, entfallen Nachleuchten und Flimmern. Auf sehr langsamen Geräten fällt „Stark“ von selbst auf „Leicht“ zurück. Der Effekt besteht nur aus CSS und Canvas, es wird nichts nachgeladen.

Anrufer können den Effekt für sich abschalten: Unter Einstellungen im Terminal erscheint dann die Taste R „Röhren-Effekt“. Vor dem Login gilt deine Einstellung.

### Kennung der Box

Unter Einstellungen steht im Abschnitt „Allgemein“ die „Kennung dieser Box“, 32 Zeichen, nur zum Lesen. Sie entsteht einmal bei der Installation oder beim Update auf 1.8.0 und ändert sich danach nie. Jede Nachricht, öffentlich wie privat, bekommt beim Speichern eine weltweit eindeutige Kennung, die mit der Kennung der Box beginnt, und merkt sich, auf welche Nachricht sie antwortet. Das ist die Grundlage für ein späteres Netz zwischen Boxen, damit dann keine alten Nachrichten umgebaut werden müssen. Ein Netz gibt es in dieser Version noch nicht, für Anrufer ändert sich nichts, und die Kennungen erscheinen weder im Terminal noch im Log.

Die Kennung steckt in der Datenbank und zieht mit der Sicherung um. Stellst du eine Sicherung auf einem anderen Webspace wieder her, bleibt die Box dieselbe. Kopierst du eine Box, um daraus eine zweite zu machen, haben beide dieselbe Kennung. Für ein späteres Netz bräuchte die Kopie dann eine neue.

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
| MSG_SEARCH | | Öffentliche Nachrichten durchsuchen (Betreff, Text, Absender) |
| FILE_AREA | | Dateibereich wählen |
| FILE_LIST | | Dateien des aktuellen Bereichs |
| FILE_NEW | | Neue Dateien seit dem letzten Anruf |
| FILE_SEARCH | | Dateien nach Name und Beschreibung suchen |
| FILE_DOWNLOAD | | Datei herunterladen |
| FILE_UPLOAD | | Datei hochladen |
| ONELINERS | | Oneliner lesen und schreiben |
| LASTCALLERS | | Letzte 15 Anrufer |
| WHO | | Belegung aller Nodes, mit N eine Nachricht an eine Node |
| USERLIST | | Liste aller User, mit P das Profil eines Users |
| USERINFO | | Statistik des Anrufers |
| SETTINGS | | Ort, Passwort, Modemgeschwindigkeit, Expertenmodus |
| PAGE | | Sysop rufen: Ist ein Sysop online, wird er benachrichtigt, sonst kann der Anrufer eine Nachricht hinterlassen |
| COMMENT | | Private Nachricht direkt an den Sysop |
| DOOR | Door-Id | Startet eine Door |
| DOORTOP | | Bestenlisten der Doors (siehe Abschnitt 11) |
| TELECONF | | Treffpunkt: Chat für alle, die online sind (siehe Abschnitt 8) |
| PROFILE | | Fragt einen Handle ab und zeigt das Profil |
| POLLS | | Umfragen (siehe Abschnitt 7) |
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

**Treffpunkt:** Mit dem Befehl TELECONF (im Hauptmenü Taste T, ab dem Level für neue User) treffen sich alle, die gerade online sind, in einem gemeinsamen Raum. Beim Betreten sieht man die Zeilen der letzten 30 Minuten (höchstens 10) und wer im Raum ist. Betreten und Verlassen erscheinen bei den anderen als Systemzeile, auch wenn jemand auflegt, getrennt wird oder wegen Untätigkeit fliegt. Eingehende Zeilen zählen nicht als Aktivität: Wer nur mitliest, wird nach der eingestellten Zeit getrennt wie überall. Befehle am Zeilenanfang: `/Q` verlassen, `/W` wer ist im Raum, `/ME Text` als Aktion („* Handle winkt“), `/?` Hilfe. Eine Zeile hat höchstens 70 Zeichen, die Sperrliste gilt. Startest du einen privaten Chat mit jemandem im Treffpunkt, verlässt er den Raum und landet bei dir. Die Box behält die letzten 50 Zeilen, unter „Oneliner“ im Backend kannst du einzelne oder alle löschen.

**Nachricht an eine Node:** Unter „Wer ist online“ schickt ein Anrufer mit N eine Zeile an eine andere Node. Sie erscheint hellcyan als „*** Nachricht von Handle (Node 2): …“ mit Klingelton. Wer das nicht möchte, schaltet unter Einstellungen „Node-Nachrichten annehmen“ aus, Sysops erreichen trotzdem jeden. Pro Absender geht höchstens eine Nachricht alle 10 Sekunden, an die eigene Node, an freie Nodes und an wartende User gar keine. Das Log nennt Absender und Empfänger, nicht den Text.

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
| `@NEWSINCE@` | Kasten „Neu seit deinem letzten Anruf“, mehrzeilig. Leer beim ersten Anruf und wenn der Kasten abgeschaltet ist (siehe Abschnitt 7) |

Für saubere Rahmen in ANSI-Screens kannst du Breite und Ausrichtung festlegen: `@BBSNAME:40C@` füllt den Namen auf genau 40 Zeichen auf und zentriert ihn. `L` ist linksbündig, `R` rechtsbündig. Längere Werte werden auf die Breite gekürzt.

`@DOORTOP@`, `@SYSOPMSG@` und `@NEWSINCE@` liefern mehrere Zeilen. Setz sie an den Anfang einer eigenen Zeile, eine Breite wie `:40` wirkt bei ihnen nicht.

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

**In WAnsiDraw öffnen:** Neben jedem Screen steht der Knopf „In WAnsiDraw öffnen“. Er öffnet den Screen in einem neuen Tab im ANSI-Editor WAnsiDraw, wo du ihn bearbeiten und wieder als Datei speichern kannst. Die Einzelheiten stehen in Abschnitt 10 unter „Mit WAnsiDraw öffnen“. In Text-Screens bleiben Makros wie `@BBSNAME@` dabei als Text stehen.

Die mitgelieferten Vorlagen liegen in `core/defaults/de/` und `core/defaults/en/`. Der Installer kopiert sie nach `data/screens/`, neue Vorlagen späterer Versionen kopiert das Update dorthin, sofern es noch keinen Screen mit dem Namen gibt. Deine eigenen Screens werden nie überschrieben.

## 10. Dateien: Bereiche, Freigabe, Import

Dateien liegen in `data/files/<Bereichsnummer>/`. Die Datenbank kennt Name, Größe, Beschreibung, Uploader und Downloadzähler.

**Uploads von Anrufern** sind nur in Bereichen möglich, bei denen Uploads eingeschaltet sind, und nur ab dem Upload-Level. Erlaubte Dateitypen und Maximalgröße stellst du in den Einstellungen ein. Dateien, die der Webserver ausführen oder als Seite ausliefern könnte (php in allen Varianten, phtml, phar, cgi, pl, asp, jsp, shtml, html, js, svg, .htaccess), werden immer abgelehnt, auch wenn du die Endung erlaubst. Dasselbe gilt für Doppelendungen wie `datei.php.zip`. Enthält ein ZIP eine FILE_ID.DIZ, bietet die Box sie als Beschreibung an.

Standardmäßig sind Uploads erst nach deiner Freigabe sichtbar. Wartende Uploads siehst du in der Übersicht und unter „Dateien“. Prüf sie, bevor du sie freigibst: Du bist als Betreiber für die Inhalte verantwortlich, die du anbietest.

**Dateien verwalten:** Unter „Dateien“ wählst du einen Bereich und siehst seine Dateien. Über die Häkchen links kannst du mehrere Dateien auf einmal freigeben, löschen (mit Rückfrage) oder in einen anderen Bereich verschieben. Beim Verschieben wandert die Datei auf der Platte nach `data/files/<neuer Bereich>/`. Gibt es im Zielbereich schon eine Datei mit dem Namen, wird sie übersprungen und du bekommst eine Meldung.

Über „Bearbeiten“ änderst du Name und Beschreibung einer Datei. Für den Namen gelten dieselben Regeln wie beim Upload: Buchstaben, Ziffern, Punkt, Binde- und Unterstrich, alles andere wird zum Unterstrich. Endungen wie php oder html sind nicht erlaubt, und ein Name, den es im Bereich schon gibt, wird abgelehnt. Die Datei wird auf der Platte mit umbenannt.

**Dateien ansehen:** In jeder Dateiliste können Anrufer mit V Textdateien direkt im Terminal ansehen: Endungen txt, nfo, diz, asc und ans, höchstens 64 KB. ANSI-Dateien laufen durch denselben Renderer wie die Screens, Textdateien werden als CP437 gelesen, außer sie sind gültiges UTF-8. Ansehen zählt nicht als Download und nicht gegen Ratio und Tageslimit, es gelten aber die Zugriffsrechte des Bereichs. Mit M merken sich Anrufer bis zu 10 Dateien für die Sitzung und laden sie dann mit D alle nacheinander, für jede Datei gelten Limit und Ratio wie beim einzelnen Download.

**Mit WAnsiDraw öffnen:** WAnsiDraw ist ein ANSI-Editor im Browser. Die Box kann ANSI- und Textdateien dorthin übergeben:

- Im Terminal steht beim Ansehen einer `.ans`- oder `.txt`-Datei in der Abschlusszeile zusätzlich „[W] In WAnsiDraw öffnen“. Der Browser öffnet den Editor in einem neuen Tab. Blockt er das neue Fenster, erscheint unter dem Terminal eine Leiste mit einem Link: Enter öffnet ihn, Esc schließt die Leiste. Das zählt nicht als Download, im Log steht nur der Dateiname.
- Im Backend steht der Knopf bei jedem Screen und in der Dateiliste bei `.ans`-Dateien.

Die Adresse des Editors stellst du unter Einstellungen mit „Adresse von WAnsiDraw“ ein, ab Werk `https://wansidraw.de/editor/`. Bei englischer Sprache der Box nimmt die Box an der Werksadresse die englische Fassung. **Leer heißt: Funktion aus**, dann gibt es weder Taste noch Knopf.

Die Zeichnung steckt vollständig im Teil der Adresse nach dem `#`. Diesen Teil sendet ein Browser nicht an den Server, an wansidraw.de wird also nichts übertragen, der Editor liest die Zeichnung erst im Browser des Anrufers. Übergeben wird das fertige Bild: Zeichen und Farben jeder Zelle, Breite und Höhe und die SAUCE-Angaben (Titel, Autor, Gruppe, Datum, Kommentare, iCE-Farben, 9-Pixel-Zeichen, Fontname). **Die Originalbytes der Datei gehen nicht mit.** Wer im Editor speichert, bekommt eine neu geschriebene Datei, die gleich aussieht, aber nicht Byte für Byte dieselbe ist. Ohne SAUCE gilt eine Breite von 80 Spalten und der Dateiname als Titel. Die Grenze liegt bei 160 Spalten und 1000 Zeilen, größere Zeichnungen meldet die Box mit „Zu groß für WAnsiDraw“.

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

Mit der Datenbank sind auch die Kennung der Box und die Kennungen der Nachrichten gesichert (siehe „Kennung der Box“ in Abschnitt 7), ebenso die Geheimnisse des zweiten Faktors.

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
- Nach 5 falschen Passwörtern oder falschen Codes des zweiten Faktors innerhalb von 15 Minuten ist der Account für den Rest dieser 15 Minuten gesperrt, im Terminal und im Backend. Die Fehlversuche stehen im Log.
- Sysops können ihr Konto mit einem zweiten Faktor schützen (Abschnitt 7). Das Geheimnis dafür steht lesbar in der Datenbank, weil die Box es zum Prüfen der Codes braucht, und damit auch in jeder Sicherung. Bewahre Sicherungen entsprechend auf. Die Notfallcodes sind nur als Hash gespeichert.
- Alle Formulare und Terminal-Anfragen sind gegen Cross-Site-Request-Forgery geschützt, alle Datenbankabfragen laufen über Prepared Statements.
- Downloads laufen über Einmal-Links, die nur für den angemeldeten Anrufer und zehn Minuten gelten. Dateien im Ordner `data/` sind direkt nicht erreichbar.
- Die Box speichert keine IP-Adressen und keine E-Mail-Adressen. Gespeichert werden Handle, Ort, Passwort-Hash, Nutzungsstatistik, Nachrichten, Uploads und das Ereignislog. Es wird nur ein technisch notwendiges Session-Cookie gesetzt. Externe Schriften oder Dienste werden nicht geladen. Die Zugriffslogs des Webservers bei deinem Hoster sind davon unabhängig und gehören mit in die Datenschutzerklärung.
- Private Post ist im Backend nicht einsehbar. Mit Sysop-Level kannst du sie im Terminal aber lesen und löschen. Schreib in die Datenschutzerklärung, wie du damit umgehst.
- Das Log lässt sich im Backend jederzeit leeren. Damit sind auch die Upload- und Download-Zahlen der Statistik weg.
- Der Treffpunkt speichert die letzten 50 Zeilen mit Handle und Zeit, ältere werden laufend überschrieben. Unter „Oneliner“ löschst du sie jederzeit.
- „Über mich“ und die Signatur schreiben Anrufer selbst, sie bleiben bis zur Änderung oder bis der User gelöscht wird. Die Signatur steht danach fest in den Nachrichten, die mit ihr geschrieben wurden.
- Bei Umfragen speichert die Box, wer wofür gestimmt hat, damit niemand zweimal abstimmt. Andere Anrufer sehen nur die Summen. Wird ein User gelöscht, bleiben seine Stimmen ohne Verbindung zu ihm stehen, sie lassen sich niemandem mehr zuordnen und das Ergebnis ändert sich nicht. Mit der Umfrage werden auch ihre Stimmen gelöscht.
- Nachrichten an eine Node werden nicht gespeichert, nur zugestellt. Im Log steht, wer wem geschrieben hat.
- Antworten aus dem Fragebogen der Anmeldung sind personenbezogene Angaben. Sie sind nur für die Sysop-Ebene sichtbar, bleiben bis du sie löschst und verschwinden mit dem User. Nenne den Fragebogen in deiner Datenschutzerklärung, wenn du ihn einschaltest.
- „In WAnsiDraw öffnen“ überträgt nichts an den Server von wansidraw.de: Die Zeichnung steht im Teil der Adresse nach dem `#`, den Browser nicht mitsenden. Der Anrufer ruft dabei aber die Seite des Editors auf, dessen Betreiber sieht wie bei jedem Link den Abruf selbst. Mit einer leeren Adresse in den Einstellungen ist die Funktion aus.
- Der Hinweis im Browser-Tab und der Röhren-Effekt laufen nur im Browser des Anrufers, dafür wird nichts gespeichert außer der Einstellung, ob ein Anrufer den Effekt abgeschaltet hat.

## 15. Fehlersuche

| Problem | Lösung |
|---|---|
| Installer meldet „core/ writable“ oder „data/ writable“ rot | Rechte der Ordner per FTP auf 775 setzen |
| Terminal zeigt „NO CARRIER“ direkt nach CONNECT | PHP-Fehlerlog des Hosters prüfen. Meist fehlende Rechte auf `data/` oder falsche Datenbankdaten |
| Immer „Alle Leitungen sind besetzt“ | Mehr Nodes einstellen. Verwaiste Nodes werden nach der Idle-Zeit plus zwei Minuten automatisch frei |
| Login meldet „Zu viele Fehlversuche“ | 15 Minuten warten. Als Sysop kannst du die Sperre sofort aufheben, indem du im Backend das Log leerst. Dabei gehen auch die Upload- und Download-Zahlen der Statistik verloren |
| Handy mit der Authenticator-App verloren | Mit einem Notfallcode einloggen. Ohne Notfallcodes per FTP `data/2fa_reset.txt` mit deinem Handle anlegen und im Backend mit dem Passwort einloggen (Abschnitt 7, „Zweiter Faktor“) |
| Code aus der App wird nicht angenommen | Uhrzeit des Handys prüfen, sie muss stimmen. Jeder Code gilt nur einmal, warte auf den nächsten |
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
