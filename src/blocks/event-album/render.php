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

// The CSRF token is fetched at runtime from the REST /token endpoint
// rather than baked into this HTML, so caching this output (page cache,
// CDN, browser bfcache, link unfurlers) can't trap visitors with a stale
// or already-consumed token. The frontend populates `nonce` on init.

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
	'initFailed'             => __( 'Could not initialize. Please try again.', 'pixfete' ),
	'initConnectionFailed'   => __( 'Could not initialize. Please check your connection and try again.', 'pixfete' ),
	'nameRequired'           => __( 'Please enter your first name.', 'pixfete' ),
	'networkError'           => __( 'A network error occurred. Please try again.', 'pixfete' ),
	'registrationFailed'     => __( 'Registration failed. Please try again.', 'pixfete' ),
	'consentFailed'          => __( 'Failed to accept consent. Please try again.', 'pixfete' ),
	'loadPhotosFailed'       => __( 'Failed to load photos.', 'pixfete' ),
	'sessionExpired'         => __( 'Your session has expired. Please re-enter the event password to continue.', 'pixfete' ),
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
	'showPasswordLabel'      => __( 'Show password', 'pixfete' ),
	'hidePasswordLabel'      => __( 'Hide password', 'pixfete' ),
	'queuedLabel'            => __( 'Uploading…', 'pixfete' ),
	'failedLabel'            => __( 'Failed — tap retry', 'pixfete' ),
	'retryUploadsLabel'      => __( 'Retry uploads', 'pixfete' ),
);

// Build the Interactivity API context consumed by the view module.
// cookiePath mirrors the server-side cookie scope so the frontend can
// clear a stale guest cookie on exactly the path it was set on (root vs.
// subdirectory vs. subdirectory-multisite installs).
$pixfete_context = array(
	'pageId'           => get_the_ID(),
	'nonce'            => '',
	'honeypotField'    => $pixfete_honeypot_field,
	'enableTableNames' => $pixfete_enable_table_names,
	'dateEnd'          => $attributes['dateRangeEnd'] ?? '',
	'dateStart'        => $attributes['dateRangeStart'] ?? '',
	'restBase'         => rest_url( 'pixfete/v1' ),
	'cookiePath'       => \Jeherve\Pixfete\Cookie::cookie_path(),
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
			<p><?php esc_html_e( 'You’re a little early! This event hasn’t started yet — check back soon.', 'pixfete' ); ?></p>
		</div>

		<?php
		// Loading view. Doubles as the surface for an init failure
		// (e.g. token fetch could not reach the server), with a retry
		// button so the user isn't permanently stuck.
		?>
		<div data-wp-bind--hidden="!state.isLoadingView" class="pixfete-loading">
			<p data-wp-bind--hidden="state.errorMessage"><?php esc_html_e( 'Loading…', 'pixfete' ); ?></p>
			<div data-wp-bind--hidden="!state.errorMessage" class="pixfete-error" data-wp-text="state.errorMessage"></div>
			<button
				data-wp-bind--hidden="!state.errorMessage"
				data-wp-on--click="actions.init"
				type="button"
			><?php esc_html_e( 'Try again', 'pixfete' ); ?></button>
		</div>

		<?php // Password view. ?>
		<div data-wp-bind--hidden="!state.isPasswordView" class="pixfete-form">
			<form data-wp-on--submit="actions.submitPassword">
				<label for="pixfete-password"><?php esc_html_e( 'Event Password', 'pixfete' ); ?></label>
				<div class="pixfete-password-field">
					<input
						id="pixfete-password"
						type="password"
						data-wp-bind--type="state.passwordInputType"
						data-wp-bind--value="state.passwordInput"
						data-wp-on--input="actions.updatePasswordInput"
						placeholder="<?php esc_attr_e( 'Enter the event password', 'pixfete' ); ?>"
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
				<label for="pixfete-guest-name"><?php esc_html_e( 'Your first name', 'pixfete' ); ?></label>
				<input
					id="pixfete-guest-name"
					type="text"
					data-wp-bind--value="state.guestName"
					data-wp-on--input="actions.updateGuestName"
					placeholder="<?php esc_attr_e( 'Your first name', 'pixfete' ); ?>"
					required
				/>
				<div data-wp-bind--hidden="!state.showTableName">
					<label for="pixfete-table-name"><?php esc_html_e( 'Your table', 'pixfete' ); ?></label>
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

			<?php // Retry button surfaces only when at least one upload has permanently failed. ?>
			<button
				data-wp-bind--hidden="!state.hasFailedUploads"
				class="pixfete-retry-uploads"
				type="button"
				data-wp-on--click="actions.retryUploads"
				data-wp-text="state.retryUploadsLabelText"
			></button>

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

			<?php // Photo grid. Queued placeholders render above server photos. ?>
			<div class="pixfete-grid">
				<template data-wp-each="state.pendingUploads">
					<div
						class="pixfete-photo pixfete-photo--queued"
						data-wp-class--pixfete-photo--failed="context.item.isFailed"
					>
						<div class="pixfete-photo-placeholder" aria-hidden="true"></div>
						<span
							class="pixfete-photo-status"
							data-wp-text="context.item.statusLabel"
						></span>
					</div>
				</template>
				<?php // The existing state.photos template stays exactly as-is below this comment. ?>
				<template data-wp-each="state.photos">
					<div class="pixfete-photo" data-wp-on--click="actions.openLightbox">
						<img
							data-wp-bind--src="context.item.src"
							data-wp-bind--srcset="context.item.srcset"
							data-wp-bind--sizes="context.item.sizes"
							data-wp-bind--width="context.item.width"
							data-wp-bind--height="context.item.height"
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
			role="dialog"
			aria-modal="true"
			aria-label="<?php esc_attr_e( 'Photo lightbox', 'pixfete' ); ?>"
			data-wp-bind--hidden="!state.lightboxOpen"
			class="pixfete-lightbox"
			data-wp-on--click="actions.closeLightbox"
			data-wp-on--touchstart="actions.lightboxTouchStart"
			data-wp-on--touchmove="actions.lightboxTouchMove"
			data-wp-on--touchend="actions.lightboxTouchEnd"
			data-wp-on--touchcancel="actions.lightboxTouchCancel"
			data-wp-init="callbacks.initLightboxKeyboard"
		>
			<button class="pixfete-lightbox-close" aria-label="<?php esc_attr_e( 'Close', 'pixfete' ); ?>">&times;</button>

			<button
				class="pixfete-lightbox-nav pixfete-lightbox-nav--prev"
				data-wp-on--click="actions.prevPhoto"
				data-wp-bind--disabled="!state.canGoPrev"
				aria-label="<?php esc_attr_e( 'Previous photo', 'pixfete' ); ?>"
				type="button"
			>&lsaquo;</button>

			<img
				class="pixfete-lightbox-image"
				data-wp-bind--src="state.lightboxPhoto.full"
				data-wp-bind--alt="state.lightboxPhoto.guest_name"
				data-wp-watch="callbacks.animateLightboxSlide"
			/>

			<button
				class="pixfete-lightbox-nav pixfete-lightbox-nav--next"
				data-wp-on--click="actions.nextPhoto"
				data-wp-bind--disabled="!state.canGoNext"
				aria-label="<?php esc_attr_e( 'Next photo', 'pixfete' ); ?>"
				type="button"
			>&rsaquo;</button>

			<span class="pixfete-lightbox-name" data-wp-text="state.lightboxPhoto.guest_name"></span>
		</div>
	</div>
</div>
