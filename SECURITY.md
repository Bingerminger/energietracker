# Security policy · Sicherheitsrichtlinie

**English** · [Deutsch](#deutsch)

## Reporting a vulnerability

Please do **not** open a public issue for a security problem. Report it
privately via GitHub instead:
**[Security → Report a vulnerability](https://github.com/Bingerminger/energietracker/security/advisories/new)**.

Helpful details:

- affected version (`VERSION` file or the version shown in the app header),
- the operating mode (Docker, Apache/Synology, other) and whether sign-in is
  switched on,
- steps to reproduce, and what an attacker gains.

Energietracker is maintained in spare time; reports are handled as quickly as
possible. Please allow time for a fix before publishing details.

## Supported versions

Fixes are made on the latest release. Please update before reporting — the
[CHANGELOG](CHANGELOG.md) lists what changed.

## Operating securely

Without sign-in, anyone who can reach the app can read and change all data.
How to protect an installation — sign-in, VPN, reverse proxy, HTTPS, web server
rules — is described in
[Security & network operation](docs/en/technical/08-security.md).

---

## Deutsch

### Eine Sicherheitslücke melden

Bitte für ein Sicherheitsproblem **kein** öffentliches Issue eröffnen, sondern
vertraulich über GitHub melden:
**[Security → Report a vulnerability](https://github.com/Bingerminger/energietracker/security/advisories/new)**.

Hilfreich sind:

- die betroffene Version (Datei `VERSION` oder die Anzeige in der Kopfleiste),
- die Betriebsart (Docker, Apache/Synology, andere) und ob die Anmeldung
  eingeschaltet ist,
- die Schritte zum Nachstellen und was ein Angreifer damit erreicht.

Der Energietracker wird in der Freizeit gepflegt; Meldungen werden so schnell
wie möglich bearbeitet. Bitte vor einer Veröffentlichung Zeit für eine
Korrektur lassen.

### Unterstützte Versionen

Korrekturen erscheinen im jeweils neuesten Release. Bitte vor einer Meldung
aktualisieren — was sich geändert hat, steht im [CHANGELOG](CHANGELOG.md).

### Sicher betreiben

Ohne Anmeldung kann jeder, der die App erreicht, alle Daten lesen und ändern.
Wie man eine Installation schützt — Anmeldung, VPN, Reverse-Proxy, HTTPS,
Webserver-Regeln —, steht in
[Sicherheit & Netzbetrieb](docs/technical/08-security.md).
