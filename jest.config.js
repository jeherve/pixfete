/**
 * Extends the default @wordpress/scripts Jest configuration to:
 *
 * 1. Map WordPress packages that are webpack externals (not installed as npm
 *    dependencies) to local stubs, so jest.mock() can intercept them.
 * 2. Add @testing-library/jest-dom matchers (toBeInTheDocument, etc.) to all
 *    tests via setupFilesAfterEnv.
 */
const defaultConfig = require('@wordpress/scripts/config/jest-unit.config');

module.exports = {
	...defaultConfig,
	moduleNameMapper: {
		...defaultConfig.moduleNameMapper,
		'^@wordpress/i18n$': '<rootDir>/tests/jest-stubs/wordpress-i18n.js',
		'^@wordpress/components$': '<rootDir>/tests/jest-stubs/wordpress-components.js',
	},
	setupFiles: [...(defaultConfig.setupFiles || []), '<rootDir>/tests/jest-setup/structured-clone-polyfill.js'],
	setupFilesAfterEnv: [...(defaultConfig.setupFilesAfterEnv || []), '@testing-library/jest-dom'],
};
