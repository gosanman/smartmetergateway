<?php

    declare(strict_types=1);

    class SmartMeterGateway extends IPSModule {
		
        public function Create() {
            	
			parent::Create();

			//Erstelle Variable
			if (!@$this->GetIDForIdent('power')) {
				$this->RegisterVariableFloat('power', $this->Translate('Power'), '~ValuePower.KNX', 1);
			}
			if (!@$this->GetIDForIdent('gridConsumption')) {
				$this->RegisterVariableFloat('gridConsumption', $this->Translate('Grid Consumption (1.8.0)'), '~Electricity', 2);
			}
			if (!@$this->GetIDForIdent('gridProduction')) {
				$this->RegisterVariableFloat('gridProduction', $this->Translate('Grid Production (2.8.0)'), '~Electricity', 3);
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
			$this->WriteAttributeString('MeterID', $meterid);

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
				if ($item['unit'] == 30) {          // kWh
					$value = $value / 1000000;
				} elseif ($item['unit'] == 27) {    // W
					$value = $value / 1000;
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
				if ($obisCode == "16.7.0") {
					SetValue($this->GetIDForIdent('power'), $value);
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
		// Register	    Einheit		Beschreibung
		// 1-0:1.8.0	kWh	        Elektrische Wirkarbeit Bezug Gesamt
		// 1-0:2.8.0	kWh	        Elektrische Wirkarbeit Lieferung Gesamt
		// 1-0:16.7.0	W	        Wirkleistung Verbrauch

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
		
    }
