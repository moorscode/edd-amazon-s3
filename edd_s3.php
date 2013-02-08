<?php
/*
Plugin Name: Easy Digital Downloads - Amazon S3
Plugin URI: http://easydigitaldownloads.com/extension/amazon-s3/
Description: Amazon S3 integration with EDD.  Allows you to upload or download directly from your S3 bucket. Configure on Settings > Misc tab
Version: 1.2.7
Author: Justin Sainton and Pippin Williamson
Author URI:  http://www.zao.is
Contributors: JustinSainton, mordauk
*/

class EDD_Amazon_S3 {

	private static $instance;
	private static $access_id;
	private static $secret_key;
	private static $bucket;

	/**
	 * Get active object instance
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @static
	 * @return object
	 */
	public static function get_instance() {

		if ( ! self::$instance )
			self::$instance = new EDD_Amazon_S3();

		return self::$instance;
	}

	/**
	 * Class constructor.  Includes constants, includes and init method.
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {

		$options          = get_option( 'edd_settings_misc' ) ;

		self::$access_id  = isset( $options['edd_amazon_s3_id'] )     ? trim( $options['edd_amazon_s3_id'] )     : '';
		self::$secret_key = isset( $options['edd_amazon_s3_key'] )    ? trim( $options['edd_amazon_s3_key'] )    : '';
		self::$bucket     = isset( $options['edd_amazon_s3_bucket'] ) ? trim( $options['edd_amazon_s3_bucket'] ) : '';


		$this->constants();
		$this->includes();
		$this->init();

	}

	/**
	 * Register generally helpful constants.
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return void
	 */
	private function constants() {

		// plugin version
		define( 'EDD_AS3_VERSION', '1.2.7' );

		// Set the core file path
		define( 'EDD_AS3_FILE_PATH', dirname( __FILE__ ) );

		// Define the path to the plugin folder
		define( 'EDD_AS3_DIR_NAME' , basename( EDD_AS3_FILE_PATH ) );

		// Define the URL to the plugin folder
		define( 'EDD_AS3_FOLDER'   , dirname( plugin_basename( __FILE__ ) ) );
		define( 'EDD_AS3_URL'      , plugins_url( '', __FILE__ ) );

		define( 'EDD_AS3_SL_STORE_API_URL', 'http://easydigitaldownloads.com' );
		define( 'EDD_AS3_SL_PRODUCT_NAME', 'Amazon S3' );

	}

	/**
	 * Register generally helpful constants.
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return void
	 */
	private function includes() {

		if ( ! class_exists( 'S3' ) )
			include_once EDD_AS3_FILE_PATH . '/s3.php';

		if( !class_exists( 'EDD_SL_Plugin_Updater' ) )
			include EDD_AS3_FILE_PATH . '/EDD_SL_Plugin_Updater.php';
	}

	/**
	 * Run action and filter hooks.
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return void
	 */
	private function init() {

		global $edd_options;

		//Adds Media Tab
		add_filter( 'media_upload_tabs'       , array( $this, 's3_tabs' ) );
		add_action( 'media_upload_s3'         , array( $this, 's3_iframe' ) );
		add_action( 'media_upload_s3_library' , array( $this, 's3_library_iframe' ) );
		add_filter( 'media_upload_default_tab', array( $this, 'default_tab' ) );

		//Adds settings to Misc Tab
		add_filter( 'edd_settings_misc' , array( $this, 'add_misc_settings' ) );

		//Handles Uploading to S3
		add_filter( 'wp_handle_upload'  , array( $this, 'upload_handler' ), 10, 2 );
		add_action( 'add_attachment'    , array( $this, 'add_post_meta' ) );

		//Low-level filter for URLs
		add_filter( 'wp_get_attachment_url', array( $this, 'url_intercept' ), 10, 2 );

		// modify the file name on download
		add_filter( 'edd_requested_file_name', array( $this, 'requested_file_name' ) );

		add_action('admin_head', array( $this, 'admin_js' ) );

		// activate the license key
		add_action( 'admin_init', array( $this, 'activate_license' ) );

		// retrieve our license key from the DB
		$edd_sl_license_key = isset( $edd_options['edd_amazon_s3_license_key'] ) ? trim( $edd_options['edd_amazon_s3_license_key'] ) : '';

		// setup the updater
		$edd_updater = new EDD_SL_Plugin_Updater( EDD_AS3_SL_STORE_API_URL, __FILE__, array(
				'version' 	=> EDD_AS3_VERSION, 			// current version number
				'license' 	=> $edd_sl_license_key, 		// license key (used get_option above to retrieve from DB)
				'item_name' => EDD_AS3_SL_PRODUCT_NAME, 	// name of this plugin
				'author' 	=> 'Pippin Williamson'  		// author of this plugin
			)
		);

	}

	public static function s3_tabs( $tabs ) {

		$tabs['s3'] = __( 'Upload to Amazon S3', 'edd' );
		$tabs['s3_library'] = __( 'Amazon S3 Library', 'edd' );

		return $tabs;
	}

	public static function s3_upload_download_tab( $type = 'file', $errors = null, $id = null ) {

		media_upload_header();
		wp_enqueue_style( 'media' );

		$post_id = isset( $_REQUEST['post_id'] ) ? intval( $_REQUEST['post_id'] ) : 0;

		$form_action_url = admin_url( "media-upload.php?type=$type&tab=s3&post_id=$post_id" );
		$form_action_url = apply_filters( 'media_upload_form_url', $form_action_url, $type );
		$form_class = 'media-upload-form type-form validate';

		if ( get_user_setting('uploader') )
			$form_class .= ' html-uploader';
		?>

		<form enctype="multipart/form-data" method="post" action="<?php echo esc_attr( $form_action_url ); ?>" class="<?php echo $form_class; ?>" id="<?php echo $type; ?>-form">
			<?php submit_button( '', 'hidden', 'save', false ); ?>
			<input type="hidden" name="post_id" id="post_id" value="<?php echo (int) $post_id; ?>" />
			<?php wp_nonce_field('media-form'); ?>

			<h3 class="media-title"><?php _e( 'Add media files to Amazon S3 from your computer', 'edd' ); ?></h3>

			<?php media_upload_form( $errors ); ?>

			<script type="text/javascript">
			//<![CDATA[
			jQuery(function($){
				var preloaded = $(".media-item.preloaded");
				if ( preloaded.length > 0 ) {
					preloaded.each(function(){prepareMediaItem({id:this.id.replace(/[^0-9]/g, '')},'');});
				}
				updateMediaForm();

			});
			//]]>
			</script>
			<div id="media-items"><?php

			if ( $id ) {
				if ( ! is_wp_error($id) ) {
					add_filter('attachment_fields_to_edit', 'media_post_single_attachment_fields_to_edit', 10, 2);
					echo get_media_items( $id, $errors );
				} else {
					echo '<div id="media-upload-error">'.esc_html( $id->get_error_message() ) . '</div></div>';
					exit;
				}
			}
			?></div>

			<p class="savebutton ml-submit">
				<?php submit_button( __( 'Save all changes', 'edd' ), 'button', 'save', false ); ?>
			</p>
		</form>
	<?php
	}

	public static function s3_library_tab( $type = 'file', $errors = null, $id = null ) {

		media_upload_header();
		wp_enqueue_style( 'media' );

		$page = isset( $_GET['p'] ) ? $_GET['p'] : 1;
		$per_page = 15;
		$offset = $per_page * ($page-1);
		$offset = $offset < 1 ? 15 : $offset;
		$start = isset( $_GET['start'] ) ? rawurldecode( $_GET['start'] ) : '';

		$files = self::get_s3_files( $start, $offset );

		//echo '<pre>'; print_r( $files ); echo '</pre>';
		?>
		<script type="text/javascript">
			//<![CDATA[
			jQuery(function($){
				$('.insert-s3').on('click', function() {
					var file = $(this).prev().data('s3');
					$(parent.window.edd_formfield).val(file);
					parent.window.tb_remove();
				});
			});
			//]]>
		</script>
		<div style="margin: 20px 1em 1em;" id="media-items">
			<h3 class="media-title"><?php _e('Select a file from your Amazon S3 Bucket', 'edd'); ?></h3>
			<?php
			if( is_array( $files ) ) {
				$i = 0;
				$total_items = count( $files );

				echo '<ul>';
				foreach ( $files as $key => $file ) {

					if( $i == 0)
						$first_file = $key;

					if( $i == 14 )
						$last_file = $key;

					echo '<li class="media-item" style="margin-bottom:0;">';
						echo '<span style="display:block;float:left;height:36px;line-height:36px;margin-left:8px;" data-s3="' . self::get_s3_url( $file['name'] ) . '">' . $file['name'] . '</span>';
						echo '<a class="insert-s3 button-secondary" href="#" style="float:right;margin:8px 8px 0;">' . __('Use File', 'edd') . '</a>';
					echo '</li>';

					$i++;
				}
				echo '</ul>';
			}

			$base = admin_url( 'media-upload.php?post_id=' . $_GET['post_id'] . '&tab=s3_library' );

			echo '<div class="s3-pagination tablenav">';
				echo '<div class="tablenav-pages alignright">';
					if( isset( $_GET['p'] ) && $_GET['p'] > 1 )
						echo '<a class="page-numbers prev" href="' . remove_query_arg('p', $base) . '">' . __('Start Over', 'edd') . '</a>';
					if( $i >= 10)
						echo '<a class="page-numbers next" href="' . add_query_arg(array('p' => $page + 1, 'start' => $last_file), $base) . '">' . __('More', 'edd') . '</a>';
				echo '</div>';
			echo '</div>';
			?>
		</div>
	<?php
	}

	public static function s3_iframe() {

		if ( ! empty( $_POST ) ) {
			$return = media_upload_form_handler();
			if ( is_string( $return ) )
				return $return;
		}

		wp_iframe( array( 'EDD_Amazon_S3', 's3_upload_download_tab' ) );
	}

	public static function s3_library_iframe() {

		if ( ! empty( $_POST ) ) {
			$return = media_upload_form_handler();
			if ( is_string( $return ) )
				return $return;
		}

		wp_iframe( array( 'EDD_Amazon_S3', 's3_library_tab' ) );
	}

	public static function add_misc_settings( $settings ) {

		$settings[] = array(
					'id'   => 'amazon_s3_settings',
					'name' => __( '<strong>Amazon S3 Settings</strong>', 'edd' ),
					'desc' => '',
					'type' => 'header'
		);

		$settings[] = array(
					'id' => 'edd_amazon_s3_license_key',
					'name' => __('License Key', 'edd_et'),
					'desc' => __('Enter your license for Amazon S3 to receive automatic upgrades', 'edd_sl'),
					'type' => 'text',
					'size' => 'regular'
		);

		$settings[] = array(
					'id'   => 'edd_amazon_s3_id',
					'name' => __( 'Amazon S3 Access Key ID', 'edd' ),
					'desc' => __( 'After logging into your S3 account, click on "Security Credentials" in the sidebar.  Scroll down to "Access Credentials" and you will see your Access Key ID.  Copy and paste it here.', 'edd' ),
					'type' => 'text',
					'size' => 'regular'
		);

		$settings[] = array(
					'id'   => 'edd_amazon_s3_key',
					'name' => __( 'Amazon S3 Secret Key', 'edd' ),
					'desc' => __( 'In the same Access Credentials area, your "Secret Key" will be hidden.  You will need to click the "Show" link to see it.  Copy and paste it here.', 'edd' ),
					'type' => 'text',
					'size' => 'regular'
		);

		$settings[] = array(
					'id'   => 'edd_amazon_s3_bucket',
					'name' => __( 'Amazon S3 Bucket', 'edd' ),
					'desc' => sprintf( __( 'To create new buckets or get a listing of your current buckets, go to your <a href="%s">S3 Console</a> (you must be logged in to access the console).  Your buckets will be listed on the left.  Enter the name of the bucket you would like to use here.', 'edd' ), esc_url( 'https://console.aws.amazon.com/s3/home' ) ),
					'type' => 'text'
		);

		return $settings;
	}

	public static function activate_license() {
		global $edd_options;
		if( ! isset( $_POST['edd_settings_misc'] ) )
			return;
		if( ! isset( $_POST['edd_settings_misc']['edd_amazon_s3_license_key'] ) )
			return;

		if( get_option( 'edd_amazon_s3_license_active' ) == 'valid' )
			return;

		$license = sanitize_text_field( $_POST['edd_settings_misc']['edd_amazon_s3_license_key'] );

		// data to send in our API request
		$api_params = array(
			'edd_action'=> 'activate_license',
			'license' 	=> $license,
			'item_name' => urlencode( EDD_AS3_SL_PRODUCT_NAME ) // the name of our product in EDD
		);

		// Call the custom API.
		$response = wp_remote_get( add_query_arg( $api_params, EDD_AS3_SL_STORE_API_URL ) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) )
			return false;

		// decode the license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		update_option( 'edd_amazon_s3_license_active', $license_data->license );

	}

	public static function get_s3_files( $marker = null, $max = null ) {

		$s3       = new S3( self::$access_id, self::$secret_key, is_ssl() );
		$bucket   = self::$bucket;
		$contents = $s3->getBucket( $bucket, null, $marker, $max );

		return $contents;
	}

	public static function get_s3_url( $filename ) {

		$s3     = new S3( self::$access_id, self::$secret_key, is_ssl() );
		$bucket = self::$bucket;
		$url 	= $s3->getAuthenticatedURL( $bucket, $filename, ( 60 * 60 * 24 * 365 * 10 ), false, true );

		return $url;
	}

	public static function url_intercept( $url, $post_id ) {

		//We only want to intercept the URL of attachments of downloads
		if ( 'download' != get_post_field( 'post_type', get_post_field( 'post_parent', $post_id ) ) )
			return $url;

		//Give room for future development to have a turn-off switch, per product.  Perhaps someone uploads to S3, but then changes their mind ( or S3 goes down :) )
		if ( apply_filters( 'edd_s3_url_intercept_override', false, $url, $post_id ) )
			return $url;

		//We only want to intercept the URL when the attachment was actually uploaded to S3.
		if ( false == ( $s3_url = get_post_meta( $post_id, 's3_url', true ) ) )
			return $url;

		return $s3_url;
	}

	public static function upload_handler( $file_array, $context ) {

		if ( 'upload' != $context )
			return $file_array;

		if ( 's3' != $_REQUEST['tab'] )
			return $file_array;

		$file = $file_array['file'];
		$url  = $file_array['url'];
		$type = $file_array['type'];

		$s3       = new S3( self::$access_id, self::$secret_key, false );
		$bucket   = self::$bucket;
		$resource = $s3->inputFile( $file );
		$resource['type'] = $type;
		$push_file = $s3->putObject( $resource, $bucket, basename( $url ) );

		return $file_array;
	}

	public static function add_post_meta( $att_id ) {

		if ( 's3' != $_REQUEST['tab'] )
			return;

		//Get URL
		$on_site_url = wp_get_attachment_url( $att_id );

		$s3_url = self::get_s3_url( basename( $on_site_url ) );

		update_post_meta( $att_id, 's3_url', $s3_url );
	}

	public static function default_tab( $default ) {
		return 's3';
	}

	public static function requested_file_name( $file_name ) {
		if( false !== ( $s3 = strpos( $file_name, 'AWSAccessKeyId' ) ) ) {
			$s3_part = substr( $file_name, $s3, strlen( $file_name) );
			$file_name = str_replace( $s3_part, '', $file_name );
			$file_name = substr( $file_name, 0, -1);
		}
		return $file_name;
	}

	public static function admin_js() {
		?>
		<script type="text/javascript">
			//<![CDATA[
			jQuery(function($){
				$('body').on('click', '.edd_upload_image_button', function(e) {

					window.edd_formfield = $(this).parent().prev();

				});

				window.edd_s3_send_to_editor = window.send_to_editor;
				window.send_to_editor = function (html) {
					if (window.edd_formfield) {
						imgurl = $('a', '<div>' + html + '</div>').attr('href');
						window.edd_formfield.val(imgurl);
						window.clearInterval(window.tbframe_interval);
						tb_remove();
					} else {
						window.edd_s3_send_to_editor(html);
					}
					window.edd_formfield = '';
					window.imagefield = false;
				}
			});
			//]]>
		</script>
		<?php
	}

}

$GLOBALS['edd_s3'] = new EDD_Amazon_S3();