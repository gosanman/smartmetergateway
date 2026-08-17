# Smart Meter Gateway

Modul für [IP-Symcon](https://www.symcon.de) zum Auslesen eines Smart Meter Gateways (SMGW) über die **HAN-Schnittstelle**. Das Modul verbindet sich per HTTPS mit Digest-Authentifizierung, sucht automatisch den TAF-1 Vertrag samt zugehöriger Zähler-ID und legt die gelieferten Messwerte in Statusvariablen ab.

## Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Einrichten der Instanz](#4-einrichten-der-instanz)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Datenaufbereitung](#6-datenaufbereitung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [Diagnose und Fehlersuche](#8-diagnose-und-fehlersuche)
9. [Anhang: OBIS-Codes und Einheiten](#9-anhang-obis-codes-und-einheiten)

---

## 1. Funktionsumfang

* Zyklisches Auslesen des Stromzählers über die HAN-Schnittstelle des Gateways
* Automatisches Ermitteln von TAF-1 Vertrag und Zähler-ID
* **Selbstheilung bei Zählertausch**: Liefert der Abruf keine Werte, wird die Zähler-ID automatisch neu ermittelt (maximal einmal alle 5 Minuten) und die Änderung im Meldungslog protokolliert
* Korrekte Skalierung der Rohwerte über das vom Gateway gelieferte `scaler`-Feld
* Werte je Phase: Spannung, Strom, Wirkleistung, Leistungsfaktor, Phasenwinkel
* Berechnung nicht gelieferter Werte (cos φ aus dem Phasenwinkel, Wirkleistung aus U · I · cos φ, Gesamtleistung als Summe der Phasen)
* OBIS-Scanner, der alle vom Gateway gelieferten Werte auflistet — auch die, die das Modul nicht auswertet
* Durchgängige Debug-Ausgaben mit Rohwert, Scaler, Einheit und Herkunft jedes Wertes

## 2. Voraussetzungen

* IP-Symcon ab Version 6.0
* Ein Smart Meter Gateway mit **aktivierter und freigeschalteter HAN-Schnittstelle**
* Zugangsdaten (Benutzername und Passwort), die du vom Messstellenbetreiber erhältst
* Netzwerkzugriff vom IP-Symcon-Server auf die IP-Adresse des Gateways

> **Hinweis:** Das Gateway verwendet ein selbst ausgestelltes TLS-Zertifikat. Das Modul deaktiviert daher die Zertifikatsprüfung (`CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST`). Setze das Gateway deshalb in ein vertrauenswürdiges Netzsegment.

## 3. Installation

1. In IP-Symcon unter **Kern-Instanzen → Modules** ein neues Modul hinzufügen
2. Die URL dieses Repositories eintragen
3. Unter **Instanz hinzufügen** nach `SmartMeterGateway` suchen und die Instanz anlegen

Modul-Informationen:

| Eigenschaft | Wert |
| ----------- | ---- |
| Instanz-GUID | `{483472DD-35A2-CC30-C6C2-0C0A160DF1D4}` |
| Präfix | `SMGW` |
| Typ | 3 (Device) |

> Werden nach einem Update neue öffentliche Funktionen nicht gefunden, muss IP-Symcon die Funktionsliste neu einlesen: `sudo systemctl restart symcon`.

## 4. Einrichten der Instanz

| Feld | Beschreibung |
| ---- | ------------ |
| IP | IP-Adresse des Smart Meter Gateways |
| Benutzername | HAN-Zugangsdaten des Messstellenbetreibers |
| Passwort | HAN-Zugangsdaten des Messstellenbetreibers |
| Intervall | Abrufintervall in Sekunden (Standard 30, `0` deaktiviert den Timer) |

Nach dem Eintragen der Zugangsdaten einmal **Gerät auslesen** drücken. Das Modul durchsucht dann alle Verträge, wählt den TAF-1 Vertrag und speichert Vertrag und Zähler-ID intern als Attribute. Erst danach liefert der Timer Werte.

Der Timer startet nur, wenn eine IP-Adresse eingetragen und das Intervall größer als 0 ist.

## 5. Statusvariablen und Profile

### Gesamtwerte

| Ident | Bezeichnung | Profil | Einheit |
| ----- | ----------- | ------ | ------- |
| `power` | Wirkleistung gesamt | `~Watt` | W |
| `powerFactor` | Leistungsfaktor gesamt | `SMGW.PowerFactor` | – |
| `powerFrequency` | Netzfrequenz | `~Hertz.50` | Hz |
| `gridConsumption` | Netzbezug (1.8.0) | `~Electricity` | kWh |
| `gridProduction` | Netzeinspeisung (2.8.0) | `~Electricity` | kWh |

### Je Phase (L1, L2, L3)

| Ident | Bezeichnung | Profil | Einheit |
| ----- | ----------- | ------ | ------- |
| `voltageL1` … `voltageL3` | Spannung | `~Volt.230` | V |
| `currentL1` … `currentL3` | Strom | `~Ampere` | A |
| `powerL1` … `powerL3` | Wirkleistung | `~Watt` | W |
| `powerFactorL1` … `powerFactorL3` | Leistungsfaktor | `SMGW.PowerFactor` | – |
| `phaseAngleL1` … `phaseAngleL3` | Phasenwinkel | `SMGW.Angle` | ° |

### Angelegte Variablenprofile

| Profil | Typ | Bereich | Nachkommastellen |
| ------ | --- | ------- | ---------------- |
| `SMGW.PowerFactor` | Float | −1 … 1 | 3 |
| `SMGW.Angle` | Float | 0 … 360 | 1 |

Die Profile werden in `Create()` und `ApplyChanges()` angelegt, sofern sie noch nicht existieren. Bestehende Profile werden nicht überschrieben.

**Vorzeichen:** Bei `power` und `powerL1`–`powerL3` bedeutet ein positiver Wert Bezug aus dem Netz, ein negativer Wert Einspeisung.

## 6. Datenaufbereitung

### Skalierung

Jeder Wert des Gateways bringt neben `unit` (DLMS-Einheitencode) ein Feld `scaler` mit. Der physikalische Wert ergibt sich aus:

```
Wert = Rohwert × 10^scaler
```

Beispiel: `{"value":"138473510","scaler":-3,"unit":30}` → 138 473,51 Wh → 138,47351 kWh

Energiewerte (`unit` 30/31/32) werden anschließend zusätzlich durch 1000 geteilt, damit sie in kWh, kVAh bzw. kvarh vorliegen. Fehlt das `scaler`-Feld, greift ein Fallback mit festen Divisoren.

### Zuordnung und Berechnung

Die Zuordnung OBIS-Code → Variable erfolgt über die Konstante `VAR_SOURCES`, die je Variable eine Prioritätsliste enthält. Der erste vom Gateway gelieferte Code gewinnt. Für die Wirkleistung L1 wird beispielsweise zuerst OBIS 36.7.0 geprüft, dann 21.7.0.

Liefert das Gateway einen Wert nicht, wird er nach Möglichkeit berechnet:

| Wert | Berechnung | Voraussetzung |
| ---- | ---------- | ------------- |
| `powerFactorLx` | cos(Phasenwinkel) | OBIS 81.7.4 / 81.7.15 / 81.7.26 |
| `powerLx` | U · I · cos φ | Spannung, Strom und cos φ der Phase |
| `power` | Summe L1 + L2 + L3 | alle drei Phasenleistungen |
| `powerFactor` | P / S | Gesamtleistung und alle U·I |

Im Debug steht bei jedem Wert die Herkunft:

```
gridConsumption  = 138.47351  <- OBIS 1.8.0 (roh: 138473510, scaler: -3, unit: 30/Wh)
powerL1          = 1025.3     <- berechnet: U * I * cos(phi)
```

### Authentifizierung

Die Digest-Authentifizierung übernimmt cURL vollständig (`CURLAUTH_DIGEST`). Eine eigene Verwaltung von Nonce und Nonce-Counter findet nicht mehr statt — diese war in früheren Versionen die Ursache für sporadische HTTP-401-Fehler, weil ein gespeicherter Nonce beim nächsten Timer-Durchlauf als Replay gewertet wurde. Das Attribut `AuthHeader` existiert nur noch aus Kompatibilitätsgründen und wird nicht mehr verwendet.

## 7. PHP-Befehlsreferenz

```php
SMGW_Update(int $InstanzID);
```
Liest den Zähler aus und aktualisiert alle Variablen. Ermittelt die Zähler-ID automatisch, falls sie fehlt oder nicht mehr passt. Wird auch vom Timer aufgerufen.

```php
bool SMGW_GetDerived(int $InstanzID);
```
Sucht den TAF-1 Vertrag und die zugehörige Zähler-ID und speichert beide. Gibt `true` bei Erfolg zurück.

```php
bool SMGW_GetMeterCounter(int $InstanzID);
```
Ruft nur den Zähler mit der gespeicherten ID ab. Gibt `true` zurück, wenn mindestens eine Variable geschrieben wurde.

```php
bool SMGW_TestConnection(int $InstanzID);
```
Prüft, ob das Gateway per HTTPS antwortet. HTTP 401 und 405 gelten als erreichbar.

```php
array SMGW_ScanOBIS(int $InstanzID);
```
Listet alle OBIS-Codes aller Zähler des Gateways mit Bedeutung, Rohwert, Scaler, skaliertem Wert und zugeordneter Variable auf. Gibt die Tabelle als Text aus und zusätzlich ein Array zurück.

```php
SMGW_ResetMeterID(int $InstanzID);
```
Löscht die gespeicherten IDs. Beim nächsten Abruf werden sie neu ermittelt — sinnvoll nach einem Zählertausch.

```php
SMGW_GetInfo(int $InstanzID);
```
Zeigt die aktuell gespeicherten Werte für Vertrag und Zähler-ID.

```php
array SMGW_DebugRequest(int $InstanzID, string $Path);
```
Ruft einen beliebigen Endpunkt des Gateways auf und gibt die dekodierte Antwort zurück:

```php
print_r(SMGW_DebugRequest(12345, '/json/metering/derived'));
```

```php
array SMGW_GetData(int $InstanzID, string $URL);
string SMGW_convertToOBIS(int $InstanzID, string $Hex);
```
Interne Hilfsfunktionen, die für eigene Skripte nutzbar sind.

## 8. Diagnose und Fehlersuche

Öffne den Debug der Instanz — jeder Schritt wird protokolliert. Typische Meldungen:

| Meldung | Bedeutung und Abhilfe |
| ------- | --------------------- |
| `HTTP 401 - Benutzername/Passwort pruefen` | Zugangsdaten falsch oder HAN-Schnittstelle nicht freigeschaltet |
| `HTTP 404 - Endpunkt nicht gefunden` | Zähler-ID passt nicht mehr, etwa nach einem Zählertausch. `SMGW_ResetMeterID()` ausführen |
| `MeterID ist leer` | Noch kein TAF-1 Vertrag ermittelt. **Gerät auslesen** drücken |
| `Kein vollstaendiger TAF-1 Vertrag gefunden` | Das Gateway liefert keinen TAF-1 Vertrag. Debug zeigt die gefundenen `taf_type`-Werte |
| `CURL Fehler (28)` | Timeout — Gateway nicht erreichbar oder Netzwerkproblem |
| `Kein scaler-Feld vorhanden` | Das Gateway liefert keinen Scaler, es greift der Fallback mit festen Divisoren |
| `Nicht ausgewertete OBIS-Codes: …` | Das Gateway liefert Werte, die das Modul nicht abbildet. Mit `SMGW_ScanOBIS()` prüfen |
| `MeterID hat sich geaendert` | Selbstheilung hat einen Zählertausch erkannt (steht auch im Meldungslog) |

**Plausibilitätsprüfung:** Netzfrequenz sollte bei ~50 Hz liegen, Spannung bei ~230 V. Weichen die Werte um Faktor 10 oder 100 ab, stimmt die Skalierung nicht — dann hilft `SMGW_ScanOBIS()`, um Rohwert und Scaler direkt zu vergleichen.

## 9. Anhang: OBIS-Codes und Einheiten

### Ausgewertete OBIS-Codes

| OBIS | Bedeutung | Variable |
| ---- | --------- | -------- |
| 1.8.0 | Wirkenergie Bezug gesamt | `gridConsumption` |
| 2.8.0 | Wirkenergie Lieferung gesamt | `gridProduction` |
| 13.7.0 | Leistungsfaktor gesamt | `powerFactor` |
| 14.7.0 | Netzfrequenz | `powerFrequency` |
| 15.7.0 | Wirkleistung Betrag gesamt | `power` (Fallback) |
| 16.7.0 | Wirkleistung Saldo gesamt | `power` |
| 21.7.0 / 41.7.0 / 61.7.0 | Wirkleistung Bezug L1 / L2 / L3 | `powerL1` … `powerL3` (Fallback) |
| 31.7.0 / 51.7.0 / 71.7.0 | Strom L1 / L2 / L3 | `currentL1` … `currentL3` |
| 32.7.0 / 52.7.0 / 72.7.0 | Spannung L1 / L2 / L3 | `voltageL1` … `voltageL3` |
| 33.7.0 / 53.7.0 / 73.7.0 | Leistungsfaktor L1 / L2 / L3 | `powerFactorL1` … `powerFactorL3` |
| 36.7.0 / 56.7.0 / 76.7.0 | Wirkleistung Saldo L1 / L2 / L3 | `powerL1` … `powerL3` |
| 81.7.4 / 81.7.15 / 81.7.26 | Phasenwinkel U zu I je Phase | `phaseAngleL1` … `phaseAngleL3` |

Weitere Codes sind in der Konstante `OBIS_DESCRIPTIONS` für den Scanner hinterlegt, werden aber nicht in Variablen geschrieben.

### DLMS-Einheitencodes (IEC 62056-6-2)

| Code | Einheit | Code | Einheit |
| ---- | ------- | ---- | ------- |
| 8 | Grad (°) | 31 | VAh |
| 27 | W | 32 | varh |
| 28 | VA | 33 | A |
| 29 | var | 35 | V |
| 30 | Wh | 44 | Hz |

### Aufbau des `logical_name`

```
0100010800ff.1apa0116236628.sm
└─┬─┘└┬┘└┬┘└┬┘ └──────┬──────┘ └┬┘
  │   │  │  │         │         └── Domain
  │   │  │  │         └──────────── Zähler-ID
  │   │  │  └────────────────────── D (255 = ff)
  │   │  └───────────────────────── C
  │   └──────────────────────────── B
  └──────────────────────────────── A/Medium und Kanal
```

Das Modul bildet daraus den OBIS-Code in der Form `C.D.E`, hier also `1.8.0`. Das Präfix wird verworfen, da nur der über den TAF-1 Vertrag adressierte Zähler ausgelesen wird.

---

## Lizenz

Siehe `LICENSE` im Repository.
