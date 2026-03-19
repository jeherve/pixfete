<?php
/**
 * Server-side render template for the Event Photo Album block.
 *
 * Receives $attributes, $content (InnerBlocks HTML), and $block
 * from the WordPress block renderer.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing;

defined( 'ABSPATH' ) || exit;

// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- $attributes, $content, and $block are provided by the WordPress block renderer.

// Generate a one-time CSRF token and store it in a transient.
$csrf_token = wp_generate_password( 32, false );
set_transient( 'egps_csrf_' . $csrf_token, get_the_ID(), HOUR_IN_SECONDS );

$honeypot_field = apply_filters( 'egps_honeypot_field_name', 'email' );

$enable_table_names = ! empty( $attributes['enableTableNames'] ) ? 'true' : 'false';
$event_version      = $attributes['eventVersion'] ?? 1;

// Build optional date-range data attributes.
$date_start_attr = '';
if ( ! empty( $attributes['dateRangeStart'] ) ) {
	$date_start_attr = ' data-date-start="' . esc_attr( $attributes['dateRangeStart'] ) . '"';
}

$date_end_attr = '';
if ( ! empty( $attributes['dateRangeEnd'] ) ) {
	$date_end_attr = ' data-date-end="' . esc_attr( $attributes['dateRangeEnd'] ) . '"';
}
?>
<div
	<?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns pre-escaped attributes. ?>
	data-page-id="<?php echo esc_attr( (string) get_the_ID() ); ?>"
	data-nonce="<?php echo esc_attr( $csrf_token ); ?>"
	data-honeypot-field="<?php echo esc_attr( $honeypot_field ); ?>"
	data-enable-table-names="<?php echo esc_attr( $enable_table_names ); ?>"
	data-event-version="<?php echo esc_attr( (string) $event_version ); ?>"
	<?php echo $date_start_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_attr(). ?>
	<?php echo $date_end_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_attr(). ?>
	data-wp-interactive="event-guest-photos-sharing"
>
	<template class="egps-consent-message">
		<?php echo wp_kses_post( $content ); ?>
	</template>
	<div class="egps-app"></div>
</div>
