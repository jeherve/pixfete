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

describe('lightbox drag-to-follow', () => {
	function touchMoveEvent(x, y) {
		return {
			touches: [{ clientX: x, clientY: y }],
			changedTouches: [{ clientX: x, clientY: y }],
			preventDefault: jest.fn(),
		};
	}

	function buildLightboxImage() {
		document.body.replaceChildren();
		const img = document.createElement('img');
		img.className = 'pixfete-lightbox-image';
		document.body.append(img);
		return img;
	}

	beforeEach(() => {
		window.matchMedia = (query) => ({
			matches: false,
			media: query,
			addEventListener() {},
			removeEventListener() {},
		});
	});

	test('the image follows the finger horizontally during a drag', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 1;
		const img = buildLightboxImage();

		store.actions.lightboxTouchStart({ touches: [{ clientX: 200, clientY: 100 }] });
		store.actions.lightboxTouchMove(touchMoveEvent(140, 105)); // dx = -60

		expect(img.style.transform).toBe('translateX(-60px)');
	});

	test('dragging past the first photo is rubber-band damped', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 0; // at the start, dragging right is blocked
		const img = buildLightboxImage();

		store.actions.lightboxTouchStart({ touches: [{ clientX: 100, clientY: 100 }] });
		store.actions.lightboxTouchMove(touchMoveEvent(200, 100)); // dx = +100, damped to 30

		expect(img.style.transform).toBe('translateX(30px)');
	});

	test('vertical-dominant drags do not translate the image', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 1;
		const img = buildLightboxImage();

		store.actions.lightboxTouchStart({ touches: [{ clientX: 100, clientY: 100 }] });
		store.actions.lightboxTouchMove(touchMoveEvent(120, 300)); // dx=20, dy=200

		expect(img.style.transform).toBe('');
	});

	test('reduced motion disables drag-follow', () => {
		window.matchMedia = () => ({ matches: true, addEventListener() {}, removeEventListener() {} });
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 1;
		const img = buildLightboxImage();

		store.actions.lightboxTouchStart({ touches: [{ clientX: 200, clientY: 100 }] });
		store.actions.lightboxTouchMove(touchMoveEvent(140, 105));

		expect(img.style.transform).toBe('');
	});

	test('a short swipe springs the image back to center', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 1;
		const img = buildLightboxImage();

		store.actions.lightboxTouchStart({ touches: [{ clientX: 100, clientY: 100 }] });
		store.actions.lightboxTouchMove(touchMoveEvent(120, 100)); // dx=20, below threshold
		store.actions.lightboxTouchEnd({ changedTouches: [{ clientX: 120, clientY: 100 }] });

		expect(store.state.lightboxIndex).toBe(1); // unchanged
		expect(img.style.transform).toBe('translateX(0)');
	});

	test('a blocked edge swipe springs back without changing the index', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 2; // last photo
		const img = buildLightboxImage();

		store.actions.lightboxTouchStart({ touches: [{ clientX: 200, clientY: 100 }] });
		store.actions.lightboxTouchMove(touchMoveEvent(80, 105)); // dx=-120, blocked at end
		store.actions.lightboxTouchEnd({ changedTouches: [{ clientX: 80, clientY: 105 }] });

		expect(store.state.lightboxIndex).toBe(2);
		expect(img.style.transform).toBe('translateX(0)');
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

describe('lightbox slide-in animation', () => {
	function buildLightboxImage(width) {
		document.body.replaceChildren();
		const img = document.createElement('img');
		img.className = 'pixfete-lightbox-image';
		Object.defineProperty(img, 'clientWidth', { value: width, configurable: true });
		document.body.append(img);
		return img;
	}

	beforeEach(() => {
		window.matchMedia = (query) => ({
			matches: false,
			media: query,
			addEventListener() {},
			removeEventListener() {},
		});
	});

	test('navigating forward slides the photo in from the right', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		const img = buildLightboxImage(300);
		let rafCb;
		window.requestAnimationFrame = (cb) => {
			rafCb = cb;
			return 1;
		};

		// First call (opening: prev was -1) establishes prevIndex without animating.
		store.state.lightboxIndex = 1;
		store.callbacks.animateLightboxSlide();
		expect(img.style.transform).toBe('');

		// Forward navigation animates.
		store.state.lightboxIndex = 2;
		store.callbacks.animateLightboxSlide();
		expect(img.style.transform).toBe('translateX(300px)');
		expect(img.style.transition).toBe('none');

		rafCb();
		expect(img.style.transform).toBe('translateX(0px)');
		expect(img.style.transition).toContain('transform');
	});

	test('navigating backward slides the photo in from the left', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		const img = buildLightboxImage(300);
		window.requestAnimationFrame = () => 1;

		store.state.lightboxIndex = 2;
		store.callbacks.animateLightboxSlide(); // establish prev = 2
		store.state.lightboxIndex = 1;
		store.callbacks.animateLightboxSlide();

		expect(img.style.transform).toBe('translateX(-300px)');
	});

	test('reduced motion skips the slide', () => {
		window.matchMedia = () => ({ matches: true, addEventListener() {}, removeEventListener() {} });
		const store = loadStore();
		seedPhotos(store, 3);
		const img = buildLightboxImage(300);

		store.state.lightboxIndex = 1;
		store.callbacks.animateLightboxSlide();
		store.state.lightboxIndex = 2;
		store.callbacks.animateLightboxSlide();

		expect(img.style.transform).toBe('');
	});

	test('does not slide when showNewPhotos shifts the index but keeps the same photo', () => {
		const store = loadStore();
		seedPhotos(store, 3); // ids 100, 101, 102
		const img = buildLightboxImage(300);
		window.requestAnimationFrame = (cb) => cb();

		// Viewing photo id=101 at index 1; prime prev trackers.
		store.state.lightboxIndex = 1;
		store.callbacks.animateLightboxSlide();

		// Simulate showNewPhotos: prepend 2 photos and shift the index so the
		// SAME photo (id=101) stays visible — now at index 3.
		store.state.photos = [
			{ id: 200, full: 'n0.jpg', thumbnail: 't0.jpg', guest_name: 'A' },
			{ id: 201, full: 'n1.jpg', thumbnail: 't1.jpg', guest_name: 'B' },
			...store.state.photos,
		];
		store.state.lightboxIndex = 3;
		store.callbacks.animateLightboxSlide();

		expect(img.style.transform).toBe(''); // no spurious slide
	});
});
