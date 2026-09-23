<?php
/**
 * Plugin Name: Custom Upstream Message Banner
 * Plugin URI:   https://github.com/pantheon-systems/wordpress-composer-managed
 * Description:  Reads custom-upstream-message.txt from this repo and renders it on the front end, in wp-admin, and via a plain-text probe endpoint. Used to verify that an upstream update reached a site.
 * Version:      1.0.0
 * Author:       Pantheon Systems
 * Author URI:   https://pantheon.io/
 * License:      MIT License
 */

namespace Pantheon\WordPressComposerManaged\UpstreamMessage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Message source, shipped in this repository and edited by CI. */
const MESSAGE_FILE = __DIR__ . '/custom-upstream-message.txt';

/** Longest message we will render, in bytes. Guards against a huge file. */
const MESSAGE_MAX_BYTES = 4096;

/** Query var that returns the message as plain text, for automated checks. */
const MESSAGE_PROBE_QUERY_VAR = 'cu-upstream-message';

/**
 * Read the message from disk.
 *
 * Deliberately uncached: no options, no transients, no static memoisation
 * across requests. A stale read here would defeat the purpose of the banner,
 * since the whole point is to see a new deploy's text without touching the
 * database. Within a single request the value is memoised, since the banner,
 * the admin notice, and the probe can all ask for it.
 *
 * @return string Message text, or '' if the file is missing or empty.
 */
function message(): string {
	static $message = null;

	if ( null !== $message ) {
		return $message;
	}

	$message = '';

	if ( ! is_readable( MESSAGE_FILE ) ) {
		return $message;
	}

	$contents = file_get_contents( MESSAGE_FILE, false, null, 0, MESSAGE_MAX_BYTES );

	if ( false === $contents ) {
		return $message;
	}

	// Normalise CRLF so a file committed from Windows does not render stray
	// carriage returns, then drop surrounding whitespace and the trailing
	// newline every well-formed text file ends with.
	$message = trim( str_replace( "\r\n", "\n", $contents ) );

	return $message;
}

/**
 * The commit-independent fingerprint of the deployed message.
 *
 * Rendered alongside the message so a human comparing two sites can tell
 * "same words, different deploy" apart at a glance.
 */
function message_fingerprint(): string {
	$message = message();

	if ( '' === $message ) {
		return 'none';
	}

	return substr( md5( $message ), 0, 8 );
}

add_action( 'wp_body_open', __NAMESPACE__ . '\\render_banner' );
add_action( 'wp_footer', __NAMESPACE__ . '\\render_banner' );

/**
 * Print the banner on the front end.
 *
 * Hooked to both wp_body_open and wp_footer because wp_body_open is only
 * emitted by themes that call it, and this plugin cannot assume the active
 * theme does. The guard makes the second hook a no-op when the first one
 * fired, so the banner is printed exactly once under either theme.
 */
function render_banner(): void {
	static $printed = false;

	if ( $printed ) {
		return;
	}

	$message = message();

	if ( '' === $message ) {
		return;
	}

	$printed = true;

	printf(
		'<div class="cu-upstream-banner" data-cu-message-hash="%1$s" style="background:#111827;color:#fff;padding:0.85rem 1.25rem;font:600 15px/1.45 system-ui,-apple-system,sans-serif;text-align:center;white-space:pre-line">%2$s</div>',
		esc_attr( message_fingerprint() ),
		esc_html( $message )
	);
}

add_action( 'admin_notices', __NAMESPACE__ . '\\render_admin_notice' );

/**
 * Show the same message in wp-admin, so the deployed text is verifiable even
 * on a site whose front end is behind maintenance mode or a coming-soon page.
 */
function render_admin_notice(): void {
	$message = message();

	if ( '' === $message ) {
		return;
	}

	printf(
		'<div class="notice notice-info"><p><strong>Custom upstream:</strong> %1$s <code>%2$s</code></p></div>',
		esc_html( $message ),
		esc_html( message_fingerprint() )
	);
}

add_action( 'init', __NAMESPACE__ . '\\maybe_serve_probe', 1 );

/**
 * Answer GET /?cu-upstream-message=1 with the raw message as text/plain.
 *
 * This is the endpoint CI should assert against: one request per site, no
 * HTML parsing, and a 404 if the message file is missing. Cache headers are
 * set to no-store so neither Pantheon's edge cache nor a CDN can answer with
 * the previous deploy's text and turn a failed update into a passing test.
 */
function maybe_serve_probe(): void {
	if ( ! isset( $_GET[ MESSAGE_PROBE_QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	$message = message();

	nocache_headers();
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-CU-Message-Hash: ' . message_fingerprint() );

	if ( '' === $message ) {
		status_header( 404 );
		echo "custom upstream message file missing or empty\n";
		exit;
	}

	status_header( 200 );
	echo $message . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- text/plain response.
	exit;
}
