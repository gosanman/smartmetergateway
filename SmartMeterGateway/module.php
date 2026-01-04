<?php

    declare(strict_types=1);

    class SmartMeterGateway extends IPSModule {
		
        public function Create() {
            	
			parent::Create();

			//Erstelle Variable
			if (!@$this->GetIDForIdent('power')) {
				$this->RegisterVariableFloat('power', $this->Translate('Power'), '~Watt', 1);
			}
			if (!@$this->GetIDForIdent('gridConsumption')) {
				$this->RegisterVariableFloat('gridConsumption', $this->Translate('Grid Consumption (1.8.0)'), '~Electricity', 2);
			}
			if (!@$this->GetIDForIdent('gridProduction')) {
				$this->RegisterVariableFloat('gridProduction', $this->Translate('Grid Production (2.8.0)'), '~Electricity', 3);
			}
			if (!@$this->GetIDForIdent('powerFrequency')) {
				$this->RegisterVariableFloat('powerFrequency', $this->Translate('Power Frequency'), '~Hertz.50', 4);
			}
			if (!@$this->GetIDForIdent('currentL1')) {
				$this->RegisterVariableFloat('currentL1', $this->Translate('Current L1'), '~Ampere', 5);
			}
			if (!@$this->GetIDForIdent('voltageL1')) {
				$this->RegisterVariableFloat('voltageL1', $this->Translate('Voltage L1'), '~Volt.230', 6);
			}
			if (!@$this->GetIDForIdent('currentL2')) {
				$this->RegisterVariableFloat('currentL2', $this->Translate('Current L2'), '~Ampere', 7);
			}
			if (!@$this->GetIDForIdent('voltageL2')) {
				$this->RegisterVariableFloat('voltageL2', $this->Translate('Voltage L2'), '~Volt.230', 8);
			}
			if (!@$this->GetIDForIdent('currentL3')) {
				$this->RegisterVariableFloat('currentL3', $this->Translate('Current L3'), '~Ampere', 9);
			}
			if (!@$this->GetIDForIdent('voltageL3')) {
				$this->RegisterVariableFloat('voltageL3', $this->Translate('Voltage L3'), '~Volt.230', 10);
			}
			if (!@$this->GetIDForIdent('powerFactorL1')) {
				$this->RegisterVariableFloat('powerFactorL1', $this->Translate('Power Factor L1'), '', 11);
			}
			if (!@$this->GetIDForIdent('powerFactorL2')) {
				$this->RegisterVariableFloat('powerFactorL2', $this->Translate('Power Factor L2'), '', 12);
			}
			if (!@$this->GetIDForIdent('powerFactorL3')) {
				$this->RegisterVariableFloat('powerFactorL3', $this->Translate('Power Factor L3'), '', 13);
			}
			
			$this->RegisterPropertyString('IP', "");
			$this->RegisterPropertyString('Username', "");
			$this->RegisterPropertyString('Password', "");
			$this->RegisterPropertyInteger('Interval', 30);
			$this->RegisterAttributeString('Derived', "");		// Es werden nur Vertraege nach TAF-1 ausgelesen
			$this->RegisterAttributeString('MeterID', "");		// Seriennummer des Stromzaehlers, es wird nur der erste ausgelesen
			$this->RegisterAttributeString('AuthHeader', "");	// Authentifizierungstoken
				
			$this->RegisterTimer('UpdateTimer', 0, 'SMGW_Update($_IPS[\'TARGET\']);');
        }
	    
		public function Destroy() {
			
			parent::Destroy();
		}

        public function ApplyChanges() {
            	
			parent::ApplyChanges();
			$this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('Interval') * 1000);
        }

        public function RequestAction($Ident, $Value) {

            $this->Update();
        }

        public function Update() {

			$this->GetMeterCounter();
        }
		
		public function GetDerived() {
			
			$this->UpdateFormField("ConfigProgress", "visible", true);		// Progressbar anzeigen
			
			// Prüfe zuerst die Verbindung
			if (!$this->CheckConnection()) {
				$this->SendDebug("GetDerived", "Verbindung zum Smart Meter Gateway nicht möglich!", 0);
				$this->UpdateFormField("ConfigProgress", "visible", false);
				return false;
			}
			
			$url = 'https://' . $this->ReadPropertyString('IP') . '/json/metering/derived';
			$data = $this->GetData($url);
			foreach ($data as $key => $value) {
				$url = 'https://' . $this->ReadPropertyString('IP') . '/json/metering/derived/' . $value;
				$data = $this->GetData($url);
					if ($data['taf_type'] == "TAF-1") {
						$derived = $value;
						$meterid = $data['sensor_domains'][0];
					}
			}

			$this->WriteAttributeString('Derived', $derived);
			$this->SendDebug("GetDerived", "TAF-1 Vertrag: " . $derived, 0);
			$this->WriteAttributeString('MeterID', $meterid);
			$this->SendDebug("GetDerived", "MeterID: " . $meterid, 0);

			$this->UpdateFormField("ConfigProgress", "visible", false); 	// Progressbar ausblenden

			echo sprintf('Es wurde der folgender TAF-1 Vertrag gefunden: %s mit der Zähler ID %s.', $derived, $meterid);
		}

		public function GetMeterCounter() {

			$url = 'https://' . $this->ReadPropertyString('IP') . '/json/metering/origin/' . $this->ReadAttributeString('MeterID') . '/extended';
			$counter = $this->GetData($url);
			foreach ($counter['values'] as $item) {

				// Berechne den Wert je nach Einheit
				$value = (int)$item['value'];

				// Umrechnung: 
				// Wenn unit 30, dann durch 1000000 (kWh)
				// Wenn unit 27, dann durch 1000 (W)
				// Wenn unit 33, 35, 44, dann durch 100 (A, V, Hz)
				// Wenn unit 8, dann durch 1000 (Leistungsfaktor)
				if ($item['unit'] == 30) {          // kWh
					$value = $value / 1000000;
				} elseif ($item['unit'] == 27) {    // W
					$value = $value / 1000;
				} elseif ($item['unit'] == 33 || $item['unit'] == 35 || $item['unit'] == 44) {    // A, V, Hz
					$value = $value / 100;
				} elseif ($item['unit'] == 8) {    // Leistungsfaktor
					$value = round($value / 1000, 1);
				}

				// Umrechnung des logical_name (Hex-Wert in Format)
				$hex = $item['logical_name']; 			// Hex-Wert aus der Antwort
				$obisCode = $this->convertToOBIS($hex); // OBIS-Code umwandeln

				if ($obisCode == "1.8.0") {
					SetValue($this->GetIDForIdent('gridConsumption'), $value);
				}
				if ($obisCode == "2.8.0") {
					SetValue($this->GetIDForIdent('gridProduction'), $value);
				}
				if ($obisCode == "14.7.0") {
					SetValue($this->GetIDForIdent('powerFrequency'), $value);
				}
				if ($obisCode == "16.7.0") {
					SetValue($this->GetIDForIdent('power'), $value);
				}
				if ($obisCode == "31.7.0") {
					SetValue($this->GetIDForIdent('currentL1'), $value);
				}
				if ($obisCode == "32.7.0") {
					SetValue($this->GetIDForIdent('voltageL1'), $value);
				}
				if ($obisCode == "51.7.0") {
					SetValue($this->GetIDForIdent('currentL2'), $value);
				}
				if ($obisCode == "52.7.0") {
					SetValue($this->GetIDForIdent('voltageL2'), $value);
				}
				if ($obisCode == "71.7.0") {
					SetValue($this->GetIDForIdent('currentL3'), $value);
				}
				if ($obisCode == "72.7.0") {
					SetValue($this->GetIDForIdent('voltageL3'), $value);
				}
				if ($obisCode == "81.7.4") {
					SetValue($this->GetIDForIdent('powerFactorL1'), $value);
				}
				if ($obisCode == "81.7.15") {
					SetValue($this->GetIDForIdent('powerFactorL2'), $value);
				}
				if ($obisCode == "81.7.26") {
					SetValue($this->GetIDForIdent('powerFactorL3'), $value);
				}
			}
		}

		// Digest-Authentifizierungs-Header abrufen
		public function getDigestAuthParams($url, $username, $password) {

			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HEADER, true);
			curl_setopt($ch, CURLOPT_NOBODY, false);
			curl_setopt($ch, CURLOPT_FAILONERROR, false);
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
			curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

			$response = curl_exec($ch);
			$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			$this->SendDebug("DigestAuthParams", "HTTP Header: " . $httpCode, 0);
			
			curl_close($ch);

			// WWW-Authenticate Header extrahieren
			preg_match('/WWW-Authenticate: Digest (.+)/i', substr($response, 0, $headerSize), $matches);
			if (!isset($matches[1])) {
				$this->SendDebug("DigestAuthParams", "Kein Digest-Authentifizierungs-Header gefunden.", 0);
			}

			// Header in ein assoziatives Array parsen
			preg_match_all('@(\w+)="([^"]+)"@', $matches[1], $authMatches, PREG_SET_ORDER);
			$digestParams = [];
			foreach ($authMatches as $match) {
				$digestParams[$match[1]] = $match[2];
			}

			return $digestParams;
		}

		// MD5-Hash-Funktion
		public function md5Hash($data) {
			return md5($data);
		}

		// Digest-Header generieren
		public function getAuthHeader($username, $password, $digestParams, $method, $path, $nc, $cnonce) {

			$HA1 = $this->md5Hash("$username:{$digestParams['realm']}:$password");
			$HA2 = $this->md5Hash("$method:$path");
			$response = $this->md5Hash("$HA1:{$digestParams['nonce']}:" . sprintf("%08d", $nc) . ":$cnonce:{$digestParams['qop']}:$HA2");

			return "Digest username=\"$username\", realm=\"{$digestParams['realm']}\", nonce=\"{$digestParams['nonce']}\", uri=\"$path\", " .
				"cnonce=\"$cnonce\", nc=" . sprintf("%08d", $nc) . ", algorithm=MD5, response=\"$response\", qop=\"{$digestParams['qop']}\"";
		}

		// HTTP-Request mit Digest-Authentifizierung senden
		public function GetData($url) {

			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');
			static $nc = 1; // Nonce-Counter (wird bei jeder Anfrage erhöht)
			$cnonce = md5(microtime(true) . rand());
			$path = parse_url($url, PHP_URL_PATH);
			$digestParams = json_decode($this->ReadAttributeString("AuthHeader"), true);

			// Falls digestParams leer ist Auth-Daten abrufen und speichern
			if (!$digestParams) {
				$this->SendDebug("GetData", "DigestAuthParams sind leer, werden neu abgerufen und gespeichert!", 0);
				$digestParams = $this->getDigestAuthParams($url, $username, $password);
				$this->WriteAttributeString("AuthHeader", json_encode($digestParams));
			}

			$authHeader = $this->getAuthHeader($username, $password, $digestParams, "GET", $path, $nc, $cnonce);

			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $authHeader"]);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

			$response = curl_exec($ch);
			$error = curl_error($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			$this->SendDebug("GetData", "Request HTTP Header: " . $httpCode, 0);

			curl_close($ch);

			// Falls der Server 401 liefert, Auth-Header neu abrufen, speichern und erneut senden
			static $retryCount = 0;
			if ($httpCode == 401 && $retryCount < 3) {
				$this->SendDebug("GetData", "DigestAuthParams sind abgelaufen, werden neu abgerufen und gespeichert! (" . $retryCount . ")", 0);
				$digestParams = $this->getDigestAuthParams($url, $username, $password);
				$this->WriteAttributeString("AuthHeader", json_encode($digestParams));
				$retryCount++;
				return $this->GetData($url);
			} else {
				$retryCount = 0;
			}

			$nc++; // Nonce-Counter für die nächste Anfrage erhöhen

			if ($error) {
				$this->SendDebug("GetData", "CURL Fehler: " . $error, 0);
			} else {
				$arr = json_decode($response, true);
				return $arr;
			}
		}

		// Funktion zur Umrechnung des Hex-Werts in den OBIS-Code
		// | OBIS-Code | Bedeutung                                               | Einheit   |
		// | --------- | ------------------------------------------------------- | --------- |
		// | 1.8.0     | Wirkenergie Bezug gesamt (Import)                       | kWh       |
		// | 2.8.0     | Wirkenergie Lieferung gesamt (Export / Einspeisung)     | kWh       |
		// | --------- | ------------------------------------------------------- | --------- |
		// | 14.7.0    | Netzfrequenz                                            | Hz        |
		// | 16.7.0    | Momentan Wirkleistung gesamt (Bezug + / Lieferung −)    | W         |
		// | --------- | ------------------------------------------------------- | --------- |
		// | 31.7.0    | Strom Phase L1                                          | A         |
		// | 32.7.0    | Spannung Phase L1                                       | V         |
		// | 51.7.0    | Strom Phase L2                                          | A         |
		// | 52.7.0    | Spannung Phase L2                                       | V         |
		// | 71.7.0    | Strom Phase L3                                          | A         |
		// | 72.7.0    | Spannung Phase L3                                       | V         |
		// | --------- | ------------------------------------------------------- | --------- |
		// | 81.7.4    | Leistungsfaktor Phase L1 (cos φ)                        | –         |
		// | 81.7.15   | Leistungsfaktor Phase L2 (cos φ)                        | –         |
		// | 81.7.26   | Leistungsfaktor Phase L3 (cos φ)                        | –         |
		// | ------------------------------------------------------------------------------- |

		public function convertToOBIS($hex) {

			$parts = str_split($hex, 2);
			$counter = $parts[0] . '-' . $parts[1];
			$valuePart = hexdec($parts[2]);
			$subValuePart = hexdec($parts[3]);
			$lastPart = hexdec($parts[4]);
			//return $counter . ':' . $valuePart . '.' . $subValuePart . '.' . $lastPart;
			if (!isset($counter, $valuePart, $subValuePart, $lastPart)) {
				$this->SendDebug("convertToOBIS", "Fehler bei der OBIS-Umwandlung: " . $hex, 0);
			}
			return $valuePart . '.' . $subValuePart . '.' . $lastPart; // Nur Werte des ersten Zaehlers
		}		
		
		public function CheckConnection() {
	
			$url = 'https://' . $this->ReadPropertyString('IP');
			$ch = curl_init($url);
			
			// Grundlegende cURL Optionen
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);           // Timeout nach 10 Sekunden
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // SSL-Verifizierung deaktivieren
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Hostname-Verifizierung deaktivieren
			
			$result = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$error = curl_error($ch);
			
			curl_close($ch);
			
			// Debug Ausgaben
			$this->SendDebug("CheckConnection", "Verbindung erfolgreich (HTTP Code: " . $httpCode . ")", 0);
			if ($error) {
				$this->SendDebug("CheckConnection", "Fehler: " . $error, 0);
			}
			
			// Auswertung
			if ($httpCode >= 200 && $httpCode <= 401) {
				//echo "Smart Meter Gateway erreichbar!";
				return true;
			} else {
				echo sprintf("Verbindung fehlgeschlagen (HTTP Code: %d)", $httpCode);
				return false;
			}
		}

    }
