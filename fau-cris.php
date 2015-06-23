<?php
/**
 * Plugin Name: FAU CRIS
 * Description: Anzeige von Daten aus dem FAU-Forschungsportal CRIS in WP-Seiten
 * Version: 1.3.0
 * Author: RRZE-Webteam
 * Author URI: http://blogs.fau.de/webworking/
 * License: GPLv2 or later
 */
/*
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

add_action('plugins_loaded', array('FAU_CRIS', 'instance'));

register_activation_hook(__FILE__, array('FAU_CRIS', 'activate'));
register_deactivation_hook(__FILE__, array('FAU_CRIS', 'deactivate'));

class FAU_CRIS {

	/**
	 * Get Started
	 */
	const version = '1.1';
	const option_name = '_fau_cris';
	const version_option_name = '_fau_cris_version';
	const textdomain = 'fau-cris';
	const php_version = '5.3'; // Minimal erforderliche PHP-Version
	const wp_version = '3.9.2'; // Minimal erforderliche WordPress-Version

	protected static $instance = null;
	private static $cris_option_page = null;

	public static function instance() {

		if (null == self::$instance) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	private function __construct() {
		load_plugin_textdomain('fau-cris', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		add_action('admin_init', array(__CLASS__, 'admin_init'));
		add_action('admin_menu', array(__CLASS__, 'add_options_page'));
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(__CLASS__, 'add_action_links') );

		add_shortcode('cris', array(__CLASS__, 'cris_shortcode'));

	}

	/**
	 * Check PHP and WP Version and if Contact Form 7 is active
	 */
	public static function activate() {
		self::version_compare();
		update_option(self::version_option_name, self::version);
	}

	private static function version_compare() {
		$error = '';

		if (version_compare(PHP_VERSION, self::php_version, '<')) {
			$error = sprintf(__('Ihre PHP-Version %s ist veraltet. Bitte aktualisieren Sie mindestens auf die PHP-Version %s.', self::textdomain), PHP_VERSION, self::php_version);
		}

		if (version_compare($GLOBALS['wp_version'], self::wp_version, '<')) {
			$error = sprintf(__('Ihre Wordpress-Version %s ist veraltet. Bitte aktualisieren Sie mindestens auf die Wordpress-Version %s.', self::textdomain), $GLOBALS['wp_version'], self::wp_version);
		}

		if (!empty($error)) {
			deactivate_plugins(plugin_basename(__FILE__), false, true);
			wp_die(
				$error,
				__('Fehler bei der Aktivierung des Plugins', self::textdomain),
				array(
					'response' => 500,
					'back_link' => TRUE
				)
			);
		}
	}

	public static function update_version() {
		if (get_option(self::version_option_name, null) != self::version)
			update_option(self::version_option_name, self::version);
	}

	/**
	 * Display settings link on the plugins page (beside the activate/deactivate links)
	 */
	public static function add_action_links ( $links ) {
		$mylinks = array(
			'<a href="' . admin_url( 'options-general.php?page=options-fau-cris' ) . '">' . __('Einstellungen', self::textdomain) . '</a>',
		);
		return array_merge( $links, $mylinks );
	}

	/**
	 * Get Options
	 */
	private static function get_options() {
		$defaults = self::default_options();
		$options = (array) get_option(self::option_name);
		$options = wp_parse_args($options, $defaults);
		$options = array_intersect_key($options, $defaults);
		return $options;
	}

	/**
	 * Set default options
	 */
	private static function default_options() {
		$options = array(
			'cris_org_nr'		=>	'142477',
			'cris_pub_order'	=>	array(
										'Journal article',
										'Article in edited volumes',
										'Translation',
										'Book',
										'Editorial',
										'Conference Contribution',
										'Thesis',
										'Other'
									),
			'cris_cache'		=>	'18000',
			'cris_univis'		=> 0,
		);
		return $options;
	}

	/**
	 * Add options page
	 */
	public static function add_options_page() {
		self::$cris_option_page = add_options_page(
				'CRIS: Einstellungen', 'CRIS', 'manage_options', 'options-fau-cris', array(__CLASS__, 'options_fau_cris')
		);
		add_action('load-' . self::$cris_option_page, array(__CLASS__, 'fs7_help_menu'));
	}

	/**
	 * Options page callback
	 */
	public static function options_fau_cris() {
		?>
		<div class="wrap">
		<?php screen_icon(); ?>
			<h2><?php echo __('Einstellungen', self::textdomain) . ' &rsaquo; CRIS'; ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields('fau_cris_options');
				do_settings_sections('fau_cris_options');
				submit_button();
				?>
			</form>
		</div>
		<?php
		}

	/**
	 * Register and add settings
	 */
	public static function admin_init() {

		register_setting(
				'fau_cris_options', // Option group
				self::option_name, // Option name
				array(__CLASS__, 'sanitize') // Sanitize
		);
		// Form Settings 1
		add_settings_section(
				'cris_section', // ID
				__('Einstellungen', self::textdomain), // Title
				'__return_false', // Callback
				'fau_cris_options' // Page
		);
		add_settings_field(
				'cris_org_nr', // ID
				__('CRIS-OrgNr.', self::textdomain), // Title
				array(__CLASS__, 'cris_textbox_callback'), // Callback
				'fau_cris_options', // Page
				'cris_section', // Section
				array (
					'name' => 'cris_org_nr',
				)
		);
		add_settings_field(
				'cris_pub_order',
				__('Reihenfolge der Publikationen', self::textdomain),
				array(__CLASS__, 'cris_textarea_callback'),
				'fau_cris_options',
				'cris_section',
				array (
					'name' => 'cris_pub_order',
					'description' => __('Wenn Sie die Publikationsliste nach Publikationstypen geordnet ausgeben, können Sie hier angeben, in welcher Reihenfolge die Typen aufgelistet werden. Eine Liste aller Typen finden Sie im Hilfemenü unter "Shortcode Publikationen". Ein Eintrag pro Zeile. ', self::textdomain)
				)
		);
		add_settings_field(
				'cris_univis',
				__('Autoren verlinken', self::textdomain),
				array(__CLASS__, 'cris_check_callback'),
				'fau_cris_options',
				'cris_section',
				array (
					'name' => 'cris_univis',
					'description' => __('Sollen die Autoren mit ihrer Personen-Detailansicht im FAU-Person-Plugin verlinkt werden?', self::textdomain)
				)
		);
	}

	/**
	 * Sanitize each setting field as needed
	 */
	public function sanitize($input) {
		$new_input = array();
		if (isset($input['cris_org_nr'])) {
			$new_input['cris_org_nr'] = absint($input['cris_org_nr']);
		}
		if (isset($input['cris_pub_order'])) {
			$new_input['cris_pub_order'] = explode("\n", str_replace("\r", "",$input['cris_pub_order']));
		}
		if (isset($input['cris_univis'])) {
			$new_input['cris_univis'] = ( $input['cris_univis'] == 1 ? 1 : 0 );
		}
		return $new_input;
	}

	/**
	 * Get the settings option array and print its values
	 */
	// Checkbox
	public static function cris_check_callback($args) {
		$options = self::get_options();
		if (array_key_exists('name', $args)) $name = esc_attr( $args['name'] );
		if (array_key_exists('description', $args)) $description = esc_attr($args['description']);
		?>
		<input name="<?php printf('%s['.$name.']' , self::option_name); ?>" type='checkbox' value='1' <?php if (array_key_exists($name, $options)) { print checked($options[$name], 1, false); } ?> >
		<?php if (isset($description)) { ?>
			<span class="description"><?php echo $description; ?></span>
		<?php }
	}

	// Textbox
	public static function cris_textbox_callback($args) {
		$options = self::get_options();
		if (array_key_exists('name', $args)) $name = esc_attr( $args['name'] );
		if (array_key_exists('description', $args)) $description = esc_attr($args['description']);
		?>
		<input name="<?php printf('%s['.$name.']', self::option_name); ?>" type='text' value="<?php if (array_key_exists($name, $options)) { echo $options[$name]; } ?>" ><br />
		<?php if (isset($description)) { ?>
			<span class="description"><?php echo $description; ?></span>
		<?php }
	}

	// Textarea
	public static function cris_textarea_callback($args) {
		$options = self::get_options();
		if (array_key_exists('name', $args)) $name = esc_attr( $args['name'] );
		if (array_key_exists('description', $args)) $description = esc_attr($args['description']);
		?>
			<textarea name="<?php printf('%s['.$name.']', self::option_name); ?>" cols="30" rows="8"><?php if (array_key_exists($name, $options)) { echo implode ("\n", $options[$name]); } ?></textarea><br />
		<?php if (isset($description)) { ?>
			<span class="description"><?php echo $description; ?></span>
		<?php }
	}

	/**
	 * Add Shortcode
	 */
	public static function cris_shortcode($atts, $content = null) {

		// Attributes
		extract(shortcode_atts(
			array(
				'show' => '',
				'orderby' => '',
				'pubtype' => '',
				'year' => '',
				'start' => '',

			),
			$atts));
		$show = sanitize_text_field($show);
		$orderby = sanitize_text_field($orderby);
		$pubtype = sanitize_text_field($pubtype);
		$year = sanitize_text_field($year);
		$start = sanitize_text_field($start);

		switch ($show) {
			case 'mitarbeiter':
				if (empty($_REQUEST)) {
					require_once('class_Mitarbeiterliste.php');
					$liste = new Mitarbeiterliste();
					if ($orderby == 'name') {
						$output = $liste->liste();
					} else {
						$output = $liste->organigramm();
					}
				} else {
					require_once("class_Personendetail.php");
					$detail = new Personendetail($_REQUEST['id']);
					$output = $detail->detail();
				}
				break;
			case 'person':
				require_once('class_Personendetail');
				break;
			case 'publikationen':
				require_once('class_Publikationsliste.php');
				$liste = new Publikationsliste();
				if (isset($pubtype) && $pubtype != '') {
					$output = $liste->publikationstypen($pubtype);
				} elseif (isset($year) && $year != '') {
					$output = $liste->publikationsjahre($year);
				} elseif (isset($start) && $start != '') {
					$output = $liste->publikationsjahrestart($start);
				} else {
					if (isset($orderby) && $orderby == 'pubtype') {
						$output = $liste->pubNachTyp();
					} elseif (isset($orderby) && $orderby == 'year') {
						$output = $liste->pubNachJahr();
					} else {
					$output = $liste->pubNachJahr();
					}
				}
				break;
			default:
				$output = 'Parameter fehlt';
				break;
		}
		return $output;

	}

	/*
	 * Hilfe-Panel über der Theme-Options-Seite
	 */
	public static function fs7_help_menu() {

		$content_cris = array(
			'<p>' . __('Binden Sie Daten aus aus dem FAU-Forschungsportal <strong>CRIS (Currrent Research Information System)</strong> in Ihren Webauftritt ein. Das Plugin ermöglicht außerdem die Integration mit dem FAU-Person-Plugin.', self::textdomain) . '</p>',
			'<p>' . __('Für die Publikationslisten lassen sich über den Shortcode verschiedene Ausgabeformen einstellen. Die Titel sind jeweils mit der Detailansicht der Publikation auf http://cris.fau.de verlinkt.', self::textdomain) . '</p>','<p>' . __('<b>CRIS-OrgNr</b>:<br>Die Nummer der der Organisationseinheit, für die die Publikationen und Personendaten ausgegeben werden. Diese erfahren Sie, wenn Sie in CRIS eingeloggt sind, oder wenn Sie ich Ihre Organisationseinheit auf http://cris.fau.de anzeigen lassen, in der URL: z.B. http://cris.fau.de/converis/publicweb/Organisation/<strong><em>141517</em></strong>.', self::textdomain) . '</p>'
			);

		$content_shortcode_publikationen = array(
			'<h3>[cris show="publikationen"]</h3>'
			. '<p>' . __('Bindet eine Liste aller Publikationen Ihrer Organisationseinheit ein.', self::textdomain) . '</p>'
			. '<p>' . __('Mögliche Zusatzoptionen:', self::textdomain)
			. '<br /><b>orderby="year"</b>: '
			. __('Liste nach Jahren absteigend gegliedert (Voreinstellung)', self::textdomain)
			. '<br /><b>orderby="pubtype"</b>: '
			. __('Liste nach Publikationstypen gegliedert', self::textdomain)
			. '<br /><b>year="2015"</b>: '
			. __('Nur Publikationen aus einem bestimmten Jahr', self::textdomain)
			. '<br /><b>start="2000"</b>: '
			. __('Nur Publikationen ab einem bestimmten Jahr', self::textdomain)
			. '<br /><b>pubtype="buecher"</b>: '
			. __('Es werden nur Publikationen eines bestimmten Typs angezeigt:', self::textdomain) . '</p>'
			. '<ul>'
			. '<li>buecher</li>'
			. '<li>zeitschriftenartikel</li>'
			. '<li>sammelbandbeitraege</li>'
			. '<li>herausgeberschaften</li>'
			. '<li>konferenzbeitraege</li>'
			. '<li>uebersetzungen</li>'
			. '<li>abschlussarbeiten</li>'
			. '<li>andere</li>'
			. '</ul>'
		);

		$content_fauperson = array(
			'<p>' . __('Wenn Sie das <strong>FAU-Person</strong>-Plugin verwenden, können Autoren mit ihrer FAU-Person-Kontaktseite verlinkt werden.', self::textdomain) . '</p>',
			'<p>' . __('Wenn diese Option in den Einstellungen des CRIS-Plugins aktiviert ist, überprüft das Plugin selbstständig, welche Personen vorhanden sind und setzt die entsprechenden Links.', self::textdomain) . '</p>',
			'<p>' . __('', self::textdomain) . '</p>'
			);

		$helptexts = array(
			array(
				'id' => 'uebersicht',
				'title' => __('Übersicht', self::textdomain),
				'content' => implode(PHP_EOL, $content_cris),
			),
			array(
				'id' => 'publikationen',
				'title' => __('Shortcodes', self::textdomain),
				'content' => implode(PHP_EOL, $content_shortcode_publikationen),
			),
			array(
				'id' => 'person',
				'title' => __('Integration "FAU Person"', self::textdomain),
				'content' => implode(PHP_EOL, $content_fauperson),
			)
		);

		$screen = get_current_screen();
		if ($screen->id != self::$cris_option_page) {
			return;
		}
		foreach ($helptexts as $helptext) {
			$screen->add_help_tab($helptext);
		}
		//$screen->set_help_sidebar($help_sidebar);
	}
}
