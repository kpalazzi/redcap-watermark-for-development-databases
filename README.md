# Watermark for Development Databases
A REDCap module to display a watermark on selected pages of a project in Development, to alert users that it is a training or other non-production project. This module is designed to be enabled at the system or project-level where it is needed. It was created to reduce the risk of data entry in training projects that look and behave like the production project.

## Prerequisites
- REDCap >= 9.1.1
- REDCap Module Framework version 3

## Easy installation
- Install the Watermark for Development Databases module from the Consortium [REDCap Repo](https://redcap.vanderbilt.edu/consortium/modules/index.php) from the control center.
- Go to **Control Center > External Modules**, enable Watermark for Development Databases.

## Manual Installation
- Clone this repo into to `<redcap-root>/modules/watermark_for_develpment_databases_v1.0`.
- Go to **Control Center > External Modules**, enable Watermark for Development Databases.

## Configuration
The watermark can be customised via the module's project-level configuration panel (**Project Home > External Modules > Watermark for Development Databases > Configure**).

| Setting | Default | Notes |
|---|---|---|
| **Watermark text** | `TEST DATA ONLY` | Cannot be blank or contain only spaces. Any leading/trailing whitespace is ignored. |
| **Watermark colour** | `#F26600` (orange) | Must be a valid 3- or 6-digit hex colour code including the `#` symbol (e.g. `#F26600`, `#C00`). White (`#fff` / `#ffffff`) is not permitted. Invalid values fall back to the default orange. |
| **Watermark opacity** | `0.2` | A number between `0.1` and `1.0`. Values below `0.1` are raised to `0.1`; values above `1.0` are capped at `1.0`. Lower values are more transparent. |

If any setting is left blank or contains an invalid value, the module falls back to its default safely, the watermark will always appear.

## Development and Production, and specific pages
The watermark is visible on specific pages when a database is in Development status, but is not visible once the project is moved to Production or Analysis/Cleanup status.

The watermark is visible on:
- Record Status Dashboard
- Add/Edit Records page
- Data entry pages/forms
- Survey Distribution Tools
- Surveys

It is not visible on:
- Setup
- Designer pages
- Dictionary
- Codebook
- Application pages

## Examples
In Development status, the watermark looks like this:

- Record Status Dashboard:

![](img/record_status_dashboard.png)

- Data Entry Form:

![](img/data_entry_form.png)

- Survey

![](img/survey.png)



## Acknowledgments
Many thanks to Chesny et al whose [Project Overlay Banner](https://github.com/ctsit/project_overlay_banner) module provided inspiration for this module.
