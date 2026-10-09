# Credits & third-party notices · Danksagungen und Hinweise zu Drittanbietern

**English** · [Deutsch](#deutsch)

The Energietracker itself — PHP, JavaScript, CSS and documentation — is
written for this project and licensed under the
[GNU AGPL v3.0 or later](LICENSE). Versions up to 2.16.0 were published under
the MIT licence and remain available under it.

## Shipped with the application

| Component | Licence | Where |
|---|---|---|
| [Chart.js](https://www.chartjs.org/) 4.5.1 | MIT | `public/vendor/chart.umd.min.js`, licence in [`public/vendor/chart.js-LICENSE.md`](public/vendor/chart.js-LICENSE.md) |
| DM Sans and DM Mono (Colophon Foundry) | SIL Open Font License 1.1 | `public/vendor/fonts/`, licence in [`public/vendor/fonts/OFL.txt`](public/vendor/fonts/OFL.txt) |

Both are vendored so that the app works without internet access; nothing is
loaded from a CDN at runtime.

## Services used at runtime

### Open-Meteo

Daily temperatures, the climate normal and the place search come from
**[Open-Meteo](https://open-meteo.com/)**. The data is licensed under
[Creative Commons Attribution 4.0 (CC BY 4.0)](https://creativecommons.org/licenses/by/4.0/);
the app names the source below the temperature chart and in the annual report.
The place search draws on [GeoNames](https://www.geonames.org/) (CC BY 4.0).
Open-Meteo is contacted only by the server, for the weather sync (which can be
switched off) and when you search for a place.

### SMARD (Federal Network Agency)

Since v3.1.0 the dynamic tariff check uses monthly day-ahead wholesale prices
for Germany/Luxembourg: source **Bundesnetzagentur | SMARD.de**, licensed under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). The app names the
source next to the prices. SMARD is contacted only when you tap “Load from
SMARD”; you can import a SMARD download file instead. Since v3.2.0 the example
household “Own house with heat pump and solar” (`demo-data/personas/`) ships
these monthly averages for 2023 to 2026, from the same source.

## Development only — not shipped

| Tool | Licence | Purpose |
|---|---|---|
| [PHPUnit](https://phpunit.de/) | BSD-3-Clause | backend tests (`composer install` in development and CI) |
| [jsdom](https://github.com/jsdom/jsdom) | MIT | browser render tests in CI |

## Defaults and reference values

The default CO₂ factors follow the published emission factors of the German
Federal Office for Economic Affairs and Export Control (BAFA) and the German
Environment Agency (UBA); efficiency classes follow the German Building
Modernisation Act (GModG, called GEG until July 2026). Sources and years are listed in section 8 (CO₂) of the
[calculation overview](docs/en/verstehen/00-overview.md) and in the
[country profiles](docs/en/verstehen/14-laenderprofile.md).

---

<a id="deutsch"></a>

## Deutsch

Der Energietracker selbst — PHP, JavaScript, CSS und Dokumentation — ist für
dieses Projekt geschrieben und steht unter der
[GNU AGPL v3.0 oder neuer](LICENSE). Versionen bis 2.16.0 sind unter der
MIT-Lizenz erschienen und bleiben unter ihr verfügbar.

### Mitgeliefert

| Bestandteil | Lizenz | Ort |
|---|---|---|
| [Chart.js](https://www.chartjs.org/) 4.5.1 | MIT | `public/vendor/chart.umd.min.js`, Lizenz in [`public/vendor/chart.js-LICENSE.md`](public/vendor/chart.js-LICENSE.md) |
| DM Sans und DM Mono (Colophon Foundry) | SIL Open Font License 1.1 | `public/vendor/fonts/`, Lizenz in [`public/vendor/fonts/OFL.txt`](public/vendor/fonts/OFL.txt) |

Beides liegt im Repository, damit die App ohne Internetzugang funktioniert;
zur Laufzeit wird nichts von einem CDN geladen.

### Dienste zur Laufzeit

Tagestemperaturen, Klimanormal und Ortssuche stammen von
**[Open-Meteo](https://open-meteo.com/)** unter
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/); die App nennt die
Quelle unter dem Temperaturdiagramm und im Jahresbericht. Die Ortssuche greift
auf [GeoNames](https://www.geonames.org/) (CC BY 4.0) zurück. Open-Meteo wird
nur vom Server angesprochen: beim abschaltbaren Wetterabgleich und bei der
Ortssuche.

Seit v3.1.0 nutzt der Dynamik-Check die Day-Ahead-Großhandelspreise für
Deutschland/Luxemburg als Monatsmittel: Quelle **Bundesnetzagentur |
SMARD.de**, unter [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). Die
App nennt die Quelle neben den Preisen. SMARD wird nur angesprochen, wenn du auf
„Von SMARD laden“ tippst; stattdessen kannst du eine SMARD-Downloaddatei
importieren. Seit v3.2.0 bringt der Beispielhaushalt „Eigenheim mit Wärmepumpe
und PV“ (`demo-data/personas/`) diese Monatsmittel für 2023 bis 2026 mit, aus
derselben Quelle.

### Nur in der Entwicklung — nicht ausgeliefert

[PHPUnit](https://phpunit.de/) (BSD-3-Clause) für die Backend-Tests und
[jsdom](https://github.com/jsdom/jsdom) (MIT) für die Browser-Tests in der CI.

### Vorgaben und Vergleichswerte

Die voreingestellten CO₂-Faktoren folgen den veröffentlichten
Emissionsfaktoren von BAFA und Umweltbundesamt, die Effizienzklassen dem
Gebäudemodernisierungsgesetz (GModG, bis Juli 2026 GEG). Quellen und Jahre stehen in Abschnitt 8 (CO₂) der
[Rechenübersicht](docs/verstehen/00-overview.md) und bei den
[Länderprofilen](docs/verstehen/14-laenderprofile.md).
