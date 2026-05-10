<?php
/**
 * Server-side rendering for the event-slideshow block.
 *
 * Generates the full-viewport HTML shell for the projection slideshow,
 * including a CSRF token for authentication and the Interactivity API
 * context. The slideshow has four views: loading, not-started, password
 * form, and the main slideshow display.
 *
 * @package Jeherve\Pixfete
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content (unused — this block has no InnerBlocks).
 * @var WP_Block $block      Block instance.
 */

declare(strict_types=1);

namespace Jeherve\Pixfete;

defined( 'ABSPATH' ) || exit;

// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- $attributes, $content, and $block are provided by the WordPress block renderer.

$pixfete_event_page_id = (int) ( $attributes['eventPageId'] ?? 0 );
$pixfete_csrf_token    = wp_generate_password( 32, false );
set_transient( 'pixfete_csrf_' . $pixfete_csrf_token, $pixfete_event_page_id, HOUR_IN_SECONDS );

/** This filter is documented in src/blocks/event-album/render.php. */
$pixfete_honeypot_field = apply_filters( 'pixfete_honeypot_field_name', 'email' );

$pixfete_context = array(
	'eventPageId'   => $pixfete_event_page_id,
	'nonce'         => $pixfete_csrf_token,
	'honeypotField' => $pixfete_honeypot_field,
	'eventVersion'  => (int) ( $attributes['eventVersion'] ?? 1 ),
	'interval'      => (int) ( $attributes['interval'] ?? 5 ),
	'dateStart'     => $attributes['dateRangeStart'] ?? '',
	'dateEnd'       => $attributes['dateRangeEnd'] ?? '',
	'restBase'      => rest_url( 'pixfete/v1' ),
);
?>
<div
	<?php echo get_block_wrapper_attributes( array( 'class' => 'pixfete-slideshow' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns pre-escaped attributes. ?>
	data-wp-interactive="pixfete/slideshow"
	data-wp-init="actions.init"
	data-wp-context='<?php echo esc_attr( wp_json_encode( $pixfete_context, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) ); ?>'
>
	<?php // Loading view — shown briefly during initialization. ?>
	<div
		class="pixfete-slideshow-loading"
		data-wp-bind--hidden="!state.isLoadingView"
	>
		<p><?php echo esc_html__( 'Loading…', 'pixfete' ); ?></p>
	</div>

	<?php // Not-started view — event hasn't begun yet. ?>
	<div
		class="pixfete-slideshow-not-started"
		data-wp-bind--hidden="!state.isNotStartedView"
	>
		<p><?php echo esc_html__( "You're a little early! This event hasn't started yet — check back soon.", 'pixfete' ); ?></p>
	</div>

	<?php // Password form — simplified auth, no registration or consent. ?>
	<div
		class="pixfete-slideshow-form"
		data-wp-bind--hidden="!state.isPasswordView"
	>
		<form data-wp-on-async--submit="actions.submitPassword">
			<label for="pixfete-slideshow-password">
				<?php echo esc_html__( 'Event Password', 'pixfete' ); ?>
			</label>
			<input
				id="pixfete-slideshow-password"
				type="password"
				autocomplete="off"
				data-wp-bind--value="state.passwordInput"
				data-wp-on--input="actions.updatePasswordInput"
				required
			/>
			<div class="pixfete-hp" aria-hidden="true" tabindex="-1">
				<input
					type="text"
					name="<?php echo esc_attr( $pixfete_honeypot_field ); ?>"
					autocomplete="off"
					tabindex="-1"
				/>
			</div>
			<button type="submit" data-wp-bind--disabled="state.isSubmitting">
				<?php echo esc_html__( 'Enter', 'pixfete' ); ?>
			</button>
			<p
				class="pixfete-slideshow-error"
				data-wp-bind--hidden="!state.errorMessage"
				data-wp-text="state.errorMessage"
			></p>
		</form>
	</div>

	<?php // Main slideshow view. ?>
	<div
		class="pixfete-slideshow-display"
		data-wp-bind--hidden="!state.isSlideshowView"
	>
		<?php // Blurred background image. ?>
		<img
			class="pixfete-slideshow-bg"
			data-wp-bind--src="state.currentPhotoFull"
			alt=""
			aria-hidden="true"
		/>

		<?php // Current photo (bottom layer). ?>
		<img
			class="pixfete-slideshow-photo pixfete-slideshow-photo--current"
			data-wp-bind--src="state.currentPhotoFull"
			data-wp-bind--alt="state.currentPhotoAlt"
		/>

		<?php // Next photo (top layer, fades in during transition). ?>
		<img
			class="pixfete-slideshow-photo pixfete-slideshow-photo--next"
			data-wp-bind--src="state.nextPhotoFull"
			data-wp-bind--alt="state.nextPhotoAlt"
			data-wp-class--pixfete-slideshow-photo--visible="state.isFading"
		/>

		<?php // Metadata overlay pill. ?>
		<div
			class="pixfete-slideshow-meta"
			data-wp-bind--hidden="!state.currentPhotoGuestName"
		>
			<span
				class="pixfete-slideshow-meta-name"
				data-wp-text="state.currentPhotoGuestName"
			></span>
			<span
				class="pixfete-slideshow-meta-table"
				data-wp-bind--hidden="!state.currentPhotoTableName"
				data-wp-text="state.currentPhotoTableName"
			></span>
		</div>

		<?php // Waiting state — authenticated but no photos yet. ?>
		<div
			class="pixfete-slideshow-waiting"
			data-wp-bind--hidden="!state.isWaiting"
		>
			<p><?php echo esc_html__( 'Waiting for photos…', 'pixfete' ); ?></p>
		</div>
	</div>
</div>
