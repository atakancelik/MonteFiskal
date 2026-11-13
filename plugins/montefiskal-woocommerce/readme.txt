=== MonteFiskal for WooCommerce ===
Contributors: montefiskal
Tags: fiscalization, montenegro, efi, invoice, woocommerce
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.5
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fiscalizes WooCommerce orders with the Montenegrin EFI service. Distributed through GitHub releases only.

== Description ==

MonteFiskal registers every invoice of your shop with the Tax Administration of Montenegro (EFI, "fiskalizacija"):

* Invoice at payment (default) or the advance flow: advance invoice at payment, invoice and corrective of the advance at delivery.
* Corrective invoices for full and partial refunds; a corrective waits until the original has its JIKR.
* The daily INITIAL cash deposit of 0.00 before the first cash invoice of the day (consumer card payments make cash invoices).
* Payment gateway mapping; BUSINESSCARD only for companies with a tax number when the gateway reports a commercial card.
* Buyer tax number field for classic and block checkout.
* IKOF, JIKR, verification link and QR code in e-mails, on the order page and on a printable invoice that themes can override.
* Queue with retries through Action Scheduler, deadline warnings before 48 hours, offline export and import.
* Inspector reports with CSV export, status page, privacy exporter and eraser, WP-CLI commands.
* HPOS and block checkout compatible. Multisite: each site is its own taxpayer.

You need your own qualified certificate (.p12), and the codes from the Tax Administration portal (SEP): business unit, software,
maintainer and operator. The plugin can register the TCR itself.

Preview release: not yet verified against the Tax Administration's test service (efitest). Test on efitest with your own certificate before you switch to production.

The software does not replace your legal responsibility as a taxpayer.

== Installation ==

1. Download montefiskal-woocommerce-<version>.zip from the GitHub release and check it against the .sha256 file.
2. Plugins > Add New > Upload Plugin, then activate.
3. Put the certificate outside the web root and add to wp-config.php:
   define( 'MONTEFISKAL_CERT_PATH', '/path/outside/webroot/<your certificate>.p12' );
   define( 'MONTEFISKAL_CERT_PASSWORD', '<your certificate password>' );
4. WooCommerce > MonteFiskal > Run the setup wizard.

The full guide is docs/setup-guide.md in the repository.

Set up with an AI assistant: https://github.com/atakancelik/MonteFiskal/blob/main/docs/ai/prompts/woocommerce-setup.md

== Frequently Asked Questions ==

= Is there a WordPress.org listing? =

No. Releases are published on GitHub. An optional update check against the GitHub releases API can be enabled in the settings.

= What happens when the service is down? =

The invoice is issued locally with its IKOF and printed without JIKR; it is sent automatically when the service is back, within 48 hours.

= Does uninstalling delete my fiscal records? =

No. Fiscal records must be kept at least five years after the end of the year. A separate tool on the status page deletes them after a warning.

== Changelog ==

= 0.1.0 =
* First release. Fiscal behaviour changed: yes (new).
