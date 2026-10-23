# dartpraat-site

Podcastsite uit RSS als template (Creation HQ, niet Makelab). Lees README.md.

- Inhoud wijzigen = `public/site.json` (+ beelden in `public/assets/img/`), geen code.
- Alles wat de server nodig heeft staat in `public/` (de uitrol zet alleen die map neer); schrijven alleen in `../cache`.
- PHP 8.1-compatibel houden (geen readonly classes, `json_validate`, typed constants). Geen framework, geen build.
- Alles uit de feed escapen met `e()`; nooit ruwe feed-HTML in de pagina. Geen externe scripts/fonts (CSP `'self'`).
- Tests: `npm test` (verplicht groen), browser: `npm run test:e2e`.
- Niet doen: DNS, Hetzner/Virtualmin, contact met Dartpraat.
