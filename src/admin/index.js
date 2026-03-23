import './style.css';
import { createRoot } from '@wordpress/element';
import { AdminPage } from './components/AdminPage';

const container = document.getElementById( 'egps-qr-admin' );
if ( container ) {
	const root = createRoot( container );
	root.render( <AdminPage /> );
}
