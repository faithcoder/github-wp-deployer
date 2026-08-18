( function () {
	'use strict';

	var supportedActions = [
		'validate_repo',
		'install',
		'check_update',
		'deploy_now',
		'toggle_auto',
		'remove_repo',
		'clear_logs',
		'save_settings'
	];

	var config = window.pushWPAdmin || {};
	var labels = config.labels || {};

	function submittedAction( form, submitter ) {
		if ( submitter && submitter.name === 'pushwp_action' ) {
			return submitter.value;
		}

		var field = form.querySelector( 'input[name="pushwp_action"]' );
		return field ? field.value : '';
	}

	function statusElement( form ) {
		var existing = document.querySelector( '.pushwp-form-status' );
		if ( existing ) {
			existing.remove();
		}

		var status = document.createElement( 'span' );
		status.className = 'pushwp-form-status';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		var actions = form.closest( '.pushwp-actions' );
		var actionStatus = actions ? actions.querySelector( '.pushwp-action-status' ) : null;
		( actionStatus || form ).appendChild( status );
		return status;
	}

	function setBusy( form, action ) {
		form.classList.add( 'is-busy' );
		form.setAttribute( 'aria-busy', 'true' );
		form.querySelectorAll( 'button, input[type="submit"]' ).forEach( function ( control ) {
			control.disabled = true;
		} );

		var status = statusElement( form );
		status.innerHTML = '<span class="spinner is-active" aria-hidden="true"></span><span></span>';
		status.lastChild.textContent = labels[ action ] || config.working || '';
		return status;
	}

	function setError( form, status, message ) {
		form.classList.remove( 'is-busy' );
		form.removeAttribute( 'aria-busy' );
		form.querySelectorAll( 'button, input[type="submit"]' ).forEach( function ( control ) {
			control.disabled = false;
		} );
		status.className = 'pushwp-form-status pushwp-form-status__error';
		status.textContent = message;
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form.closest || ! form.closest( '.pushwp' ) ) {
			return;
		}

		var action = submittedAction( form, event.submitter );
		if ( supportedActions.indexOf( action ) === -1 || form.classList.contains( 'is-busy' ) ) {
			return;
		}

		if ( form.dataset.confirm && ! window.confirm( form.dataset.confirm ) ) {
			event.preventDefault();
			return;
		}

		event.preventDefault();

		var status = setBusy( form, action );
		var data = new FormData( form );
		if ( event.submitter && event.submitter.name ) {
			data.set( event.submitter.name, event.submitter.value );
		}

		fetch( form.action || window.location.href, {
			method: ( form.method || 'post' ).toUpperCase(),
			body: data,
			credentials: 'same-origin',
			redirect: 'follow'
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Request failed with status ' + response.status + '.' );
			}
			return Promise.all( [ response.text(), response.url ] );
		} ).then( function ( result ) {
			var page = new DOMParser().parseFromString( result[ 0 ], 'text/html' );
			var incoming = page.querySelector( '#wpbody-content' );
			var current = document.querySelector( '#wpbody-content' );

			if ( ! incoming || ! current ) {
				window.location.assign( result[ 1 ] );
				return;
			}

			current.innerHTML = incoming.innerHTML;
			window.history.replaceState( {}, '', result[ 1 ] );
			window.scrollTo( { top: 0, behavior: 'smooth' } );
		} ).catch( function () {
			setError( form, status, config.failed || '' );
		} );
	} );
}() );
