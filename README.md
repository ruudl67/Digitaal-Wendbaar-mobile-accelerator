# Digitaal Wendbaar Mobile Accelerator

Gerichte mobiele snelheidsoptimalisaties voor WordPress-sites met Elementor. Werkt naast SiteGround Speed Optimizer en lost op wat SG Optimizer niet doet: critical CSS, LCP-preload van achtergrondafbeeldingen en het weghalen van jQuery uit het render-blokkerende pad.

Alle opties staan standaard **uit**. Zonder instellingen verandert er niets aan de front-end.

## Wat het doet

| PageSpeed-melding | Optie in de plugin |
|---|---|
| Verzoeken voor renderblokkering (Elementor CSS) | Critical CSS per pagina → overige CSS async |
| Verzoeken voor renderblokkering (jQuery, Migrate) | jQuery naar footer, jQuery Migrate verwijderen |
| Verzoeken voor renderblokkering (derden, bijv. CookieFirst) | Preconnect, optioneel async |
| LCP-verzoekdetectie: niet vindbaar / geen fetchpriority | LCP-preload mobiel + desktop met `fetchpriority="high"` |
| Lettertypeweergave | Font-preload (woff2) |

## Installatie

1. Download de zip van de laatste release (of `Code → Download ZIP`).
2. WordPress → Plugins → Nieuwe plugin → Plugin uploaden → activeren.
3. Instellingen → **DW Mobile Accelerator**: plugin inschakelen.
4. Open de pagina in WordPress (niet in Elementor) → blok **DW Mobile Accelerator** onder de editor → aanzetten, LCP-URL en critical CSS invullen.
5. SiteGround-cache legen, PageSpeed mobiel 3× meten. Vergelijk met `?dwma=off`.

**Updates via GitHub:** ingebouwd. Nieuwe versies op de branch `main` verschijnen vanzelf als update bij Plugins (Plugin Update Checker). Git Updater is niet nodig.

## Aanbevolen volgorde per site

1. **Elementor:** Motion Effects op het LCP-element uitzetten voor mobiel; per apparaat een kleinere achtergrondafbeelding instellen.
2. **SiteGround Speed Optimizer:** Minify CSS/JS aan, *Combine CSS uit* op pagina's met critical CSS, WebP aan.
3. **Plugin:** jQuery Migrate weg → testen. jQuery naar footer → testen.
4. **Plugin:** LCP-URL (mobiel) invullen uit PageSpeed → "LCP-verzoekdetectie".
5. **Plugin:** critical CSS genereren (bijv. met een online critical-CSS-generator voor de mobiele weergave) en plakken.

## Critical CSS

Zodra een pagina critical CSS heeft, worden **alle** stylesheets op die pagina asynchroon geladen (behalve `admin-bar`, `dashicons` en wat je bij *Uitsluiten* invult). Ontbreekt er iets in de critical CSS, dan zie je bij het laden kort ongestylede inhoud of verspringing (CLS). Vul de critical CSS dan aan of sluit de betreffende handle uit.

Pas je de bovenkant van de pagina aan in Elementor, genereer dan de critical CSS opnieuw.

## Beperkingen

- Async-scripts werken voor scripts in de `<head>`, ook hardgecodeerde; scripts in de body worden niet aangepast.
- Async CSS gebruikt een `onload`-handler; een strikte Content-Security-Policy kan dat blokkeren.
- Bij SiteGround *Combine CSS* werkt async per handle niet; de plugin toont dan een waarschuwing.
- De plugin genereert zelf geen critical CSS.

## Terugdraaien

Vinkje *Plugin inschakelen* uit, of per pagina uit. SiteGround-cache legen. Bij verwijderen ruimt `uninstall.php` alle instellingen op.

## Licentie

GPL-2.0-or-later
