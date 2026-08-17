<?php

declare(strict_types=1);

class SmartMeterGateway extends IPSModule
{
    // =====================================================================
    // DLMS-Einheitencodes (IEC 62056-6-2), nur die hier relevanten
    // =====================================================================
    private const UNIT_NAMES = [
        4  => 'd',
        5  => 'h',
        6  => 'min',
        7  => 's',
        8  => 'Grad',
        27 => 'W',
        28 => 'VA',
        29 => 'var',
        30 => 'Wh',
        31 => 'VAh',
        32 => 'varh',
        33 => 'A',
        34 => 'C',
        35 => 'V',
        44 => 'Hz'
    ];

    // =====================================================================
    // Quellen der Variablen: Ident => Liste der OBIS-Codes nach Prioritaet.
    // Der erste gelieferte Code gewinnt. Nicht gelieferte Werte werden,
    // wo moeglich, berechnet (siehe DeriveValues()).
    // =====================================================================
    private const VAR_SOURCES = [
        // Energie
        'gridConsumption' => ['1.8.0'],
        'gridProduction'  => ['2.8.0'],

        // Gesamtwerte
        'power'           => ['16.7.0', '15.7.0'],
        'powerFrequency'  => ['14.7.0'],
        'powerFactor'     => ['13.7.0'],

        // Phase L1
        'voltageL1'       => ['32.7.0'],
        'currentL1'       => ['31.7.0'],
        'powerL1'         => ['36.7.0', '21.7.0'],
        'powerFactorL1'   => ['33.7.0'],
        'phaseAngleL1'    => ['81.7.4'],

        // Phase L2
        'voltageL2'       => ['52.7.0'],
        'currentL2'       => ['51.7.0'],
        'powerL2'         => ['56.7.0', '41.7.0'],
        'powerFactorL2'   => ['53.7.0'],
        'phaseAngleL2'    => ['81.7.15'],

        // Phase L3
        'voltageL3'       => ['72.7.0'],
        'currentL3'       => ['71.7.0'],
        'powerL3'         => ['76.7.0', '61.7.0'],
        'powerFactorL3'   => ['73.7.0'],
        'phaseAngleL3'    => ['81.7.26']
    ];

    // Rundung je Variable (Nachkommastellen)
    private const ROUND_DIGITS = [
        'gridConsumption' => 6,
        'gridProduction'  => 6,
        'power'           => 1,
        'powerFrequency'  => 2,
        'powerFactor'     => 3,
        'voltageL1'       => 1,
        'voltageL2'       => 1,
        'voltageL3'       => 1,
        'currentL1'       => 2,
        'currentL2'       => 2,
        'currentL3'       => 2,
        'powerL1'         => 1,
        'powerL2'         => 1,
        'powerL3'         => 1,
        'powerFactorL1'   => 3,
        'powerFactorL2'   => 3,
        'powerFactorL3'   => 3,
        'phaseAngleL1'    => 1,
        'phaseAngleL2'    => 1,
        'phaseAngleL3'    => 1
    ];

    // =====================================================================
    // Bekannte OBIS-Codes fuer den Scanner (SMGW_ScanOBIS)
    // =====================================================================
    private const OBIS_DESCRIPTIONS = [
        '1.8.0'   => 'Wirkenergie Bezug gesamt',
        '1.8.1'   => 'Wirkenergie Bezug Tarif 1',
        '1.8.2'   => 'Wirkenergie Bezug Tarif 2',
        '2.8.0'   => 'Wirkenergie Lieferung gesamt',
        '2.8.1'   => 'Wirkenergie Lieferung Tarif 1',
        '2.8.2'   => 'Wirkenergie Lieferung Tarif 2',
        '3.8.0'   => 'Blindenergie Bezug gesamt',
        '4.8.0'   => 'Blindenergie Lieferung gesamt',
        '9.8.0'   => 'Scheinenergie Bezug gesamt',
        '10.8.0'  => 'Scheinenergie Lieferung gesamt',
        '13.7.0'  => 'Leistungsfaktor gesamt (cos phi)',
        '14.7.0'  => 'Netzfrequenz',
        '15.7.0'  => 'Wirkleistung Betrag gesamt',
        '16.7.0'  => 'Wirkleistung Saldo gesamt (Bezug + / Lief. -)',
        '21.7.0'  => 'Wirkleistung Bezug L1',
        '22.7.0'  => 'Wirkleistung Lieferung L1',
        '23.7.0'  => 'Blindleistung Bezug L1',
        '24.7.0'  => 'Blindleistung Lieferung L1',
        '29.7.0'  => 'Scheinleistung Bezug L1',
        '31.7.0'  => 'Strom L1',
        '32.7.0'  => 'Spannung L1',
        '33.7.0'  => 'Leistungsfaktor L1 (cos phi)',
        '36.7.0'  => 'Wirkleistung Saldo L1',
        '41.7.0'  => 'Wirkleistung Bezug L2',
        '42.7.0'  => 'Wirkleistung Lieferung L2',
        '43.7.0'  => 'Blindleistung Bezug L2',
        '49.7.0'  => 'Scheinleistung Bezug L2',
        '51.7.0'  => 'Strom L2',
        '52.7.0'  => 'Spannung L2',
        '53.7.0'  => 'Leistungsfaktor L2 (cos phi)',
        '56.7.0'  => 'Wirkleistung Saldo L2',
        '61.7.0'  => 'Wirkleistung Bezug L3',
        '62.7.0'  => 'Wirkleistung Lieferung L3',
        '63.7.0'  => 'Blindleistung Bezug L3',
        '69.7.0'  => 'Scheinleistung Bezug L3',
        '71.7.0'  => 'Strom L3',
        '72.7.0'  => 'Spannung L3',
        '73.7.0'  => 'Leistungsfaktor L3 (cos phi)',
        '76.7.0'  => 'Wirkleistung Saldo L3',
        '81.7.1'  => 'Phasenwinkel U(L2) zu U(L1)',
        '81.7.2'  => 'Phasenwinkel U(L3) zu U(L1)',
        '81.7.4'  => 'Phasenwinkel U(L1) zu I(L1)',
        '81.7.15' => 'Phasenwinkel U(L2) zu I(L2)',
        '81.7.26' => 'Phasenwinkel U(L3) zu I(L3)',
        '96.1.0'  => 'Geraetenummer / Zaehleridentifikation',
        '96.5.0'  => 'Betriebszustand / Statuswort',
        '96.8.0'  => 'Betriebsdauer'
    ];

    // Eigene Variablenprofile
    private const PROFILE_POWERFACTOR = 'SMGW.PowerFactor';
    private const PROFILE_ANGLE       = 'SMGW.Angle';

    // =====================================================================
    // Lebenszyklus
    // =====================================================================

    public function Create()
    {
        parent::Create();

        $this->RegisterProfiles();

        // --- Gesamtwerte ---
        $this->RegisterVariableFloat('power', $this->Translate('Power'), '~Watt', 10);
        $this->RegisterVariableFloat('powerFactor', $this->Translate('Power Factor'), self::PROFILE_POWERFACTOR, 11);
        $this->RegisterVariableFloat('powerFrequency', $this->Translate('Power Frequency'), '~Hertz.50', 12);
        $this->RegisterVariableFloat('gridConsumption', $this->Translate('Grid Consumption (1.8.0)'), '~Electricity', 13);
        $this->RegisterVariableFloat('gridProduction', $this->Translate('Grid Production (2.8.0)'), '~Electricity', 14);

        // --- Phase L1 ---
        $this->RegisterVariableFloat('voltageL1', $this->Translate('Voltage L1'), '~Volt.230', 20);
        $this->RegisterVariableFloat('currentL1', $this->Translate('Current L1'), '~Ampere', 21);
        $this->RegisterVariableFloat('powerL1', $this->Translate('Active Power L1'), '~Watt', 22);
        $this->RegisterVariableFloat('powerFactorL1', $this->Translate('Power Factor L1'), self::PROFILE_POWERFACTOR, 23);
        $this->RegisterVariableFloat('phaseAngleL1', $this->Translate('Phase Angle L1'), self::PROFILE_ANGLE, 24);

        // --- Phase L2 ---
        $this->RegisterVariableFloat('voltageL2', $this->Translate('Voltage L2'), '~Volt.230', 30);
        $this->RegisterVariableFloat('currentL2', $this->Translate('Current L2'), '~Ampere', 31);
        $this->RegisterVariableFloat('powerL2', $this->Translate('Active Power L2'), '~Watt', 32);
        $this->RegisterVariableFloat('powerFactorL2', $this->Translate('Power Factor L2'), self::PROFILE_POWERFACTOR, 33);
        $this->RegisterVariableFloat('phaseAngleL2', $this->Translate('Phase Angle L2'), self::PROFILE_ANGLE, 34);

        // --- Phase L3 ---
        $this->RegisterVariableFloat('voltageL3', $this->Translate('Voltage L3'), '~Volt.230', 40);
        $this->RegisterVariableFloat('currentL3', $this->Translate('Current L3'), '~Ampere', 41);
        $this->RegisterVariableFloat('powerL3', $this->Translate('Active Power L3'), '~Watt', 42);
        $this->RegisterVariableFloat('powerFactorL3', $this->Translate('Power Factor L3'), self::PROFILE_POWERFACTOR, 43);
        $this->RegisterVariableFloat('phaseAngleL3', $this->Translate('Phase Angle L3'), self::PROFILE_ANGLE, 44);

        $this->RegisterPropertyString('IP', '');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('Interval', 30);

        $this->RegisterAttributeString('Derived', '');    // Es wird nur der TAF-1 Vertrag ausgelesen
        $this->RegisterAttributeString('MeterID', '');    // Seriennummer des Stromzaehlers
        $this->RegisterAttributeString('AuthHeader', ''); // Legacy, nicht mehr benoetigt

        $this->RegisterTimer('UpdateTimer', 0, 'SMGW_Update($_IPS[\'TARGET\']);');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();

        $ip       = trim($this->ReadPropertyString('IP'));
        $interval = $this->ReadPropertyInteger('Interval');

        if ($ip === '' || $interval <= 0) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SendDebug('ApplyChanges', 'Timer deaktiviert (IP leer oder Intervall <= 0)', 0);
            return;
        }

        $this->SetTimerInterval('UpdateTimer', $interval * 1000);
        $this->SendDebug('ApplyChanges', 'Timer aktiv, Intervall: ' . $interval . ' s', 0);
    }

    public function RequestAction($Ident, $Value)
    {
        $this->Update();
    }

    private function RegisterProfiles()
    {
        if (!IPS_VariableProfileExists(self::PROFILE_POWERFACTOR)) {
            IPS_CreateVariableProfile(self::PROFILE_POWERFACTOR, 2); // 2 = Float
            IPS_SetVariableProfileDigits(self::PROFILE_POWERFACTOR, 3);
            IPS_SetVariableProfileValues(self::PROFILE_POWERFACTOR, -1, 1, 0);
            IPS_SetVariableProfileIcon(self::PROFILE_POWERFACTOR, 'Electricity');
        }

        if (!IPS_VariableProfileExists(self::PROFILE_ANGLE)) {
            IPS_CreateVariableProfile(self::PROFILE_ANGLE, 2);
            IPS_SetVariableProfileDigits(self::PROFILE_ANGLE, 1);
            IPS_SetVariableProfileValues(self::PROFILE_ANGLE, 0, 360, 0);
            IPS_SetVariableProfileText(self::PROFILE_ANGLE, '', ' °');
            IPS_SetVariableProfileIcon(self::PROFILE_ANGLE, 'Move');
        }
    }

    // =====================================================================
    // Oeffentliche Aktionen
    // =====================================================================

    public function Update()
    {
        if (trim($this->ReadPropertyString('IP')) === '') {
            $this->SendDebug('Update', 'Keine IP konfiguriert - Abbruch', 0);
            return;
        }

        if ($this->ReadAttributeString('MeterID') === '') {
            $this->SendDebug('Update', 'MeterID fehlt - versuche automatische Ermittlung', 0);
            if (!$this->DetectDerived()) {
                $this->SendDebug('Update', 'Automatische Ermittlung fehlgeschlagen - bitte Geraet im Formular auslesen', 0);
                return;
            }
        }

        if ($this->GetMeterCounter()) {
            return;
        }

        // Fehlgeschlagen: moeglicherweise wurde der Zaehler getauscht.
        // Hoechstens einmal pro 5 Minuten neu ermitteln.
        $lastTry = (int) $this->GetBuffer('LastDetect');
        if ($lastTry > 0 && (time() - $lastTry) < 300) {
            $this->SendDebug('Update', 'Neuermittlung uebersprungen (letzter Versuch vor ' . (time() - $lastTry) . ' s)', 0);
            return;
        }
        $this->SetBuffer('LastDetect', (string) time());

        $oldMeterID = $this->ReadAttributeString('MeterID');
        $this->SendDebug('Update', 'Abruf fehlgeschlagen - ermittle Vertrag/MeterID neu (alt: ' . $oldMeterID . ')', 0);

        if (!$this->DetectDerived()) {
            $this->SendDebug('Update', 'Neuermittlung fehlgeschlagen', 0);
            return;
        }

        $newMeterID = $this->ReadAttributeString('MeterID');

        if ($newMeterID !== $oldMeterID) {
            $this->LogMessage(
                sprintf('Smart Meter Gateway: MeterID hat sich geaendert (alt: %s, neu: %s)', $oldMeterID, $newMeterID),
                KL_NOTIFY
            );
        }

        $this->GetMeterCounter();
    }

    /**
     * Button "Geraet auslesen" im Konfigurationsformular.
     */
    public function GetDerived()
    {
        $this->UpdateFormField('ConfigProgress', 'visible', true);

        if (!$this->CheckConnection()) {
            $this->UpdateFormField('ConfigProgress', 'visible', false);
            echo $this->Translate('Connection to the Smart Meter Gateway failed!');
            return false;
        }

        $result = $this->DetectDerived();

        $this->UpdateFormField('ConfigProgress', 'visible', false);

        if (!$result) {
            echo $this->Translate('No TAF-1 contract found. Please check IP, username, password and the instance debug log.');
            return false;
        }

        echo sprintf(
            $this->Translate('Found TAF-1 contract: %s with meter id %s.'),
            $this->ReadAttributeString('Derived'),
            $this->ReadAttributeString('MeterID')
        );

        return true;
    }

    /**
     * Button "Verbindung testen".
     */
    public function TestConnection()
    {
        if ($this->CheckConnection()) {
            echo $this->Translate('Smart Meter Gateway reachable.');
            return true;
        }

        echo $this->Translate('Connection to the Smart Meter Gateway failed!');
        return false;
    }

    /**
     * Listet ALLE vom Gateway gelieferten OBIS-Codes aller Zaehler auf,
     * inklusive der Werte, die dieses Modul nicht auswertet.
     * Aufruf: SMGW_ScanOBIS($id);
     */
    public function ScanOBIS()
    {
        $this->UpdateFormField('ConfigProgress', 'visible', true);

        $ip = trim($this->ReadPropertyString('IP'));
        if ($ip === '') {
            $this->UpdateFormField('ConfigProgress', 'visible', false);
            echo $this->Translate('Please enter the IP address first.');
            return [];
        }

        // Alle Zaehler ermitteln, nicht nur den konfigurierten
        $meters  = [];
        $origins = $this->GetData('https://' . $ip . '/json/metering/origin');

        if (is_array($origins)) {
            foreach ($origins as $entry) {
                $id = is_array($entry) ? ($entry['id'] ?? '') : (string) $entry;
                if ($id !== '') {
                    $meters[] = $id;
                }
            }
        }

        if (count($meters) === 0) {
            $stored = $this->ReadAttributeString('MeterID');
            if ($stored !== '') {
                $meters[] = $stored;
                $this->SendDebug('ScanOBIS', 'Zaehlerliste nicht abrufbar - nutze gespeicherte MeterID', 0);
            }
        }

        if (count($meters) === 0) {
            $this->UpdateFormField('ConfigProgress', 'visible', false);
            echo $this->Translate('No meter found. Please run "Read Device" first.');
            return [];
        }

        // Welche OBIS-Codes wertet das Modul aktuell aus?
        $used = [];
        foreach (self::VAR_SOURCES as $ident => $codes) {
            foreach ($codes as $code) {
                $used[$code] = $ident;
            }
        }

        $out    = '';
        $report = [];

        foreach ($meters as $meterID) {

            $data = $this->GetData('https://' . $ip . '/json/metering/origin/' . $meterID . '/extended');

            $out .= str_repeat('=', 104) . "\n";
            $out .= 'Zaehler: ' . $meterID . "\n";
            $out .= str_repeat('=', 104) . "\n";

            if (!is_array($data) || !isset($data['values']) || !is_array($data['values'])) {
                $out .= "  Keine Werte abrufbar (Details im Debug)\n\n";
                continue;
            }

            $out .= sprintf(
                "%-9s %-44s %-13s %-7s %-14s %s\n",
                'OBIS', 'Bedeutung', 'Rohwert', 'Scaler', 'Wert', 'Variable'
            );
            $out .= str_repeat('-', 104) . "\n";

            foreach ($data['values'] as $item) {

                if (!is_array($item) || !isset($item['logical_name'])) {
                    continue;
                }

                $hex    = (string) $item['logical_name'];
                $obis   = $this->convertToOBIS($hex);
                $unit   = isset($item['unit']) ? (int) $item['unit'] : 0;
                $raw    = $item['value'] ?? '';
                $scaler = array_key_exists('scaler', $item) ? (int) $item['scaler'] : null;

                $scaled = ($scaler === null) ? (float) $raw : (float) $raw * pow(10, $scaler);

                $unitName = self::UNIT_NAMES[$unit] ?? ('unit ' . $unit);
                $desc     = self::OBIS_DESCRIPTIONS[$obis] ?? '(unbekannt)';

                $out .= sprintf(
                    "%-9s %-44s %-13s %-7s %-14s %s\n",
                    $obis === '' ? $hex : $obis,
                    substr($desc, 0, 44),
                    (string) $raw,
                    $scaler === null ? '-' : (string) $scaler,
                    round($scaled, 4) . ' ' . $unitName,
                    $used[$obis] ?? '-'
                );

                $report[] = [
                    'meter'    => $meterID,
                    'obis'     => $obis,
                    'hex'      => $hex,
                    'desc'     => $desc,
                    'raw'      => $raw,
                    'scaler'   => $scaler,
                    'value'    => $scaled,
                    'unit'     => $unitName,
                    'variable' => $used[$obis] ?? '-'
                ];
            }

            $out .= "\n";
        }

        $out .= 'Spalte "Variable": Ident der Modulvariable, "-" = wird nicht ausgewertet.' . "\n";

        $this->SendDebug('ScanOBIS', "\n" . $out, 0);
        $this->UpdateFormField('ConfigProgress', 'visible', false);

        echo $out;

        return $report;
    }

    /**
     * Setzt die gespeicherten IDs zurueck (z. B. nach einem Zaehlertausch).
     * Aufruf: SMGW_ResetMeterID($id);
     */
    public function ResetMeterID()
    {
        $this->SendDebug(
            'ResetMeterID',
            'Alte Werte - Derived: ' . $this->ReadAttributeString('Derived') . ' | MeterID: ' . $this->ReadAttributeString('MeterID'),
            0
        );

        $this->WriteAttributeString('Derived', '');
        $this->WriteAttributeString('MeterID', '');
        $this->WriteAttributeString('AuthHeader', '');
        $this->SetBuffer('LastDetect', '');

        echo $this->Translate('Stored ids have been reset and will be detected again on the next update.');
    }

    /**
     * Zeigt die aktuell gespeicherten IDs an.
     * Aufruf: SMGW_GetInfo($id);
     */
    public function GetInfo()
    {
        $derived = $this->ReadAttributeString('Derived');
        $meterID = $this->ReadAttributeString('MeterID');

        echo sprintf(
            "Derived: %s\nMeterID: %s\n",
            $derived === '' ? '(leer)' : $derived,
            $meterID === '' ? '(leer)' : $meterID
        );
    }

    /**
     * Freier Endpunkt-Aufruf zum Testen aus der Konsole:
     * print_r(SMGW_DebugRequest($id, '/json/metering/derived'));
     */
    public function DebugRequest(string $path)
    {
        return $this->GetData('https://' . trim($this->ReadPropertyString('IP')) . $path);
    }

    // =====================================================================
    // Interne Logik
    // =====================================================================

    /**
     * Ermittelt den TAF-1 Vertrag und die zugehoerige MeterID.
     */
    private function DetectDerived(): bool
    {
        $ip   = trim($this->ReadPropertyString('IP'));
        $list = $this->GetData('https://' . $ip . '/json/metering/derived');

        if (!is_array($list) || count($list) === 0) {
            $this->SendDebug('DetectDerived', 'Keine Vertragsliste erhalten. Antwort: ' . json_encode($list), 0);
            return false;
        }

        $this->SendDebug('DetectDerived', 'Gefundene Vertraege: ' . json_encode($list), 0);

        $derived = '';
        $meterID = '';

        foreach ($list as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? '') : (string) $entry;
            if ($id === '') {
                continue;
            }

            $detail = $this->GetData('https://' . $ip . '/json/metering/derived/' . $id);

            if (!is_array($detail)) {
                $this->SendDebug('DetectDerived', 'Keine Details fuer Vertrag ' . $id, 0);
                continue;
            }

            $tafType = $detail['taf_type'] ?? '(kein taf_type)';
            $this->SendDebug('DetectDerived', 'Vertrag ' . $id . ' -> taf_type: ' . $tafType, 0);

            if ($tafType === 'TAF-1') {
                $derived = $id;
                if (isset($detail['sensor_domains'][0])) {
                    $meterID = (string) $detail['sensor_domains'][0];
                } else {
                    $this->SendDebug('DetectDerived', 'TAF-1 Vertrag ohne sensor_domains: ' . json_encode($detail), 0);
                }
                break;
            }
        }

        if ($derived === '' || $meterID === '') {
            $this->SendDebug('DetectDerived', 'Kein vollstaendiger TAF-1 Vertrag gefunden (Derived: "' . $derived . '", MeterID: "' . $meterID . '")', 0);
            return false;
        }

        $this->WriteAttributeString('Derived', $derived);
        $this->WriteAttributeString('MeterID', $meterID);

        $this->SendDebug('DetectDerived', 'Gespeichert - TAF-1 Vertrag: ' . $derived . ' | MeterID: ' . $meterID, 0);

        return true;
    }

    public function GetMeterCounter(): bool
    {
        $ip      = trim($this->ReadPropertyString('IP'));
        $meterID = $this->ReadAttributeString('MeterID');

        if ($meterID === '') {
            $this->SendDebug('GetMeterCounter', 'MeterID ist leer - bitte Geraet im Formular auslesen', 0);
            return false;
        }

        $url     = 'https://' . $ip . '/json/metering/origin/' . $meterID . '/extended';
        $counter = $this->GetData($url);

        if (!is_array($counter)) {
            $this->SendDebug('GetMeterCounter', 'Keine Daten erhalten (null) - siehe GetData-Debug oben', 0);
            return false;
        }

        if (!isset($counter['values']) || !is_array($counter['values'])) {
            $this->SendDebug('GetMeterCounter', 'Antwort enthaelt kein Array "values": ' . substr((string) json_encode($counter), 0, 500), 0);
            return false;
        }

        // --- 1. Rohdaten in OBIS => Wert umwandeln ----------------------
        $values = [];
        $first  = true;

        foreach ($counter['values'] as $item) {

            if ($first) {
                $this->SendDebug('GetMeterCounter', 'Rohdatensatz (Beispiel): ' . json_encode($item), 0);
                $first = false;
            }

            if (!is_array($item) || !isset($item['logical_name'], $item['value'], $item['unit'])) {
                $this->SendDebug('GetMeterCounter', 'Unvollstaendiger Datensatz: ' . json_encode($item), 0);
                continue;
            }

            $obis = $this->convertToOBIS((string) $item['logical_name']);
            if ($obis === '') {
                continue;
            }

            $raw  = (float) $item['value'];
            $unit = (int) $item['unit'];

            if (array_key_exists('scaler', $item)) {
                // Rohwert mit mitgeliefertem Scaler in die DLMS-Basiseinheit umrechnen
                $value = $raw * pow(10, (int) $item['scaler']);
            } else {
                $this->SendDebug('GetMeterCounter', 'Kein scaler-Feld - nutze festen Divisor fuer Einheit ' . $unit, 0);
                switch ($unit) {
                    case 30: $value = $raw / 1000;  break;
                    case 27: $value = $raw / 1000;  break;
                    case 33:
                    case 35:
                    case 44: $value = $raw / 100;   break;
                    case 8:  $value = $raw / 10000; break;
                    default: $value = $raw;         break;
                }
            }

            // Wh / VAh / varh in kWh / kVAh / kvarh umrechnen
            if ($unit === 30 || $unit === 31 || $unit === 32) {
                $value = $value / 1000;
            }

            if (!isset(self::UNIT_NAMES[$unit])) {
                $this->SendDebug('GetMeterCounter', 'Unbekannte Einheit ' . $unit . ' bei OBIS ' . $obis, 0);
            }

            $values[$obis] = [
                'value'  => $value,
                'raw'    => $item['value'],
                'scaler' => $item['scaler'] ?? '-',
                'unit'   => $unit
            ];
        }

        if (count($values) === 0) {
            $this->SendDebug('GetMeterCounter', 'Keine verwertbaren OBIS-Werte in der Antwort', 0);
            return false;
        }

        // --- 2. Variablen aus den OBIS-Codes zuordnen -------------------
        $result = [];

        foreach (self::VAR_SOURCES as $ident => $codes) {
            foreach ($codes as $code) {
                if (isset($values[$code])) {
                    $result[$ident] = [
                        'value'  => $values[$code]['value'],
                        'source' => sprintf(
                            'OBIS %s (roh: %s, scaler: %s, unit: %s/%s)',
                            $code,
                            $values[$code]['raw'],
                            $values[$code]['scaler'],
                            $values[$code]['unit'],
                            self::UNIT_NAMES[$values[$code]['unit']] ?? '?'
                        )
                    ];
                    break;
                }
            }
        }

        // --- 3. Fehlende Werte berechnen --------------------------------
        $this->DeriveValues($result);

        // --- 4. Variablen schreiben -------------------------------------
        $count = 0;

        foreach ($result as $ident => $entry) {

            $varID = @$this->GetIDForIdent($ident);
            if ($varID === false || $varID === 0) {
                $this->SendDebug('GetMeterCounter', 'Variable "' . $ident . '" existiert nicht', 0);
                continue;
            }

            $value = round($entry['value'], self::ROUND_DIGITS[$ident] ?? 3);
            $this->SetValue($ident, $value);
            $this->SendDebug('GetMeterCounter', str_pad($ident, 16) . ' = ' . $value . '   <- ' . $entry['source'], 0);
            $count++;
        }

        // Nicht ausgewertete OBIS-Codes melden
        $known = [];
        foreach (self::VAR_SOURCES as $codes) {
            foreach ($codes as $code) {
                $known[$code] = true;
            }
        }
        $unused = array_diff(array_keys($values), array_keys($known));
        if (count($unused) > 0) {
            $this->SendDebug('GetMeterCounter', 'Nicht ausgewertete OBIS-Codes: ' . implode(', ', $unused), 0);
        }

        $this->SendDebug('GetMeterCounter', $count . ' Variablen aktualisiert (' . count($values) . ' OBIS-Werte empfangen)', 0);

        return $count > 0;
    }

    /**
     * Berechnet Werte, die das Gateway nicht direkt liefert:
     * - cos(phi) je Phase aus dem Phasenwinkel
     * - Wirkleistung je Phase aus U * I * cos(phi)
     * - Gesamtwirkleistung als Summe der Phasen
     * - Gesamt-cos(phi) aus Wirk- und Scheinleistung
     */
    private function DeriveValues(array &$result)
    {
        $phasePower = [];

        foreach (['L1', 'L2', 'L3'] as $phase) {

            // cos(phi) aus dem Phasenwinkel
            if (!isset($result['powerFactor' . $phase]) && isset($result['phaseAngle' . $phase])) {
                $result['powerFactor' . $phase] = [
                    'value'  => cos(deg2rad($result['phaseAngle' . $phase]['value'])),
                    'source' => 'berechnet: cos(' . round($result['phaseAngle' . $phase]['value'], 1) . ' Grad)'
                ];
            }

            // Wirkleistung je Phase
            if (!isset($result['power' . $phase])
                && isset($result['voltage' . $phase], $result['current' . $phase], $result['powerFactor' . $phase])) {

                $result['power' . $phase] = [
                    'value'  => $result['voltage' . $phase]['value']
                              * $result['current' . $phase]['value']
                              * $result['powerFactor' . $phase]['value'],
                    'source' => 'berechnet: U * I * cos(phi)'
                ];
            }

            if (isset($result['power' . $phase])) {
                $phasePower[$phase] = $result['power' . $phase]['value'];
            }
        }

        // Gesamtwirkleistung als Summe, falls nicht geliefert
        if (!isset($result['power']) && count($phasePower) === 3) {
            $result['power'] = [
                'value'  => array_sum($phasePower),
                'source' => 'berechnet: Summe L1 + L2 + L3'
            ];
        }

        // Gesamt-cos(phi) aus Wirkleistung und Scheinleistung
        if (!isset($result['powerFactor']) && isset($result['power'])) {
            $apparent = 0.0;
            foreach (['L1', 'L2', 'L3'] as $phase) {
                if (isset($result['voltage' . $phase], $result['current' . $phase])) {
                    $apparent += $result['voltage' . $phase]['value'] * $result['current' . $phase]['value'];
                }
            }
            if ($apparent > 0) {
                $result['powerFactor'] = [
                    'value'  => max(-1.0, min(1.0, $result['power']['value'] / $apparent)),
                    'source' => 'berechnet: P / S'
                ];
            }
        }
    }

    // =====================================================================
    // Kommunikation
    // =====================================================================

    /**
     * HTTP-GET mit Digest-Authentifizierung.
     * cURL uebernimmt den kompletten Digest-Handshake inkl. Nonce-Verwaltung.
     * Gibt bei jedem Fehler null zurueck und schreibt die Ursache ins Debug.
     */
    public function GetData($url)
    {
        $username = $this->ReadPropertyString('Username');
        $password = $this->ReadPropertyString('Password');

        $this->SendDebug('GetData', 'Request: ' . $url, 0);

        $ch = curl_init($url);
        if ($ch === false) {
            $this->SendDebug('GetData', 'curl_init fehlgeschlagen', 0);
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST,
            CURLOPT_USERPWD        => $username . ':' . $password,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FAILONERROR    => false,
            CURLOPT_HTTPGET        => true
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $errno    = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            $this->SendDebug('GetData', 'CURL Fehler (' . $errno . '): ' . $error, 0);
            return null;
        }

        $body = is_string($response) ? $response : '';
        $this->SendDebug('GetData', 'HTTP ' . $httpCode . ' | Antwortlaenge: ' . strlen($body) . ' Bytes', 0);

        if ($httpCode === 401) {
            $this->SendDebug('GetData', 'HTTP 401 - Benutzername/Passwort pruefen (Digest-Auth abgelehnt)', 0);
            return null;
        }

        if ($httpCode === 404) {
            $this->SendDebug('GetData', 'HTTP 404 - Endpunkt nicht gefunden. Ist die MeterID korrekt? URL: ' . $url, 0);
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->SendDebug('GetData', 'Unerwarteter Status ' . $httpCode . ' | Body: ' . substr($body, 0, 500), 0);
            return null;
        }

        if ($body === '') {
            $this->SendDebug('GetData', 'Leere Antwort erhalten', 0);
            return null;
        }

        $arr = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->SendDebug('GetData', 'JSON Fehler: ' . json_last_error_msg(), 0);
            $this->SendDebug('GetData', 'Rohdaten: ' . substr($body, 0, 500), 0);
            return null;
        }

        return $arr;
    }

    public function CheckConnection()
    {
        $ip = trim($this->ReadPropertyString('IP'));

        if ($ip === '') {
            $this->SendDebug('CheckConnection', 'Keine IP konfiguriert', 0);
            return false;
        }

        $ch = curl_init('https://' . $ip);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPGET        => true
        ]);

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $this->SendDebug('CheckConnection', 'CURL Fehler (' . $errno . '): ' . $error, 0);
            return false;
        }

        $this->SendDebug('CheckConnection', 'HTTP Code: ' . $httpCode, 0);

        // Jede gueltige HTTP-Antwort bedeutet, dass das Gateway erreichbar ist.
        // 401 (Auth verlangt) und 405 (Methode auf / nicht erlaubt) sind normal.
        if ($httpCode > 0) {
            $this->SendDebug('CheckConnection', 'Gateway erreichbar', 0);
            return true;
        }

        $this->SendDebug('CheckConnection', 'Keine HTTP-Antwort erhalten', 0);
        return false;
    }

    // =====================================================================
    // Hilfsfunktionen
    // =====================================================================

    /**
     * Wandelt den Hex-Wert des logical_name in einen OBIS-Code um.
     * Beispiel: 0100010800ff.1apa0116236628.sm -> 1.8.0
     * Rueckgabe "" bei ungueltigem Eingangswert.
     */
    public function convertToOBIS($hex)
    {
        $hex = trim((string) $hex);

        // Nur den Hex-Teil vor dem ersten Punkt verwenden
        $dot = strpos($hex, '.');
        if ($dot !== false) {
            $hex = substr($hex, 0, $dot);
        }

        if ($hex === '' || strlen($hex) < 10) {
            $this->SendDebug('convertToOBIS', 'Ungueltiger Hex-Wert: "' . $hex . '"', 0);
            return '';
        }

        $parts = str_split($hex, 2);

        if (count($parts) < 5) {
            $this->SendDebug('convertToOBIS', 'Zu wenige Bytes im Hex-Wert: ' . $hex, 0);
            return '';
        }

        // Praefix (Medium/Kanal) wird verworfen, es wird nur der erste Zaehler gelesen
        return hexdec($parts[2]) . '.' . hexdec($parts[3]) . '.' . hexdec($parts[4]);
    }
}
