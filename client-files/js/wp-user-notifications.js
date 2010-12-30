var wpUserNotifications = (function(f) {
	
	if ( ! f ) 
		return false;
	
	var d = document,

	eventClickCloseNotificationLink = function(e) {
		hideNotificationWrap(registerMessageClose); 
		return false;
	},
	
	notifications = {},
	addNotificationID = function( noteID ) {
		notifications[ noteID ] = noteID;
	},

	registerMessageClose = function() {
		if ( 'undefined' != typeof wpJSON ) {
			wpJSON.request(
				'wpUserNotifications.registerClosedNotifications',
				{'note-ids':notifications}
			);
		}
	},

	/**
	 * Hide the notification wrap.
	 *
	 * @param function finalCallback. 
	 * 	The function to call once the hiding is complete.
	 */
	hideNotificationWrap = function( finalCallback ) {
		if ( ! finalCallback )
			finalCallback = function(){};
		var wrap = d.getElementById('wp-user-notification-wrap');
		f.fade( wrap, -1, finalCallback );
	},

	setNotificationExpire = function( notificationID, timeLeft ) {
		var noteWrap = d.getElementById( notificationID ),
		countDown = 1000 * parseInt( timeLeft, 10 );
		if ( noteWrap ) {
			(function(note) {
				setTimeout( function() {
					f.fade( note, -1, function() {
						// decrement count
						notificationCount--; 
						if ( 1 > notificationCount )
							hideNotificationWrap(); 
					});
				}, countDown );
			})(noteWrap);
		}
	},
	
	notificationCount = 0,
	setNotificationTotalCount = function( count ) {
		notificationCount = parseInt( count, 10 );
	},

	init = function() {
		f.attachClassClickListener( 'close-link', eventClickCloseNotificationLink, d.getElementById('wp-user-notification-wrap') ); 
	}

	f.doWhenReady( init );

	return {
		addNotificationID:addNotificationID,
		setNotificationExpire:setNotificationExpire,
		setNotificationTotalCount:setNotificationTotalCount
	}
})( 'undefined' != typeof FilosofoJS ? new FilosofoJS() : null );
