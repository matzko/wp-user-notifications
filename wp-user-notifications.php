<?php
/*
Plugin Name: User Notifications
Version: 1.0
Plugin URI: http://austinmatzko.com/wordpress-plugins/wp-user-notifications/
Description: An API and graphical elements for sending user notifications.
Author: Austin Matzko
Author URI: http://austinmatzko.com
*/

if ( ! class_exists( 'WP_User_Notification_Control' ) ) {
	
	include_once 'includes' . DIRECTORY_SEPARATOR . 'wp-json-rpc-api' . DIRECTORY_SEPARATOR . 'wp-json-rpc-api.php';
	include_once 'includes' . DIRECTORY_SEPARATOR . 'wp-filosofo-js-library' . DIRECTORY_SEPARATOR . 'wp-filosofo-js-library.php';

	class WP_User_Notification_Control
	{

		public $model;	
		public $view;	
		public function __construct()
		{
			$this->model = new WP_User_Notification_Model;
			$this->view = new WP_User_Notification_View;

			add_action('admin_init', array(&$this, 'event_admin_init'));
			add_action('init', array(&$this->model, 'event_init'));
			add_action('init', array(&$this->view, 'event_init'));
			add_action('template_redirect', array(&$this, 'event_template_redirect'));
			add_action('wpun_ready_client_files', array(&$this->view, 'ready_client_files'));
			
			add_filter('json_server_classname', array(&$this, 'filter_json_server_classname'), 10, 2);

			add_action('admin_footer', array(&$this, 'event_footer'));
			add_action('wp_footer', array(&$this, 'event_footer'));

			add_action('cleanup_user_notifications', array(&$this->model, 'event_cleanup_user_notifications') );
			add_action('create_new_user_notification', array(&$this->model, 'maybe_schedule_cleanup') );
		}

		public function event_admin_init()
		{
			do_action( 'wpun_ready_client_files' );
		}

		public function event_footer()
		{
			if ( $notifications = $this->model->get_current_notifications() ) {
				$this->view->print_message_markup( $notifications );
				$this->view->print_messages_js( $notifications );
			}
		}

		public function event_template_redirect()
		{
			do_action( 'wpun_ready_client_files' );
		}

		public function filter_json_server_classname( $server_class = '', $method = '' )
		{
			switch( $method ) :
				case 'wpUserNotifications.registerClosedNotifications' :
					$server_class = 'WP_User_Notifications_Server';
				break;
			endswitch;
			return $server_class;
		}
	}
		
	class WP_User_Notification_Model
	{
		public function event_cleanup_user_notifications()
		{
			$posts = get_posts( array(
				'meta_compare' => '<',
				'meta_key' => 'expiration-time',
				'meta_value' => time(),
				'post_type' => 'wp-user-notification',
			) );

			foreach( (array) $posts as $post_obj ) {
				if ( ! empty( $post_obj->ID ) ) {
					wp_delete_post( $post_obj->ID, true );
				}
			}
		}

		/**
		 * Get the notification IDs to hide from the given user.
		 *
		 * @param int $user_id The ID of the user from which to hide the notes.
		 * @return array The array of user notification IDs.
		 */
		public function get_hidden_user_notes( $user_id = 0 )
		{
			$user_id = (int) $user_id;
			return array_filter( array_map( 'intval', (array) get_transient( "user_not_ign_{$user_id}" ) ) );
		}


		/**
		 * Hide the given notifications from the user for the given period of time.
		 * The idea is mainly to use this to keep them from showing up right after
		 * someone closes out the window.
		 *
		 * @param int $user_id The ID of the user
		 * @param array $note_ids The array of notification IDs to hide.
		 * @param int $time The amount of time in seconds to hide them.
		 */
		public function hide_notes_from_user( $user_id = 0, $note_ids = array(), $time = 120 )
		{
			$user_id = (int) $user_id;
			$note_ids = (array) $note_ids;
			$time = (int) $time;

			$ignored = (array) get_transient( "user_not_ign_{$user_id}" );
			$ignored = array_unique( 
				array_filter( 
					array_map( 'intval', 
						array_merge( 
							$ignored,
							$note_ids
						)
					)
				)
			);

			set_transient(  "user_not_ign_{$user_id}", $ignored, $time );
		}

		public function maybe_schedule_cleanup()
		{
			if ( ! wp_next_scheduled( 'cleanup_user_notifications' ) ) {
				wp_schedule_event( time(), 'hourly', 'cleanup_user_notifications' );
			}
		}

		public function event_init()
		{
			load_plugin_textdomain('wp-user-notification', null, dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'l10n');
			register_post_type( 'wp-user-notification', array(
				'public' => false,
				'show_ui' => false,
				'capability_type' => 'post',
				'hierarchical' => false,
				'rewrite' => false,
				'query_var' => true,
			) );

			if ( ! headers_sent() ) {
				$cookie_name = 'wp-un-id';
				if ( empty( $_COOKIE[ $cookie_name ] ) ) {
					$expire = time() + ( 60*60*24*2 );
					$cookie_value = md5( $_SERVER['REMOTE_ADDR'] . uniqid() );

					setcookie($cookie_name, $cookie_value, $expire, PLUGINS_COOKIE_PATH, COOKIE_DOMAIN );
					setcookie($cookie_name, $cookie_value, $expire, ADMIN_COOKIE_PATH, COOKIE_DOMAIN );
					setcookie($cookie_name, $cookie_value, $expire, COOKIE_PATH, COOKIE_DOMAIN );
					setcookie($cookie_name, $cookie_value, $expire, SITECOOKIEPATH, COOKIE_DOMAIN );
				}
			}
		}

		protected function _get_current_notification_ids_by_unique_id( $unique_id = '' )
		{
			global $wpdb;
			if ( empty( $unique_id ) ) {
				return array();
			}

			$unique_id = mysql_real_escape_string( $unique_id, $wpdb->dbh );

			$current_time = time();

			$query = "	
				SELECT ID FROM {$wpdb->posts} AS p 
					LEFT JOIN {$wpdb->postmeta} AS user ON user.post_id = p.ID 
					LEFT JOIN {$wpdb->postmeta} AS type ON type.post_id = p.ID 
					JOIN {$wpdb->postmeta} AS exp ON exp.post_id = p.ID 
				    
					WHERE type.meta_key = 'notification-type' AND 
					exp.meta_key = 'expiration-time' AND 
					exp.meta_value > '{$current_time}' AND 
					type.meta_value = 'user' AND 
					user.meta_key = 'notification-unique-id' AND 
					user.meta_value = '{$unique_id}'
			";

			return $wpdb->get_col( $query );

		}

		protected function _get_current_notification_ids_by_user( $user_id = 0 )
		{
			global $wpdb;
			$user_id = (int) $user_id;

			// $current_time = mysql_real_escape_string( current_time( 'mysql', true ), $wpdb->dbh );
			$current_time = time();

			$query = "	
				SELECT ID FROM {$wpdb->posts} AS p 
					LEFT JOIN {$wpdb->postmeta} AS user ON user.post_id = p.ID 
					LEFT JOIN {$wpdb->postmeta} AS type ON type.post_id = p.ID 
					JOIN {$wpdb->postmeta} AS exp ON exp.post_id = p.ID 
				    
					WHERE type.meta_key = 'notification-type' AND 
					exp.meta_key = 'expiration-time' AND 
					exp.meta_value > '{$current_time}' AND 
					type.meta_value = 'user' AND 
					user.meta_key = 'notification-user' AND 
					user.meta_value = {$user_id}
			";

			return $wpdb->get_col( $query );
		}

		/**
		 * Get the current user's notification, if available.
		 *
		 * @return WP_User_Notification|false if no notification.
		 */
		public function get_current_notifications()
		{
			$notifications = array();
			$user_id = get_current_user_id();

			switch_to_blog( 1 );
			if ( empty( $user_id ) ) {
				$ids = $this->_get_current_notification_ids_by_unique_id( $_COOKIE['wp-un-id'] );
			} else {
				$ids = $this->_get_current_notification_ids_by_user( $user_id );
			}
			$to_ignore = $this->get_hidden_user_notes( $user_id );

			foreach( (array) $ids as $id ) {
				if ( ! in_array( $id, $to_ignore ) ) {
					$notifications[] = $this->get_notification_by_id( $id );
				}
			}
			restore_current_blog();

			return $notifications;
		}

		public function delete_notification( WP_User_Notification $notification )
		{
			if ( empty( $notification->id ) ) {
				$_post = get_post( $notification->id );
				if ( ! empty( $_post->post_type ) && 'wp-user-notification' == $_post->post_type ) {
					return wp_delete_post( $notification->id, true );
				}
			}

			return false;
		}

		public function get_notification_by_id( $id = 0 )
		{
			$id = (int) $id;
			$notification = new WP_User_Notification;
			$_post = get_post( $id );
			if ( ! empty( $_post->post_type ) && 'wp-user-notification' == $_post->post_type ) {
				$msg_type = get_post_meta( $id, 'message-type', true );
				$rec_type = get_post_meta( $id, 'notification-type', true );
				$rec_cap = get_post_meta( $id, 'notification-cap', true );
				$rec_user = get_post_meta( $id, 'notification-user', true );
				$rec_id = get_post_meta( $id, 'notification-unique-id', true );
				$exp = (int) get_post_meta( $id, 'expiration-time', true );

				$notification->message_type = empty( $msg_type ) ? 'info' : $msg_type;

				$notification->recipient_type = in_array( $rec_type, array( 'capability', 'user' ) ) ?
					$rec_type :
					'user';

				$notification->recipient_cap = empty( $rec_cap ) ? 'read' : $rec_cap;
				$notification->recipient_user = (int) $rec_user;
				$notification->unique_id = $rec_id;
				if ( empty( $exp ) ) {
					$exp = -1;
				}
				$notification->set_expiration_time( $exp );
				$notification->set_notification_text( $_post->post_content );
				$notification->id = (int) $_post->ID;
			}
			return $notification;
		}

		public function save_notification( WP_User_Notification &$notification )
		{
			switch_to_blog( 1 );
			if ( empty( $notification->id ) ) {
				$_id = wp_insert_post( array(
					'post_type' => 'wp-user-notification',
					'post_content' => $notification->get_notification_text(),
					'post_status' => 'publish',
				), true );
				$notification->id = is_wp_error( $_id ) ? 0 : (int) $_id;
			}

			if ( ! empty( $notification->id ) ) {
				update_post_meta( $notification->id, 'message-type', $notification->message_type );
				update_post_meta( $notification->id, 'notification-type', $notification->recipient_type );
				update_post_meta( $notification->id, 'notification-cap', $notification->recipient_cap );
				update_post_meta( $notification->id, 'notification-user', $notification->recipient_user );
				update_post_meta( $notification->id, 'notification-unique-id', $notification->unique_id );
				update_post_meta( $notification->id, 'expiration-time', $notification->get_expiration_time() );
			}

			restore_current_blog();
		}
	}
	
	class WP_User_Notification_View
	{
		protected $_client_dir_url;
		public $model;

		public function __construct()
		{
			$this->_client_dir_url = plugin_dir_url( __FILE__ ) . 'client-files/';
		}

		public function event_init()
		{
			$this->register_client_files();
		}

		public function print_messages_js( $user_notifications )
		{
			?>
			<script type="text/javascript">
			// <![CDATA[
			if ( 'undefined' != typeof wpUserNotifications  ) {
				wpUserNotifications.setNotificationTotalCount(<?php echo (int) count( $user_notifications ); ?>); 
				<?php foreach( (array) $user_notifications as $note ) : ?>
					wpUserNotifications.addNotificationID(<?php echo intval( $note->id ); ?>);
					wpUserNotifications.setNotificationExpire('<?php echo esc_js('user-notification-' . $note->id ); ?>', <?php echo intval( $note->get_expiration_time() - time() ); ?> ); 
				<?php endforeach; ?>
			}
			// ]]>
			</script>
			<?php
		}

		public function print_message_markup( $user_notifications )
		{
			?>
			<div id="wp-user-notification-wrap">
				<div id="wp-user-notification-main">
					<?php foreach( (array) $user_notifications as $note ) : ?>
						<div class="user-notification-wrap <?php echo esc_attr( $note->get_notification_class() ); ?>" id="user-notification-<?php
							echo esc_js( $note->id );	
						?>">
							<a href="<?php echo esc_attr( $note->get_close_link() ); ?>" title="<?php echo esc_attr( __('Close', 'wp-user-notification') ); ?>" id="user-notification-close-link" class="close-link">X</a>
							<?php echo $note->get_notification_text(); ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		}

		public function ready_client_files()
		{
			wp_enqueue_script( 'wp-user-notifications-js' );
			wp_enqueue_style( 'wp-user-notifications-css' );
		}

		public function register_client_files()
		{
			wp_register_style(
				'wp-user-notifications-css', 
				$this->_client_dir_url . 'css/wp-user-notifications.css',
				null,
				'1.0'
			);

			wp_register_script(
				'wp-user-notifications-js', 
				$this->_client_dir_url . 'js/wp-user-notifications.js',
				array( 'filosofo-common-js' ),
				'1.0'
			);
		}
	}

	class WP_User_Notification 
	{
		public $id = 0;
		public $recipient_cap = 'read';
		public $recipient_type = 'capability'; // 'capability' or 'user'
		public $recipient_user = 0;
		public $unique_id; // a cookie identifying a non-logged-in user
		
		/**
		 * Type of notification message.
		 *
		 * Can be one of "info," "success," "warning," "error," and "validation"
		 */
		public $message_type = 'info'; 

		protected $_text = '';

		public function __construct()
		{
		}

		public function get_close_link()
		{
			return add_query_arg( array(
				'wp-user-notification' => $this->id,
				'wp-user-notification-action' => 'close',	
				'user-notification-nonce' => wp_create_nonce( 'user-notification-action' ),
			) );
		}

		public function get_expiration_time()
		{
			return $this->_exp_time;
		}

		public function set_expiration_time( $time = null )
		{
			$this->_exp_time = $time;
		}

		public function get_notification_class()
		{
			$class = '';
			switch ( $this->message_type ) {
				case 'error' :
					$class = 'wp-user-notification-error';
				break;

				case 'success' :
					$class = 'wp-user-notification-success';
				break;

				case 'warning' :
					$class = 'wp-user-notification-warning';
				break;

				case 'validation' :
					$class = 'wp-user-notification-validation';
				break;

				case 'info' :
				default :
					$class = 'wp-user-notification-info';
				break;
			}

			return apply_filters( 'wp_user_notification_get_class', $class, $this->id );
		}

		public function get_notification_text()
		{
			return $this->_text;
		}

		public function set_notification_text( $text = '' )
		{
			$this->_text = $text;
		}
	}
}

if ( ! function_exists( 'load_wp_user_notification_helper' ) ) {

	/**
	 * Create a notification for the current user.
	 *
	 * @param string $text The text of the notification message.
	 * @param string $type The type of notifiction to create.
	 * @param int $til_expiration The number of seconds from now until the message expires.
	 * @return int The ID of the notification.
	 */
	function wp_create_user_notification( $text = '', $type = 'info', $til_expiration = 60 )
	{
		global $wp_user_notification_helper;
		$til_expiration = (int) $til_expiration;

		$note = new WP_User_Notification; 
		$note->recipient_type = 'user';
		$note->recipient_user = get_current_user_id();
		$note->unique_id = $_COOKIE['wp-un-id'];
		$note->message_type = in_array( $type, array( 
			'error',
			'info',
			'success',
			'validation',
			'warning',
		) ) ? $type : 'info';

		$note->set_expiration_time( time() + $til_expiration );
		$note->set_notification_text( $text );

		$wp_user_notification_helper->model->save_notification( $note );
		do_action_ref_array( 'create_new_user_notification', array( &$note ) );
		return $note->id;
	}

	function load_wp_user_notification_helper()
	{
		global $wp_user_notification_helper;
		$wp_user_notification_helper = new WP_User_Notification_Control;

		load_wp_json_rpc_api();

		include_once 'includes' . DIRECTORY_SEPARATOR . 'json-server-class.php';
	}

	add_action('plugins_loaded', 'load_wp_user_notification_helper');
	remove_action('plugins_loaded', 'load_wp_json_rpc_api');
}
// eof
