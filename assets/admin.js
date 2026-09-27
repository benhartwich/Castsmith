/* global aaspfAdmin */
( function () {
	'use strict';

	var CLASSES = {
		ok: 'aaspf-status-ok',
		warn: 'aaspf-status-warn',
		fail: 'aaspf-status-fail',
		skip: 'aaspf-status-skip'
	};

	function cell( row ) {
		return row.querySelector( '.aaspf-health-cell' );
	}

	function setRunning( row ) {
		cell( row ).innerHTML = '';
		var span = document.createElement( 'span' );
		span.className = 'aaspf-status aaspf-status-running';
		span.textContent = aaspfAdmin.strings.running;
		cell( row ).appendChild( span );
	}

	function setResult( row, status, message, detail ) {
		var target = cell( row );
		target.innerHTML = '';

		var span = document.createElement( 'span' );
		span.className = 'aaspf-status ' + ( CLASSES[ status ] || CLASSES.fail );
		span.textContent = message;
		target.appendChild( span );

		if ( detail ) {
			var note = document.createElement( 'span' );
			note.className = 'aaspf-detail';
			note.textContent = detail;
			target.appendChild( note );
		}
	}

	function check( row ) {
		var body = new FormData();
		body.append( 'action', aaspfAdmin.action );
		body.append( 'nonce', aaspfAdmin.nonce );
		body.append( 'check', row.getAttribute( 'data-aaspf-check' ) );

		setRunning( row );

		return window.fetch( aaspfAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( payload ) {
			if ( payload && payload.success && payload.data ) {
				setResult( row, payload.data.status, payload.data.message, payload.data.detail );
				return;
			}

			var message = payload && payload.data && payload.data.message
				? payload.data.message
				: aaspfAdmin.strings.failed;
			setResult( row, 'fail', message, '' );
		} ).catch( function ( error ) {
			setResult( row, 'fail', aaspfAdmin.strings.failed, error.message );
		} );
	}

	/* One after another, not in parallel: every concurrent request occupies a
	   PHP-FPM worker, and small hosting pools run out of them quickly. */
	function runAll( rows, index, button ) {
		if ( index >= rows.length ) {
			button.disabled = false;
			return;
		}

		check( rows[ index ] ).then( function () {
			runAll( rows, index + 1, button );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'aaspf-run-health' );
		if ( ! button ) {
			return;
		}

		var rows = Array.prototype.slice.call(
			document.querySelectorAll( '[data-aaspf-check]' )
		);

		button.addEventListener( 'click', function () {
			button.disabled = true;
			runAll( rows, 0, button );
		} );

		// Run the checks once when the page opens.
		button.disabled = true;
		runAll( rows, 0, button );
	} );
}() );
