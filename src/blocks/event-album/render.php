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
$egps_csrf_token = wp_generate_password( 32, false );
set_transient( 'egps_csrf_' . $egps_csrf_token, get_the_ID(), HOUR_IN_SECONDS );

$egps_honeypot_field = apply_filters( 'egps_honeypot_field_name', 'email' );

$egps_enable_table_names = ! empty( $attributes['enableTableNames'] );

// Build the Interactivity API context.
$egps_context = array(
	'pageId'           => get_the_ID(),
	'nonce'            => $egps_csrf_token,
	'honeypotField'    => $egps_honeypot_field,
	'enableTableNames' => $egps_enable_table_names,
	'dateEnd'          => $attributes['dateRangeEnd'] ?? '',
	'dateStart'        => $attributes['dateRangeStart'] ?? '',
	'restBase'         => rest_url( 'pixfete/v1' ),
);

// Detect whether the current visitor is an assigned moderator for this event.
$egps_is_moderator = false;
if ( is_user_logged_in() ) {
	$egps_is_moderator = \Jeherve\Pixfete\Moderator::is_moderator_for_page(
		get_current_user_id(),
		get_the_ID()
	);
}

if ( $egps_is_moderator ) {
	$egps_context['isModerator'] = true;
	$egps_context['restNonce']   = wp_create_nonce( 'wp_rest' );
} else {
	$egps_context['isModerator'] = false;
	$egps_context['restNonce']   = '';
}
?>
<div
	<?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns pre-escaped attributes. ?>
	data-wp-interactive="pixfete"
	data-wp-init="actions.init"
	data-wp-context='<?php echo esc_attr( wp_json_encode( $egps_context, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) ); ?>'
>
	<div class="egps-app">
		<?php // Not-started view — shown when the event date hasn't arrived yet. ?>
		<div data-wp-bind--hidden="!state.isNotStartedView" class="egps-not-started">
			<p><?php esc_html_e( "You\u{2019}re a little early! This event hasn\u{2019}t started yet \u{2014} check back soon.", 'pixfete' ); ?></p>
		</div>

		<?php // Loading view. ?>
		<div data-wp-bind--hidden="!state.isLoadingView" class="egps-loading">
			<p><?php esc_html_e( 'Loading…', 'pixfete' ); ?></p>
		</div>

		<?php // Password view. ?>
		<div data-wp-bind--hidden="!state.isPasswordView" class="egps-form">
			<form data-wp-on--submit="actions.submitPassword">
				<label for="egps-password"><?php esc_html_e( 'Event Password', 'pixfete' ); ?></label>
				<input
					id="egps-password"
					type="password"
					data-wp-bind--value="state.passwordInput"
					data-wp-on--input="actions.updatePasswordInput"
					placeholder="<?php esc_attr_e( 'Enter the event password', 'pixfete' ); ?>"
					required
				/>
				<?php // Honeypot field — hidden from humans. ?>
				<div class="egps-hp" aria-hidden="true" tabindex="-1">
					<input type="text" name="<?php echo esc_attr( $egps_honeypot_field ); ?>" autocomplete="off" tabindex="-1" />
				</div>
				<div data-wp-bind--hidden="!state.errorMessage" class="egps-error" data-wp-text="state.errorMessage"></div>
				<button type="submit" data-wp-bind--disabled="state.isSubmitting">
					<?php esc_html_e( 'Enter', 'pixfete' ); ?>
				</button>
			</form>
		</div>

		<?php // Registration view. ?>
		<div data-wp-bind--hidden="!state.isRegistrationView" class="egps-form">
			<form data-wp-on--submit="actions.submitRegistration">
				<label for="egps-guest-name"><?php esc_html_e( 'Your Name', 'pixfete' ); ?></label>
				<input
					id="egps-guest-name"
					type="text"
					data-wp-bind--value="state.guestName"
					data-wp-on--input="actions.updateGuestName"
					placeholder="<?php esc_attr_e( 'Your name', 'pixfete' ); ?>"
					required
				/>
				<div data-wp-bind--hidden="!state.showTableName">
					<label for="egps-table-name"><?php esc_html_e( 'Your Table', 'pixfete' ); ?></label>
					<input
						id="egps-table-name"
						type="text"
						data-wp-bind--value="state.tableName"
						data-wp-on--input="actions.updateTableName"
						placeholder="<?php esc_attr_e( 'Your table', 'pixfete' ); ?>"
					/>
				</div>
				<?php // Honeypot field — hidden from humans. ?>
				<div class="egps-hp" aria-hidden="true" tabindex="-1">
					<input type="text" name="<?php echo esc_attr( $egps_honeypot_field ); ?>" autocomplete="off" tabindex="-1" />
				</div>
				<div data-wp-bind--hidden="!state.errorMessage" class="egps-error" data-wp-text="state.errorMessage"></div>
				<button type="submit" data-wp-bind--disabled="state.isSubmitting">
					<?php esc_html_e( 'Continue', 'pixfete' ); ?>
				</button>
			</form>
		</div>

		<?php // Consent view. ?>
		<div data-wp-bind--hidden="!state.isConsentView" class="egps-consent">
			<div class="egps-consent-text"><?php echo wp_kses_post( $content ); ?></div>
			<div data-wp-bind--hidden="!state.errorMessage" class="egps-error" data-wp-text="state.errorMessage"></div>
			<button
				class="egps-accept-btn"
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
				class="egps-moderation-banner"
				role="status"
			>
				<span aria-hidden="true">&#x1f6e1;&#xfe0f;</span>
				<?php esc_html_e( 'Moderating — tap the X on a photo to remove it', 'pixfete' ); ?>
			</div>

			<?php // Upload FAB — hidden when date range has expired or lightbox is open. ?>
			<div
				data-wp-bind--hidden="!state.showFab"
				class="egps-fab-container"
				data-wp-on--keydown="actions.handleFabKeydown"
			>
				<?php // Scrim overlay when FAB is expanded. ?>
				<div
					data-wp-bind--hidden="!state.fabOpen"
					class="egps-fab-scrim"
					data-wp-on--click="actions.closeFab"
					aria-hidden="true"
				></div>

				<?php // Expanded sub-buttons. ?>
				<div data-wp-bind--hidden="!state.fabOpen" class="egps-fab-menu">
					<div class="egps-fab-option">
						<span class="egps-fab-label"><?php esc_html_e( 'Take Photo', 'pixfete' ); ?></span>
						<button
							class="egps-fab-btn egps-fab-btn--secondary"
							data-wp-on--click="actions.triggerCapture"
							aria-label="<?php esc_attr_e( 'Take a photo', 'pixfete' ); ?>"
							type="button"
						>
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4z"/><path d="M9 2 7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9zm3 15c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z"/></svg>
						</button>
					</div>
					<div class="egps-fab-option">
						<span class="egps-fab-label"><?php esc_html_e( 'Choose from Gallery', 'pixfete' ); ?></span>
						<button
							class="egps-fab-btn egps-fab-btn--secondary"
							data-wp-on--click="actions.triggerGallery"
							aria-label="<?php esc_attr_e( 'Choose photos from gallery', 'pixfete' ); ?>"
							type="button"
						>
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M22 16V4c0-1.1-.9-2-2-2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2zm-11-4 2.03 2.71L16 11l4 5H8l3-4zM2 6v14c0 1.1.9 2 2 2h14v-2H4V6H2z"/></svg>
						</button>
					</div>
				</div>

				<?php // Main FAB toggle button. ?>
				<button
					class="egps-fab-btn egps-fab-btn--main"
					data-wp-on--click="actions.toggleFab"
					data-wp-bind--aria-expanded="state.fabOpen"
					aria-label="<?php esc_attr_e( 'Upload photos', 'pixfete' ); ?>"
					type="button"
				>
					<svg class="egps-fab-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
				</button>

				<?php // Hidden file inputs triggered programmatically by FAB buttons. ?>
				<input
					type="file"
					id="egps-file-capture"
					accept="image/*"
					capture="environment"
					data-wp-on--change="actions.handleFileSelect"
					class="egps-hp"
				/>
				<input
					type="file"
					id="egps-file-gallery"
					accept="image/*"
					multiple
					data-wp-on--change="actions.handleFileSelect"
					class="egps-hp"
				/>
			</div>

			<?php // Upload progress banner — visible while files are uploading. ?>
			<div
				data-wp-bind--hidden="!state.isUploading"
				class="egps-upload-progress"
				role="status"
				aria-live="polite"
			>
				<span class="egps-upload-progress-bar"></span>
				<span data-wp-text="state.uploadBannerText"></span>
			</div>

			<?php // New photos banner. ?>
			<div
				data-wp-bind--hidden="!state.newPhotoCount"
				class="egps-new-photos"
				data-wp-on--click="actions.showNewPhotos"
				data-wp-text="state.newPhotoBannerText"
			></div>

			<?php // Error message. ?>
			<div data-wp-bind--hidden="!state.errorMessage" class="egps-error" data-wp-text="state.errorMessage"></div>

			<?php // Photo grid. ?>
			<div class="egps-grid">
				<template data-wp-each="state.photos">
					<div class="egps-photo" data-wp-on--click="actions.openLightbox">
						<img
							data-wp-bind--src="context.item.thumbnail"
							data-wp-bind--alt="context.item.guest_name"
							loading="lazy"
						/>
						<span class="egps-photo-name" data-wp-text="context.item.guest_name"></span>
						<button
							data-wp-bind--hidden="!state.isModerator"
							class="egps-delete-badge"
							data-wp-on--click="actions.deletePhoto"
							aria-label="<?php esc_attr_e( 'Delete this photo', 'pixfete' ); ?>"
							type="button"
						>&times;</button>
					</div>
				</template>
			</div>

			<?php // Load more button. ?>
			<div data-wp-bind--hidden="!state.hasMore" class="egps-load-more">
				<button data-wp-on--click="actions.loadMore" data-wp-bind--disabled="state.isSubmitting">
					<?php esc_html_e( 'Load more photos', 'pixfete' ); ?>
				</button>
			</div>
		</div>

		<?php // Lightbox overlay. ?>
		<div
			data-wp-bind--hidden="!state.lightboxOpen"
			class="egps-lightbox"
			data-wp-on--click="actions.closeLightbox"
		>
			<button class="egps-lightbox-close" aria-label="<?php esc_attr_e( 'Close', 'pixfete' ); ?>">&times;</button>
			<img
				class="egps-lightbox-image"
				data-wp-bind--src="state.lightboxPhoto.full"
				data-wp-bind--alt="state.lightboxPhoto.guest_name"
			/>
			<span class="egps-lightbox-name" data-wp-text="state.lightboxPhoto.guest_name"></span>
		</div>
	</div>
</div>
