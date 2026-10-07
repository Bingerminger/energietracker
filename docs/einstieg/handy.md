# Auf dem Handy nutzen

**Deutsch** · [English](../en/einstieg/handy.md)

[← Kompendium-Index](../README.md)

Der Energietracker ist für das Ablesen am Zähler gebaut: Tab-Leiste in der
Daumenzone, alle Zähler in einem Durchgang, große Tippziele. Was du dafür
brauchst und was ohne Netz geht.

---

## 1. Im Heimnetz aufrufen

Der Energietracker läuft auf einem Rechner oder NAS in deinem Netz. Auf dem
Handy im selben WLAN genügt dessen Adresse im Browser, etwa
`http://<Adresse>:8080` — die Adresse zeigt der Router oder das NAS. Das
reicht zum Ablesen, Eintragen und für alle Auswertungen.

## 2. Als App auf den Home-Bildschirm

- **iPhone/iPad:** in Safari öffnen → Teilen → **„Zum Home-Bildschirm“**.
- **Android:** in Chrome → Menü → **„App installieren“** bzw. „Zum
  Startbildschirm hinzufügen“.

Die installierte App startet ohne Browserleiste, reicht bis unter Uhr und
Home-Balken und zeigt die zuletzt geladenen Daten auch ohne Netz.

> **Dafür braucht es HTTPS.** Browser erlauben den Service Worker — die
> Grundlage der Anzeige ohne Netz — nur in einem sicheren Kontext: über HTTPS
> oder `localhost`. Über `http://<Adresse>` ist das Symbol auf dem
> Home-Bildschirm nur ein Lesezeichen. HTTPS im Heimnetz geht über einen
> Reverse-Proxy mit Zertifikat (Synology mit Let's Encrypt, Caddy, nginx,
> Traefik) oder über ein VPN wie Tailscale mit eigenem Zertifikat — siehe
> [Sicherheit & Netzbetrieb → HTTPS](../betrieb/sicherheit.md#7-https).

## 3. Schnell ablesen

- **＋ Erfassen** in der Tab-Leiste → „Zählerstände erfassen“: alle Zähler auf
  einer Seite, der letzte Stand steht darüber. Die Tastatur zeigt „Weiter“ und
  springt ins nächste Feld, beim letzten auf „Alle speichern“.
- **Zählwerk abfotografieren:** Feld antippen → „Text scannen“ (Live Text) —
  die Ziffern landen im Feld.
- **Foto als Beleg (seit v3.1.0):** Jede Karte hat **„📷 Foto“**; am Handy
  öffnet das die Kamera. Der Browser verkleinert das Bild auf höchstens
  1600 Pixel und speichert es neu als JPEG — dabei fallen die EXIF-Daten samt
  GPS-Ort weg. Das Foto wird mit dem Stand gespeichert; in der Ablesetabelle der
  Verbrauchsart zeigt ein Vorschaubild darauf.
- **Stand vom Foto vorschlagen lassen:** Ist ein eigener Texterkennungsdienst
  im Heimnetz eingetragen, liest der Server den Stand aus dem Foto und die Karte
  zeigt „Erkannt: … – **Übernehmen**“. Gespeichert wird erst mit deinem Klick.
  Einrichtung: [Texterkennung im Heimnetz](../anleitungen/texterkennung.md).
- **Lesezeichen je Zähler:** `#/zaehlerstaende?meter=<id>` öffnet die
  Erfassung mit genau diesem Zähler im Fokus — als Symbol auf dem
  Home-Bildschirm oder in einem Kurzbefehl. Die ID zeigt die Adresszeile, wenn
  „Zu tun“ direkt zu einem Zähler führt; alle IDs liefert
  `GET /api/readings-overview` (Feld `meter_id`).
- Tippfehler fängt die Plausibilitätsprüfung ab („Das wären 400 kWh am Tag,
  üblich sind 8“), und nach dem Speichern gibt es zehn Sekunden
  **„Rückgängig“**.

## 4. Was ohne Netz geht

| | ohne Netz |
|---|---|
| Übersicht und Auswertungen ansehen | ✓ mit dem Stand des letzten Aufrufs; die Kopfleiste zeigt „Offline – Stand vom …“ |
| Zählerstände in „Zählerstände“ speichern | ✓ seit v3.1.0: Der Stand wartet im Browser und wird nachgesendet (s. u.) |
| Foto zum Stand | ✓ wartet mit dem Stand und wird vor ihm hochgeladen |
| Monatswert eines Zählers mit Verbrauch je Zeitraum | ✓ wartet wie ein Stand ([Heizwärme](../verstehen/15-waerme.md)) |
| Stand vom Foto vorschlagen lassen | — die Texterkennung läuft über den Server |
| Andere Änderungen speichern (Verträge, Einstellungen, Ablesetabelle einer Verbrauchsart …) | — die App meldet, dass nichts gespeichert wurde; die Eingabe bleibt stehen, bis die Verbindung wieder da ist und du erneut speicherst |
| Wetterdaten abgleichen | — |

### Die Warteschlange „Noch nicht gespeichert“

Scheitert das Speichern in „Zählerstände“ an der Verbindung — kein Netz, oder
der Server antwortet nicht in der Zeit —, geht der Stand nicht verloren. Er
landet in einer Warteschlange im Browser dieses Geräts. Die Karte zeigt
⏳ „Wartet auf Verbindung“, oben in der Ansicht steht die Liste **„Noch nicht
gespeichert“**, und eine Zahl am Menüpunkt „Zählerstände“ und am ＋ der
Tab-Leiste zählt mit.

Nachgesendet wird von selbst: sobald das Gerät wieder online ist, sobald der
Server wieder antwortet, beim Start der App und wenn du sie wieder in den
Vordergrund holst — oder sofort mit **„Jetzt senden“**. Doppelt wird dabei
nichts: Jede Erfassung trägt eine Kennung, und der Server legt für dieselbe
Kennung keinen zweiten Stand an.

- **Konflikt:** Steht am selben Tag schon ein anderer Stand am Zähler (etwa
  von Home Assistant oder von einem zweiten Gerät), sendet die App nicht still
  darüber. Der Eintrag nennt den vorhandenen Wert und bietet **„Ersetzen“** oder
  **„Vorhandenen behalten“**.
- **Abgelehnt:** Weist der Server einen Stand ab, steht „Nicht gespeichert:
  Grund“ dabei. **„Bearbeiten“** holt die Werte samt Foto zurück in die Karte,
  von dort speicherst du wie gewohnt; **„Verwerfen“** löscht den Eintrag.
- Ist die Anmeldung abgelaufen, hält das Senden an, bis du dich wieder
  anmeldest.

Grenzen:

- Die Warteschlange liegt im Browser **dieses** Geräts. Ein anderes Gerät sieht
  sie nicht.
- Safari kann die Daten einer Website, die nicht auf dem Home-Bildschirm liegt,
  nach sieben Tagen ohne Nutzung löschen. Wer offline abliest, legt die App
  deshalb auf den Home-Bildschirm (Abschnitt 2) und öffnet sie bald wieder mit
  Verbindung.
- Ohne HTTPS gibt es keinen Service Worker: Ohne Netz lädt die App dann nicht
  neu. Die Seite muss schon offen sein, bevor die Verbindung wegfällt. Was in
  der Warteschlange liegt, geht beim nächsten Öffnen mit Verbindung hinaus.
- Gesendet wird nur, solange die App offen ist. Senden im Hintergrund gibt es
  nicht — iOS kann es nicht.

## 5. Von unterwegs

Außerhalb des Heimnetzes nur mit eingeschalteter **Anmeldung** und über HTTPS —
am einfachsten über ein VPN (Tailscale, WireGuard, die VPN-Funktion des
Routers), dann bleibt die App im Internet unsichtbar. Die Checkliste steht unter
[Sicherheit & Netzbetrieb](../betrieb/sicherheit.md#11-checkliste-vor-der-freigabe-nach-außen).

---

[← Kompendium-Index](../README.md)
