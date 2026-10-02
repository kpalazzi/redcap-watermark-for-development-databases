# Change Log
All notable changes to the REDCap Watermark for Development Databases will be documented in this file.


## [1.1.0] - 2026-10-02
### Added
 - System setting **Apply watermark to**: all projects (default) or new projects only. It sits directly under REDCap's built-in "Enable module on all projects by default" checkbox (via the `redcap_module_configuration_settings` hook) and only appears, and only applies, when that box is ticked. "New projects only" records a cut-off when saved; only projects created after it show the watermark. Switching back to "All" or unticking the box clears the cut-off.
 - System setting **Increase opacity as a development project fills up**, with "Start increasing at" (default 20) and "Reach maximum at" (default 30) record counts. Opacity rises linearly from the project opacity to 5 × the project opacity (capped at 1.0). Invalid thresholds are rejected on save.
 - **Watermark text size** setting in pt (default 90pt = the original 120px; limited to 10–300).
 - **Watermark colour picker**. The colour code box now also accepts `rgb()` / `rgba()` and overrides the picker when filled in; the picker and code box stay in step both ways (typing a valid code updates the picker; choosing in the picker fills in the code).
 - Site-wide **default appearance** (text, colour picker, colour code, opacity, text size) in the Control Center. Projects see these values pre-filled in their own Configure dialog and can change them (uses the framework's `allow-project-overrides`).
 - System setting **Allow project-level settings to override these defaults** (default on). When off, all projects use the Control Center values, the project Configure button is hidden from non-admins, and the appearance fields are removed from the project dialog. The "(this setting can be overridden on each project)" labels show and hide live with the checkbox.
 - With **New projects only**, existing projects can opt in with a **Show the watermark on this project** checkbox in their Configure dialog (shown only on projects created before the cut-off, only under "New projects only"; pre-ticked until the project saves a choice). Projects that enable the module on the project itself are also included.
 - Site defaults are seeded on install/upgrade, and again (for any missing value) whenever an admin opens Control Center > External Modules, so the Control Center and project dialogs never open blank. This needs `enable-every-page-hooks-on-system-pages`.
 - **Project limits** so project overrides can't make the watermark practically invisible: minimum letters/digits in the text (default 4; invisible characters don't count), smallest text size (default 48pt) and lowest opacity (default 0.1), all set in the Control Center. Breaking values are rejected when the project config is saved, and fall back field by field to the Control Center value if already stored.
 - Project Configure dialog flags problems as you type or pick a colour, and its labels show this site's limits (e.g. "(pt, 48 – 300)").
### Fixed
 - A project colour that's the site colour written differently (the picker saves lowercase hex; the code box is filled in from the picker) no longer counts as a project override, and is removed when the project dialog is saved, so the project keeps following the site colour.
 - On tall windows the watermark no longer sits below the content: its centre is halfway down the window but at most 450px from the top (unchanged from v1.0.0 on windows up to ~900px tall).
 - Watermark placement: centred over the content beside the left menu instead of at 40% of the window, so it no longer drifts away from the form on wide screens. Text shrinks to fit small screens, so it no longer runs off the edge on tablets; the size setting is now a maximum.
### Changed
 - Minimum REDCap version is now 14.1.6.
 - The appearance settings moved from project settings to overridable Control Center settings with the same keys. Values saved on projects under v1.0.0 are kept as project overrides (no automatic cleanup), so those projects don't follow later changes to site defaults until reset.
 - Default opacity is now 0.1 (was 0.2). Projects that already saved an opacity keep it.
 - Watermark text is no longer required at project level; blank falls back to the site default.
 - The white-only colour rule now covers all very light colours (contrast against white below 1.5:1, e.g. pale greys, yellow, cyan, lime). Projects can no longer choose white for surveys; Control Center colours are still allowed on surveys.
### Fixed
 - README: prerequisites, configuration table and image paths.

## [1.0.0] - 2026-05-08
### Summary
 - This is the first release of Watermark for Development Databases and my first EM. YAY!
