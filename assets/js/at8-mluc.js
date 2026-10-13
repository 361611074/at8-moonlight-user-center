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

	/**
	 * Password strength meter.
	 *
	 * A small heuristic over length, character variety and repeated patterns.
	 * It never blocks a submission on its own — the form's own minlength and
	 * the server-side check stay authoritative.
	 */
	function bindStrength() {
		var meters = document.querySelectorAll( '[data-at8-mluc-strength]' );

		Array.prototype.forEach.call( meters, function ( meter ) {
			var input = document.getElementById( meter.getAttribute( 'data-at8-mluc-strength' ) );
			if ( ! input ) {
				return;
			}

			var labels = meter.getAttribute( 'data-labels' );
			labels = labels ? labels.split( '|' ) : [];
			var fill = meter.querySelector( '.at8-mluc-strength-fill' );
			var text = meter.querySelector( '.at8-mluc-strength-label' );

			input.addEventListener( 'input', function () {
				var score = scorePassword( input.value );
				meter.setAttribute( 'data-score', score );

				if ( text ) {
					text.textContent = labels[ score ] || '';
				}

				if ( 0 === score && fill ) {
					fill.style.width = '0';
				}
			} );
		} );
	}

	function scorePassword( value ) {
		if ( ! value ) {
			return 0;
		}

		var score = 0;
		var variety = 0;

		if ( value.length >= 8 ) {
			score += 1;
		}

		if ( value.length >= 12 ) {
			score += 1;
		}

		if ( /[a-z]/.test( value ) && /[A-Z]/.test( value ) ) {
			variety += 1;
		}

		if ( /\d/.test( value ) ) {
			variety += 1;
		}

		if ( /[^a-zA-Z0-9]/.test( value ) ) {
			variety += 1;
		}

		if ( variety >= 2 ) {
			score += 1;
		}

		if ( variety >= 3 ) {
			score += 1;
		}

		// Obvious sequences and repeats count against the result.
		if ( /^(.)\1+$/.test( value ) || /^(0123|1234|2345|3456|4567|5678|6789|abcd|qwer)/i.test( value ) ) {
			score = Math.min( score, 1 );
		}

		return Math.max( 0, Math.min( 4, score ) );
	}

	/**
	 * Membership redemption with points.
	 *
	 * The button carries its own nonce because it is rendered per level; the
	 * account reloads on success so the new level shows immediately.
	 */
	function bindRedeem() {
		var buttons = document.querySelectorAll( '[data-at8-mluc-redeem]' );

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				var card = button.closest( '.at8-mluc-upgrade-paths' );
				var notice = card ? card.querySelector( '.at8-mluc-redeem-notice' ) : null;
				var body = new FormData();

				body.append( 'action', 'at8_mluc_redeem_level' );
				body.append( 'nonce', button.getAttribute( 'data-nonce' ) );
				body.append( 'level', button.getAttribute( 'data-at8-mluc-redeem' ) );

				button.disabled = true;

				fetch( data.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( response ) { return response.json(); } )
					.then( function ( result ) {
						if ( result && result.success ) {
							if ( notice ) {
								setNotice( notice, result.data.message, 'success' );
							}

							setTimeout( function () {
								window.location.reload();
							}, 700 );

							return;
						}

						button.disabled = false;

						if ( notice ) {
							setNotice( notice, ( result && result.data && result.data.message ) || data.i18n.genericError, 'error' );
						}
					} )
					.catch( function () {
						button.disabled = false;

						if ( notice ) {
							setNotice( notice, data.i18n.genericError, 'error' );
						}
					} );
			} );
		} );
	}

	/**
	 * Paywall unlock buttons.
	 *
	 * Creates a pending order through the same engine as membership purchases,
	 * so the admin confirms it from the order list exactly like any other
	 * payment.
	 */
	function bindPaywall() {
		var buttons = document.querySelectorAll( '[data-at8-mluc-paywall-buy]' );

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				var card = button.closest( '.at8-mluc-paywall' );
				var notice = card ? card.querySelector( '.at8-mluc-paywall-notice' ) : null;
				var body = new FormData();

				body.append( 'action', 'at8_mluc_paywall_buy' );
				body.append( 'nonce', button.getAttribute( 'data-nonce' ) );
				body.append( 'post_id', button.getAttribute( 'data-post' ) );
				body.append( 'gateway', button.getAttribute( 'data-at8-mluc-paywall-buy' ) );

				button.disabled = true;

				fetch( data.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( response ) { return response.json(); } )
					.then( function ( result ) {
						if ( result && result.success ) {
							if ( notice ) {
								setNotice( notice, result.data.message, 'success' );
							}

							return;
						}

						button.disabled = false;

						if ( notice ) {
							setNotice( notice, ( result && result.data && result.data.message ) || data.i18n.genericError, 'error' );
						}
					} )
					.catch( function () {
						button.disabled = false;

						if ( notice ) {
							setNotice( notice, data.i18n.genericError, 'error' );
						}
					} );
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			bindForms();
			bindAvatarPicker();
			bindStrength();
			bindRedeem();
			bindPaywall();
		} );
	} else {
		bindForms();
		bindAvatarPicker();
		bindStrength();
		bindRedeem();
		bindPaywall();
	}
} )();
