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
| Zählerstände und andere Änderungen speichern | — die App meldet, dass nichts gespeichert wurde; die Eingabe bleibt auf der Karte stehen, bis die Verbindung wieder da ist und du erneut speicherst |
| Wetterdaten abgleichen | — |

Einen Puffer, der Eingaben später von selbst speichert, gibt es nicht und ist
nicht geplant: Abgelesen wird meist im eigenen WLAN.

## 5. Von unterwegs

Außerhalb des Heimnetzes nur mit eingeschalteter **Anmeldung** und über HTTPS —
am einfachsten über ein VPN (Tailscale, WireGuard, die VPN-Funktion des
Routers), dann bleibt die App im Internet unsichtbar. Die Checkliste steht unter
[Sicherheit & Netzbetrieb](../betrieb/sicherheit.md#11-checkliste-vor-der-freigabe-nach-außen).

---

[← Kompendium-Index](../README.md)
