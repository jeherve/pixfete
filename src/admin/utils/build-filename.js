export function buildFilename(slug, tableName) {
	const parts = ['event-qr'];

	if (slug) {
		parts.push(slug);
	}

	if (tableName) {
		parts.push(tableName.toLowerCase().replace(/\s+/g, '-'));
	}

	return parts.join('-') + '.png';
}
