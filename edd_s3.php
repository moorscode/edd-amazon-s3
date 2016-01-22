<?php
/*
Plugin Name: Easy Digital Downloads - Amazon S3
Plugin URI: http://easydigitaldownloads.com/extension/amazon-s3/
Description: Amazon S3 integration with EDD.  Allows you to upload or download directly from your S3 bucket. Configure on Settings > Extensions tab
Version: 2.2.1
Author: Justin Sainton, Pippin Williamson & Brad Vincent
Author URI:  http://www.zao.is
Contributors: JustinSainton, mordauk
Text Domain: edd_s3
Domain Path: languages
*/

class EDD_Amazon_S3 {

	private static $instance;
	private $access_id;
	private $secret_key;
	private $bucket;
	private $default_expiry;
	private $s3;

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

		if ( ! self::$instance ) {
			self::$instance = new EDD_Amazon_S3();
		}

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
	private function __construct() {

		global $edd_options;

		if( ! class_exists( 'Easy_Digital_Downloads' ) ) {
			return;
		}

		$this->access_id      = isset( $edd_options['edd_amazon_s3_id'] )             ? trim( $edd_options['edd_amazon_s3_id'] )             : '';
		$this->secret_key     = isset( $edd_options['edd_amazon_s3_key'] )            ? trim( $edd_options['edd_amazon_s3_key'] )            : '';
		$this->bucket         = isset( $edd_options['edd_amazon_s3_bucket'] )         ? trim( $edd_options['edd_amazon_s3_bucket'] )         : '';
		$this->default_expiry = isset( $edd_options['edd_amazon_s3_default_expiry'] ) ? trim( $edd_options['edd_amazon_s3_default_expiry'] ) : '5';

		$this->constants();
		$this->includes();
		$this->load_textdomain();
		$this->init();

		$this->s3 = new S3( $this->access_id, $this->secret_key, is_ssl(), $this->get_host() );

		if( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->s3->setExceptions();
		}
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
		define( 'EDD_AS3_VERSION', '2.2.1' );

		// Set the core file path
		define( 'EDD_AS3_FILE_PATH', dirname( __FILE__ ) );

		// Define the path to the plugin folder
		define( 'EDD_AS3_DIR_NAME' , basename( EDD_AS3_FILE_PATH ) );

		// Define the URL to the plugin folder
		define( 'EDD_AS3_FOLDER'   , dirname( plugin_basename( __FILE__ ) ) );
		define( 'EDD_AS3_URL'      , plugins_url( '', __FILE__ ) );

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

		if ( ! class_exists( 'S3' ) ) {
			include_once EDD_AS3_FILE_PATH . '/s3.php';
		}

	}

		/**
		 * Internationalization
		 *
		 * @access      public
		 * @since       2.1.9
		 * @return      void
		 */
		public function load_textdomain() {
		// Set filter for language directory
		$lang_dir = EDD_AS3_FILE_PATH . '/languages/';
		$lang_dir = apply_filters( 'edd_amazon_s3_languages_directory', $lang_dir );

		// Traditional WordPress plugin locale filter
		$locale = apply_filters( 'plugin_locale', get_locale(), 'edd-amazon-s3' );
		$mofile = sprintf( '%1$s-%2$s.mo', 'edd-amazon-s3', $locale );

		// Setup paths to current locale file
		$mofile_local   = $lang_dir . $mofile;
		$mofile_global  = WP_LANG_DIR . '/edd-amazon-s3/' . $mofile;

		if( file_exists( $mofile_global ) ) {
			// Look in global /wp-content/languages/edd-amazon-s3/ folder
			load_textdomain( 'edd_s3', $mofile_global );
		} elseif( file_exists( $mofile_local ) ) {
			// Look in local /wp-content/plugins/edd-amazon-s3/languages/ folder
			load_textdomain( 'edd_s3', $mofile_local );
		} else {
			// Load the default language files
			load_plugin_textdomain( 'edd_s3', false, $lang_dir );
		}
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

		if( class_exists( 'EDD_License' ) ) {
			$edds3_license = new EDD_License( __FILE__, EDD_AS3_SL_PRODUCT_NAME, EDD_AS3_VERSION, 'Pippin Williamson', 'edd_amazon_s3_license_key' );
		}

		//Adds Media Tab
		add_filter( 'media_upload_tabs'       , array( $this, 's3_tabs' ) );
		add_action( 'media_upload_s3'         , array( $this, 's3_upload_iframe' ) );
		add_action( 'media_upload_s3_library' , array( $this, 's3_library_iframe' ) );

		//Adds settings to Misc Tab
		add_filter( 'edd_settings_extensions' , array( $this, 'add_settings' ) );

		//Handles Uploading to S3
		add_filter( 'edd_s3_upload'  , array( $this, 'upload_handler' ), 10, 2 );

		// modify the file name on download
		add_filter( 'edd_requested_file_name', array( $this, 'requested_file_name' ) );

		// intercept the file download and generate an expiring link
		add_filter( 'edd_requested_file', array( $this, 'generate_url' ), 10, 3 );
		add_action( 'edd_process_verified_download', array( $this, 'add_set_download_method' ), 10, 4 );

		// add some javascript to the admin
		add_action( 'admin_head', array( $this, 'admin_js' ) );

		add_filter( 'fes_validate_multiple_pricing_field', array( $this, 'bad_url' ), 10, 2 );
		add_filter( 'fes_pre_files_save', array( $this, 'send_fes_files_to_s3' ), 10, 2 );

		add_action( 'admin_notices', array( $this, 'show_admin_notices' ), 10 );

	}

	public function show_admin_notices() {

		if ( empty( $this->access_id ) || empty( $this->secret_key ) ) {
			$url = admin_url( 'edit.php?post_type=download&page=edd-settings&tab=extensions' );
			echo '<div class="update error"><p>' . sprintf( __( 'Please enter your <a href="%s">Amazon S3 Access Key ID and Secret Key</a>', 'edd_s3' ), $url ) . '</p></div>';
		}


	}

	public function s3_tabs( $tabs ) {

		if ( ! wp_script_is( 'fes_form', 'enqueued' ) ) {

			$tabs['s3']         = __( 'Upload to Amazon S3', 'edd_s3' );
			$tabs['s3_library'] = __( 'Amazon S3 Library', 'edd_s3' );

		}

		return $tabs;
	}

	public function s3_upload_iframe() {

		if ( ! empty( $_POST ) ) {
			$return = media_upload_form_handler();
			if ( is_string( $return ) )
				return $return;
		}

		wp_iframe( array( $this, 's3_upload_download_tab' ) );
	}

	public function s3_upload_download_tab( $type = 'file', $errors = null, $id = null ) {

		wp_enqueue_style( 'media' );

		$form_action_url = esc_url( add_query_arg( array( 'edd_action' => 's3_upload' ), admin_url() ) );
?>
		<style>
		.edd_errors { -webkit-border-radius: 2px; -moz-border-radius: 2px; border-radius: 2px; border: 1px solid #E6DB55; margin: 0 0 21px 0; background: #FFFFE0; color: #333; }
		.edd_errors p { margin: 10 15px; padding: 0 10px; }
		</style>
		<script>
		jQuery(document).ready(function($) {
			$('.edd-s3-insert').click(function() {
				var file   = "<?php echo EDD()->session->get( 's3_file_name' ); ?>";
				var bucket = "<?php echo EDD()->session->get( 's3_file_bucket' ); ?>";
				$(parent.window.edd_filename).val(file);
				$(parent.window.edd_fileurl).val(bucket + '/' + file);
				parent.window.tb_remove();
			});
		});
		</script>
		<div class="wrap">
<?php
			if( ! $this->api_keys_entered() ) :
?>
			<div class="error"><p><?php printf( __( 'Please enter your <a href="%s" target="_blank">Amazon S3 API keys</a>.', 'edd_s3' ), admin_url( 'edit.php?post_type=download&page=edd-settings&tab=extensions' ) ); ?></p></div>
<?php
				return;
			endif;

			$buckets = $this->get_s3_buckets();
			if ( empty( $buckets ) ) {
				$errors = edd_get_errors();
				if ( array_key_exists( 'edd-amazon-s3', $errors ) ) {
					if ( current_user_can( 'manage_options' ) ) {
						$message = $errors['edd-amazon-s3'];
					} else {
						$message = __( 'Error retrieving file. Please contact the site administrator.', 'edd_s3' );
					}

					echo '<div class="update error"><p>' . $message . '</p></div>';
					exit;
				}
			}
?>

			<form enctype="multipart/form-data" method="post" action="<?php echo esc_attr( $form_action_url ); ?>" class="edd-s3-upload">
				<p>
					<select name="edd_s3_bucket" id="edd_s3_bucket">
					<?php foreach ( $buckets as $key => $bucket ) : ?>
						<option value="<?php echo $bucket; ?>"><?php echo $bucket; ?></option>
					<?php endforeach; ?>
					</select>
					<label for="edd_s3_bucket"><?php _e( 'Select a bucket to upload the file to', 'edd_s3' ); ?></label>
				</p>
				<p>
					<input type="file" name="edd_s3_file"/>
				</p>
				<p>
					<input type="submit" class="button-secondary" value="<?php esc_attr_e( 'Upload to S3', 'edd_s3' ); ?>"/>
				</p>
<?php
				if( ! empty( $_GET['s3_success'] ) && '1' == $_GET['s3_success'] ) {
					echo '<div class="edd_errors"><p class="edd_success">' . sprintf( __( 'Success! <a href="#" class="edd-s3-insert">Insert uploaded file into %s</a>.', 'edd_s3' ), edd_get_label_singular() ) . '</p></div>';
				}
?>
			</form>
		</div>
<?php
	}

	public function s3_library_iframe() {

		if ( ! empty( $_POST ) ) {
			$return = media_upload_form_handler();
			if ( is_string( $return ) )
				return $return;
		}

		wp_iframe( array( $this, 's3_library_tab' ) );
	}

	public function s3_library_tab( $type = 'file', $errors = null, $id = null ) {

		media_upload_header();
		wp_enqueue_style( 'media' );

		$page     = isset( $_GET['p'] ) ? $_GET['p'] : 1;
		$per_page = 30;
		$offset   = $per_page * ( $page - 1 );
		$offset   = $offset < 1 ? 30 : $offset;
		$start    = isset( $_GET['start'] )  ? rawurldecode( $_GET['start'] )  : '';
		$bucket   = isset( $_GET['bucket'] ) ? rawurldecode( $_GET['bucket'] ) : false;

		if( ! $bucket ) {

			$buckets = $this->get_s3_buckets();
			if ( false === $buckets ) {
				$errors = edd_get_errors();
				if ( array_key_exists( 'edd-amazon-s3', $errors ) ) {
					if ( current_user_can( 'manage_options' ) ) {
						$message = $errors['edd-amazon-s3'];
					} else {
						$message = __( 'Error retrieving file. Please contact the site administrator.', 'edd_s3' );
					}

					echo '<div class="update error"><p>' . $message . '</p></div>';
					exit;
				}
			}
		} else {

			$this->bucket = $bucket;
			$files = $this->get_s3_files( $start, $offset );

			if ( false === $files ) {
				$errors = edd_get_errors();
				if ( array_key_exists( 'edd-amazon-s3', $errors ) ) {
					if ( current_user_can( 'manage_options' ) ) {
						$message = $errors['edd-amazon-s3'];
					} else {
						$message = __( 'Error retrieving file. Please contact the site administrator.', 'edd_s3' );
					}

					echo '<div class="update error"><p>' . $message . '</p></div>';
					exit;
				}
			}

		}
?>
		<script type="text/javascript">
			//<![CDATA[
			jQuery(function($){
				$('.insert-s3').on('click', function() {
					var file = $(this).data('s3');
					$(parent.window.edd_filename).val(file);
					$(parent.window.edd_fileurl).val( "<?php echo $this->bucket; ?>/" + file);
					parent.window.tb_remove();
				});
			});
			//]]>
		</script>
		<div style="margin: 20px 1em 1em; padding-right:20px;" id="media-items">
<?php
			if( ! $this->api_keys_entered() ) :
?>
			<div class="error"><p><?php printf( __( 'Please enter your <a href="%s" target="blank">Amazon S3 API keys</a>.', 'edd_s3' ), admin_url( 'edit.php?post_type=download&page=edd-settings&tab=extensions' ) ); ?></p></div>
<?php
				return;
			endif;

			if( ! $bucket ) { ?>
				<h3 class="media-title"><?php _e('Select a Bucket', 'edd_s3'); ?></h3>
				<?php

				if( is_array( $buckets ) ) {

					echo '<table class="wp-list-table widefat fixed striped" style="max-height: 500px;overflow-y:scroll;">';

						echo '<tr>';
							echo '<th>' . __( 'Bucket name', 'edd_s3' ) . '</th>';
							echo '<th>' . __( 'Actions', 'edd_s3' ) . '</th>';
						echo '</tr>';

						foreach ( $buckets as $key => $bucket ) {
							echo '<tr>';
								echo '<td>' . $bucket . '</td>';
								echo '<td>';
									echo '<a href="' . esc_url( add_query_arg( 'bucket', $bucket ) ) . '">' . __( 'Browse', 'edd_s3' ) . '</a>';
								echo '</td>';
							echo '</tr>';

						}
					echo '</table>';
				}

			} else {

				$back = admin_url( 'media-upload.php?post_id=' . absint( $_GET['post_id'] ) );

				if( is_array( $files ) ) {
					$i = 0;
					$total_items = count( $files );

					echo '<p><button class="button-secondary" onclick="history.back();">' . __( 'Go Back', 'edd_s3' ) . '</button></p>';

					echo '<table class="wp-list-table widefat fixed striped" style="max-height: 500px;overflow-y:scroll;">';

						echo '<tr>';
							echo '<th>' . __( 'File name', 'edd_s3' ) . '</th>';
							echo '<th>' . __( 'Actions', 'edd_s3' ) . '</th>';
						echo '</tr>';

						foreach ( $files as $key => $file ) {
							echo '<tr>';
								if( $i == 0)
									$first_file = $key;

								if( $i == 14 )
									$last_file = $key;

								if( $file['name'][ strlen( $file['name'] ) - 1 ] === '/' ) {
									continue; // Don't show folders
								}

								echo '<td style="padding-right:20px;">' . $file['name'] . '</td>';
								echo '<td>';
									echo '<a class="insert-s3 button-secondary" href="#" data-s3="' . esc_attr( $file['name'] ) . '">' . __( 'Use File', 'edd_s3' ) . '</a>';
								echo '</td>';
							echo '</tr>';
							$i++;
						}
					echo '</table>';
				}

				$base = admin_url( 'media-upload.php?post_id=' . absint( $_GET['post_id'] ) . '&tab=s3_library' );

				if( $bucket ) {
					$base = add_query_arg( 'bucket', $bucket, $base );
				}

				echo '<div class="s3-pagination tablenav">';
					echo '<div class="tablenav-pages alignright">';
						if( isset( $_GET['p'] ) && $_GET['p'] > 1 )
							echo '<a class="page-numbers prev" href="' . esc_url( remove_query_arg( 'p', $base ) ) . '">' . __( 'Start Over', 'edd_s3' ) . '</a>';
						if( $i >= 10)
							echo '<a class="page-numbers next" href="' . esc_url( add_query_arg( array( 'p' => $page + 1, 'start' => $last_file ), $base ) ) . '">' . __( 'View More', 'edd_s3' ) . '</a>';
					echo '</div>';
				echo '</div>';
			}
			?>
		</div>
<?php
	}

	public function get_s3_buckets( $marker = null, $max = null ) {
		return $this->s3->listBuckets();
	}

	public function get_s3_files( $marker = null, $max = null ) {
		return $this->s3->getBucket( $this->bucket, null, $marker, $max );
	}

	public function get_s3_url( $filename, $expires = 5 ) {

		if( false !== strpos( $filename, '/' ) ) {

			$parts    = explode( '/', $filename );
			$bucket   = $parts[0];
			$buckets  = $this->get_s3_buckets();

			if ( empty( $buckets ) ) {
				$errors = edd_get_errors();
				if ( array_key_exists( 'edd-amazon-s3', $errors ) ) {
					if ( current_user_can( 'manage_options' ) ) {
						wp_die( $errors['edd-amazon-s3'] );
					} else {
						wp_die( __( 'Error retrieving file. Please contact the site administrator.', 'edd_s3' ) );
					}
				}
			}

			if( in_array( $bucket, $buckets ) ) {

				$filename = preg_replace( '#^' . $parts[0] . '/#', '', $filename, 1 );

			} else {

				$bucket = $this->bucket;

			}

		} else {

			$bucket = $this->bucket;

		}

		$url = $this->s3->getAuthenticatedURL( $bucket, $filename, ( 60 * $expires ), false, is_ssl() );

		return $url;
	}

	public function upload_handler() {

		if( ! is_admin() ) {
			return;
		}

		$s3_upload_cap = apply_filters( 'edd_s3_upload_cap', 'edit_products' );

		if( ! current_user_can( $s3_upload_cap ) ) {
			wp_die( __( 'You do not have permission to upload files to S3', 'edd_s3' ) );
		}

		if( empty( $_FILES['edd_s3_file'] ) || empty( $_FILES['edd_s3_file']['name'] ) ) {
			wp_die( __( 'Please select a file to upload', 'edd_s3' ), __( 'Error', 'edd_s3' ), array( 'back_link' => true ) );
		}

		$file = array(
			'bucket' => $_POST['edd_s3_bucket'],
			'name'   => $_FILES['edd_s3_file']['name'],
			'file'   => $_FILES['edd_s3_file']['tmp_name'],
			'type'   => $_FILES['edd_s3_file']['type']
		);

		if( $this->upload_file( $file ) ) {
			EDD()->session->set( 's3_file_name', $file['name'] );
			EDD()->session->set( 's3_file_bucket', $file['bucket'] );
			wp_safe_redirect( add_query_arg( 's3_success', '1', $_SERVER['HTTP_REFERER'] ) ); exit;
		} else {
			wp_die( __( 'Something went wrong during the upload process', 'edd_s3' ), __( 'Error', 'edd_s3' ), array( 'back_link' => true ) );
		}
	}

	public function upload_file( $file = array() ) {

		$bucket            = empty( $file['bucket'] ) ? $this->bucket : $file['bucket'];

		$resource          = $this->s3->inputFile( $file['file'] );
		$resource['type']  = $file['type'];

		$push_file = $this->s3->putObject( $resource, $bucket, $file['name'] );

		if( $push_file ) {
			return true;
		} else {
			return false;
		}
	}

	public function requested_file_name( $file_name ) {
		if( false !== ( $s3 = strpos( $file_name, 'AWSAccessKeyId' ) ) ) {
			$s3_part = substr( $file_name, $s3, strlen( $file_name) );
			$file_name = str_replace( $s3_part, '', $file_name );
			$file_name = substr( $file_name, 0, -1);
		}
		return $file_name;
	}

	public function get_host() {
		global $edd_options;
		return ! empty( $edd_options['edd_amazon_s3_host'] ) ? trim( $edd_options['edd_amazon_s3_host'] ) : 's3.amazonaws.com';
	}

	public function admin_js() {
		?>
		<script type="text/javascript">
			//<![CDATA[
			jQuery(function($){
				$('body').on('click', '.edd_upload_file_button', function(e) {

					window.edd_fileurl = $(this).parent().prev().find('input');
					window.edd_filename = $(this).parent().parent().parent().prev().find('input');

				});
			});
			//]]>
		</script>
		<?php
	}

	public function generate_url($file, $download_files, $file_key) {
		$file_data = $download_files[$file_key];
		$file_name = $file_data['file'];

		// Check whether thsi is an Amazon S3 file or not
		if( ( '/' !== $file_name[0] && strpos( $file_data['file'], 'http://' ) === false && strpos( $file_data['file'], 'https://' ) === false && strpos( $file_data['file'], 'ftp://' ) === false )|| false !== ( strpos( $file_name, 'AWSAccessKeyId' ) ) ) {

			$expires = $this->default_expiry;

			if( false !== ( strpos( $file_name, 'AWSAccessKeyId' ) ) ) {
				//we are dealing with a URL prior to Amazon S3 extension 1.4
				$file_name = $this->cleanup_filename($file_name);

				//if we still get back the old format then there is something wrong here and just return the old filename
				if( false !== ( strpos( $file_name, 'AWSAccessKeyId' ) ) ) {
					return $file_name;
				}
			}

			return set_url_scheme( $this->get_s3_url( $file_name , $expires ), 'http' );
		}
		return $file;
	}

	public function add_set_download_method( $download, $email, $payment, $args = array() ) {

		if( empty( $args ) ) {
			return;
		}

		if( $this->is_s3_download( $download, $args['file_key'] ) ) {

			add_filter( 'edd_file_download_method', array( $this, 'set_download_method' ) );

		}

	}

	public function set_download_method( $method ) {
		return 'redirect';
	}

	private function is_s3_download( $download_id = 0, $file_id = 0 ) {

		$ret   = false;
		$files = edd_get_download_files( $download_id );

		if( isset( $files[ $file_id ] ) ) {

			$file_name = $files[ $file_id ]['file'];

			// Check whether thsi is an Amazon S3 file or not
			if( ( '/' !== $file_name[0] && strpos( $file_name, 'http://' ) === false && strpos( $file_name, 'https://' ) === false && strpos( $file_name, 'ftp://' ) === false ) || false !== ( strpos( $file_name, 'AWSAccessKeyId' ) ) ) {

				$ret = true;

			}

		}

		return $ret;
	}

	public function cleanup_filename($old_file_name) {
		//strip all amazon querystrings
		//strip amazon host from url

		if ( $url = parse_url( $old_file_name ) ) {
			return ltrim( $url['path'], '/' );
		}

		return $old_file_name;
	}

	public function add_settings( $settings ) {

		$settings[] = array(
					'id'   => 'amazon_s3_settings',
					'name' => __( '<strong>Amazon S3 Settings</strong>', 'edd_s3' ),
					'desc' => '',
					'type' => 'header'
		);

		$settings[] = array(
					'id'   => 'edd_amazon_s3_id',
					'name' => __( 'Amazon S3 Access Key ID', 'edd_s3' ),
					'desc' => __( 'After logging into your S3 account, click on "Security Credentials" in the sidebar.  Scroll down to "Access Credentials" and you will see your Access Key ID.  Copy and paste it here.', 'edd_s3' ),
					'type' => 'text',
					'size' => 'regular'
		);

		$settings[] = array(
					'id'   => 'edd_amazon_s3_key',
					'name' => __( 'Amazon S3 Secret Key', 'edd_s3' ),
					'desc' => __( 'In the same Access Credentials area, your "Secret Key" will be hidden.  You will need to click the "Show" link to see it.  Copy and paste it here.', 'edd_s3' ),
					'type' => 'text',
					'size' => 'regular'
		);

		$settings[] = array(
					'id'   => 'edd_amazon_s3_bucket',
					'name' => __( 'Amazon S3 Bucket', 'edd_s3' ),
					'desc' => sprintf( __( 'To create new buckets or get a listing of your current buckets, go to your <a href="%s">S3 Console</a> (you must be logged in to access the console).  Your buckets will be listed on the left.  Enter the name of the default bucket you would like to use here.', 'edd_s3' ), esc_url( 'https://console.aws.amazon.com/s3/home' ) ),
					'type' => 'text'
		);

		$settings[] = array(
					'id'   => 'edd_amazon_s3_host',
					'name' => __( 'Amazon S3 Host', 'edd_s3' ),
					'desc' => __( 'Set the host you wish to use. Leave default if you do not know what this is for', 'edd_s3' ),
					'type' => 'text',
					'std'  => 's3.amazonaws.com'
		);

		$settings[] = array(
					'id'   => 'edd_amazon_s3_default_expiry',
					'name' => __( 'Link Expiry Time', 'edd_s3' ),
					'desc' => __( 'Amazon S3 links expire after a certain amount of time. This default number of minutes will be used when capturing file downloads, but can be overriden per file if needed.', 'edd_s3' ),
					'std' => '5',
					'type' => 'text'
		);

		return $settings;
	}

	public function api_keys_entered() {

		$id  = edd_get_option( 'edd_amazon_s3_id' );
		$key = edd_get_option( 'edd_amazon_s3_key' );

		if( empty( $id ) || empty( $key ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Tells FES that Amazon S3 URLs are valid
	 *
	 * @since 2.2.1
	 *
	 * @access public
	 * @return bool
	 */
	public function bad_url( $error, $value = '' ) {

		if( $error ) {
			$ext   = edd_get_file_extension( $value );
			$error = ! empty( $ext ); 
		}

		return $error;
	}


	/**
	 * Uploads files to Amazon S3 during FES form submissions
	 *
	 * Only runs if Frontend Submissions is active
	 *
	 * @since 2.1
	 *
	 * @access public
	 * @return array
	 */
	public function send_fes_files_to_s3( $files = array(), $post_id = 0 ) {

		if( ! function_exists( 'fes_get_attachment_id_from_url' ) ) {
			return $files;
		}

		if( ! empty( $files ) && is_array( $files ) ) {

			foreach( $files as $key => $file ) {

				$attachment_id = fes_get_attachment_id_from_url( $file['file'], get_current_user_id() );
				if( ! $attachment_id ) {
					continue;
				}

				$user   = get_userdata( get_current_user_id() );
				$folder = trailingslashit( $user->user_login );
				$args   = array(
					'file' => get_attached_file( $attachment_id, false ),
					'name' => $folder . basename( $file['name'] ),
					'type' => get_post_mime_type( $attachment_id )
				);

				$this->upload_file( $args );

				$files[ $key ]['file'] = edd_get_option( 'edd_amazon_s3_bucket' ) . '/' . $folder . basename( $file['file'] );

				wp_delete_attachment( $attachment_id, true );


			}

		}

		return $files;

	}
}

function edd_s3_load() {
	$GLOBALS['edd_s3'] = EDD_Amazon_S3::get_instance();
}
add_action( 'plugins_loaded', 'edd_s3_load' );
