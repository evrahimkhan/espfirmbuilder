# ESPForge UI/UX and Cross-Device Review

**Reviewed:** 2026-09-17  
**Branch:** `arena/01a07f43-espfirmbuilder`  
**Scope:** landing/authentication, dashboard navigation, repositories, Live Builds, Web Flasher, settings, responsive CSS, accessibility, browser/platform behavior, and security-related frontend delivery.

## Executive summary

The interface has a coherent visual system and already contains meaningful responsive work: desktop sidebar becomes a mobile bottom bar, dashboard grids collapse, build cards reflow, dialogs are custom rather than native popups, active pages persist, the flasher terminal is bounded and scrollable, and safe-area CSS is partly present. Desktop Chrome/Edge and typical Android Chrome should be usable.

It is not yet equally reliable or clear on iOS, narrow/landscape phones, keyboard-only desktop use, or failure-prone mobile networks. The largest UX risks are runtime CDN dependence for flashing, unreliable hidden-iframe download errors, misleading Web Serial guidance on iOS, and all-or-nothing dashboard startup. Accessibility also needs a deliberate pass: labels/names, focus visibility, dialog semantics, status announcements, target sizes, and very small text.

**Findings:** 0 critical, 3 high, 8 medium, 6 low.

## What is working well

- Responsive breakpoints at 850, 650, 600, and 430 px cover common phone/tablet transitions.
- Desktop sidebar becomes a persistent bottom navigation on mobile.
- `env(safe-area-inset-bottom)` is used for bottom navigation and page padding.
- Main grids collapse to one column; repository/build actions wrap rather than intentionally overflow.
- Live Builds is the last primary navigation item, active pages persist in the URL hash/local storage, and completed/active build groups are separated.
- Active build cards are compact by default and expose View/Minimize and Cancel.
- Build durations update without a misleading `mm.ss` legend.
- Native `alert`, `confirm`, and `prompt` are absent; destructive operations use custom dialogs.
- Backdrop click and close controls are implemented for authentication, target selection, and application dialogs.
- Flash logs use a fixed/limited height and scrolling rather than expanding with output.
- Dynamic repository, target, job, and build strings are HTML-escaped before insertion.
- Security headers, frame denial, HSTS, nosniff, and a restrictive CSP are configured.

## High findings

### H1. Browser flashing depends on third-party executable code at runtime

`dashboard.js` imports `esptool-js@0.5.4` from jsDelivr only after the user starts flashing. Its transitive browser modules are also allowed in the CSP. A CDN outage, blocked network, package-delivery change, CSP/browser discrepancy, or privacy filter can make a core product feature fail after the user has selected a file and connected hardware. It also weakens the trust boundary for firmware flashing.

**Affected:** Web Flasher on PC/Android; especially corporate networks, privacy browsers, and intermittent mobile connections.

**Recommendation:** vendor a reviewed, pinned bundle and its transitive dependencies under `public/assets/vendor`; serve it from the application origin; record hashes/version/license; remove jsDelivr from `script-src`. Prefer a build-time bundle rather than browser-time dependency resolution.

### H2. Artifact/error downloads use a hidden iframe and cannot deliver errors reliably

`downloadBuild()` posts to a hidden iframe, then attempts to inspect/parse its body on load. Download responses, redirects, browser download handling, malformed HTML, network failures, and some mobile browser behavior do not reliably produce a readable iframe document. The iframe is retained for three minutes and the user may receive no feedback.

**Affected:** artifact ZIP and error-log downloads on Safari/iOS, mobile Chrome, constrained networks, and expired GitHub artifact cases.

**Recommendation:** request a short-lived same-origin download ticket through JSON first. Surface JSON errors in the custom dialog, then navigate to a GET download URL. Alternatively fetch the bounded response as a Blob and save it, with explicit HTTP/error handling and an iOS-compatible fallback.

### H3. Dashboard initialization is unnecessarily all-or-nothing

`load()` starts settings, projects, builds, and flashes requests concurrently, but awaits settings outside a local error boundary. If settings fails, repository/build/flash rendering is never reached even if those requests succeeded. The outer handler only logs to the developer console. On mobile networks this can leave stale zeros and “Loading…” with no recovery UI.

**Affected:** all platforms; higher frequency on mobile and transient shared-hosting failures.

**Recommendation:** use `Promise.allSettled`, render every successful section independently, show an inline/custom retry state for each failed section, and reserve authentication redirect behavior for 401 only.

## Medium findings

### M1. iOS/Web Serial limitation is not stated explicitly

The flasher says “Requires Chrome or Edge” and the runtime failure says to use Chrome or Edge over HTTPS. On iPhone/iPad, changing to Chrome or Edge does not add Web Serial because iOS browsers use the platform WebKit engine and Web Serial is unavailable. This guidance sends users in a loop.

**Recommendation:** detect iOS/iPadOS (including desktop-mode iPad) and show: browser flashing is not supported on iPhone/iPad; download the artifact and use a supported desktop/Android flashing route. Disable the Connect button with a visible explanation rather than waiting for a click.

### M2. iOS safe-area support is incomplete

CSS uses `env(safe-area-inset-bottom)`, but both HTML documents omit `viewport-fit=cover`. Consequently safe-area values may not behave as intended in standalone/full-screen/notched iOS layouts. There are no top/left/right safe-area accommodations.

**Recommendation:** use `width=device-width, initial-scale=1, viewport-fit=cover`; test portrait and landscape on notched iPhones; apply side insets to the bottom bar in landscape.

### M3. Keyboard focus is not visibly designed

Interactive elements rely heavily on hover/background rules and browser defaults; there is no coherent `:focus-visible` treatment. Some controls remove borders/backgrounds, making default focus hard to see against white or dark surfaces.

**Recommendation:** add a high-contrast global focus-visible ring with offsets for buttons, links, inputs, selects, drop zone, and dialog controls. Verify tab order and focus restoration after dialogs close.

### M4. Dialog semantics and focus management are incomplete

Native `<dialog>` provides a good base, but dialogs lack `aria-labelledby`/`aria-describedby`, the target close button has no accessible name, and application dialog state is not protected against a second call replacing the outstanding resolver. There is no deliberate initial focus or restoration to the invoking control.

**Recommendation:** add accessible names/relationships, `aria-label="Close"`, retain the invoker, focus the safest action, restore focus on close, and serialize dialog requests.

### M5. Several controls and labels are too small for touch and low-vision use

The interface commonly uses 9–10 px text and action buttons with roughly 7–10 px vertical padding. Build actions, repository actions, target metadata, helper text, status labels, and bottom-nav captions can fall below comfortable mobile readability and the recommended approximately 44×44 CSS-pixel touch target.

**Recommendation:** use at least 12 px for secondary operational text and 14–16 px for body/form text; enforce a 44 px minimum touch target while preserving compact visual styling via internal layout.

### M6. The advertised drop zone does not implement drag and drop

The label says “Drop a .bin file here,” but only file-input `change` handling exists. There are no dragenter/dragover/drop handlers or visual drag state.

**Recommendation:** either implement accessible drag/drop with validation and keyboard-equivalent browse behavior, or change the copy to “Choose a .bin file.”

### M7. Status/error announcements are inconsistent for assistive technology

Only the AI key status has `aria-live`. Build status changes every two seconds, flash progress, repository errors, target errors, and settings save results update visually without dependable announcements. Conversely announcing every polling update would be noisy.

**Recommendation:** add carefully scoped `role=status`/`aria-live=polite` regions for user-initiated outcomes and major build state transitions; avoid announcing unchanged poll results.

### M8. Polling is aggressive for mobile data/battery and has no backoff

Live Builds refreshes every two seconds whenever the Builds tab is active and the document is visible. This meets low-delay expectations but does not back off after repeated failures, when no build is active, or on constrained connections.

**Recommendation:** retain two-second polling only while an active build exists; use a slower interval for idle history and exponential backoff with a visible stale/offline indicator after errors.

## Low findings

### L1. External fonts remain a third-party availability/privacy dependency

Google Fonts is allowed by CSP and used by both pages. Failure falls back safely, but causes layout/visual changes and adds an external request.

**Recommendation:** self-host WOFF2 subsets with `font-display: swap` and remove Google font origins from CSP.

### L2. Mobile bottom navigation has limited horizontal/landscape resilience

Five destinations share the bottom bar; labels use `white-space: nowrap`. Very narrow screens, larger system text, translated labels, and landscape safe areas can clip captions.

**Recommendation:** test at 320 px and 200% text, permit controlled two-line labels or an accessible More pattern, and account for left/right safe-area insets.

### L3. No reduced-motion preference is defined

Smooth scrolling is always enabled on the landing page. Current motion is limited, but user preferences should still be respected.

**Recommendation:** add `@media (prefers-reduced-motion: reduce)` and disable smooth scrolling/transitions/animations.

### L4. Dynamic greeting is static copy

The dashboard initially says “Good morning,” then immediately changes the title to “Workspace overview.” The initial copy can flash and is incorrect by time zone before JavaScript runs.

**Recommendation:** make server/static copy neutral or compute a localized greeting once without immediately replacing it.

### L5. Landing-page feature links are placeholders

“Explore AI builds,” “See build monitoring,” and “Discover web flashing” link to `#`, moving users to the top rather than providing information or opening authentication.

**Recommendation:** connect them to real sections/actions or render non-link explanatory elements.

### L6. Source maintainability raises regression risk

Dashboard CSS is effectively compressed into two very long lines and dashboard JavaScript is densely packed. This does not directly break users, but makes responsive/a11y review and safe changes harder and contributed to brittle behavior-specific assertions.

**Recommendation:** format source files, add linting/format checks, and use minified output only as a generated deployment artifact.

## Device/browser assessment

| Environment | Current assessment | Main caveats |
|---|---|---|
| Windows/macOS/Linux desktop Chrome/Edge | Generally usable | CDN flashing dependency; keyboard focus; download errors |
| Desktop Firefox | Dashboard/build management usable | Web Serial unavailable; guidance should provide fallback |
| Android Chrome with USB OTG | Potentially usable | Hardware/browser support varies; small controls; polling/data usage |
| Android Firefox/privacy browsers | Dashboard usable | Web Serial/CDN may be unavailable or blocked |
| iPhone/iPad Safari | Dashboard mostly usable | Web Serial unavailable; current guidance is misleading; safe-area setup incomplete |
| iOS Chrome/Edge | Dashboard mostly usable | Still no Web Serial; switching iOS browser does not solve it |
| Tablets/compact laptops | Responsive layout likely usable | 850 px mode switch can produce dense bottom navigation |
| Keyboard/screen-reader desktop | Partially usable | focus visibility, names, dialog focus, and live status semantics need work |

## Recommended remediation order

1. Vendor the flasher module and remove runtime third-party scripts.
2. Replace iframe downloads with a JSON ticket plus reliable download flow.
3. Make dashboard startup independently resilient with visible retry states.
4. Add explicit iOS/Web Serial capability messaging and artifact-download fallback.
5. Complete `viewport-fit=cover` and portrait/landscape safe-area behavior.
6. Perform accessibility pass: focus-visible, dialog labels/focus, live regions, touch sizes, readable type.
7. Make polling state-aware with backoff.
8. Implement real drag/drop or correct its copy.
9. Self-host fonts and clean placeholder links.
10. Format frontend source and add automated HTML/a11y/responsive smoke checks.

## Suggested acceptance matrix

Before calling the UI cross-platform complete, test at minimum:

- 320×568, 360×800, 390×844, 430×932 phone viewports.
- iPhone portrait/landscape with safe areas and 200% text.
- iPad portrait/landscape and split view.
- 768, 850, 1024, 1280, and 1920 px desktop/tablet widths.
- Chrome/Edge desktop Web Serial success, wrong-chip rejection, cancellation, and CDN-offline simulation after vendoring.
- Safari/iOS capability fallback with no claim that another iOS browser will enable Web Serial.
- Keyboard-only navigation through every page and dialog.
- Screen-reader names for navigation, close buttons, statuses, build actions, and settings.
- Slow/offline API startup, failed settings with successful projects/builds, expired artifact, and download-network failure.
