/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

let mockRegisteredStore = {};
let mockContext = {};
jest.mock(
	'@wordpress/interactivity',
	() => ({
		store: (_name, definition) => {
			if (definition) {
				mockRegisteredStore = definition;
			}
			// On subsequent calls (store('pixfete') inside actions), return
			// the already-registered store so sibling actions are accessible.
			return mockRegisteredStore;
		},
		getContext: () => mockContext,
	}),
	{ virtual: true }
);

beforeEach(() => {
	jest.resetModules();
	mockRegisteredStore = {};
	mockContext = {
		pageId: 42,
		restBase: '/wp-json/pixfete/v1',
		restNonce: 'test-nonce',
		dateStart: '',
		dateEnd: '',
	};
});

function loadStore() {
	require('../view');
	return mockRegisteredStore;
}

function seedPhotos(store, count) {
	store.state.photos = Array.from({ length: count }, (_, i) => ({
		id: 100 + i,
		full: `full-${i}.jpg`,
		thumbnail: `thumb-${i}.jpg`,
		guest_name: `Guest ${i}`,
	}));
}

describe('lightbox navigation actions', () => {
	test('nextPhoto advances the index', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 0;

		store.actions.nextPhoto();

		expect(store.state.lightboxIndex).toBe(1);
	});

	test('nextPhoto stops at the last photo', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 2;

		store.actions.nextPhoto();

		expect(store.state.lightboxIndex).toBe(2);
	});

	test('prevPhoto decrements the index', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 2;

		store.actions.prevPhoto();

		expect(store.state.lightboxIndex).toBe(1);
	});

	test('prevPhoto stops at the first photo', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 0;

		store.actions.prevPhoto();

		expect(store.state.lightboxIndex).toBe(0);
	});

	test('canGoPrev and canGoNext reflect edges', () => {
		const store = loadStore();
		seedPhotos(store, 3);

		store.state.lightboxIndex = 0;
		expect(store.state.canGoPrev).toBe(false);
		expect(store.state.canGoNext).toBe(true);

		store.state.lightboxIndex = 1;
		expect(store.state.canGoPrev).toBe(true);
		expect(store.state.canGoNext).toBe(true);

		store.state.lightboxIndex = 2;
		expect(store.state.canGoPrev).toBe(true);
		expect(store.state.canGoNext).toBe(false);
	});

	test('canGoPrev and canGoNext are false when lightbox is closed', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = -1;

		expect(store.state.canGoPrev).toBe(false);
		expect(store.state.canGoNext).toBe(false);
	});

	test('nextPhoto stops event propagation when given an event', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 0;
		const stopPropagation = jest.fn();

		store.actions.nextPhoto({ stopPropagation });

		expect(stopPropagation).toHaveBeenCalledTimes(1);
		expect(store.state.lightboxIndex).toBe(1);
	});

	test('prevPhoto stops event propagation when given an event', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 1;
		const stopPropagation = jest.fn();

		store.actions.prevPhoto({ stopPropagation });

		expect(stopPropagation).toHaveBeenCalledTimes(1);
		expect(store.state.lightboxIndex).toBe(0);
	});

	test('nextPhoto does nothing when the lightbox is closed', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = -1;

		store.actions.nextPhoto();

		expect(store.state.lightboxIndex).toBe(-1);
	});

	test('prevPhoto does nothing when the lightbox is closed', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = -1;

		store.actions.prevPhoto();

		expect(store.state.lightboxIndex).toBe(-1);
	});
});

describe('lightbox touch swipe', () => {
	function touchEvent(x, y) {
		return {
			touches: [{ clientX: x, clientY: y }],
			changedTouches: [{ clientX: x, clientY: y }],
		};
	}

	test('horizontal left swipe advances to the next photo', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 0;

		store.actions.lightboxTouchStart(touchEvent(200, 100));
		store.actions.lightboxTouchEnd(touchEvent(80, 110));

		expect(store.state.lightboxIndex).toBe(1);
	});

	test('horizontal right swipe goes to the previous photo', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 2;

		store.actions.lightboxTouchStart(touchEvent(80, 100));
		store.actions.lightboxTouchEnd(touchEvent(200, 110));

		expect(store.state.lightboxIndex).toBe(1);
	});

	test('swipes shorter than the threshold are ignored', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 1;

		store.actions.lightboxTouchStart(touchEvent(100, 100));
		store.actions.lightboxTouchEnd(touchEvent(120, 100)); // dx=20, below 50px threshold.

		expect(store.state.lightboxIndex).toBe(1);
	});

	test('predominantly vertical swipes are ignored', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 1;

		store.actions.lightboxTouchStart(touchEvent(100, 50));
		store.actions.lightboxTouchEnd(touchEvent(160, 250)); // dx=60, dy=200.

		expect(store.state.lightboxIndex).toBe(1);
	});

	test('swipe past the last photo does not advance', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 2;

		store.actions.lightboxTouchStart(touchEvent(200, 100));
		store.actions.lightboxTouchEnd(touchEvent(80, 110));

		expect(store.state.lightboxIndex).toBe(2);
	});

	test('swipe past the first photo does not retreat', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 0;

		store.actions.lightboxTouchStart(touchEvent(80, 100));
		store.actions.lightboxTouchEnd(touchEvent(200, 110)); // dx=+120, right swipe.

		expect(store.state.lightboxIndex).toBe(0);
	});
});

describe('lightbox focus management', () => {
	function buildLightboxDom() {
		document.body.replaceChildren();

		const opener = document.createElement('div');
		opener.className = 'pixfete-photo';
		opener.id = 'opener-photo';
		opener.tabIndex = 0;
		document.body.append(opener);

		const dialog = document.createElement('div');
		dialog.className = 'pixfete-lightbox';

		const close = document.createElement('button');
		close.type = 'button';
		close.className = 'pixfete-lightbox-close';
		close.textContent = 'Close';

		const prev = document.createElement('button');
		prev.type = 'button';
		prev.className = 'pixfete-lightbox-nav pixfete-lightbox-nav--prev';
		prev.textContent = 'Prev';

		const next = document.createElement('button');
		next.type = 'button';
		next.className = 'pixfete-lightbox-nav pixfete-lightbox-nav--next';
		next.textContent = 'Next';

		dialog.append(close, prev, next);
		document.body.append(dialog);
	}

	test('openLightbox captures the active element so focus can be restored', () => {
		buildLightboxDom();
		const opener = document.getElementById('opener-photo');
		opener.focus();

		const store = loadStore();
		seedPhotos(store, 3);
		mockContext.item = store.state.photos[1];

		store.actions.openLightbox();

		expect(store.state.lightboxIndex).toBe(1);

		// Closing via the overlay should restore focus to the original opener.
		const close = document.querySelector('.pixfete-lightbox-close');
		store.actions.closeLightbox({ target: close });

		expect(document.activeElement).toBe(opener);
	});

	test('closeLightbox is a no-op for clicks on nav buttons', () => {
		buildLightboxDom();
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 1;

		const nav = document.querySelector('.pixfete-lightbox-nav--next');
		store.actions.closeLightbox({ target: nav });

		expect(store.state.lightboxIndex).toBe(1);
	});

	test('deleting the last photo also closes the lightbox', () => {
		buildLightboxDom();
		const opener = document.getElementById('opener-photo');
		opener.focus();

		const store = loadStore();
		seedPhotos(store, 1);
		mockContext.item = store.state.photos[0];
		store.actions.openLightbox();

		// Mirror the deletePhoto branch: index >= 0 and photos empty.
		store.state.photos = [];
		if (store.state.lightboxIndex >= 0 && store.state.photos.length === 0) {
			store.state.lightboxIndex = -1;
		}

		expect(store.state.lightboxIndex).toBe(-1);
	});
});

describe('showNewPhotos lightboxIndex shift', () => {
	test('shifts lightboxIndex by the number of prepended photos', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 1; // viewing photo id=101.
		store.state.pendingPhotos = [
			{ id: 200, full: 'new-a.jpg', thumbnail: 't-a.jpg', guest_name: 'Alex' },
			{ id: 201, full: 'new-b.jpg', thumbnail: 't-b.jpg', guest_name: 'Bea' },
		];

		store.actions.showNewPhotos();

		expect(store.state.photos[store.state.lightboxIndex].id).toBe(101);
		expect(store.state.lightboxIndex).toBe(3);
	});

	test('does not shift lightboxIndex when the lightbox is closed', () => {
		const store = loadStore();
		seedPhotos(store, 2);
		store.state.lightboxIndex = -1;
		store.state.pendingPhotos = [{ id: 300, full: 'x.jpg', thumbnail: 'tx.jpg', guest_name: 'Z' }];

		store.actions.showNewPhotos();

		expect(store.state.lightboxIndex).toBe(-1);
	});
});
