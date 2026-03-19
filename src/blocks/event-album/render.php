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

$enable_table_names = ! empty( $attributes['enableTableNames'] );

// Build the Interactivity API context.
$context = array(
	'pageId'           => get_the_ID(),
	'nonce'            => $csrf_token,
	'honeypotField'    => $honeypot_field,
	'enableTableNames' => $enable_table_names,
	'dateEnd'          => $attributes['dateRangeEnd'] ?? '',
	'dateStart'        => $attributes['dateRangeStart'] ?? '',
	'restBase'         => rest_url( 'event-guest-photos-sharing/v1' ),
);
?>
<div
	<?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns pre-escaped attributes. ?>
	data-wp-interactive="event-guest-photos-sharing"
	data-wp-init="actions.init"
	data-wp-context='<?php echo esc_attr( wp_json_encode( $context ) ); ?>'
>
	<template class="egps-consent-message">
		<?php echo wp_kses_post( $content ); ?>
	</template>

	<div class="egps-app">
		<?php // Loading view. ?>
		<div data-wp-bind--hidden="!state.isLoadingView" class="egps-loading">
			<p><?php esc_html_e( 'Loading…', 'event-guest-photos-sharing' ); ?></p>
		</div>

		<?php // Password view. ?>
		<div data-wp-bind--hidden="!state.isPasswordView" class="egps-form">
			<form data-wp-on--submit="actions.submitPassword">
				<label for="egps-password"><?php esc_html_e( 'Event Password', 'event-guest-photos-sharing' ); ?></label>
				<input
					id="egps-password"
					type="password"
					data-wp-bind--value="state.passwordInput"
					data-wp-on--input="actions.updatePasswordInput"
					placeholder="<?php esc_attr_e( 'Enter the event password', 'event-guest-photos-sharing' ); ?>"
					required
				/>
				<?php // Honeypot field — hidden from humans. ?>
				<div class="egps-hp" aria-hidden="true" tabindex="-1">
					<input type="text" name="<?php echo esc_attr( $honeypot_field ); ?>" autocomplete="off" tabindex="-1" />
				</div>
				<div data-wp-bind--hidden="!state.errorMessage" class="egps-error" data-wp-text="state.errorMessage"></div>
				<button type="submit" data-wp-bind--disabled="state.isSubmitting">
					<?php esc_html_e( 'Enter', 'event-guest-photos-sharing' ); ?>
				</button>
			</form>
		</div>

		<?php // Registration view. ?>
		<div data-wp-bind--hidden="!state.isRegistrationView" class="egps-form">
			<form data-wp-on--submit="actions.submitRegistration">
				<label for="egps-guest-name"><?php esc_html_e( 'Your Name', 'event-guest-photos-sharing' ); ?></label>
				<input
					id="egps-guest-name"
					type="text"
					data-wp-bind--value="state.guestName"
					data-wp-on--input="actions.updateGuestName"
					placeholder="<?php esc_attr_e( 'Your name', 'event-guest-photos-sharing' ); ?>"
					required
				/>
				<div data-wp-bind--hidden="!state.showTableName">
					<label for="egps-table-name"><?php esc_html_e( 'Your Table', 'event-guest-photos-sharing' ); ?></label>
					<input
						id="egps-table-name"
						type="text"
						data-wp-bind--value="state.tableName"
						data-wp-on--input="actions.updateTableName"
						placeholder="<?php esc_attr_e( 'Your table', 'event-guest-photos-sharing' ); ?>"
					/>
				</div>
				<?php // Honeypot field — hidden from humans. ?>
				<div class="egps-hp" aria-hidden="true" tabindex="-1">
					<input type="text" name="<?php echo esc_attr( $honeypot_field ); ?>" autocomplete="off" tabindex="-1" />
				</div>
				<div data-wp-bind--hidden="!state.errorMessage" class="egps-error" data-wp-text="state.errorMessage"></div>
				<button type="submit" data-wp-bind--disabled="state.isSubmitting">
					<?php esc_html_e( 'Continue', 'event-guest-photos-sharing' ); ?>
				</button>
			</form>
		</div>

		<?php // Consent view. ?>
		<div data-wp-bind--hidden="!state.isConsentView" class="egps-consent">
			<div class="egps-consent-text" data-wp-html="state.consentHtml"></div>
			<div data-wp-bind--hidden="!state.errorMessage" class="egps-error" data-wp-text="state.errorMessage"></div>
			<button
				class="egps-accept-btn"
				data-wp-on--click="actions.acceptConsent"
				data-wp-bind--disabled="state.isSubmitting"
			>
				<?php esc_html_e( 'I Accept', 'event-guest-photos-sharing' ); ?>
			</button>
		</div>

		<?php // Gallery view. ?>
		<div data-wp-bind--hidden="!state.isGalleryView">
			<?php // Upload area — hidden when date range has expired. ?>
			<div data-wp-bind--hidden="!state.isUploadEnabled" class="egps-upload">
				<label class="egps-upload-btn egps-upload-camera">
					<?php esc_html_e( 'Take Photo', 'event-guest-photos-sharing' ); ?>
					<input
						type="file"
						accept="image/*"
						capture="environment"
						data-wp-on--change="actions.handleFileSelect"
					/>
				</label>
				<label class="egps-upload-btn egps-upload-gallery">
					<?php esc_html_e( 'Choose from Gallery', 'event-guest-photos-sharing' ); ?>
					<input
						type="file"
						accept="image/*"
						multiple
						data-wp-on--change="actions.handleFileSelect"
					/>
				</label>
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
					</div>
				</template>
			</div>

			<?php // Load more button. ?>
			<div data-wp-bind--hidden="!state.hasMore" class="egps-load-more">
				<button data-wp-on--click="actions.loadMore" data-wp-bind--disabled="state.isSubmitting">
					<?php esc_html_e( 'Load more photos', 'event-guest-photos-sharing' ); ?>
				</button>
			</div>
		</div>

		<?php // Lightbox overlay. ?>
		<div
			data-wp-bind--hidden="!state.lightboxOpen"
			class="egps-lightbox"
			data-wp-on--click="actions.closeLightbox"
		>
			<button class="egps-lightbox-close" aria-label="<?php esc_attr_e( 'Close', 'event-guest-photos-sharing' ); ?>">&times;</button>
			<img
				class="egps-lightbox-image"
				data-wp-bind--src="state.lightboxPhoto.full"
				data-wp-bind--alt="state.lightboxPhoto.guest_name"
			/>
			<span class="egps-lightbox-name" data-wp-text="state.lightboxPhoto.guest_name"></span>
		</div>
	</div>
</div>
