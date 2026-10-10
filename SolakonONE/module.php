<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/VerbindungKette.php';

/**
 * Solakon ONE (Balkonspeicher) ueber das ModBus Gateway von IP-Symcon.
 *
 * Die Instanz haengt unter einem ModBus Gateway mit Client Socket. Adresse,
 * Port und Unit-ID stehen in dieser Instanz; sie traegt sie selbst in Gateway
 * und Socket ein (libs/VerbindungKette.php).
 *
 * Grundlage: "Solakon ONE Modbus Protokoll v.02/26". Jedes verwendete
 * Register ist am echten Geraet geprueft (Firmware 2.031).
 *
 * Eigenheiten des Geraets:
 * 1. Trifft ein Leseblock auf unbelegte Adressen, meldet der ONE im Kopf die
 *    angefragte Byteanzahl, liefert aber nur die Register bis zur Luecke.
 *    Die Bloecke liegen deshalb nur in belegten Bereichen.
 * 2. Mehr als 70 Register je Anfrage kommen nicht vollstaendig an.
 * 3. Mehrere Verbindungen gleichzeitig sind kein Problem.
 * 4. 32-Bit-Werte: hohes Wort zuerst. Der "Faktor" der Doku ist ein Teiler.
 * 5. Schreiben nur mit Funktion 16, Funktion 6 lehnt der ONE ab.
 */
class SolakonONE extends IPSModule
{
    use \SymconSolakonONE\VerbindungKette;

    private const VERBINDUNG = ['Port' => 502, 'GatewayMode' => 0, 'LoggerSerial' => false];
    private const GATEWAY = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';
    private const MODBUS_TX = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';

    // Darstellungen von IP-Symcon (/usr/share/symcon/presentations/)
    private const P_WERT = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
    private const P_AUFZ = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';
    private const P_ZEIT = '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}';
    private const P_DAUER = '{08A6AF76-394E-D354-48D5-BFC690488E4E}';

    /** Nur belegte Bereiche, hoechstens 70 Register (Eigenheiten 1 und 2). */
    private const BLOECKE = [
        [30000, 48],   // Modell, Seriennummer
        [37609, 28],   // Batteriemanagement
        [39000, 70],   // Typenschild, Status, Alarme
        [39070, 8],    // Spannung und Strom je Eingang
        [39118, 52],   // PV gesamt, Wirkleistung, Frequenz, Temperatur
        [39227, 12],   // Batterie Spannung/Strom/Leistung
        [39279, 8],    // Leistung je Eingang
        [39601, 32],   // Energiezaehler
        [46001, 7],    // Fernsteuerung
        [46607, 13],   // Grenzen und Ladefenster
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Interval', 10);
        $this->RegisterPropertyBoolean('AllowWrite', false);
        $this->RegisterAttributeString('YieldState', '');
        $this->RegisterTimer('Update', 0, 'SOLAKON_Update($_IPS[\'TARGET\']);');
        $this->registriereVerbindung();

        $this->RequireParent(self::GATEWAY);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->registerVariables();
        $this->setActionEnabled('FERN_SOLL', $this->ReadPropertyBoolean('AllowWrite'));
        $this->SetTimerInterval('Update', max(2, $this->ReadPropertyInteger('Interval')) * 1000);
        $this->wendeVerbindungAn();
    }

    public function GetConfigurationForm()
    {
        return $this->formularMitVerbindung(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'FERN_SOLL':
                $this->SetPowerSetpoint((int) $Value);
                break;
            default:
                throw new Exception('Unbekannter Ident ' . $Ident);
        }
    }

    // ===================== Oeffentliche Funktionen =====================

    /** Liest alle Register und schreibt die Variablen. */
    public function Update(): bool
    {
        $r = [];
        $fehlt = [];
        foreach (self::BLOECKE as [$start, $anzahl]) {
            $teil = $this->readRegisters($start, $anzahl);
            if ($teil === null || count($teil) < $anzahl) {
                $fehlt[] = $start;
            }
            $r += $teil ?? [];
        }

        // Ohne die Kernwerte nichts schreiben - lieber stehen lassen als Unsinn.
        if (!isset($r[39134], $r[37612])) {
            $this->SetValue('VERBINDUNG', false);
            $this->SendDebug('Update', 'Kernregister nicht lesbar', 0);
            return false;
        }
        if ($fehlt !== []) {
            $this->SendDebug('Update', 'Bloecke unvollstaendig: ' . implode(', ', $fehlt), 0);
        }

        $this->writeValues($r);
        $this->SetValue('STAND', time());
        $this->SetValue('VERBINDUNG', true);
        return true;
    }

    /**
     * Leistungsvorgabe in Register 46003 (W, positiv = Abgabe ans Haus).
     * Wirkt nur, solange die Fernsteuerung (46001 Bit 0) eingeschaltet ist.
     */
    public function SetPowerSetpoint(int $Watt): bool
    {
        $this->requireWrite();
        $w = $Watt & 0xFFFFFFFF;
        return $this->writeRegisters(46003, [($w >> 16) & 0xFFFF, $w & 0xFFFF]);
    }

    /** Register 46001 (Bitfeld der Fernsteuerung, 0 = aus) schreiben. */
    public function SetRemoteControl(int $Mode): bool
    {
        $this->requireWrite();
        return $this->writeRegisters(46001, [$Mode & 0xFFFF]);
    }

    /** Register 46002 (Timeout/Herzschlag) schreiben. */
    public function SetRemoteTimeout(int $Seconds): bool
    {
        $this->requireWrite();
        return $this->writeRegisters(46002, [$Seconds & 0xFFFF]);
    }

    /**
     * Gesamtertrag je Eingang uebernehmen, z. B. aus einer frueheren Loesung.
     * Der heutige Anteil wird beibehalten, die Zaehler laufen von dort weiter.
     */
    public function SetInputTotals(float $PV1, float $PV2, float $PV3, float $PV4): void
    {
        $st = json_decode($this->ReadAttributeString('YieldState'), true);
        if (!is_array($st) || ($st['d'] ?? '') !== date('Y-m-d')) {
            $st = ['d' => date('Y-m-d'), 'roh' => [0.0, 0.0, 0.0, 0.0], 't' => 0];
        }
        $st['basis'] = [];
        foreach ([$PV1, $PV2, $PV3, $PV4] as $i => $gesamt) {
            $n = $i + 1;
            $heute = (float) $this->GetValue("PV{$n}_HEUTE");
            $st['basis'][] = max(0.0, $gesamt - $heute);
            $this->SetValue("PV{$n}_GESAMT", max($gesamt, $heute));
        }
        $this->WriteAttributeString('YieldState', json_encode($st));
    }

    /** Fuer "Verbindung testen" (libs/VerbindungKette.php). */
    private function hinweisOhneAntwort(): string
    {
        return 'Ist der Speicher im Netz erreichbar, stimmen Adresse und Port?';
    }

    // ===================== Modbus ueber das Gateway =====================

    /** Liest Holding-Register (Funktion 3). Gibt [Adresse => Wert] oder null. */
    private function readRegisters(int $start, int $anzahl): ?array
    {
        $daten = $this->sendModbus(3, $start, $anzahl, '');
        if ($daten === null) {
            return null;
        }
        $reg = [];
        // Nach der tatsaechlich gelieferten Laenge auswerten (Eigenheit 1).
        for ($i = 0; $i + 1 < strlen($daten) && intdiv($i, 2) < $anzahl; $i += 2) {
            $reg[$start + intdiv($i, 2)] = (ord($daten[$i]) << 8) | ord($daten[$i + 1]);
        }
        return $reg;
    }

    /** Schreibt Register mit Funktion 16 (Eigenheit 5). */
    private function writeRegisters(int $start, array $werte): bool
    {
        $roh = '';
        foreach ($werte as $w) {
            $roh .= pack('n', $w & 0xFFFF);
        }
        $this->SendDebug('Schreiben', sprintf('%d = %s', $start, bin2hex($roh)), 0);
        return $this->sendModbus(16, $start, count($werte), $roh) !== null;
    }

    /**
     * Eine Anfrage ans ModBus Gateway. Gibt die Registerdaten ohne
     * Funktionscode und Byteanzahl zurueck oder null bei Fehler.
     */
    private function sendModbus(int $funktion, int $adresse, int $anzahl, string $daten): ?string
    {
        if (!$this->HasActiveParent()) {
            return null;
        }
        // Schreibdaten sind rohe Bytes; damit json_encode sie annimmt, werden
        // sie als Latin-1 nach UTF-8 gewandelt (so erwartet es das Gateway).
        $fehler = '';
        set_error_handler(static function (int $nr, string $text) use (&$fehler): bool {
            $fehler = $text;
            return true;
        });
        try {
            $antwort = $this->SendDataToParent(json_encode([
                'DataID'   => self::MODBUS_TX,
                'Function' => $funktion,
                'Address'  => $adresse,
                'Quantity' => $anzahl,
                'Data'     => mb_convert_encoding($daten, 'UTF-8', 'ISO-8859-1'),
            ]));
        } catch (Throwable $e) {
            $fehler = $e->getMessage();
            $antwort = false;
        } finally {
            restore_error_handler();
        }
        if (!is_string($antwort) || strlen($antwort) < 2) {
            $this->SendDebug('Modbus', sprintf('F%d %d/%d: %s', $funktion, $adresse, $anzahl, $fehler ?: 'keine Antwort'), 0);
            return null;
        }
        // Ausnahme-Antwort: Bit 0x80 im Funktionscode, danach der Fehlercode.
        if (ord($antwort[0]) & 0x80) {
            $this->SendDebug('Modbus', sprintf('F%d %d/%d: Ausnahme %d', $funktion, $adresse, $anzahl, ord($antwort[1])), 0);
            return null;
        }
        // Die Antwort ist binaer: Funktionscode, Byteanzahl, dann die Daten.
        return $funktion <= 4 ? substr($antwort, 2) : $antwort;
    }

    private function requireWrite(): void
    {
        if (!$this->ReadPropertyBoolean('AllowWrite')) {
            throw new Exception('Schreiben ist in der Instanz nicht freigegeben (Schreibzugriff erlauben)');
        }
    }

    // ===================== Auswertung =====================

    private function writeValues(array $r): void
    {
        $teile = static fn (?int $w, float $f): ?float => $w === null ? null : round($w / $f, 3);

        // --- Status ---
        $st1 = self::u16($r, 39063) ?? 0;
        $this->SetValue('ZUSTAND', ($st1 & 0x40) ? 3 : (($st1 & 0x04) ? 2 : (($st1 & 0x01) ? 1 : 0)));
        $alarme = self::alarme($r);
        $this->SetValue('STOERUNG', $alarme !== [] || ($st1 & 0x40) !== 0);
        $this->SetValue('ALARME', $alarme === [] ? 'keine' : implode("\n", $alarme));
        $this->SetValue('FERN', self::fernsteuerung(self::u16($r, 46001)));
        $this->setIf('FERN_SOLL', self::i32($r, 46003));
        $this->setIf('FERN_REST', self::u16($r, 46007));

        // --- Leistung ---
        // 39134 und 39118 stehen in der Doku als "kW, Faktor 1000": Rohwert = Watt.
        $pBat = self::i32($r, 39237);
        $this->setIf('P_AC', self::i32($r, 39134));
        $this->setIf('P_PV', self::i32($r, 39118));
        $this->setIf('P_BAT', $pBat);
        if ($pBat !== null) {
            $this->SetValue('BAT_MODUS', $pBat > 10 ? 1 : ($pBat < -10 ? 2 : 0));
        }
        // Eingaenge aus den Strang-Registern (39070ff. U/I, 39279ff. Leistung).
        // Die MPPT-Register 39327ff. taugen nicht: Tracker 1 meldet die Summe.
        for ($n = 1; $n <= 4; $n++) {
            $this->setIf("PV{$n}_U", $teile(self::i16($r, 39068 + 2 * $n), 10));
            $this->setIf("PV{$n}_I", $teile(self::i16($r, 39069 + 2 * $n), 100));
            $this->setIf("PV{$n}_P", self::i32($r, 39277 + 2 * $n));
        }
        $this->setIf('FREQ', $teile(self::i16($r, 39139), 100));
        $this->setIf('TEMP', $teile(self::i16($r, 39141), 10));

        // --- Batterie ---
        $soc = self::u16($r, 37612);
        $this->setIf('SOC', $soc);
        // Nennkapazitaet: Doku "Wh, Faktor 0.1" -> Rohwert mal 10.
        $kapWh = self::u16($r, 37635);
        if ($kapWh !== null && $kapWh > 0) {
            $this->SetValue('KAPAZ', $kapWh * 10 / 1000.0);
            if ($soc !== null) {
                $this->SetValue('E_INHALT', round($kapWh * 10 / 1000.0 * $soc / 100.0, 2));
            }
        }
        $this->setIf('BAT_U', $teile(self::i16($r, 37609), 10));
        // Das BMS meldet den Strom ohne Richtung; das Vorzeichen kommt aus der Leistung.
        $iBat = $teile(self::i16($r, 37610), 10);
        if ($iBat !== null) {
            $this->SetValue('BAT_I', ($pBat !== null && $pBat < 0) ? -abs($iBat) : abs($iBat));
        }
        $this->setIf('SOH', self::u16($r, 37624));
        $this->setIf('T_MAX', $teile(self::i16($r, 37617), 10));
        $this->setIf('T_MIN', $teile(self::i16($r, 37618), 10));
        $this->setIf('Z_MAX', self::u16($r, 37619));
        $this->setIf('Z_MIN', self::u16($r, 37620));
        $this->setIf('SOC_MIN', self::u16($r, 46609));
        $this->setIf('SOC_MAX', self::u16($r, 46610));
        $bms = [];
        for ($a = 37626; $a <= 37631; $a++) {
            if (($r[$a] ?? 0) !== 0) {
                $bms[] = sprintf('Fault%d: 0x%04X', $a - 37625, $r[$a]);
            }
        }
        $this->SetValue('BMS_FEHLER', $bms === [] ? 'keine' : implode("\n", $bms));

        // --- Energie (kWh, Teiler 100) ---
        // Gesamtstaende nie auf 0 setzen: Eine 0 ist immer ein Lesefehler, und
        // das Archiv zaehlt sonst den ganzen Stand neu.
        foreach ([['PV_HEUTE', 39603], ['LAD_HEUTE', 39607], ['ENT_HEUTE', 39611], ['AUS_HEUTE', 39623]] as [$ident, $a]) {
            $this->setIf($ident, $teile(self::u32($r, $a), 100));
        }
        foreach ([['PV_GESAMT', 39601], ['LAD_GESAMT', 39605], ['ENT_GESAMT', 39609], ['AUS_GESAMT', 39621]] as [$ident, $a]) {
            $w = self::u32($r, $a);
            if ($w !== null && $w > 0) {
                $this->SetValue($ident, $w / 100.0);
            }
        }
        $this->yieldPerInput($r);

        // --- Geraet ---
        $modell = self::text($r, 30000, 16);
        if ($modell !== '') {
            $this->SetValue('MODELL', $modell);
        }
        $sn = self::text($r, 30016, 16);
        if ($sn !== '') {
            $this->SetValue('SN', $sn);
        }
        $this->setIf('P_NENN', self::i32($r, 39053));
        $this->setIf('P_EXPORT', self::i32($r, 46616));
        $this->setIf('I_LAD_MAX', $teile(self::i16($r, 46607), 10));
        $this->setIf('I_ENT_MAX', $teile(self::i16($r, 46608), 10));
    }

    /**
     * Ertrag je Eingang. Das Geraet zaehlt nur den PV-Ertrag gesamt. Die
     * Leistung je Eingang wird ueber den Tag aufsummiert, und der echte
     * Tagesertrag des Geraets wird im Verhaeltnis dieser Summen verteilt -
     * die Summe stimmt so immer mit dem Geraet ueberein. Liegt zwischen zwei
     * Laeufen mehr als das Dreifache des Takts, zaehlt die Luecke nicht.
     */
    private function yieldPerInput(array $r): void
    {
        $st = json_decode($this->ReadAttributeString('YieldState'), true);
        if (!is_array($st)) {
            $st = [];
        }
        $jetzt = microtime(true);
        $tag = date('Y-m-d');
        if (($st['d'] ?? '') !== $tag) {
            $st['d'] = $tag;
            $st['roh'] = [0.0, 0.0, 0.0, 0.0];
            $st['basis'] = [];
            for ($n = 1; $n <= 4; $n++) {
                $this->SetValue("PV{$n}_HEUTE", 0.0);
                $st['basis'][] = (float) $this->GetValue("PV{$n}_GESAMT");
            }
        }
        $dt = $jetzt - (float) ($st['t'] ?? 0);
        if ($dt > 0 && $dt <= 3 * max(2, $this->ReadPropertyInteger('Interval'))) {
            for ($n = 1; $n <= 4; $n++) {
                $p = self::i32($r, 39277 + 2 * $n);
                if ($p !== null && $p > 0) {
                    $st['roh'][$n - 1] += $p * $dt / 3600.0;   // Wh
                }
            }
        }
        $st['t'] = $jetzt;
        $pvHeute = self::u32($r, 39603);
        $rohSumme = array_sum($st['roh']);
        if ($pvHeute !== null && $rohSumme > 0) {
            $geraet = $pvHeute / 100.0;
            $st['k'] = round($geraet * 1000.0 / $rohSumme, 3);   // nur zur Kontrolle
            for ($n = 1; $n <= 4; $n++) {
                $heute = round($geraet * $st['roh'][$n - 1] / $rohSumme, 3);
                $this->SetValue("PV{$n}_HEUTE", $heute);
                // Verschieben sich die Anteile, kann "heute" kurz sinken; der
                // Zaehler bleibt dann stehen statt rueckwaerts zu laufen.
                $gesamt = $st['basis'][$n - 1] + $heute;
                if ($gesamt > (float) $this->GetValue("PV{$n}_GESAMT")) {
                    $this->SetValue("PV{$n}_GESAMT", $gesamt);
                }
            }
        }
        $this->WriteAttributeString('YieldState', json_encode($st));
    }

    // ===================== Variablen =====================

    private function registerVariables(): void
    {
        $pos = 0;
        $v = function (string $ident, string $name, int $typ, array $praes = []) use (&$pos): void {
            $pos += 10;
            $this->registerVariable($ident, $name, $typ, $praes, $pos);
        };

        // Status
        $v('VERBINDUNG', 'Verbindung', 0, self::pBool('getrennt', 'verbunden', 'Network'));
        $v('STAND', 'Letzte Aktualisierung', 1, self::pZeit());
        $v('ZUSTAND', 'Betriebszustand', 1, self::pAufz([0 => 'unbekannt', 1 => 'Standby', 2 => 'Betrieb', 3 => 'Fehler'], 'Information'));
        $v('STOERUNG', 'Störung', 0, self::pBool('keine', 'Störung', 'Warning'));
        $v('ALARME', 'Alarmmeldungen', 3, ['ICON' => 'Alert']);
        $v('FERN', 'Fernsteuerung', 3, ['ICON' => 'Remote']);
        $v('FERN_SOLL', 'Fernsteuerung Sollwert', 1, self::pWert(' W', 0, 'Remote', -2400, 2400));
        $v('FERN_REST', 'Fernsteuerung Restzeit', 1, self::pDauer());

        // Leistung (Batterie > 0 laedt, < 0 entlaedt)
        $v('P_AC', 'Abgabe ans Haus', 1, self::pWert(' W', 0, 'Lightning', -1200, 1200));
        $v('P_PV', 'PV-Leistung', 1, self::pWert(' W', 0, 'EnergySolar', 0, 2400));
        $v('P_BAT', 'Batterieleistung', 1, self::pWert(' W', 0, 'Battery', -2400, 2400));
        $v('BAT_MODUS', 'Batterie', 1, self::pAufz([0 => 'Ruhe', 1 => 'lädt', 2 => 'entlädt'], 'Battery'));
        for ($n = 1; $n <= 4; $n++) {
            $v("PV{$n}_U", "Eingang $n Spannung", 2, self::pWert(' V', 1, 'Power', 0, 60));
            $v("PV{$n}_I", "Eingang $n Strom", 2, self::pWert(' A', 2, 'Electricity', 0, 20));
            $v("PV{$n}_P", "Eingang $n Leistung", 1, self::pWert(' W', 0, 'EnergySolar', 0, 1200));
        }
        $v('FREQ', 'Netzfrequenz', 2, self::pWert(' Hz', 2, 'Gauge', 45, 55));
        $v('TEMP', 'Innentemperatur', 2, self::pWert(' °C', 1, 'Temperature', -20, 90));

        // Batterie
        $v('SOC', 'Ladestand', 1, self::pWert(' %', 0, 'Battery', 0, 100));
        $v('E_INHALT', 'Energie im Speicher', 2, self::pWert(' kWh', 2, 'Battery', 0, 2.2));
        $v('KAPAZ', 'Nennkapazität', 2, self::pWert(' kWh', 2, 'Battery'));
        $v('BAT_U', 'Batteriespannung', 2, self::pWert(' V', 1, 'Power', 0, 60));
        $v('BAT_I', 'Batteriestrom', 2, self::pWert(' A', 1, 'Electricity', -80, 80));
        $v('SOH', 'Gesundheit (SoH)', 1, self::pWert(' %', 0, 'Ok', 0, 100));
        $v('T_MAX', 'Zelltemperatur max.', 2, self::pWert(' °C', 1, 'Temperature', -20, 70));
        $v('T_MIN', 'Zelltemperatur min.', 2, self::pWert(' °C', 1, 'Temperature', -20, 70));
        $v('Z_MAX', 'Zellspannung max.', 1, self::pWert(' mV', 0, 'Power', 2500, 3700));
        $v('Z_MIN', 'Zellspannung min.', 1, self::pWert(' mV', 0, 'Power', 2500, 3700));
        $v('SOC_MIN', 'Entladegrenze', 1, self::pWert(' %', 0, 'Battery', 0, 100));
        $v('SOC_MAX', 'Ladegrenze', 1, self::pWert(' %', 0, 'Battery', 0, 100));
        $v('BMS_FEHLER', 'BMS-Fehlercodes', 3, ['ICON' => 'Alert']);

        // Energie
        $v('PV_HEUTE', 'PV-Ertrag heute', 2, self::pWert(' kWh', 2, 'EnergySolar', 0, 10));
        $v('PV_GESAMT', 'PV-Ertrag gesamt', 2, self::pWert(' kWh', 2, 'Gauge'));
        $v('LAD_HEUTE', 'Geladen heute', 2, self::pWert(' kWh', 2, 'Battery', 0, 10));
        $v('LAD_GESAMT', 'Geladen gesamt', 2, self::pWert(' kWh', 2, 'Gauge'));
        $v('ENT_HEUTE', 'Entladen heute', 2, self::pWert(' kWh', 2, 'Battery', 0, 10));
        $v('ENT_GESAMT', 'Entladen gesamt', 2, self::pWert(' kWh', 2, 'Gauge'));
        $v('AUS_HEUTE', 'Abgabe ans Haus heute', 2, self::pWert(' kWh', 2, 'EnergyProduction', 0, 10));
        $v('AUS_GESAMT', 'Abgabe ans Haus gesamt', 2, self::pWert(' kWh', 2, 'Gauge'));
        for ($n = 1; $n <= 4; $n++) {
            $v("PV{$n}_HEUTE", "Eingang $n Ertrag heute", 2, self::pWert(' kWh', 2, 'EnergySolar', 0, 5));
            $v("PV{$n}_GESAMT", "Eingang $n Ertrag gesamt", 2, self::pWert(' kWh', 2, 'Gauge'));
        }

        // Geraet
        $v('MODELL', 'Modell', 3, ['ICON' => 'Information']);
        $v('SN', 'Seriennummer', 3, ['ICON' => 'Information']);
        $v('P_NENN', 'Nennleistung', 1, self::pWert(' W', 0, 'Lightning'));
        $v('P_EXPORT', 'Einspeisegrenze', 1, self::pWert(' W', 0, 'Lightning'));
        $v('I_LAD_MAX', 'Max. Ladestrom', 2, self::pWert(' A', 1, 'Electricity'));
        $v('I_ENT_MAX', 'Max. Entladestrom', 2, self::pWert(' A', 1, 'Electricity'));
    }

    /**
     * Variable mit Darstellung anlegen. Ohne Schluessel PRESENTATION wird
     * nur das Icon gesetzt (Textvariablen).
     */
    private function registerVariable(string $ident, string $name, int $typ, array $praes, int $pos): void
    {
        $mitDarstellung = isset($praes['PRESENTATION']);
        switch ($typ) {
            case 0:
                $this->RegisterVariableBoolean($ident, $name, $mitDarstellung ? $praes : '', $pos);
                break;
            case 1:
                $this->RegisterVariableInteger($ident, $name, $mitDarstellung ? $praes : '', $pos);
                break;
            case 2:
                $this->RegisterVariableFloat($ident, $name, $mitDarstellung ? $praes : '', $pos);
                break;
            default:
                $this->RegisterVariableString($ident, $name, $mitDarstellung ? $praes : '', $pos);
        }
        $icon = $praes['ICON'] ?? '';
        $id = $this->GetIDForIdent($ident);
        if ($icon !== '' && IPS_GetObject($id)['ObjectIcon'] !== $icon) {
            IPS_SetIcon($id, $icon);
        }
    }

    private function setActionEnabled(string $ident, bool $an): void
    {
        if ($an) {
            $this->EnableAction($ident);
        } else {
            $this->DisableAction($ident);
        }
    }

    private function setIf(string $ident, $wert): void
    {
        if ($wert !== null) {
            $this->SetValue($ident, $wert);
        }
    }

    private static function pWert(string $suffix, int $digits, string $icon, float $min = 0.0, float $max = 0.0): array
    {
        return ['PRESENTATION' => self::P_WERT, 'SUFFIX' => $suffix, 'DIGITS' => $digits,
                'ICON' => $icon, 'MIN' => $min, 'MAX' => $max];
    }

    private static function pAufz(array $optionen, string $icon): array
    {
        $o = [];
        foreach ($optionen as $wert => $text) {
            $o[] = ['Value' => $wert, 'Caption' => $text, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        return ['PRESENTATION' => self::P_AUFZ, 'OPTIONS' => json_encode($o), 'ICON' => $icon, 'LAYOUT' => 0];
    }

    private static function pBool(string $aus, string $an, string $icon): array
    {
        return self::pAufz([0 => $aus, 1 => $an], $icon);
    }

    private static function pZeit(string $icon = 'Clock'): array
    {
        return ['PRESENTATION' => self::P_ZEIT, 'DATE' => 2, 'TIME' => 1, 'MONTH_TEXT' => false, 'ICON' => $icon];
    }

    private static function pDauer(string $icon = 'Hourglass'): array
    {
        return ['PRESENTATION' => self::P_DAUER, 'FORMAT' => 1, 'MILLISECONDS' => false, 'ICON' => $icon];
    }

    // ===================== Umrechnungen =====================

    private static function u16(array $r, int $a): ?int
    {
        return $r[$a] ?? null;
    }

    private static function i16(array $r, int $a): ?int
    {
        if (!isset($r[$a])) {
            return null;
        }
        return $r[$a] >= 0x8000 ? $r[$a] - 0x10000 : $r[$a];
    }

    private static function u32(array $r, int $a): ?int
    {
        if (!isset($r[$a], $r[$a + 1])) {
            return null;
        }
        return ($r[$a] << 16) | $r[$a + 1];
    }

    private static function i32(array $r, int $a): ?int
    {
        $v = self::u32($r, $a);
        if ($v === null) {
            return null;
        }
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    private static function text(array $r, int $a, int $anzahl): string
    {
        $s = '';
        for ($i = 0; $i < $anzahl; $i++) {
            if (!isset($r[$a + $i])) {
                break;
            }
            $s .= chr($r[$a + $i] >> 8) . chr($r[$a + $i] & 0xFF);
        }
        $s = explode("\0", $s)[0];
        return trim((string) preg_replace('/[^\x20-\x7E]/', '', $s));
    }

    /** Alarmregister 39067..39069 in Klartext (Tabelle 3-1 der Doku). */
    private static function alarme(array $r): array
    {
        $texte = [
            39067 => [0 => 'PV-Eingangsspannung zu hoch', 1 => 'Lichtbogen auf der DC-Seite',
                      2 => 'PV-Strang verpolt', 7 => 'Netzausfall', 8 => 'Netzspannung außerhalb der Grenzen',
                      11 => 'Netzfrequenz außerhalb der Grenzen', 14 => 'Überstrom am Ausgang',
                      15 => 'Gleichanteil im Ausgangsstrom zu hoch'],
            39068 => [0 => 'Fehlerstrom', 1 => 'Erdungsfehler', 2 => 'Isolationswiderstand zu niedrig',
                      3 => 'Temperatur zu hoch', 9 => 'Speicher gestört', 10 => 'Inselnetz erkannt',
                      14 => 'Überlast am Notstromausgang'],
            39069 => [3 => 'Lüfter gestört', 4 => 'Speicher verpolt', 9 => 'Zähler nicht erreichbar',
                      10 => 'Batteriemanagement nicht erreichbar'],
        ];
        $liste = [];
        foreach ($texte as $adr => $bits) {
            $w = $r[$adr] ?? 0;
            for ($b = 0; $b < 16; $b++) {
                if ($w & (1 << $b)) {
                    $liste[] = $bits[$b] ?? sprintf('unbekannter Alarm %d Bit %d', $adr - 39066, $b);
                }
            }
        }
        return $liste;
    }

    /** Register 46001 (Fernsteuerung) in Klartext. Ein Bitfeld, kein Ein/Aus. */
    private static function fernsteuerung(?int $w): string
    {
        if ($w === null) {
            return '-';
        }
        if (($w & 1) === 0) {
            return 'aus';
        }
        $ziel = [0 => 'AC', 1 => 'Batterie', 2 => 'Netz (Zähler)', 3 => 'AC mit Netzvorrang'][($w >> 2) & 3];
        $richtung = ($w & 2) ? 'positiv = Verbrauch' : 'positiv = Erzeugung';
        return "an, Ziel $ziel, $richtung";
    }
}
