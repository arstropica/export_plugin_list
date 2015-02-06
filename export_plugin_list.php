<?php
    ini_set('display_errors', 1);
    error_reporting(E_ALL);

    if ( ! defined('WP_ADMIN') )
        define('WP_ADMIN', true);

    if ( ! defined('WP_NETWORK_ADMIN') )
        define('WP_NETWORK_ADMIN', false);

    if ( ! defined('WP_USER_ADMIN') )
        define('WP_USER_ADMIN', false);

    if ( ! WP_NETWORK_ADMIN && ! WP_USER_ADMIN ) {
        define('WP_BLOG_ADMIN', true);
    }

    if ( isset($_GET['import']) && !defined('WP_LOAD_IMPORTERS') )
        define('WP_LOAD_IMPORTERS', true);

    require_once((dirname(__FILE__)) . '/wp-load.php');

    if ( get_option('db_upgraded') ) {
        flush_rewrite_rules();
        update_option( 'db_upgraded',  false );

        /**
        * Runs on the next page load after successful upgrade
        *
        * @since 2.8
        */
        do_action('after_db_upgrade');
    } elseif ( get_option('db_version') != $wp_db_version && empty($_POST) ) {
        if ( !is_multisite() ) {
            wp_redirect(admin_url('upgrade.php?_wp_http_referer=' . urlencode(stripslashes($_SERVER['REQUEST_URI']))));
            exit;
        } elseif ( apply_filters( 'do_mu_upgrade', true ) ) {
            /**
            * On really small MU installs run the upgrader every time,
            * else run it less often to reduce load.
            *
            * @since 2.8.4b
            */
            $c = get_blog_count();
            if ( $c <= 50 || ( $c > 50 && mt_rand( 0, (int)( $c / 50 ) ) == 1 ) ) {
                require_once( ABSPATH . WPINC . '/http.php' );
                $response = wp_remote_get( admin_url( 'upgrade.php?step=1' ), array( 'timeout' => 120, 'httpversion' => '1.1' ) );
                do_action( 'after_mu_upgrade', $response );
                unset($response);
            }
            unset($c);
        }
    }

    require_once(ABSPATH . 'wp-admin/includes/admin.php');
    require_once(ABSPATH . 'wp-admin/includes/class-wp-plugins-list-table.php');

    class Export_WP_List 
    extends WP_Plugins_List_Table {

        function __construct( $args = array() ) {
            global $status, $page;

            parent::__construct($args);
        }

        function export() {
            $output = array();
            $headings = $this->get_column_headers();
            $rows = $this->get_rows_or_placeholder();
            if (empty($rows) === false)
                array_walk_recursive($rows, array(&$this, '_sanitize'));
            $output = array_merge(array($headings), $rows);
            return $this->arrayToCsv($output, ',');
        }

        function _sanitize(&$item) {
            if (is_array($item) === false)
                $item = html_entity_decode(strip_tags(rawurldecode($item)));
        }

        function get_column_headers() {
            return array('Plugin Name', 'Plugin Description', 'Plugin Version', 'Plugin URI', 'Plugin Author', 'Plugin Author URI', 'Activated');
        }

        function get_rows_or_placeholder() {
            if ( $this->has_items() ) {
                return $this->get_rows();
            } else {
                return array();
            }
        }

        function get_rows() {
            $output = array();
            $must_use = get_mu_plugins();
            $drop_ins = get_dropins();
            foreach ( $this->items as $plugin_file => $plugin_data ) {
                $output[] = $this->get_single_row( $plugin_file, $plugin_data, 'all' );
            }

            if (empty($must_use) === false) {
                foreach ( $must_use as $plugin_file => $plugin_data ) {
                    $output[] = $this->get_single_row( $plugin_file, $plugin_data, 'mustuse' );
                }
            }

            if (empty($drop_ins) === false) {
                foreach ( $drop_ins as $plugin_file => $plugin_data ) {
                    $output[] = $this->get_single_row( $plugin_file, $plugin_data, 'dropins' );
                }
            }

            return $output;
        }

        function get_single_row( $plugin_file, $plugin_data, $status ) {
            $context = $status;
            $screen = get_current_screen();
            $output = array("","","","","","","");

            // preorder
            if ( 'mustuse' == $context ) {
                $is_active = true;
                $output[6] = "Must Use";
            } elseif ( 'dropins' == $context ) {
                $dropins = _get_dropins();
                $plugin_name = $plugin_file;
                if ( $plugin_file != $plugin_data['Name'] )
                    $plugin_name .= $plugin_data['Name'];
                if ( true === ( $dropins[ $plugin_file ][1] ) ) { // Doesn't require a constant
                    $is_active = true;
                    $output[6] = "Active Drop-in";
                    $description = $dropins[ $plugin_file ][0];
                } elseif ( constant( $dropins[ $plugin_file ][1] ) ) { // Constant is true
                    $is_active = true;
                    $output[6] = "Active Drop-in";
                    $description = $dropins[ $plugin_file ][0];
                } else {
                    $is_active = false;
                    $output[6] = "Inactive Drop-in";
                    $description = $dropins[ $plugin_file ][0];
                }
                if ( $plugin_data['Description'] )
                    $description .= '<p>' . $plugin_data['Description'] . '</p>';
            } else {
                $is_active = is_plugin_active( $plugin_file );
                if ( $is_active ) {
                    $output[6] = is_plugin_active_for_network( $plugin_file ) ? "Network Activated" : "Unknown";
                } else {
                    $output[6] = "Inactive";
                }

            } // end if $context

            if ( 'dropins' != $context ) {
                $description = ( $plugin_data['Description'] ? $plugin_data['Description'] : '&nbsp;' );
                $plugin_name = $plugin_data['Name'];
            }

            $output[0]  = $plugin_name;
            $output[1] = $description;
            if ( !empty( $plugin_data['Version'] ) ) {
                $output[2] = sprintf( __( 'Version %s' ), $plugin_data['Version'] );
            } else {
                $output[2] = "N/A";
            }
            if ( !empty( $plugin_data['Author'] ) ) {
                $output[4] = $plugin_data['Author'];
                if ( !empty( $plugin_data['AuthorURI'] ) ) {
                    $output[5] = $plugin_data['AuthorURI'];
                } else {
                    $output[5] = "N/A";
                }
            } else {
                $output[4] = "N/A";
                $output[5] = "N/A";
            }
            if ( ! empty( $plugin_data['PluginURI'] ) ) {
                $output[3] = $plugin_data['PluginURI']; 
            } else {
                $output[3] = "N/A";
            }

            $output[6] = empty($output[6]) ? ($is_active ? 'Active' : 'Inactive') : $output[6];

            ksort($output);

            return $output;
        }

        function arrayToCsv( array &$fields, $delimiter = ';', $enclosure = '"', $encloseAll = false, $nullToMysqlNull = false ) {
            $delimiter_esc = preg_quote($delimiter, '/');
            $enclosure_esc = preg_quote($enclosure, '/');

            $output = array();
            $return = false;
            if (is_array(current($fields))){
                foreach($fields as $subArr){
                    $line = array();
                    foreach ( $subArr as $field ) {
                        if ($field === null && $nullToMysqlNull) {
                            $line[] = 'NULL';
                            continue;
                        }

                        // Enclose fields containing $delimiter, $enclosure or whitespace
                        if ( $encloseAll || preg_match( "/(?:${delimiter_esc}|${enclosure_esc}|\s)/", $field ) ) {
                            $line[] = $enclosure . str_replace($enclosure, $enclosure . $enclosure, $field) . $enclosure;
                        }
                        else {
                            $line[] = $field;
                        }                          
                    }
                    $output[] = implode( $delimiter, $line );
                }
                $return = implode("\r\n", $output);
            } else {
                foreach ( $fields as $field ) {
                    if ($field === null && $nullToMysqlNull) {
                        $output[] = 'NULL';
                        continue;
                    }

                    // Enclose fields containing $delimiter, $enclosure or whitespace
                    if ( $encloseAll || preg_match( "/(?:${delimiter_esc}|${enclosure_esc}|\s)/", $field ) ) {
                        $output[] = $enclosure . str_replace($enclosure, $enclosure . $enclosure, $field) . $enclosure;
                    }
                    else {
                        $output[] = $field;
                    }                          
                }
                $return = implode( $delimiter, $output );
            }
            return $return;
        }

        function generate_csv($csv, $title=false){
            if (empty($title)) $title = get_bloginfo('name') . ' Plugin Report';
            $decoded = stripcslashes(rawurldecode($csv));
            header("Content-type: application/octet-stream");
            header("Content-Disposition: attachment; filename=\"" . $title . " - " . date('m-d-Y') . ".csv\"");
            echo $decoded;
            return 1; 
        }
    }

    /*if ( ! is_user_logged_in())
    auth_redirect();*/

    if (empty($_GET['export'])) {
        $output = <<<HTML
  <div class='wrap'>
    <form method='get' name='exportform'>
      <button type='submit'>Export</button>
      <input type='hidden' name='export' value='1' />
    </form>
  </div>
HTML;
        echo $output;
    } else {
        global $screen, $current_screen, $status, $plugins;
        set_current_screen( 'plugins-network' );
        remove_all_filters( 'all_plugins' );
        $wp_list_table = new Export_WP_List(array('screen' => get_current_screen()));
        $wp_list_table->prepare_items();
        $output = $wp_list_table->export();

        // var_dump($wp_list_table);
        // var_dump($output);
        $wp_list_table->generate_csv($output);
    }
    exit;
?>
