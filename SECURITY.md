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

You will receive an acknowledgement of receipt within **7 days**.
Energietracker is maintained in spare time; reports are handled as quickly as
possible. Please allow time for a fix before publishing details.

## Supported versions

Fixes are made on the latest release. Please update before reporting — the
[CHANGELOG](CHANGELOG.md) lists what changed.

## Container image: scans and deadlines

The image on GHCR (`ghcr.io/bingerminger/energietracker`, also used by the
Home Assistant app) is checked for known vulnerabilities right after every
release and after every weekly rebuild (Grype). Only findings with an
available fix are reported; they appear under Security → Code scanning.

- Every Tuesday GitHub rebuilds the latest release on the current base image
  (Alpine, PHP), with the same tags. Most fixes arrive this way.
- **Critical or high finding with an available fix:** the image is rebuilt
  within **7 days** of the fix being available, at the latest with the next
  weekly rebuild.
- A vulnerability in Energietracker's own code is fixed in a new release.
- For a critical or high finding with a fix, the Home Assistant app also gets
  a new patch version, so that Home Assistant fetches the rebuilt image.

## Checking the image

Since 3.10.0 every published image is signed with
[cosign](https://docs.sigstore.dev/) (keyless: the certificate names the
GitHub workflow that built it, the signature is recorded in the public
transparency log). With cosign 3 or newer:

```bash
cosign verify ghcr.io/bingerminger/energietracker:3 \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  --certificate-identity-regexp '^https://github\.com/Bingerminger/energietracker/\.github/workflows/docker-(publish|rebuild)\.yml@refs/'
```

Every image also carries a software bill of materials (SBOM) and a provenance
attestation (since 2.6.0):

```bash
docker buildx imagetools inspect ghcr.io/bingerminger/energietracker:3 --format '{{ json .SBOM }}'
docker buildx imagetools inspect ghcr.io/bingerminger/energietracker:3 --format '{{ json .Provenance }}'
```

Details: [Docker → Checking signature and SBOM](docs/en/betrieb/docker.md#checking-signature-and-sbom-v3100).

## Operating securely

Without sign-in, anyone who can reach the app can read and change all data.
How to protect an installation — sign-in, VPN, reverse proxy, HTTPS, web server
rules — is described in
[Security & network operation](docs/en/betrieb/sicherheit.md).

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

Du bekommst binnen **7 Tagen** eine Eingangsbestätigung. Der Energietracker
wird in der Freizeit gepflegt; Meldungen werden so schnell wie möglich
bearbeitet. Bitte vor einer Veröffentlichung Zeit für eine
Korrektur lassen.

### Unterstützte Versionen

Korrekturen erscheinen im jeweils neuesten Release. Bitte vor einer Meldung
aktualisieren — was sich geändert hat, steht im [CHANGELOG](CHANGELOG.md).

### Container-Image: Prüfung und Fristen

Das Image auf GHCR (`ghcr.io/bingerminger/energietracker`, auch für die App in
Home Assistant) wird direkt nach jedem Release und nach jedem wöchentlichen
Neubau auf bekannte Lücken geprüft (Grype). Gemeldet werden nur Befunde, für
die es eine Korrektur gibt; sie stehen unter Security → Code scanning.

- Jeden Dienstag baut GitHub das neueste Release auf dem aktuellen
  Basis-Image (Alpine, PHP) neu, unter denselben Tags. Die meisten
  Korrekturen kommen so an.
- **Kritischer oder hoher Befund mit verfügbarer Korrektur:** Das Image wird
  binnen **7 Tagen** neu gebaut, nachdem die Korrektur verfügbar ist,
  spätestens mit dem nächsten wöchentlichen Neubau.
- Eine Lücke im Code des Energietrackers selbst behebt ein neues Release.
- Bei einem kritischen oder hohen Befund mit Korrektur bekommt zusätzlich die
  App in Home Assistant eine neue Patch-Version, damit Home Assistant das neu
  gebaute Image holt.

### Das Image prüfen

Seit 3.10.0 ist jedes veröffentlichte Image mit
[cosign](https://docs.sigstore.dev/) signiert (ohne Schlüssel: Das Zertifikat
nennt den GitHub-Workflow, der das Image gebaut hat, die Signatur steht im
öffentlichen Transparenzprotokoll). Mit cosign 3 oder neuer:

```bash
cosign verify ghcr.io/bingerminger/energietracker:3 \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  --certificate-identity-regexp '^https://github\.com/Bingerminger/energietracker/\.github/workflows/docker-(publish|rebuild)\.yml@refs/'
```

Jedes Image trägt außerdem eine Stückliste (SBOM) und einen
Herkunftsnachweis (seit 2.6.0):

```bash
docker buildx imagetools inspect ghcr.io/bingerminger/energietracker:3 --format '{{ json .SBOM }}'
docker buildx imagetools inspect ghcr.io/bingerminger/energietracker:3 --format '{{ json .Provenance }}'
```

Einzelheiten: [Docker → Signatur und Stückliste prüfen](docs/betrieb/docker.md#signatur-und-stückliste-prüfen-v3100).

### Sicher betreiben

Ohne Anmeldung kann jeder, der die App erreicht, alle Daten lesen und ändern.
Wie man eine Installation schützt — Anmeldung, VPN, Reverse-Proxy, HTTPS,
Webserver-Regeln —, steht in
[Sicherheit & Netzbetrieb](docs/betrieb/sicherheit.md).
