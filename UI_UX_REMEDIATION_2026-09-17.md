# UI/UX Remediation — 2026-09-17

This change set addresses the findings in `UI_UX_REVIEW_2026-09-17.md`.

## Implemented

- Vendored a browser ESM bundle of `esptool-js@0.5.4` and its runtime dependencies under `public/assets/vendor`; removed jsDelivr from runtime code and CSP.
- Replaced hidden-iframe downloads with an awaited same-origin request, explicit JSON error handling, Blob download, filename handling, and object-URL cleanup.
- Made dashboard startup section-independent with `Promise.allSettled` and visible settings/repository/build failure states.
- Added explicit iPhone/iPad detection and honest Web Serial messaging; flashing is disabled there while artifact download remains available.
- Added `viewport-fit=cover` and horizontal/bottom safe-area accommodations.
- Added consistent keyboard focus rings, larger operational touch targets/text on mobile, named/associated dialogs, application-dialog initial focus and focus restoration.
- Added scoped live status regions for repository/build availability and refresh failures.
- Replaced fixed two-second polling with active/idle intervals and exponential failure backoff; active builds still refresh every two seconds.
- Implemented `.bin` drag-and-drop with validation and visual feedback.
- Added reduced-motion handling.
- Replaced placeholder feature links with real page anchors.
- Removed external Google Fonts requests and narrowed CSP to same-origin fonts/styles/scripts. System UI fonts preserve fast, native rendering on Windows, Linux, Android, macOS, and iOS.
- Added narrow-screen and safe-area bottom-navigation hardening.

## Notes

- Web Serial availability still depends on browser, OS, USB host capability, cable, and device drivers. It is not available in iOS/iPadOS browsers.
- Artifact downloads remain capped and archive-inspected server-side. The browser now receives actionable errors rather than relying on iframe document behavior.
- The vendored flasher bundle includes a generated legal notice alongside the bundle.
