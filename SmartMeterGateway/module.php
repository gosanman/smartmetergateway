<?php

    declare(strict_types=1);

    class SmartMeterGateway extends IPSModule {
		
        public function Create() {
            	
			parent::Create();

			//Erstelle Variable
			if (!@$this->GetIDForIdent('power')) {
				$this->RegisterVariableFloat('power', 'Power', '~ValuePower.KNX', 1);
			}
			if (!@$this->GetIDForIdent('gridConsumption')) {
				$this->RegisterVariableFloat('gridConsumption', 'Grid Consumption', '~Electricity', 2);
			}
			if (!@$this->GetIDForIdent('gridProduction')) {
				$this->RegisterVariableFloat('gridProduction', 'Grid Production', '~Electricity', 3);
			}
			
			$this->RegisterPropertyString('IP', "");
			$this->RegisterPropertyString('Username', "");
			$this->RegisterPropertyString('Password', "");
			$this->RegisterPropertyInteger('Interval', 30);
			$this->RegisterAttributeString('Derived', "");		// Es werden nur Verträge nach TAF-1 ausgelesen
			$this->RegisterAttributeString('MeterID', "");		// Seriennummer des Stromzählers, es wird nur der erste ausgelesen
				
			$this->RegisterTimer('UpdateTimer', 0, 'SMGW_Update($_IPS[\'TARGET\']);');
        }
	    
		public function Destroy() {
			
			parent::Destroy();
		}

        public function ApplyChanges() {
            	
			parent::ApplyChanges();
			$this->GetDerived();
			$this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('Interval') * 1000);
        }

        public function RequestAction($Ident, $Value) {

            $this->Update();
        }

        public function Update() {

			$this->GetMeterCounter();
			//$this->SendDebug("Update", "Run", 0);
        }
		
		public function GetDerived() {
			
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

		public function GetData($url, $call = "") {

			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');
			
			$curl = curl_init();

			curl_setopt_array($curl, array(
			CURLOPT_URL => $url . $call,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
			CURLOPT_USERPWD => $username . ":" . $password,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			));

			$response = curl_exec($curl);
			$err = curl_error($curl);

			curl_close($curl);

			if ($err) {
			echo "cURL Error #:" . $err;
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
			return $valuePart . '.' . $subValuePart . '.' . $lastPart; // Nur Werte des ersten Zählers
		}		
		
    }
