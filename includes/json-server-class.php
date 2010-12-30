<?php

class WP_User_Notifications_Server extends WP_JSON_RPC_Server 
{
	public function __construct()
	{
		$this->methods['wpUserNotifications.registerClosedNotifications'] = 'this:registerClosedNotifications';
	}

	public function registerClosedNotifications( $args = null )
	{
		global $wp_user_notification_helper;
		if ( isset( $args->{'note-ids'} ) ) {
			$to_ignore = get_object_vars( $args->{'note-ids'} );
			$wp_user_notification_helper->model->hide_notes_from_user( 
				get_current_user_id(),
				$to_ignore,
				120
			);
		}
	}
}
