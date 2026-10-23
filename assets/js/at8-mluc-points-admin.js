/**
 * AT8 Moonlight User Center — profile-screen points buttons.
 * Loads only on profile.php / user-edit.php; config comes from
 * window.at8MlucData (inline script attached by class-points.php).
 */
( function () {
	var data = window.at8MlucData || {};

	function post( mode ) {
		var userId = document.getElementById( 'at8-mluc-points-add' ).getAttribute( 'data-user' );
		var amount = parseInt( document.getElementById( 'at8-mluc-points-amount' ).value, 10 );
		var status = document.getElementById( 'at8-mluc-points-status' );
		var body = new FormData();

		body.append( 'action', 'at8_mluc_adjust_points' );
		body.append( 'nonce', data.nonce );
		body.append( 'user_id', userId );
		body.append( 'amount', isNaN( amount ) ? 0 : amount );
		body.append( 'mode', mode );

		fetch( data.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					status.textContent = ( res && res.data && res.data.message ) || 'Error.';
					return;
				}

				document.getElementById( 'at8-mluc-points-balance' ).value = res.data.balance;
				status.textContent = res.data.label;
			} )
			.catch( function () {
				status.textContent = 'Error.';
			} );
	}

	var add = document.getElementById( 'at8-mluc-points-add' );
	var deduct = document.getElementById( 'at8-mluc-points-deduct' );

	if ( add ) {
		add.addEventListener( 'click', function () { post( 'add' ); } );
	}

	if ( deduct ) {
		deduct.addEventListener( 'click', function () { post( 'deduct' ); } );
	}
}() );
