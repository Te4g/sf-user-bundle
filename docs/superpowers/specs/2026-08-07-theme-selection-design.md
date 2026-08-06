# Theme Selection Design

## Status

Approved in conversation on 2026-08-07.

## Goal

Allow users of the generated Symfony application to choose System, Light, or
Dark appearance from every page header. System is the initial default and
follows the device color-scheme preference whenever the browser exposes it.

## Scope

The feature belongs entirely to the generated application. It adds no account
field, database migration, server request, runtime dependency, or starter
configuration option.

The selector is present in the public, authentication, and authenticated
headers. It uses a native select labelled Theme with three values:

- System;
- Light;
- Dark.

The selected preference is local to the browser. It is not synchronized
between devices or attached to an authenticated account.

## Browser Behavior

A small application-owned script loads in the document head before the main
stylesheet. Before the page paints, it reads the saved preference and resolves
the effective theme:

- a saved Light preference always resolves to light;
- a saved Dark preference always resolves to dark;
- System, a missing preference, or an invalid preference resolves through
  `prefers-color-scheme: dark`;
- when `matchMedia` is unavailable, System resolves to light.

The script applies the existing `.dark` class to the root element only when the
effective theme is dark. It also sets the root `color-scheme` property so native
browser controls use the effective palette.

Changing the select saves the new preference and applies it immediately. While
System is selected, a live device color-scheme change updates the page without
a reload. Explicit Light or Dark preferences ignore device changes.

Turbo Drive may replace header markup without reloading the document head. The
theme behavior therefore uses document-level change handling and synchronizes
every theme selector after initial page readiness and each Turbo page load.

## Components and Assets

The generated application adds one reusable `ThemeSelector` Twig component.
Each of the three layouts renders that component in its header. The component
uses the existing shadcn-inspired tokens and compact responsive styling; it
does not add an icon or third-party UI component.

The theme script is a standalone application asset referenced by the base Twig
template before `styles/app.css`. The existing CSS already contains the light
tokens, `.dark` tokens, and Tailwind dark variant, so no palette redesign is
required.

## Resilience and Accessibility

The native select has an associated accessible label and exposes the active
preference directly. It remains usable with a keyboard and screen reader
without custom menu behavior.

Browser storage reads and writes may fail in restricted environments. The
script catches those failures, continues with System behavior, and keeps the
page interactive. Unknown stored values are treated as System. No theme error
is sent to the server or shown to the user because a safe visual fallback is
always available.

## Testing

A focused JavaScript behavior test exercises the real theme asset with browser
API fakes. It proves:

- a first visit follows a light or dark device preference;
- saved Light and Dark preferences override the device;
- changing the selector persists and immediately applies the choice;
- returning to System resumes device-following behavior;
- device changes are applied only while System is active;
- blocked browser storage and missing `matchMedia` fall back safely.

PHP resource tests verify that the selector component is included in all three
layouts and that the theme script is loaded before the stylesheet. Existing
Twig, JavaScript syntax, generated-resource, static-analysis, and full PHPUnit
checks remain required.

## Acceptance Criteria

- Every generated page header provides System, Light, and Dark choices.
- A browser with no saved choice follows its current device preference.
- The explicit choice survives navigation, reloads, and later visits in the
  same browser.
- System reacts to device theme changes without reloading.
- The correct theme is applied before the stylesheet renders.
- The feature remains functional when browser storage is unavailable.
- The generated application gains no persistence or server-side theme state.
