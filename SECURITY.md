# Security policy

## Supported versions

Only the latest minor release receives fixes.

| Version | Supported |
|---|---|
| 0.1.x | yes |

## Reporting a vulnerability

Please report privately through GitHub's
[private vulnerability reporting](https://github.com/atakancelik/MonteFiskal/security/advisories/new). Do not open a
public issue. Include the affected component and version, steps to reproduce, and the impact you see.

Never include a certificate, private key, password, real tax number or real invoice data in a report. If a request or
response is needed, redact the tax number, names, certificate and signature values.

You will get an acknowledgement within five working days and an assessment within fourteen. Fixes are released as a
patch version with an advisory; we credit reporters who want to be credited.

## Scope

In scope: the .NET packages, the PHP core, the WooCommerce plugin and the tools in this repository, for example signing
flaws, certificate or password exposure, injection in the plugin, numbering that can be made to repeat, or archives
readable from the web.

Out of scope: the Tax Administration's EFI service and SEP portal (report those to the Tax Administration), and
vulnerabilities in WordPress, WooCommerce or other dependencies that are not caused by MonteFiskal.

## Handling of secrets by MonteFiskal

- Certificates are read from a path and a password source that the operator controls; MonteFiskal never stores the
  password in its own configuration files. The WooCommerce plugin prefers `wp-config.php` constants; a password saved
  in the settings is encrypted with a key derived from the site's salts.
- Logs and archives contain signed requests, which include the seller's tax number but never the private key.
- The archive folder of the plugin is protected against web access; keep it outside the web root where possible.
