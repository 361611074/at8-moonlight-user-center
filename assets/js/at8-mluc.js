/**
 * AT8 Moonlight User Center - front-end behaviour.
 *
 * Progressive enhancement: every form posts through AJAX when JavaScript is
 * available, and falls back to the normal page reload when it is not.
 */
( function () {
	'use strict';

	if ( typeof window.at8MlucData === 'undefined' ) {
		return;
	}

	var data = window.at8MlucData;

	/**
	 * Send a form to an admin-ajax action.
	 *
	 * @param {HTMLFormElement} form   The form element.
	 * @param {string}          action AJAX action name.
	 * @param {string}          nonce  Nonce for that action.
	 * @param {HTMLElement}     notice Element that receives the message.
	 */
	function submitForm( form, action, nonce, notice ) {
		var body = new FormData( form );

		body.append( 'action', action );
		body.append( 'nonce', nonce );

		setNotice( notice, data.i18n.sending, 'pending' );
		form.classList.add( 'is-busy' );

		window
			.fetch( data.ajaxUrl, {
				method: 'POST',
				body: body,
				credentials: 'same-origin'
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				var success = payload && payload.success;

				setNotice( notice, ( payload && payload.message ) || '', success ? 'success' : 'error' );

				if ( success && payload.data && payload.data.redirect ) {
					window.location.assign( payload.data.redirect );
					return;
				}

				if ( success ) {
					form.reset();
					form.dispatchEvent(
						new CustomEvent( 'at8-mluc:success', {
							bubbles: true,
							detail: payload.data || {}
						} )
					);
				}
			} )
			.catch( function () {
				setNotice( notice, data.i18n.genericError, 'error' );
			} )
			.finally( function () {
				form.classList.remove( 'is-busy' );
			} );
	}

	/**
	 * Write a message into a notice element.
	 *
	 * @param {HTMLElement} notice  Target element.
	 * @param {string}      message Message text.
	 * @param {string}      state   One of pending, success, error.
	 */
	function setNotice( notice, message, state ) {
		if ( ! notice ) {
			return;
		}

		notice.textContent = message;
		notice.className = 'at8-mluc-message at8-mluc-message--' + state;

		if ( ! message ) {
			notice.hidden = true;
		} else {
			notice.hidden = false;
		}
	}

	/**
	 * Find the notice element that belongs to a form.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return {HTMLElement|null} Notice element.
	 */
	function noticeFor( form ) {
		var id = form.getAttribute( 'data-at8-mluc-notice' );

		if ( id ) {
			return document.getElementById( id );
		}

		return form.querySelector( '.at8-mluc-form-notice' );
	}

	/**
	 * Bind every AJAX form on the page.
	 */
	function bindForms() {
		var forms = document.querySelectorAll( 'form[data-at8-mluc-action]' );

		Array.prototype.forEach.call( forms, function ( form ) {
			var action = form.getAttribute( 'data-at8-mluc-action' );
			var nonce = form.getAttribute( 'data-at8-mluc-nonce' );
			var notice = noticeFor( form );

			if ( ! action ) {
				return;
			}

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				submitForm( form, action, nonce || '', notice );
			} );
		} );
	}

	/**
	 * Bind the avatar picker.
	 */
	function bindAvatarPicker() {
		var picker = document.querySelector( '[data-at8-mluc-avatar-picker]' );

		if ( ! picker ) {
			return;
		}

		var notice = document.getElementById( picker.getAttribute( 'data-at8-mluc-notice' ) || '' );
		var preview = document.querySelector( '[data-at8-mluc-avatar-preview]' );
		var buttons = picker.querySelectorAll( '[data-at8-mluc-avatar-id]' );

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				var body = new FormData();

				body.append( 'action', 'at8_mluc_select_avatar' );
				body.append( 'nonce', data.nonces.avatar );

				if ( 'reset' === button.getAttribute( 'data-at8-mluc-avatar-reset' ) ) {
					body.append( 'at8_mluc_reset', '1' );
				} else {
					body.append( 'avatar_id', button.getAttribute( 'data-at8-mluc-avatar-id' ) );
				}

				setNotice( notice, data.i18n.sending, 'pending' );

				window
					.fetch( data.ajaxUrl, {
						method: 'POST',
						body: body,
						credentials: 'same-origin'
					} )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( payload ) {
						var success = payload && payload.success;

						setNotice( notice, ( payload && payload.message ) || '', success ? 'success' : 'error' );

						if ( success && payload.data && payload.data.avatar_url && preview ) {
							preview.src = payload.data.avatar_url;
							preview.removeAttribute( 'srcset' );
						}
					} )
					.catch( function () {
						setNotice( notice, data.i18n.genericError, 'error' );
					} );
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			bindForms();
			bindAvatarPicker();
		} );
	} else {
		bindForms();
		bindAvatarPicker();
	}
} )();
