# WebCarrier BBS: Benutzerhandbuch

Anleitung für Anrufer.

## Das Wichtigste vorweg

- Die Mailbox wird **nur mit der Tastatur** bedient. Die Maus brauchst du nicht.
- Die meisten Menüs reagieren auf **eine einzige Taste**, ohne Enter. Die Taste steht in eckigen Klammern, zum Beispiel `[M] Nachrichten`.
- Wenn du etwas eintippen sollst (Name, Betreff, Text), schließt du die Eingabe mit **Enter** ab.
- **?** zeigt im Menü die Auswahl erneut an.
- Läuft gerade Text über den Bildschirm, holst du ihn mit **Esc** sofort komplett herein.

## 1. Anrufen

Öffne die Adresse der Mailbox im Browser. Du siehst das Terminal mit „ATZ“ und „OK“. Drück eine beliebige Taste: Das Terminal wählt, du hörst die Wähltöne und das Modem-Pfeifen, dann erscheint `CONNECT` und der Begrüßungsbildschirm.

Sind alle Leitungen belegt, bekommst du eine Besetzt-Meldung. Dann später nochmal versuchen.

Wird die Box gerade aktualisiert, erscheint statt des Terminals der Hinweis, in ein paar Minuten wieder anzurufen. Wer in diesem Moment online ist, wird getrennt.

Mit **F11** schaltest du den Browser in den Vollbildmodus.

## 2. Einloggen und Anmelden

**Du hast schon einen Account:** Name (Handle) eingeben, Enter, Passwort eingeben, Enter. Nach drei falschen Passwörtern wird aufgelegt. Nach fünf Fehlversuchen innerhalb von 15 Minuten ist der Account für diese Zeit gesperrt, auch mit dem richtigen Passwort.

**Du bist neu:** Statt deines Namens `NEU` eintippen (in englischen Boxen `NEW`). Dann wählst du:

1. einen **Handle** mit 3 bis 20 Zeichen, unter dem dich alle kennen,
2. deinen **Ort**, also woher du „anrufst“,
3. ein **Passwort** mit mindestens 6 Zeichen, zweimal.

Eine E-Mail-Adresse brauchst du nicht. Ein vergessenes Passwort kann nur der Sysop zurücksetzen. Die Kontaktdaten stehen im Impressum (im Hauptmenü oder als Link unter dem Terminal).

Neue User haben oft eingeschränkte Rechte, bis der Sysop sie freischaltet. Manche Boxen lassen neue User erst nach einer Prüfung durch den Sysop hinein. Dann siehst du am Ende der Anmeldung den Hinweis, dass der Sysop deinen Zugang prüft, und die Verbindung wird getrennt. Bis zur Freischaltung erscheint dieser Hinweis auch bei jedem weiteren Login. Ruf einfach später wieder an.

## 3. Nach dem Login

Du siehst, wie oft du schon angerufen hast, wie viel Zeit du heute noch hast, ob private Post auf dich wartet und die letzten Oneliner. Mit Enter geht es ins Hauptmenü.

**Zeitlimit:** Die Zeit pro Tag ist begrenzt. Deine Restzeit steht in jeder Menüzeile. Ist sie aufgebraucht, wird aufgelegt, am nächsten Tag geht es weiter.

**Untätigkeit:** Gibst du einige Minuten nichts ein, legt die Box auf, damit die Leitung für andere frei wird. Solange du im Editor tippst oder eine Datei hochlädst, passiert das nicht.

## 4. Das Hauptmenü

Die Punkte können je nach Box anders aussehen. Typisch sind:

| Taste | Funktion |
|---|---|
| M | Nachrichten |
| F | Dateien |
| B | Bulletins, also Infos des Sysops |
| D | Doors (Spiele) |
| O | Oneliner lesen und schreiben |
| L | Letzte Anrufer |
| W | Wer ist gerade online |
| U | Userliste |
| Y | Deine Statistik |
| S | Deine Einstellungen |
| C | Nachricht an den Sysop |
| P | Sysop rufen |
| I / X | Impressum und Datenschutz |
| G | Ausloggen |

## 5. Nachrichten

Nachrichten sind nach Themen in **Bereiche** sortiert. Im Nachrichtenmenü:

| Taste | Funktion |
|---|---|
| A | Bereich wählen. Die Liste zeigt, wie viele Nachrichten es gibt und wie viele neu für dich sind |
| R | Nachrichten im aktuellen Bereich lesen: `N` nur neue, `A` alle, oder eine Nummer, ab der du lesen willst |
| N | Alle neuen Nachrichten aus allen Bereichen nacheinander |
| E | Neue Nachricht im aktuellen Bereich schreiben |
| M | Deine private Post lesen |
| S | Private Nachricht an einen User schicken |

**Beim Lesen** steht unter jeder Nachricht:

| Taste | Funktion |
|---|---|
| N oder Enter | Nächste Nachricht |
| P | Vorige Nachricht |
| R | Antworten |
| A | Nachricht nochmal anzeigen |
| D | Löschen (nur private Post, die an dich ging) |
| Q | Lesen beenden |

Ist eine Nachricht länger als der Bildschirm, hält die Anzeige an. Enter zeigt die nächste Seite, Q springt direkt zu den Befehlen.

Die Box merkt sich, was du gelesen hast. Beim nächsten Anruf siehst du nur noch, was dazugekommen ist.

## 6. Der Editor

Zum Schreiben gibt es einen Zeileneditor mit nummerierten Zeilen. Wird eine Zeile zu lang, rutscht das letzte Wort automatisch in die nächste Zeile. Enter beginnt eine neue Zeile.

Befehle tippst du **am Anfang einer leeren Zeile** ein und bestätigst mit Enter:

| Befehl | Wirkung |
|---|---|
| `/S` | Speichern und absenden |
| `/A` | Abbrechen, nichts wird gespeichert |
| `/L` | Bisherigen Text komplett anzeigen |
| `/D 5` | Zeile 5 löschen |
| `/C` | Einfach weiterschreiben |
| `/?` | Hilfe |

Mit Backspace korrigierst du in der aktuellen Zeile. Eine bereits abgeschlossene Zeile änderst du, indem du sie mit `/D` löschst und neu schreibst.

**Antworten mit Zitat:** Beim Antworten fragt die Box, ob die Originalnachricht zitiert werden soll. Die zitierten Zeilen beginnen mit den Initialen des Absenders, etwa `CS>`. Was du nicht brauchst, löschst du mit `/D`.

Text aus der Zwischenablage kannst du mit **Strg+V** einfügen.

## 7. Dateien

Im Dateimenü:

| Taste | Funktion |
|---|---|
| A | Dateibereich wählen |
| L | Dateien des aktuellen Bereichs auflisten |
| N | Neue Dateien seit deinem letzten Anruf |
| S | Suchen nach Name oder Beschreibung |
| D | Download |
| U | Upload |

In jeder Dateiliste kannst du mit **D** direkt zum Download springen.

**Download:** Dateinamen genau so eintippen, wie er in der Liste steht (Groß- und Kleinschreibung ist egal). Die Box zeigt die geschätzte Übertragungszeit bei deiner Modemgeschwindigkeit, dann speichert dein Browser die Datei ganz normal.

Manche Boxen begrenzen die Downloads pro Tag oder verlangen eine **Ratio**: Bei einer Ratio von 1:3 darfst du drei Dateien laden, dann musst du selbst etwas hochladen. Deine Werte siehst du unter „Deine Statistik“.

**Upload:** Taste U, dann öffnet sich das Dateifenster deines Betriebssystems (Q bricht ab). Datei wählen, die Box zeigt den Fortschritt. Enthält ein ZIP eine `FILE_ID.DIZ`, wird sie als Beschreibung angeboten, sonst gibst du eine kurze Beschreibung ein. Je nach Box ist dein Upload sofort sichtbar oder erst, nachdem der Sysop ihn geprüft hat. Lade nur Dateien hoch, die du weitergeben darfst.

## 8. Weitere Funktionen

**Oneliner:** Eine Pinnwand für kurze Sprüche. Jeder darf eine Zeile hinterlassen.

**Letzte Anrufer und Wer ist online:** Wer zuletzt da war und wer gerade auf welcher Node was macht.

**Doors:** Kleine Spiele innerhalb der Mailbox. Mit dabei ist „Hi-Lo“: eine Zahl zwischen 1 und 100 in sieben Versuchen erraten, die besten Ergebnisse kommen in die Bestenliste.

**Sysop rufen:** Ist der Sysop gerade eingeloggt, wird er benachrichtigt und kann sich zu dir in den Chat schalten. Ist er nicht online, kannst du ihm eine Nachricht hinterlassen.

**Chat mit dem Sysop:** Schaltet sich der Sysop zu, erscheint „Der Sysop hat sich zugeschaltet.“ Was du dann tippst und mit Enter abschickst, sieht der Sysop, seine Antworten erscheinen mit seinem Handle davor. `/Q` beendet den Chat, danach bist du wieder im Hauptmenü. Schreibst du gerade eine Nachricht oder lädst eine Datei hoch, wartet der Chat, bis du fertig bist.

**Nachrichten vom Sysop:** Ein Rundruf des Sysops erscheint als gelbe Zeile mit Klingelton, auch mitten in einer Eingabe. Was du gerade tippst, bleibt erhalten.

## 9. Deine Einstellungen

| Taste | Einstellung |
|---|---|
| L | Deinen Ort ändern |
| P | Passwort ändern, du brauchst dafür dein altes |
| B | Modemgeschwindigkeit: wie schnell der Text erscheint, von 300 bps (sehr langsam, sehr nostalgisch) bis „Aus“ für volle Geschwindigkeit |
| E | Expertenmodus: Menüs werden nicht mehr angezeigt, nur noch die Eingabezeile. `?` zeigt das Menü bei Bedarf |
| Q | Zurück zum Menü |

## 10. Ausloggen

Im Hauptmenü **G** drücken und bestätigen. Nach dem Abschiedsbildschirm erscheint `NO CARRIER`. Eine beliebige Taste wählt die Box erneut an.

Den Browser-Tab einfach zu schließen geht auch, die Leitung wird dann automatisch freigegeben.

## Kurzübersicht

| Wo | Taste | Wirkung |
|---|---|---|
| Menü | ? | Menü erneut zeigen |
| Bei laufender Ausgabe | Esc | Ausgabe sofort komplett anzeigen |
| Eingabezeile | Enter | Eingabe abschließen |
| Eingabezeile | Backspace | Zeichen löschen |
| Editor | `/S` | Speichern |
| Editor | `/A` | Abbrechen |
| Liste mit „weiter“ | Enter | Nächste Seite |
| Liste | Q | Abbrechen |
| Ja/Nein-Fragen | J / N (Y / N) | Die hell hervorgehobene Antwort gilt bei Enter |
