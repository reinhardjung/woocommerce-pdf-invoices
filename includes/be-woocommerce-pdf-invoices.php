<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'BE_WooCommerce_PDF_Invoices' ) ) {

	/**
	 * Implements main function for attaching invoice to email and show invoice buttons.
	 */
	final class BE_WooCommerce_PDF_Invoices {

		private $lang_code = 'en-US';

		private $options_key = 'bewpi-invoices';

		public $settings_tabs = array();

		public $general_options = array();

		public $template_options = array();

		public function __construct() {
			$this->lang_code = get_bloginfo( "language" );
			new BEWPI_General_Settings();
			new BEWPI_Template_Settings();

			do_action( 'bewpi_after_init_settings' );

			/**
			 * Initialize.
			 */
			add_action( 'init', array( $this, 'init' ) );

			/**
			 * Initialize admin.
			 */
			add_action( 'admin_init', array( $this, 'admin_init' ) );

			/**
			 * Initialize collizo4sky/persist-admin-notices-dismissal.
			 */
			add_action( 'admin_init', array( 'PAnD', 'init' ) );

			/**
			 * Add "Invoices" submenu to WooCommerce menu.
			 */
			add_action( 'admin_menu', array( $this, 'add_woocommerce_submenu_page' ) );

			/**
			 * Admin notice to rate plugin on wordpress.org.
			 */
			add_action( 'admin_notices', array( $this, 'admin_notice_rate' ) );

			/**
			 * Admin notice to display settings page.
			 */
			add_action( 'admin_notices', array( $this, 'admin_notice_activation' ) );

			/**
			 * Admin notice to ask user to fill in a form on wcpdfinvoices.com.
			 */
			add_action( 'wp_ajax_bewpi_deactivation_notice', array( $this, 'admin_notice_deactivation' ) );

			/**
			 * Enqueue admin scripts
			 */
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

			/**
			 * Add actions to overview order page.
			 */
			add_action( 'woocommerce_admin_order_actions_end', array(
				$this,
				'woocommerce_order_page_action_view_invoice'
			) );

			/**
			 * Adds a meta box to the order details page.
			 */
			add_action( 'add_meta_boxes', array( $this, 'add_meta_box_to_order_page' ) );

			/**
			 * Adds the Email It In email as an extra recipient
			 */
			add_filter( 'woocommerce_email_headers', array(
				$this,
				'add_email_it_in_account_to_email_headers'
			), 10, 2 );

			/**
			 * Attach invoice to a specific WooCommerce email
			 */
			add_filter( 'woocommerce_email_attachments', array( $this, 'attach_invoice_to_email' ), 99, 3 );

            /**
             * Attach invoice to a new order email
             */
            add_filter( 'woocommerce_email_attachments', array( &$this, 'attach_invoice_to_new_order_email' ), 99, 3 );

			/**
			 * Adds a download link for the pdf invoice on the my account page
			 */
			add_filter( 'woocommerce_my_account_my_orders_actions', array(
				$this,
				'add_my_account_download_pdf_action'
			), 10, 2 );

			/**
			 * Shortcode to display invoice from view
			 */
			add_shortcode( 'bewpi-download-invoice', array( $this, 'bewpi_download_invoice_func' ) );

            add_action('wp_trash_post', array( $this, 'delete_invoice' ), 10, 1);

            add_action('before_delete_post', array( $this, 'delete_invoice' ), 10, 1);
		}

		/**
		 * Initialize.
		 */
		public function init() {
			$this->init_settings_tabs();
			$this->create_bewpi_dirs();
			$this->invoice_actions();
		}

		/**
		 * Initialize admin.
		 */
		public function admin_init() {
			// Add plugin action links on "Plugins" page.
			add_filter( 'plugin_action_links_woocommerce-pdf-invoices/bootstrap.php', array( $this, 'add_plugin_action_links' ) );
		}

		/**
		 * Save plugin install date as site option.
		 */
		private static function save_install_date() {
			$now = (new \DateTime())->format( 'Y-m-d' );
			update_site_option( 'bewpi-install-date', $now );
		}

		/**
		 * Get plugin install date.
		 *
		 * @return DateTime|bool
		 */
		private static function get_install_date() {
			$install_date = get_site_option( 'bewpi-install-date' );

			if ( false !== $install_date ) {
				$install_date = DateTime::createFromFormat( 'Y-m-d', $install_date );
			}

			return $install_date;
		}

		/**
		 * Plugin activation.
		 */
		public static function plugin_activation() {
			// to ask administrator to rate plugin on wordpress.org.
			self::save_install_date();
			// use transient to display activation admin notice.
			set_transient( 'bewpi-admin-notice-activation', true, 30 );
		}

		/**
		 * Admin notice for administrator to rate plugin on wordpress.org.
		 */
		public function admin_notice_rate() {
			// notice needs to be inactive.
			if ( ! PAnD::is_admin_notice_active( 'rate-forever' ) ) {
				return;
			}

			// user needs to be an administrator and can install plugins.
			if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'install_plugins' ) ) {
				return;
			}

			// install date should be valid.
			$install_date = self::get_install_date();
			if ( false === $install_date ) {
				return;
			}

			// at least 10 days should be past to display notice.
			if ( new DateTime( '-10 second' ) >= $install_date ) {
				include( BEWPI_DIR . 'includes/admin/views/html-rate-notice.php' );
			}
		}

		/**
		 * Admin notice to configure plugin when activated.
		 */
		public function admin_notice_activation() {
			// notice needs to be inactive.
			if ( ! PAnD::is_admin_notice_active( 'activation-forever' ) ) {
				return;
			}

			// check if plugin has been activated by checking transient that has been set on plugin activation.
			if ( ! get_transient( 'bewpi-admin-notice-activation' ) ) {
				return;
			}

			include( BEWPI_DIR . 'includes/admin/views/html-activation-notice.php' );
			delete_transient( 'bewpi-admin-notice-activation' );
		}

		/**
		 * AJAX backend deactivation notice.
		 */
		public function admin_notice_deactivation() {
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'bewpi_deactivation_notice' ) ) { // Input var okay.
				die( 0 );
			}

			ob_start();
			include BEWPI_DIR . 'includes/admin/views/html-deactivation-notice.php';
			$content = ob_get_clean();
			die( $content ); // WPCS: XSS OK.
		}

        public function delete_invoice($post_id){
            $type = get_post_type($post_id);
            if ( $type === 'shop_order' ) {
                $invoice = new BEWPI_Invoice( $post_id );
                $invoice->delete();
            }
        }

		/**
		 * Add plugin action links on plugin.php page.
		 *
		 * @param array $links action links.
		 *
		 * @return array
		 */
		function add_plugin_action_links( $links ) {
			// add onclick event to deactivate link to display reason for deactivation admin notice.
			$dom = new DOMDocument();
			$dom->loadHTML( $links['deactivate'] );
			$anchors = $dom->getElementsByTagName( 'a' );
			foreach ( $anchors as $node ) {
				$node->setAttribute( 'id', 'bewpi-deactivate' );
				$node->setAttribute( 'onclick', 'BEWPI.Settings.displayDeactivationNotice()' );
				$links['deactivate'] = $dom->saveHTML( $node );
				break;
			}

			// add settings link.
			$settings_url = admin_url( 'admin.php?page=bewpi-invoices' );
			$settings_title = __( 'Settings', 'woocommerce-pdf-invoices' );
			$additional_links[] = sprintf( '<a href="%1$s">%2$s</a>', $settings_url, $settings_title );

			// add premium plugin link.
			$premium_url = 'http://wcpdfinvoices.com';
			$premium_title = __( 'Premium', 'woocommerce-pdf-invoices' );
			$additional_links[] = sprintf( '<a href="%1$s" target="_blank">%2$s</a>', $premium_url, $premium_title );

			$links = array_merge( $additional_links, $links );
			return $links;
		}

		public function bewpi_download_invoice_func( $atts ) {
            if(!isset($atts["order_id"]))
            {
                return;
            }

            $order_id = $atts["order_id"];
            $order = wc_get_order( $order_id );
			$title = $atts[ 'title' ];
			$invoice = new BEWPI_Invoice( $order->id );

			if ( $invoice->exists() && $invoice->is_download_allowed( $order->post_status ) ) {
				$url = admin_url( 'admin-ajax.php?bewpi_action=view&post=' . $order->id . '&nonce=' . wp_create_nonce( 'view' ) );

				$tags = array (
					'{formatted_invoice_number}'    => $invoice->formatted_number,
					'{order_number}'                => $order->id,
					'{formatted_invoice_date}'      => $invoice->get_formatted_invoice_date(),
					'{formatted_order_date}'        => $invoice->get_formatted_order_date()
				);

				$title = str_replace( array_keys( $tags ), array_values( $tags ), $title );

				// example: Download (PDF) Invoice {formatted_invoice_number}
				echo '<a href="' . esc_attr( $url ) . '" alt="' . esc_attr( $title ) . '">' . esc_html( $title ) . '</a>';
			}
		}

		/**
		 * Callback to sniff for specific plugin actions to view, create or delete invoice.
		 */
		private function invoice_actions() {
			if ( isset( $_GET['bewpi_action'] ) && isset( $_GET['post'] ) && is_numeric( $_GET['post'] ) && isset( $_GET['nonce'] ) ) {
				$action   = $_GET['bewpi_action'];
				$order_id = $_GET['post'];
				$nonce    = $_REQUEST['nonce'];

				if ( ! wp_verify_nonce( $nonce, $action ) )
					wp_die( __( 'Invalid request', 'woocommerce-pdf-invoices' ) );

				if ( empty( $order_id ) )
					wp_die( __( 'Invalid order ID', 'woocommerce-pdf-invoices' ) );

				$user = wp_get_current_user();
				$allowed_roles = apply_filters("bewpi_allowed_roles_to_download_invoice", array("administrator", "shop_manager"));
				$customer_user_id = get_post_meta( $order_id, '_customer_user', true );
				if (  ! array_intersect( $allowed_roles, $user->roles ) && get_current_user_id() != $customer_user_id  ) {
					wp_die( __( 'Access denied', 'woocommerce-pdf-invoices' ) );
				}

				$invoice = new BEWPI_Invoice( $order_id );
				switch ( $action ) {
					case "view":
						$invoice->view();
						break;
					case "cancel":
						$invoice->delete();
						break;
					case "create":
						$invoice->save( "F" );
						break;
				}

			}
		}

		public function init_settings_tabs() {
			$this->settings_tabs['bewpi_general_settings']  = __( 'General', 'woocommerce-pdf-invoices' );
			$this->settings_tabs['bewpi_template_settings'] = __( 'Template', 'woocommerce-pdf-invoices' );

			$this->settings_tabs = apply_filters( 'bewpi_settings_tabs', $this->settings_tabs );
		}

		/**
		 * Creates invoices dir in uploads folder
		 */
		private function create_bewpi_dirs() {
			// invoices
			$current_year_dir = BEWPI_INVOICES_DIR . date_i18n( 'Y', current_time( 'timestamp' ) ) . "/";

			wp_mkdir_p( $current_year_dir );

			if ( ! file_exists ( $current_year_dir . ".htaccess" ) ) {
				copy( BEWPI_DIR . 'tmp/.htaccess', $current_year_dir . ".htaccess" );
			}

			if ( ! file_exists( $current_year_dir . "index.php" ) ) {
				copy( BEWPI_DIR . 'tmp/index.php', $current_year_dir . "index.php" );
			}

			// custom templates
			wp_mkdir_p( BEWPI_CUSTOM_TEMPLATES_INVOICES_DIR . 'simple/' );

			do_action( 'mk_custom_template_invoices_dir' );
		}

		/**
		 * Adds submenu to WooCommerce menu.
		 */
		public function add_woocommerce_submenu_page() {
			add_submenu_page( 'woocommerce', __( 'Invoices', 'woocommerce-pdf-invoices' ), __( 'Invoices', 'woocommerce-pdf-invoices' ), 'manage_options', $this->options_key, array(
				$this,
				'options_page'
			) );
		}

		/**
		 * Admin scripts
		 */
		public function admin_enqueue_scripts() {
			wp_enqueue_script( 'bewpi_admin_settings_script', BEWPI_URL . 'assets/js/admin.js', array(), false, true );
			wp_localize_script( 'bewpi_admin_settings_script', 'BEWPI_AJAX', array(
					'ajaxurl'          => admin_url( 'admin-ajax.php' ),
					'deactivation_nonce' => wp_create_nonce( 'bewpi_deactivation_notice' ),
				)
			);
			wp_register_style( 'bewpi_admin_settings_css', BEWPI_URL . 'assets/css/admin.css', false, '1.0.0' );
			wp_enqueue_style( 'bewpi_admin_settings_css' );
		}

		/**
		 * Callback function for adding plugin options tabs.
		 */
		private function plugin_options_tabs() {
			$current_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'bewpi_general_settings';

			echo '<h2 class="nav-tab-wrapper">';
			foreach ( $this->settings_tabs as $tab_key => $tab_caption ) {
				$active = $current_tab == $tab_key ? 'nav-tab-active' : '';
				echo '<a class="nav-tab ' . $active . '" href="?page=' . 'bewpi-invoices' . '&tab=' . $tab_key . '">' . $tab_caption . '</a>';
			}
			echo '</h2>';
		}

		/**
		 * The options page..
		 */
		public function options_page() {
			$tab = isset( $_GET['tab'] ) ? (string) $_GET['tab'] : 'bewpi_general_settings';
			?>
			<div class="wrap">
				<?php $this->plugin_options_tabs(); ?>
				<form class="bewpi-settings-form" method="post" action="options.php"
				      enctype="multipart/form-data">
					<?php wp_nonce_field( 'update-options' ); ?>
					<?php settings_fields( $tab ); ?>
					<?php do_settings_sections( $tab ); ?>
					<?php submit_button(); ?>
				</form>

				<?php if ( ! is_plugin_active( 'woocommerce-pdf-invoices-premium/bootstrap.php' ) ) {
					$this->options_page_sidebar_html();
				} ?>

			</div>
		<?php

			add_filter( 'admin_footer_text', array( $this, 'plugin_review_text' ), 50 );
			add_filter( 'update_footer', array( $this, 'plugin_version' ), 50 );
		}

		/**
		 * @param string $text
		 *
		 * @return string
		 */
		public function plugin_review_text( $text ) {
			return sprintf( __( 'If you like <strong>WooCommerce PDF Invoices</strong> please leave us a %s★★★★★%s rating. A huge thank you in advance!', 'woocommerce-pdf-invoices' ), '<a href=\'https://wordpress.org/support/view/plugin-reviews/woocommerce-pdf-invoices?rate=5#postform\'>', '</a>' );
		}

		/**
		 * @param string $text
		 *
		 * @return string
		 */
		public function plugin_version( $text ) {
			return sprintf( __( 'Version %s', 'woocommerce-pdf-invoices' ), BEWPI_VERSION );
		}

		private function options_page_sidebar_html() {
			include( BEWPI_DIR . 'includes/partials/settings-sidebar.php' );
		}

		/**
		 * Adds the Email It In email as an extra recipient
		 *
		 * @param $headers
		 * @param $status
		 *
		 * @return string
		 */
		function add_email_it_in_account_to_email_headers( $headers, $status ) {
			$general_options        = get_option( 'bewpi_general_settings' );
			$email_it_in_account    = $general_options['bewpi_email_it_in_account'];

			if ( $status !== $general_options['bewpi_email_type'] )
				return $headers;

			if ( ! (bool)$general_options['bewpi_email_it_in'] || empty( $email_it_in_account ) )
				return $headers;

			$headers .= 'BCC: <' . $email_it_in_account . '>' . "\r\n";

			return $headers;
		}

        public function attach_invoice_to_new_order_email($attachments, $status, $order)
        {
            $general_options = get_option( 'bewpi_general_settings' );
            if(!$general_options['bewpi_new_order'] || $status !== "new_order")
            {
                return $attachments;
            }

            $invoice = new BEWPI_Invoice( $order->id );
            if($invoice->exists())
            {
                $full_path = $invoice->get_full_path();
            }
            else
            {
                $full_path = $invoice->save("F");
            }

            // only add to attachments if it isn't already added.
            if(!in_array($full_path, $attachments))
            {
                $attachments[] = $full_path;
            }

            return $attachments;
        }

		/**
		 * Attaches invoice to a specific WooCommerce email. Invoice will only be generated when it does not exists already.
		 *
		 * @param $attachments
		 * @param $status
		 * @param $order
		 *
		 * @return array
		 */
		function attach_invoice_to_email( $attachments, $status, $order ) {
			$attachments = apply_filters( 'bewpi_email_attachments', $attachments, $status, $order );

            $general_options = get_option( 'bewpi_general_settings' );
            if(empty($general_options["bewpi_email_type"]) || $status !== $general_options["bewpi_email_type"])
            {
                return $attachments;
            }

			$payment_methods = apply_filters('bewpi_attach_invoice_excluded_payment_methods', array());
			if(in_array($order->payment_method, $payment_methods))
            {
				return $attachments;
			}

			$invoice = new BEWPI_Invoice( $order->id );
            if($invoice->exists())
            {
                $full_path = $invoice->get_full_path();
            }
            else
            {
                $full_path = $invoice->save("F");
            }

			// only add to attachments if it isn't already added.
			if(!in_array($full_path, $attachments))
            {
				$attachments[] = $full_path;
			}

			return $attachments;
		}

		/**
		 * Adds a box to the main column on the Post and Page edit screens.
		 */
		function add_meta_box_to_order_page() {
			add_meta_box( 'order_page_create_invoice', __( 'PDF Invoice', 'woocommerce-pdf-invoices' ), array(
				$this,
				'woocommerce_order_details_page_meta_box_create_invoice'
			), 'shop_order', 'side', 'high' );
		}

		/**
		 * Shows the view invoice button on the all orders page.
		 *
		 * @param $order
		 */
		public function woocommerce_order_page_action_view_invoice( $order ) {
			$invoice = new BEWPI_Invoice( $order->id );
			if ( $invoice->exists() )
				$this->show_invoice_button( 'View invoice', $order->id, 'view', '', array( 'class="button tips bewpi-admin-order-create-invoice-btn"', 'target="_blank"' ) );
		}

		/**
		 * Shows invoice number info on the order details page.
		 *
		 * @param $date
		 * @param $number
		 */
		private function show_invoice_number_info( $date, $number ) {
			echo '<table class="invoice-info" width="100%">
                <tr>
                    <td>' . __( 'Invoiced on:', 'woocommerce-pdf-invoices' ) . '</td>
                    <td align="right"><b>' . $date . '</b></td>
                </tr>
                <tr>
                    <td>' . __( 'Invoice number:', 'woocommerce-pdf-invoices' ) . '</td>
                    <td align="right"><b>' . $number . '</b></td>
                </tr>
            </table>';
		}

		/**
		 * Show a specific invoice button to for example view, create or delete an invoice.
		 *
		 * @param $title
		 * @param $order_id
		 * @param $wpi_action
		 * @param $btn_title
		 * @param array $arr
		 */
		private function show_invoice_button( $title, $order_id, $wpi_action, $btn_title, $arr = array() ) {
			$title      = __( $title, 'woocommerce-pdf-invoices');
			$href       = admin_url() . 'post.php?post=' . $order_id . '&action=edit&bewpi_action=' . $wpi_action . '&nonce=' . wp_create_nonce( $wpi_action );
			$btn_title  = __( $btn_title, 'woocommerce-pdf-invoices');
			$attr       = '';

			foreach ( $arr as $str ) {
				$attr .= $str . ' ';
			}

			echo $btn = '<a title="' . $title . '" href="' . $href . '" ' . $attr . '>' . $btn_title . '</a>';
		}

		/**
		 * Show all the meta box actions/buttons on the order details page to create, view or cancel/delete an invoice.
		 *
		 * @param $post
		 */
		public function woocommerce_order_details_page_meta_box_create_invoice( $post ) {
			$invoice = new BEWPI_Invoice( $post->ID );

			if ( $invoice->exists() ) {
				$this->show_invoice_number_info( $invoice->get_formatted_invoice_date(), $invoice->formatted_number );
				$this->show_invoice_button( __( 'View invoice', 'woocommerce-pdf-invoices'), $post->ID, 'view', __( 'View', 'woocommerce-pdf-invoices'), array( 'class="invoice-btn button grant_access"', 'target="_blank"' ) );
				$this->show_invoice_button( __( 'Cancel invoice', 'woocommerce-pdf-invoices'), $post->ID, 'cancel', __( 'Cancel', 'woocommerce-pdf-invoices' ), array(
					'class="invoice-btn button grant_access"',
					'onclick="return confirm(\'' . __( 'Are you sure to delete the invoice?', 'woocommerce-pdf-invoices') . '\')"'
				) );
			} else {
				$this->show_invoice_button( __( 'Create invoice', 'woocommerce-pdf-invoices'), $post->ID, 'create', __( 'Create', 'woocommerce-pdf-invoices'), array( 'class="invoice-btn button grant_access"' ) );
			}
		}

		/**
		 * Display download link on My Account page
		 */
		public function add_my_account_download_pdf_action( $actions, $order ) {
			$general_options = get_option( 'bewpi_general_settings' );

			if ( ! (bool)$general_options[ 'bewpi_download_invoice_account' ] )
				return $actions;

			$invoice = new BEWPI_Invoice( $order->id );
			if ( ! $invoice->exists() )
				return $actions;

			if ( ! $invoice->is_download_allowed( $order->post_status ) )
				return $actions;

			$url = admin_url( 'admin-ajax.php?bewpi_action=view&post=' . $order->id . '&nonce=' . wp_create_nonce( 'view' ) );
			$actions[ 'invoice' ] = array(
				'url'  => $url,
				'name' => sprintf( __( 'Invoice %s (PDF)', 'woocommerce-pdf-invoices' ), $invoice->formatted_number )
			);

			return $actions;
		}
	}
}