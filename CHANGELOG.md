# Changelog

## 0.3.2
- Scripts async laden: met `defer:` voor een regel krijgt een script `defer` i.p.v. `async` (veilig voor scripts die de DOM nodig hebben, zoals de TranslatePress-taalwisselaar).

## 0.3.1
- Ingebouwde GitHub-updater (Plugin Update Checker 5.4, branch `main`): updates verschijnen gewoon bij Plugins, zonder Git Updater.

## 0.3.0
- "Scripts async laden" werkt nu ook voor scripts die hard in de `<head>` staan (Elementor Custom Code, header-codeplugins, thema), bijv. `consent.js` van CookieFirst. Scripts met `async`, `defer` of `type="module"` blijven ongemoeid; alleen de `<head>` wordt aangepast.

## 0.2.1
- Async CSS werkt nu ook voor stylesheets met een media-query (Elementor v4/Atomic breakpoint-bestanden zoals `base-mobile.css`); media-query blijft behouden. Alleen print-CSS wordt overgeslagen.

## 0.2.0
- Niet meer vast aan één pagina/site: instellingen per pagina via meta-box, algemene opties voor geselecteerde pagina's of hele site.
- Critical CSS per pagina; overige CSS dan asynchroon.
- LCP-preload apart voor mobiel en desktop (Elementor-breakpoint), of via media-ID met `imagesrcset`.
- jQuery Migrate verwijderen, jQuery naar footer.
- Preconnect, font-preload, async voor gekozen scripts, emoji uitschakelen.
- Bypass met `?dwma=off`, nooit actief in Elementor-editor/preview.
- Waarschuwing bij SiteGround Combine CSS.
- `www`/zonder-`www` geaccepteerd bij eigen-domeincontrole.
- `uninstall.php`, GitHub-headers voor Git Updater.

## 0.1.0
- Eerste versie, alleen voor `/homepage-nieuw-concept-geo/` op danastraveldimensions.nl.
