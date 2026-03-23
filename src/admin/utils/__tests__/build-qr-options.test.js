import { buildQrOptions } from '../build-qr-options';

describe( 'buildQrOptions', () => {
	test( 'returns correct defaults', () => {
		const options = buildQrOptions( {
			data: 'https://example.com',
			fgColor: '#1d2327',
			bgColor: '#ffffff',
			cornerStyle: 'square',
			logoDataUrl: null,
		} );

		expect( options.data ).toBe( 'https://example.com' );
		expect( options.dotsOptions.type ).toBe( 'rounded' );
		expect( options.dotsOptions.color ).toBe( '#1d2327' );
		expect( options.backgroundOptions.color ).toBe( '#ffffff' );
		expect( options.cornersSquareOptions.type ).toBe( 'square' );
		expect( options.cornersDotOptions.type ).toBe( 'square' );
		expect( options.width ).toBe( 300 );
		expect( options.height ).toBe( 300 );
		expect( options.qrOptions.errorCorrectionLevel ).toBe( 'Q' );
		expect( options.image ).toBeUndefined();
	} );

	test( 'includes image and imageOptions when logoDataUrl is provided', () => {
		const options = buildQrOptions( {
			data: 'https://example.com',
			fgColor: '#000000',
			bgColor: '#ffffff',
			cornerStyle: 'dot',
			logoDataUrl: 'data:image/png;base64,abc',
		} );

		expect( options.image ).toBe( 'data:image/png;base64,abc' );
		expect( options.imageOptions.margin ).toBe( 4 );
		expect( options.imageOptions.imageSize ).toBe( 0.3 );
		expect( options.cornersSquareOptions.type ).toBe( 'dot' );
		expect( options.cornersDotOptions.type ).toBe( 'dot' );
	} );

	test( 'cornersDotOptions falls back to dot when cornerStyle is extra-rounded', () => {
		const options = buildQrOptions( {
			data: 'https://example.com',
			fgColor: '#000',
			bgColor: '#fff',
			cornerStyle: 'extra-rounded',
			logoDataUrl: null,
		} );

		expect( options.cornersSquareOptions.type ).toBe( 'extra-rounded' );
		expect( options.cornersDotOptions.type ).toBe( 'dot' );
	} );
} );
