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

// The CSRF token is fetched at runtime from the REST /token endpoint
// rather than baked into this HTML, so caching this output (page cache,
// CDN, browser bfcache, link unfurlers) can't trap visitors with a stale
// or already-consumed token. The frontend populates `nonce` on init.

$pixfete_event_page_id = (int) ( $attributes['eventPageId'] ?? 0 );

/** This filter is documented in src/blocks/event-album/render.php. */
$pixfete_honeypot_field = apply_filters( 'pixfete_honeypot_field_name', 'email' );

/*
 * Translation strings consumed by the slideshow view module.
 *
 * Script modules can't import @wordpress/i18n yet, so user-facing strings
 * are translated server-side and passed via data-wp-context. See
 * src/blocks/event-album/render.php for the same pattern.
 */
$pixfete_i18n = array(
	'passwordRequired'     => __( 'Please enter the event password.', 'pixfete' ),
	'passwordIncorrect'    => __( 'The password is incorrect.', 'pixfete' ),
	'networkError'         => __( 'A network error occurred.', 'pixfete' ),
	'initFailed'           => __( 'Could not initialize. Please try again.', 'pixfete' ),
	'initConnectionFailed' => __( 'Could not initialize. Please check your connection and try again.', 'pixfete' ),
	'showPasswordLabel'    => __( 'Show password', 'pixfete' ),
	'hidePasswordLabel'    => __( 'Hide password', 'pixfete' ),
);

$pixfete_context = array(
	'eventPageId'   => $pixfete_event_page_id,
	'nonce'         => '',
	'honeypotField' => $pixfete_honeypot_field,
	'eventVersion'  => (int) ( $attributes['eventVersion'] ?? 1 ),
	'interval'      => (int) ( $attributes['interval'] ?? 5 ),
	'dateStart'     => $attributes['dateRangeStart'] ?? '',
	'dateEnd'       => $attributes['dateRangeEnd'] ?? '',
	'restBase'      => rest_url( 'pixfete/v1' ),
	'i18n'          => $pixfete_i18n,
);
?>
<div
	<?php echo get_block_wrapper_attributes( array( 'class' => 'pixfete-slideshow' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns pre-escaped attributes. ?>
	data-wp-interactive="pixfete/slideshow"
	data-wp-init="actions.init"
	data-wp-context='<?php echo esc_attr( wp_json_encode( $pixfete_context, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) ); ?>'
>
	<?php
	// Loading view — shown briefly during initialization. Also
	// surfaces an init error (e.g. token fetch failed) with a
	// retry button so the slideshow isn't stuck on first load.
	?>
	<div
		class="pixfete-slideshow-loading"
		data-wp-bind--hidden="!state.isLoadingView"
	>
		<p data-wp-bind--hidden="state.errorMessage"><?php echo esc_html__( 'Loading…', 'pixfete' ); ?></p>
		<p
			class="pixfete-slideshow-error"
			data-wp-bind--hidden="!state.errorMessage"
			data-wp-text="state.errorMessage"
		></p>
		<button
			data-wp-bind--hidden="!state.errorMessage"
			data-wp-on--click="actions.init"
			type="button"
		><?php echo esc_html__( 'Try again', 'pixfete' ); ?></button>
	</div>

	<?php // Not-started view — event hasn't begun yet. ?>
	<div
		class="pixfete-slideshow-not-started"
		data-wp-bind--hidden="!state.isNotStartedView"
	>
		<p><?php echo esc_html__( 'You’re a little early! This event hasn’t started yet — check back soon.', 'pixfete' ); ?></p>
	</div>

	<?php // Password form — simplified auth, no registration or consent. ?>
	<div
		class="pixfete-slideshow-form"
		data-wp-bind--hidden="!state.isPasswordView"
	>
		<?php
		// novalidate keeps `required` for accessibility but disables the native
		// validation UI, which would otherwise abort submission before our handler
		// runs and leave guests with no feedback (the bubble is invisible on mobile).
		?>
		<form data-wp-on--submit="actions.submitPassword" novalidate>
			<label for="pixfete-slideshow-password">
				<?php echo esc_html__( 'Event Password', 'pixfete' ); ?>
			</label>
			<div class="pixfete-password-field">
				<input
					id="pixfete-slideshow-password"
					type="password"
					autocomplete="off"
					data-wp-bind--type="state.passwordInputType"
					data-wp-bind--value="state.passwordInput"
					data-wp-on--input="actions.updatePasswordInput"
					required
				/>
				<button
					type="button"
					class="pixfete-password-toggle"
					data-wp-on--click="actions.togglePasswordVisibility"
					data-wp-bind--aria-label="state.passwordToggleLabel"
					data-wp-bind--aria-pressed="state.passwordVisible"
				>
					<?php // Eye (closed = password hidden). ?>
					<svg
						data-wp-bind--hidden="state.passwordVisible"
						class="pixfete-password-toggle-icon"
						xmlns="http://www.w3.org/2000/svg"
						viewBox="0 0 24 24"
						width="20"
						height="20"
						fill="none"
						stroke="currentColor"
						stroke-width="2"
						stroke-linecap="round"
						stroke-linejoin="round"
						aria-hidden="true"
						focusable="false"
					>
						<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
						<circle cx="12" cy="12" r="3" />
					</svg>
					<?php // Eye-off (visible = password revealed). Hidden by default so both icons don't flash before hydration. ?>
					<svg
						data-wp-bind--hidden="!state.passwordVisible"
						class="pixfete-password-toggle-icon"
						xmlns="http://www.w3.org/2000/svg"
						viewBox="0 0 24 24"
						width="20"
						height="20"
						fill="none"
						stroke="currentColor"
						stroke-width="2"
						stroke-linecap="round"
						stroke-linejoin="round"
						aria-hidden="true"
						focusable="false"
						hidden
					>
						<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.6 21.6 0 0 1 5.17-6.17M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.6 21.6 0 0 1-3.17 4.31M14.12 14.12A3 3 0 1 1 9.88 9.88" />
						<line x1="1" y1="1" x2="23" y2="23" />
					</svg>
				</button>
			</div>
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
