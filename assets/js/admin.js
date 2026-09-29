/**
 * Runs an export step by step through the REST API and shows its progress.
 */
( function () {
	'use strict';

	const config = window.rmdMflAdmin;
	const panel = document.getElementById( 'rmd-mfl-progress' );
	if ( ! config || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}
	const apiFetch = window.wp.apiFetch;
	const t = config.strings;
	let running = false;

	document.addEventListener( 'click', ( event ) => {
		const confirmLink = event.target.closest( '.rmd-mfl-confirm' );
		if ( confirmLink && ! window.confirm( t.confirmDelete ) ) {
			event.preventDefault();
			return;
		}

		const button = event.target.closest( '.rmd-mfl-export' );
		if ( button && ! running ) {
			start( button.dataset.profile );
		}
	} );

	if ( panel && config.status ) {
		render( config.status );
	}

	function start( profile ) {
		running = true;
		setButtons( true );
		render( { progress: 0, message: t.starting } );
		apiFetch( { path: config.path, method: 'POST', data: { profile } } )
			.then( loop )
			.catch( fail );
	}

	function loop( status ) {
		render( status );
		if ( status.done ) {
			running = false;
			window.location.reload();
			return;
		}
		apiFetch( { path: config.path + '/step', method: 'POST' } )
			.then( loop )
			.catch( fail );
	}

	function resume() {
		running = true;
		setButtons( true );
		apiFetch( { path: config.path + '/step', method: 'POST' } )
			.then( loop )
			.catch( fail );
	}

	function cancel( confirmFirst ) {
		if ( confirmFirst && ! window.confirm( t.confirmCancel ) ) {
			return;
		}
		apiFetch( { path: config.path, method: 'DELETE' } )
			.then( () => window.location.reload() )
			.catch( fail );
	}

	function fail( error ) {
		running = false;
		setButtons( false );
		const box = element( 'div', 'notice notice-error inline' );
		box.append(
			element( 'p', '', t.failed + ' ' + ( error && error.message ? error.message : String( error ) ) )
		);
		const actions = element( 'p' );
		actions.append(
			button( t.retry, 'button button-primary', resume ),
			' ',
			button( t.cancel, 'button', () => cancel( true ) )
		);
		box.append( actions );
		panel.append( box );
	}

	function render( status ) {
		panel.hidden = false;
		panel.replaceChildren();

		const title = element( 'h2', '', status.profile || '' );
		const bar = element( 'progress', 'rmd-mfl-bar' );
		bar.max = 1;
		bar.value = status.progress || 0;
		panel.append( title, bar, element( 'p', 'rmd-mfl-message', status.message || '' ) );

		if ( status.done ) {
			panel.append( element( 'p', 'rmd-mfl-finished', t.finished ) );
		}

		if ( status.dir ) {
			const folder = element( 'p' );
			const path = element( 'code', 'rmd-mfl-path', status.dir );
			folder.append( t.folder + ' ', path, ' ' );
			if ( navigator.clipboard ) {
				const copy = button( t.copy, 'button button-small', () => {
					navigator.clipboard.writeText( status.dir ).then( () => {
						copy.textContent = t.copied;
					} );
				} );
				folder.append( copy );
			}
			panel.append( folder );
		}

		if ( status.leftovers > 0 ) {
			panel.append( element( 'p', 'rmd-mfl-hint', t.leftovers ) );
		}

		if ( status.warnings && status.warnings.length ) {
			panel.append( element( 'h3', '', t.warnings ) );
			const list = element( 'ul', 'ul-disc' );
			status.warnings.forEach( ( warning ) => list.append( element( 'li', '', warning ) ) );
			panel.append( list );
		}

		const actions = element( 'p', 'rmd-mfl-panel-actions' );
		if ( status.done ) {
			actions.append( button( t.dismiss, 'button', () => cancel( false ) ) );
		} else if ( ! running ) {
			actions.append(
				button( t.retry, 'button button-primary', resume ),
				' ',
				button( t.cancel, 'button', () => cancel( true ) )
			);
		}
		panel.append( actions );
	}

	function setButtons( disabled ) {
		document.querySelectorAll( '.rmd-mfl-export' ).forEach( ( el ) => {
			el.disabled = disabled;
		} );
	}

	function button( label, className, onClick ) {
		const el = element( 'button', className, label );
		el.type = 'button';
		el.addEventListener( 'click', onClick );
		return el;
	}

	function element( tag, className, text ) {
		const el = document.createElement( tag );
		if ( className ) {
			el.className = className;
		}
		if ( undefined !== text ) {
			el.textContent = text;
		}
		return el;
	}
} )();
