# ToolPWA v2.4.0 — Combined Tool Suites

ToolPWA now groups closely related browser tools into one canonical tool page per category.

## Suites
- calculators → `/calculators/calculator-suite/` — 14 functions
- text-tools → `/text-tools/text-toolkit/` — 18 functions
- image-tools → `/image-tools/image-toolkit/` — 13 functions
- developer-tools → `/developer-tools/developer-toolkit/` — 22 functions
- converters → `/converters/unit-converter/` — 12 functions
- security-tools → `/security-tools/security-toolkit/` — 9 functions
- bd-tools → `/bd-tools/bangladesh-toolkit/` — 7 functions
- browser-utilities → `/browser-utilities/browser-utility-suite/` — 5 functions

## URL behavior
Each category has one canonical suite URL. Functions are selected using a `?tab=` parameter on that suite page. Legacy individual tool URLs return a 301 redirect to the corresponding suite/tab.

Example:
`/calculators/percentage-calculator/` → 301 → `/calculators/calculator-suite/?tab=percentage-calculator`

This reduces the number of standalone tool pages while keeping old links working.
