# ToolPWA Complete Website v3.0

Built from the supplied ToolPWA HTML design direction, but converted into a reusable PHP website with a true root homepage, category pages, multiple working tools per category, category-level PWA manifests/service workers, and an admin panel.

## Current URL structure

Install this folder as `/toolpwa/`:

- `/toolpwa/` — Root ToolPWA homepage
- `/toolpwa/calculators/`
- `/toolpwa/calculators/bmi-calculator/`
- `/toolpwa/text-tools/`
- `/toolpwa/image-tools/`
- `/toolpwa/developer-tools/`
- `/toolpwa/converters/`
- `/toolpwa/security-tools/`
- `/toolpwa/admin.php`

## Included tools

6 categories, 4 tools each (24 total): Calculators, Text Tools, Image Tools, Developer Tools, Converters, Security Tools.

## PWA

Each category has a generated manifest and service worker. The installed app starts at the category URL and caches that category's shell/tools.

## Admin

Default first-install credentials: `admin` / `ChangeMe123!`. Change the password immediately.

Admin controls categories, tools, tool type, publishing, SEO content, FAQs and ads.

## Subdomain later

For `calculator.example.com`, point the subdomain document root at this folder and set `BASE_PATH` to `''`. The category then becomes the subdomain root without changing tool code.

## Tool grouping rule for future additions

When adding new tools, follow this rule:

1. Keep the overall site **category-based** and each category installable as its own mini PWA.
2. If multiple functions use the same input/data and the same primary workflow, **combine them into one mini-app** with tabs/options instead of creating separate apps.
3. Keep tools separate when they have a genuinely different workflow, input model, or user purpose, even if they belong to the same category.
4. Do not create duplicate apps merely because the output/result differs when the underlying workflow is the same.
5. Before adding a new tool, check the existing mini-apps first; add a new function to an existing mini-app when it naturally fits.

Examples: image resize + crop + rotate + flip → one Image Editor; all measurement conversions → one Unit Converter; JSON formatting + JSON conversions → one JSON Toolkit.

## Current architecture

ToolPWA uses category-based mini PWAs. Closely related functions are grouped into focused mini-apps when they share the same input/data and workflow. Do not create separate apps for functions that naturally belong together.

## Result actions

Generated results should expose Copy, Share and/or Download whenever applicable. Text and data results use a consistent action bar; file-producing tools should download the actual generated file.


## ToolPWA shared design system (v2.7)

All public pages use one shared visual system from `assets/design-system.css`. It centralizes colors, spacing, radii, shadows, inputs, buttons, cards, workspaces, result actions, responsive behaviour and toast notifications.

### Theme rule
The default mode is `System` and follows `prefers-color-scheme`. Manual `Dark` or `Light` selection is stored as `toolpwa-theme` in `localStorage` and overrides the system preference.

### Permanent grouping rule
Do not split closely related functions into separate mini-apps when they share the same input/data and workflow. Put those functions into a suite and expose them as tabs/functions using `?tab=` within the suite URL.

### Result action rule
Whenever a result can reasonably be copied, shared or downloaded, use the shared `ResultActions` behaviour. Generated files must download the actual file rather than placeholder status text.

### Browser-first rule
Keep private/local processing client-side whenever technically practical. The category service worker caches the category shell/tools for offline use; it does not upload or intentionally cache user files.


# ToolPWA v2.8 UX Architecture Applied

This build applies the utility-directory redesign across the shared platform layer.

- Six core discovery buckets on the homepage: Bangladesh Utilities, Image Tools, Text & String, Fast Calculators, Security & Crypto, Developer & System.
- Universal three-zone tool canvas: micro-navigation, functional workspace, contextual/ads rail.
- Global fuzzy search with `/` keyboard shortcut.
- Local favorites and recently-used tool history via localStorage.
- Unified dense cards, controls, result actions, theme tokens, responsive mobile collapse, and related-tool recommendations.
- SEO content remains below the functional workspace.
- Existing tool URLs and browser functionality are preserved.


## BDIX Server Directory (v2.9.1)

The Bangladesh Tools category now includes **BDIX Server Tools** with two functions:

- **BDIX Server Finder** — searches the administrator-managed server directory by name, URL, location, ISP and tags.
- **BDIX Server Checker** — checks the reachability of a registered server URL and reports HTTP status and response time.

### Adding BDIX server URLs

Open `admin.php` → **BDIX Servers**. Add the server name, full `http://` or `https://` URL, location, ISP/network and tags, then publish it. The public checker only tests URLs that are stored in this directory.

For production, admin authentication is enabled. The first-install default credentials remain `admin / ChangeMe123!`; change the password immediately from **Settings**.


## BDIX UI/UX 2.9.1
- Modernized BDIX Finder and Checker interface.
- Clear Reachable / Not reachable / Could not verify states.
- Browser-side test progress and responsive controls.
- Mobile-first server cards and clearer trust/help messaging.
- Theme stability overrides for light/dark modes.


## ToolPWA UI v3.0

The UI layer was upgraded without changing the existing tool routing or tool logic.

- Added `assets/ui-v3.css` as a dedicated final UI layer instead of rewriting legacy styles.
- Added semantic layout hooks: `site-header`, `nav-inner`, `brand`, `primary-nav`, `header-actions`, `mobile-primary-nav`, `site-footer`.
- Desktop layout now scales from 4 to 5 tool columns on wide screens and uses a three-zone tool workspace.
- Tablet layouts collapse navigation and side rails before content becomes cramped.
- Mobile layouts use horizontal category navigation, single-column tools, larger touch targets and simplified workspace controls.
- Added reusable tokens for spacing, radii, borders, focus states, motion and layout widths.
- Improved cards, search, buttons, forms, workspace chrome, category panels, suite tabs and footer.
- Added reduced-motion support and stronger keyboard focus states.
- Existing v2.x classes remain in place for compatibility, making the UI layer safer to extend.
