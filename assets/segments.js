/* global aaspfSegmente */
( function () {
	'use strict';

	var aufnahme = null;

	function meldung( bereich, text, fehler ) {
		var el = bereich.querySelector( '.aaspf-meldung' );
		if ( ! el ) {
			return;
		}
		el.textContent = text;
		el.className = 'aaspf-meldung ' + ( fehler ? 'aaspf-meldung-fehler' : 'aaspf-meldung-ok' );
	}

	/* ── Option B: dictionary rule ────────────────────────────────────── */

	function regelAnlegen( bereich ) {
		var knopf = bereich.querySelector( '[data-aaspf="regel-senden"]' );
		var begriff = bereich.querySelector( '[data-aaspf="regel-begriff"]' ).value.trim();
		var alias = bereich.querySelector( '[data-aaspf="regel-alias"]' ).value.trim();
		var ipaFeld = bereich.querySelector( '[data-aaspf="regel-ipa"]' );
		var ipa = ipaFeld ? ipaFeld.value.trim() : '';

		/* One of the two forms is enough — the configured model decides which
		   one is used. */
		if ( ! begriff || ( ! ipa && ! alias ) ) {
			meldung( bereich, aaspfSegmente.strings.regelUnvollstaendig, true );
			return;
		}

		var body = new FormData();
		body.append( 'action', aaspfSegmente.regelAction );
		body.append( 'nonce', aaspfSegmente.regelNonce );
		body.append( 'segment', bereich.getAttribute( 'data-segment' ) );
		body.append( 'grapheme', begriff );
		body.append( 'alias', alias );
		body.append( 'ipa', ipa );

		knopf.disabled = true;
		meldung( bereich, aaspfSegmente.strings.regelLaeuft, false );

		window.fetch( aaspfSegmente.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( p ) {
				knopf.disabled = false;
				if ( p && p.success ) {
					meldung( bereich, p.data.message, false );
				} else {
					meldung( bereich, ( p && p.data && p.data.message ) || aaspfSegmente.strings.fehler, true );
				}
			} )
			.catch( function ( e ) {
				knopf.disabled = false;
				meldung( bereich, e.message, true );
			} );
	}

	/* ── Option A: re-record the sentence ─────────────────────────────── */

	function aufnahmeStoppen() {
		if ( ! aufnahme ) {
			return;
		}
		if ( aufnahme.recorder && aufnahme.recorder.state !== 'inactive' ) {
			aufnahme.recorder.stop();
		}
		if ( aufnahme.stream ) {
			aufnahme.stream.getTracks().forEach( function ( t ) { t.stop(); } );
		}
		if ( aufnahme.timer ) {
			window.clearInterval( aufnahme.timer );
		}
	}

	function aufnahmeStarten( bereich ) {
		var knopf = bereich.querySelector( '[data-aaspf="aufnahme"]' );
		var uhr = bereich.querySelector( '[data-aaspf="uhr"]' );

		if ( aufnahme && aufnahme.bereich === bereich ) {
			aufnahmeStoppen();
			return;
		}

		if ( aufnahme ) {
			aufnahmeStoppen();
		}

		if ( ! navigator.mediaDevices || ! window.MediaRecorder ) {
			meldung( bereich, aaspfSegmente.strings.keinRecorder, true );
			return;
		}

		navigator.mediaDevices.getUserMedia( { audio: true } ).then( function ( stream ) {
			var recorder = new window.MediaRecorder( stream );
			var teile = [];
			var start = Date.now();

			recorder.ondataavailable = function ( e ) {
				if ( e.data && e.data.size > 0 ) {
					teile.push( e.data );
				}
			};

			recorder.onstop = function () {
				knopf.classList.remove( 'aaspf-laeuft', 'aaspf-knopf-aufnahme' );
				knopf.querySelector( '[data-aaspf="beschriftung"]' ).textContent = aaspfSegmente.strings.aufnahmeStarten;
				uhr.textContent = '';
				aufnahme = null;

				var blob = new window.Blob( teile, { type: recorder.mimeType || 'audio/webm' } );
				vorschau( bereich, blob );
			};

			aufnahme = { recorder: recorder, stream: stream, bereich: bereich, timer: null };

			aufnahme.timer = window.setInterval( function () {
				var s = Math.floor( ( Date.now() - start ) / 1000 );
				uhr.textContent = Math.floor( s / 60 ) + ':' + ( '0' + ( s % 60 ) ).slice( -2 );
			}, 250 );

			knopf.classList.add( 'aaspf-laeuft', 'aaspf-knopf-aufnahme' );
			knopf.querySelector( '[data-aaspf="beschriftung"]' ).textContent = aaspfSegmente.strings.aufnahmeStoppen;
			meldung( bereich, aaspfSegmente.strings.aufnahmeLaeuft, false );

			recorder.start();
		} ).catch( function ( e ) {
			meldung( bereich, aaspfSegmente.strings.keinMikrofon + ' ' + e.message, true );
		} );
	}

	function vorschau( bereich, blob ) {
		var spieler = bereich.querySelector( '[data-aaspf="vorschau"]' );
		var senden = bereich.querySelector( '[data-aaspf="uebernehmen"]' );

		spieler.src = window.URL.createObjectURL( blob );
		spieler.hidden = false;
		senden.disabled = false;
		bereich.aaspfBlob = blob;

		meldung( bereich, aaspfSegmente.strings.aufnahmeFertig, false );
	}

	function uebernehmen( bereich ) {
		var blob = bereich.aaspfBlob;
		if ( ! blob ) {
			return;
		}

		var senden = bereich.querySelector( '[data-aaspf="uebernehmen"]' );
		var body = new FormData();
		body.append( 'action', aaspfSegmente.patchAction );
		body.append( 'nonce', aaspfSegmente.patchNonce );
		body.append( 'segment', bereich.getAttribute( 'data-segment' ) );
		body.append( 'recording', blob, 'aufnahme.webm' );

		senden.disabled = true;
		meldung( bereich, aaspfSegmente.strings.wirdUmgewandelt, false );

		window.fetch( aaspfSegmente.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( p ) {
				if ( p && p.success ) {
					meldung( bereich, p.data.message + ' (' + p.data.duration + ')', false );
					var haupt = bereich.closest( '.aaspf-segment' ).querySelector( 'audio[data-aaspf="segment-audio"]' );
					if ( haupt ) {
						haupt.src = p.data.audioUrl;
						haupt.load();
					}
				} else {
					senden.disabled = false;
					meldung( bereich, ( p && p.data && p.data.message ) || aaspfSegmente.strings.fehler, true );
				}
			} )
			.catch( function ( e ) {
				senden.disabled = false;
				meldung( bereich, e.message, true );
			} );
	}

	/* ── Chapter jumps in the player of the whole episode ─────────────── */

	function springe( knopf ) {
		var spieler = document.getElementById( 'aaspf-folge' );
		if ( ! spieler ) {
			return;
		}

		spieler.currentTime = parseFloat( knopf.getAttribute( 'data-sekunde' ) ) || 0;

		if ( spieler.paused ) {
			spieler.play().catch( function () { /* Autoplay refused, never mind. */ } );
		}
	}

	/* Highlights the chapter the player is currently in. */
	function kapitelMitlaufen() {
		var spieler = document.getElementById( 'aaspf-folge' );
		var knoepfe = Array.prototype.slice.call( document.querySelectorAll( '[data-aaspf="sprung"]' ) );

		if ( ! spieler || ! knoepfe.length ) {
			return;
		}

		spieler.addEventListener( 'timeupdate', function () {
			var aktuell = null;
			knoepfe.forEach( function ( k ) {
				if ( spieler.currentTime + 0.25 >= parseFloat( k.getAttribute( 'data-sekunde' ) ) ) {
					aktuell = k;
				}
			} );
			knoepfe.forEach( function ( k ) {
				if ( k === aktuell ) {
					k.setAttribute( 'aria-current', 'true' );
				} else {
					k.removeAttribute( 'aria-current' );
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', kapitelMitlaufen );

	document.addEventListener( 'click', function ( ereignis ) {
		var ziel = ereignis.target.closest( '[data-aaspf]' );
		if ( ! ziel ) {
			return;
		}

		var rolle = ziel.getAttribute( 'data-aaspf' );
		var bereich = ziel.closest( '.aaspf-korrektur' );

		if ( rolle === 'sprung' ) {
			ereignis.preventDefault();
			springe( ziel );
			return;
		}

		if ( rolle === 'aufnahme' && bereich ) {
			ereignis.preventDefault();
			aufnahmeStarten( bereich );
		} else if ( rolle === 'uebernehmen' && bereich ) {
			ereignis.preventDefault();
			uebernehmen( bereich );
		} else if ( rolle === 'regel-senden' && bereich ) {
			ereignis.preventDefault();
			regelAnlegen( bereich );
		}
	} );

	window.addEventListener( 'beforeunload', aufnahmeStoppen );
}() );
