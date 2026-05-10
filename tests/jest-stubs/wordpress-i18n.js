// Stub for @wordpress/i18n in Jest tests.
// The actual implementation is mocked per-test with jest.mock().
module.exports = {
	__: (str) => str,
	_n: (single, plural, count) => (count === 1 ? single : plural),
	_x: (str) => str,
	sprintf: (fmt, ...args) => {
		let i = 0;
		return fmt.replace(/%(?:(\d+)\$)?[sdif]/g, (_, position) => {
			if (position) {
				return String(args[parseInt(position, 10) - 1]);
			}
			return String(args[i++]);
		});
	},
};
