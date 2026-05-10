<?php
/**
 * Server-side render template for the Event Photo Album block.
 *
 * Receives $attributes, $content (InnerBlocks HTML), and $block
 * from the WordPress block renderer.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete;

defined( 'ABSPATH' ) || exit;

// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable -- $attributes, $content, and $block are provided by the WordPress block renderer.

// Generate a one-time CSRF token and store it in a transient.
$pixfete_csrf_token = wp_generate_password( 32, false );
set_transient( 'pixfete_csrf_' . $pixfete_csrf_token, get_the_ID(), HOUR_IN_SECONDS );

$pixfete_honeypot_field = apply_filters( 'pixfete_honeypot_field_name', 'email' );

$pixfete_enable_table_names = ! empty( $attributes['enableTableNames'] );

/*
 * Translation strings consumed by the Interactivity API view module.
 *
 * View modules (--experimental-modules) cannot import @wordpress/i18n
 * directly, so user-facing strings are translated server-side here and
 * passed to the JS store via the data-wp-context attribute.
 *
 * Keys ending in *BannerSingle/Plural are templates for the new-photos
 * banner; the JS picks the matching template based on count and
 * substitutes %d. Keys ending in `BulkFailed` use %1$d/%2$d positional
 * tokens and `confirmDeletePhoto` uses %s for the guest name.
 */
$pixfete_i18n = array(
	'passwordRequired'       => __( 'Please enter the event password.', 'pixfete' ),
	'passwordIncorrect'      => __( 'The password is incorrect.', 'pixfete' ),
	'nameRequired'           => __( 'Please enter your name.', 'pixfete' ),
	'networkError'           => __( 'A network error occurred. Please try again.', 'pixfete' ),
	'registrationFailed'     => __( 'Registration failed. Please try again.', 'pixfete' ),
	'consentFailed'          => __( 'Failed to accept consent. Please try again.', 'pixfete' ),
	'loadPhotosFailed'       => __( 'Failed to load photos.', 'pixfete' ),
	'uploadFailed'           => __( 'Upload failed. Please try again.', 'pixfete' ),
	'uploadConnectionFailed' => __( 'Upload failed. Please check your connection and try again.', 'pixfete' ),
	/* translators: 1: number of failed uploads, 2: total number of files in the batch. */
	'uploadBulkFailed'       => __( '%1$d of %2$d photos failed to upload.', 'pixfete' ),
	/* translators: %s: guest name attached to the photo being deleted. */
	'confirmDeletePhoto'     => __( '%s — delete this photo? This cannot be undone.', 'pixfete' ),
	'deletePhotoFailed'      => __( 'Failed to delete photo. Please try again.', 'pixfete' ),
	'deleteNetworkError'     => __( 'Network error. Please try again.', 'pixfete' ),
	/* translators: %d: number of new photos waiting to be revealed. */
	'newPhotoBannerSingle'   => __( '%d new photo — tap to see', 'pixfete' ),
	/* translators: %d: number of new photos waiting to be revealed. */
	'newPhotoBannerPlural'   => __( '%d new photos — tap to see', 'pixfete' ),
);

// Build the Interactivity API context.
$pixfete_context = array(
	'pageId'           => get_the_ID(),
	'nonce'            => $pixfete_csrf_token,
	'honeypotField'    => $pixfete_honeypot_field,
	'enableTableNames' => $pixfete_enable_table_names,
	'dateEnd'          => $attributes['dateRangeEnd'] ?? '',
	'dateStart'        => $attributes['dateRangeStart'] ?? '',
	'restBase'         => rest_url( 'pixfete/v1' ),
	'i18n'             => $pixfete_i18n,
);

// Detect whether the current visitor is an assigned moderator for this event.
$pixfete_is_moderator = false;
if ( is_user_logged_in() ) {
	$pixfete_is_moderator = \Jeherve\Pixfete\Moderator::is_moderator_for_page(
		get_current_user_id(),
		get_the_ID()
	);
}

if ( $pixfete_is_moderator ) {
	$pixfete_context['isModerator'] = true;
	$pixfete_context['restNonce']   = wp_create_nonce( 'wp_rest' );
} else {
	$pixfete_context['isModerator'] = false;
	$pixfete_context['restNonce']   = '';
}
?>
<div
	<?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns pre-escaped attributes. ?>
	data-wp-interactive="pixfete"
	data-wp-init="actions.init"
	data-wp-context='<?php echo esc_attr( wp_json_encode( $pixfete_context, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) ); ?>'
>
	<div class="pixfete-app">
		<?php // Not-started view — shown when the event date hasn't arrived yet. ?>
		<div data-wp-bind--hidden="!state.isNotStartedView" class="pixfete-not-started">
			<p><?php esc_html_e( "You\u{2019}re a little early! This event hasn\u{2019}t started yet \u{2014} check back soon.", 'pixfete' ); ?></p>
		</div>

		<?php // Loading view. ?>
		<div data-wp-bind--hidden="!state.isLoadingView" class="pixfete-loading">
			<p><?php esc_html_e( 'Loading…', 'pixfete' ); ?></p>
		</div>

		<?php // Password view. ?>
		<div data-wp-bind--hidden="!state.isPasswordView" class="pixfete-form">
			<form data-wp-on--submit="actions.submitPassword">
				<label for="pixfete-password"><?php esc_html_e( 'Event Password', 'pixfete' ); ?></label>
				<input
					id="pixfete-password"
					type="password"
					data-wp-bind--value="state.passwordInput"
					data-wp-on--input="actions.updatePasswordInput"
					placeholder="<?php esc_attr_e( 'Enter the event password', 'pixfete' ); ?>"
					required
				/>
				<?php // Honeypot field — hidden from humans. ?>
				<div class="pixfete-hp" aria-hidden="true" tabindex="-1">
					<input type="text" name="<?php echo esc_attr( $pixfete_honeypot_field ); ?>" autocomplete="off" tabindex="-1" />
				</div>
				<div data-wp-bind--hidden="!state.errorMessage" class="pixfete-error" data-wp-text="state.errorMessage"></div>
				<button type="submit" data-wp-bind--disabled="state.isSubmitting">
					<?php esc_html_e( 'Enter', 'pixfete' ); ?>
				</button>
			</form>
		</div>

		<?php // Registration view. ?>
		<div data-wp-bind--hidden="!state.isRegistrationView" class="pixfete-form">
			<form data-wp-on--submit="actions.submitRegistration">
				<label for="pixfete-guest-name"><?php esc_html_e( 'Your Name', 'pixfete' ); ?></label>
				<input
					id="pixfete-guest-name"
					type="text"
					data-wp-bind--value="state.guestName"
					data-wp-on--input="actions.updateGuestName"
					placeholder="<?php esc_attr_e( 'Your name', 'pixfete' ); ?>"
					required
				/>
				<div data-wp-bind--hidden="!state.showTableName">
					<label for="pixfete-table-name"><?php esc_html_e( 'Your Table', 'pixfete' ); ?></label>
					<input
						id="pixfete-table-name"
						type="text"
						data-wp-bind--value="state.tableName"
						data-wp-on--input="actions.updateTableName"
						placeholder="<?php esc_attr_e( 'Your table', 'pixfete' ); ?>"
					/>
				</div>
				<?php // Honeypot field — hidden from humans. ?>
				<div class="pixfete-hp" aria-hidden="true" tabindex="-1">
					<input type="text" name="<?php echo esc_attr( $pixfete_honeypot_field ); ?>" autocomplete="off" tabindex="-1" />
				</div>
				<div data-wp-bind--hidden="!state.errorMessage" class="pixfete-error" data-wp-text="state.errorMessage"></div>
				<button type="submit" data-wp-bind--disabled="state.isSubmitting">
					<?php esc_html_e( 'Continue', 'pixfete' ); ?>
				</button>
			</form>
		</div>

		<?php // Consent view. ?>
		<div data-wp-bind--hidden="!state.isConsentView" class="pixfete-consent">
			<div class="pixfete-consent-text"><?php echo wp_kses_post( $content ); ?></div>
			<div data-wp-bind--hidden="!state.errorMessage" class="pixfete-error" data-wp-text="state.errorMessage"></div>
			<button
				class="pixfete-accept-btn"
				data-wp-on--click="actions.acceptConsent"
				data-wp-bind--disabled="state.isSubmitting"
			>
				<?php esc_html_e( 'I Accept', 'pixfete' ); ?>
			</button>
		</div>

		<?php // Gallery view. ?>
		<div data-wp-bind--hidden="!state.isGalleryView">
			<?php // Moderation banner — visible only to assigned moderators. ?>
			<div
				data-wp-bind--hidden="!state.isModerator"
				class="pixfete-moderation-banner"
				role="status"
			>
				<span aria-hidden="true">&#x1f6e1;&#xfe0f;</span>
				<?php esc_html_e( 'Moderating — tap the X on a photo to remove it', 'pixfete' ); ?>
			</div>

			<?php // Upload FAB — hidden when date range has expired or lightbox is open. ?>
			<div
				data-wp-bind--hidden="!state.showFab"
				class="pixfete-fab-container"
				data-wp-on--keydown="actions.handleFabKeydown"
			>
				<?php // Scrim overlay when FAB is expanded. ?>
				<div
					data-wp-bind--hidden="!state.fabOpen"
					class="pixfete-fab-scrim"
					data-wp-on--click="actions.closeFab"
					aria-hidden="true"
				></div>

				<?php // Expanded sub-buttons. ?>
				<div data-wp-bind--hidden="!state.fabOpen" class="pixfete-fab-menu">
					<button
						class="pixfete-fab-option pixfete-fab-btn pixfete-fab-btn--secondary"
						data-wp-on--click="actions.triggerCapture"
						type="button"
					>
						<span class="pixfete-fab-label"><?php esc_html_e( 'Take Photo', 'pixfete' ); ?></span>
						<span class="pixfete-fab-btn-icon" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4z"/><path d="M9 2 7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9zm3 15c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z"/></svg>
						</span>
					</button>
					<button
						class="pixfete-fab-option pixfete-fab-btn pixfete-fab-btn--secondary"
						data-wp-on--click="actions.triggerGallery"
						type="button"
					>
						<span class="pixfete-fab-label"><?php esc_html_e( 'Choose from Gallery', 'pixfete' ); ?></span>
						<span class="pixfete-fab-btn-icon" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M22 16V4c0-1.1-.9-2-2-2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2zm-11-4 2.03 2.71L16 11l4 5H8l3-4zM2 6v14c0 1.1.9 2 2 2h14v-2H4V6H2z"/></svg>
						</span>
					</button>
				</div>

				<?php // Main FAB toggle button. ?>
				<button
					class="pixfete-fab-btn pixfete-fab-btn--main"
					data-wp-on--click="actions.toggleFab"
					data-wp-bind--aria-expanded="state.fabOpen"
					aria-label="<?php esc_attr_e( 'Upload photos', 'pixfete' ); ?>"
					type="button"
				>
					<svg class="pixfete-fab-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
				</button>

				<?php // Hidden file inputs triggered programmatically by FAB buttons. ?>
				<input
					type="file"
					id="pixfete-file-capture"
					accept="image/*"
					capture="environment"
					data-wp-on--change="actions.handleFileSelect"
					class="pixfete-hp"
				/>
				<input
					type="file"
					id="pixfete-file-gallery"
					accept="image/*"
					multiple
					data-wp-on--change="actions.handleFileSelect"
					class="pixfete-hp"
				/>
			</div>

			<?php // Upload progress banner — visible while files are uploading. ?>
			<div
				data-wp-bind--hidden="!state.isUploading"
				class="pixfete-upload-progress"
				role="status"
				aria-live="polite"
			>
				<span class="pixfete-upload-progress-bar"></span>
				<span data-wp-text="state.uploadBannerText"></span>
			</div>

			<?php // New photos banner. ?>
			<div
				data-wp-bind--hidden="!state.newPhotoCount"
				class="pixfete-new-photos"
				data-wp-on--click="actions.showNewPhotos"
				data-wp-text="state.newPhotoBannerText"
			></div>

			<?php // Error message. ?>
			<div data-wp-bind--hidden="!state.errorMessage" class="pixfete-error" data-wp-text="state.errorMessage"></div>

			<?php // Photo grid. ?>
			<div class="pixfete-grid">
				<template data-wp-each="state.photos">
					<div class="pixfete-photo" data-wp-on--click="actions.openLightbox">
						<img
							data-wp-bind--src="context.item.thumbnail"
							data-wp-bind--alt="context.item.guest_name"
							loading="lazy"
						/>
						<span class="pixfete-photo-name" data-wp-text="context.item.guest_name"></span>
						<button
							data-wp-bind--hidden="!state.isModerator"
							class="pixfete-delete-badge"
							data-wp-on--click="actions.deletePhoto"
							aria-label="<?php esc_attr_e( 'Delete this photo', 'pixfete' ); ?>"
							type="button"
						>&times;</button>
					</div>
				</template>
			</div>

			<?php // Load more button. ?>
			<div data-wp-bind--hidden="!state.hasMore" class="pixfete-load-more">
				<button data-wp-on--click="actions.loadMore" data-wp-bind--disabled="state.isSubmitting">
					<?php esc_html_e( 'Load more photos', 'pixfete' ); ?>
				</button>
			</div>
		</div>

		<?php // Lightbox overlay. ?>
		<div
			data-wp-bind--hidden="!state.lightboxOpen"
			class="pixfete-lightbox"
			data-wp-on--click="actions.closeLightbox"
		>
			<button class="pixfete-lightbox-close" aria-label="<?php esc_attr_e( 'Close', 'pixfete' ); ?>">&times;</button>
			<img
				class="pixfete-lightbox-image"
				data-wp-bind--src="state.lightboxPhoto.full"
				data-wp-bind--alt="state.lightboxPhoto.guest_name"
			/>
			<span class="pixfete-lightbox-name" data-wp-text="state.lightboxPhoto.guest_name"></span>
		</div>
	</div>
</div>
