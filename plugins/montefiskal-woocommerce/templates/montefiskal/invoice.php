<?php
/**
 * Printable fiscal invoice. The default prints the core's A4 layout unchanged.
 *
 * Override by copying to yourtheme/woocommerce/montefiskal/invoice.php. Keep the IKOF, JIKR, the verification link
 * and the QR code (at least 21 mm) on the printed page (BR-P1, BR-P2, BR-Q8).
 *
 * @var string $html complete HTML document, every value already escaped
 * @var \MonteFiskal\Outbox\StoredRequest $doc
 * @var \WC_Order $order
 *
 * @package MonteFiskal\WooCommerce
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

echo str_replace('</body>', '<script>window.addEventListener("load",function(){if(location.hash==="#print"){window.print();}});</script></body>', $html); // phpcs:ignore WordPress.Security.EscapeOutput
