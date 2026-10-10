# SymconSolakonONE

IP-Symcon-Modul für den Balkonspeicher **Solakon ONE**. Es liest den Speicher
über das ModBus Gateway von IP-Symcon aus. Auf Wunsch gibt es auch die
Leistung vor.

## Voraussetzungen

- IP-Symcon ab 8.0, getestet mit 9.0
- Solakon ONE im lokalen Netz, Modbus TCP an Port 502
- getestet mit Firmware 2.031

## Einrichtung

1. In der **Modulsteuerung** (Kern-Instanzen → Modules) diese URL hinzufügen:
   `https://github.com/HeikoSchroers/SymconSolakonONE`
2. Eine Instanz **Solakon ONE** anlegen. Symcon legt dazu ein
   **ModBus Gateway** und einen **Client Socket** an.
3. Im Gateway diese Werte einstellen:
   - Modus: **Modbus TCP**
   - Geräte-ID: **1**
4. Im Client Socket die Adresse des Speichers und Port **502** eintragen.
5. In der Instanz das Abfrageintervall einstellen (Vorgabe 10 Sekunden).

Das Modul selbst kennt keine Adresse und kein Passwort. Alles, was das Gerät
betrifft, steht im Gateway und im Client Socket.

Der ONE nimmt mehrere Modbus-Verbindungen gleichzeitig an. Neben einer
bestehenden Steuerung kann die Instanz also mitlaufen.

## Variablen

| Bereich | Variablen |
|---|---|
| Status | Verbindung, letzte Aktualisierung, Betriebszustand, Störung, Alarmmeldungen im Klartext, Zustand der Fernsteuerung, Sollwert, Restzeit |
| Leistung | Abgabe ans Haus, PV gesamt, Batterie (positiv = lädt), Spannung/Strom/Leistung je Eingang, Netzfrequenz, Innentemperatur |
| Batterie | Ladestand, Energie im Speicher, Nennkapazität, Spannung, Strom, SoH, Zelltemperaturen, Zellspannungen, Lade- und Entladegrenze, BMS-Fehlercodes |
| Energie | PV, Laden, Entladen und Abgabe ans Haus, je für heute und gesamt; Ertrag je Eingang für heute und gesamt |
| Gerät | Modell, Seriennummer, Nennleistung, Einspeisegrenze, maximaler Lade- und Entladestrom |

**Ertrag je Eingang:** Der ONE zählt den PV-Ertrag nur insgesamt. Das Modul
summiert deshalb die Leistung je Eingang über den Tag auf und teilt den
echten Tagesertrag des Geräts im Verhältnis dieser Summen auf. Die Summe der
Eingänge stimmt so immer mit dem Gerät überein.

Die Gesamtzähler werden nie auf 0 gesetzt. Eine 0 ist immer ein Lesefehler,
und das Archiv würde den ganzen Stand sonst neu zählen.

## Schreiben

Ab Werk liest die Instanz nur. Schreiben ist erst möglich, wenn in der
Instanz **Schreibzugriff erlauben** angehakt ist. Erst dann lässt sich der
Sollwert bedienen, und die folgenden Funktionen sind freigegeben:

| Funktion | Register | Bedeutung |
|---|---|---|
| `SOLAKON_SetRemoteControl($id, $Modus)` | 46001 | Bitfeld der Fernsteuerung, 0 = aus |
| `SOLAKON_SetRemoteTimeout($id, $Sekunden)` | 46002 | Zeitüberschreitung bzw. Herzschlag |
| `SOLAKON_SetPowerSetpoint($id, $Watt)` | 46003 | Leistungsvorgabe in W |

Der Sollwert wirkt nur bei eingeschalteter Fernsteuerung. Der ONE nimmt nur
Funktion 16 (mehrere Register schreiben) an, und das Modul nutzt
ausschließlich diese.

> **Achtung:** Eine Regelung, die den Speicher fernsteuert, muss den Fall
> abdecken, dass Symcon ausfällt. Am sichersten ist ein Wächter, der bei
> ausbleibendem Herzschlag die Fernsteuerung abschaltet.

## Eigenheiten des Geräts

- Trifft ein Leseblock auf unbelegte Adressen, kommen nur die Register bis
  zur Lücke. Der Kopf der Antwort nennt trotzdem die volle Byteanzahl. Das
  Modul liest deshalb nur belegte Bereiche und wertet nach der
  tatsächlichen Länge aus.
- Bei mehr als 70 Registern je Anfrage kommt die Antwort nicht vollständig
  an.
- 32-Bit-Werte stehen mit dem hohen Wort zuerst. Den „Faktor" der
  Herstellerdoku teilt das Modul (er ist ein Teiler, kein Multiplikator).

## Quelle

Die Register stammen aus dem Herstellerdokument **„Solakon ONE Modbus
Protokoll v.02/26"**. Jedes verwendete Register ist am Gerät geprüft.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
