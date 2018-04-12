<?php
/**
 * Overriding custom SuperPWA functions before they are loaded for multisite
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pwa_plugin_name = 'super-progressive-web-apps/superpwa.php';
$pwa_plugin_dir  = ABSPATH . PLUGINDIR . '/' . $pwa_plugin_name;

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );


if ( file_exists( $pwa_plugin_dir ) && is_plugin_active( $pwa_plugin_name ) ) {
	function superpwa_mu_prefix() {
		return ! is_main_site() ? '_' . get_current_blog_id() : '';
	}


	function superpwa_delete_manifest_mu() {

		return superpwa_delete( str_replace( '.json', superpwa_mu_prefix() . '.json', SUPERPWA_MANIFEST_ABS ) );
	}

	function superpwa_generate_manifest_mu() {

		// Get Settings
		$settings = superpwa_get_settings();

		/**
		 * @TODO Add extra awesome params to make website perform better :-)
		 */
		$manifest = array(
			'name'             => $settings['app_name'],
			'short_name'       => $settings['app_short_name'],
			'icons'            => superpwa_get_pwa_icons(),
			'background_color' => $settings['background_color'],
			'theme_color'      => $settings['background_color'],
			'display'          => 'standalone',
			'orientation'      => 'natural',
			'start_url'        => superpwa_get_start_url( true ),
		);

		// Delete manifest if it exists
		superpwa_delete_manifest_mu();

		$site_manifest_file = SUPERPWA_MANIFEST_ABS;

		if ( is_multisite() ) {
			$site_manifest_file = str_replace( '.json', superpwa_mu_prefix() . '.json', $site_manifest_file );
		}

		if ( ! superpwa_put_contents( $site_manifest_file, json_encode( $manifest ) ) ) {
			return false;
		}

		return true;
	}


	/**
	 * Add manifest to header (wp_head)
	 *
	 * @since    1.0
	 */
	function superpwa_add_manifest_to_header_mu() {

		// Get Settings
		$settings = superpwa_get_settings();

		$site_manifest_src = SUPERPWA_MANIFEST_SRC;
		if ( is_multisite() ) {
			$site_manifest_src = str_replace( '.json', superpwa_mu_prefix() . '.json', $site_manifest_src );
		}

		echo '<!-- Manifest added by SuperPWA -->' . PHP_EOL . '<link rel="manifest" href="' . $site_manifest_src . '">' . PHP_EOL;
		echo '<meta name="theme-color" content="' . $settings['background_color'] . '">' . PHP_EOL;
	}

	add_action( 'wp_head', 'superpwa_add_manifest_to_header_mu' );

	function superpwa_generate_sw_mu() {
		// Get the service worker tempalte
		$sw = superpwa_sw_template_mu();

		// Delete service worker if it exists
		superpwa_delete_sw();

		if ( ! superpwa_put_contents( str_replace( '.js', superpwa_mu_prefix() . '.js', SUPERPWA_SW_ABS ), $sw ) ) {
			return false;
		}

		return true;
	}

	function superpwa_delete_sw_mu() {

		return superpwa_delete( str_replace( '.js', superpwa_mu_prefix() . '.js', SUPERPWA_SW_ABS ) );
	}

	/**
	 * Service Worker Tempalte
	 *
	 * @TODO Add tweaking with extra params to make site perform better
	 *
	 * @return    String    Contents to be written to superpwa-sw.js
	 * @since    1.0
	 */
	function superpwa_sw_template_mu() {

		// Get Settings
		$settings = superpwa_get_settings();

		// Start output buffer. Everything from here till ob_get_clean() is returned
		ob_start();
	if ( false ): ?>
        <script type="text/javascript"><?php endif; ?>
            'use strict';

            /**
             * Service Worker of SuperPWA
             * https://wordpress.org/plugins/super-progressive-web-apps/
             */

            const cacheName = '<?php echo parse_url( get_bloginfo( 'wpurl' ), PHP_URL_HOST ) . '-superpwa-' . SUPERPWA_VERSION; ?>';
            const startPage = '<?php echo superpwa_get_start_url(); ?>';
            const offlinePage = '<?php echo get_permalink($settings['offline_page']) ? trailingslashit(get_permalink($settings['offline_page'])) : trailingslashit(get_bloginfo( 'wpurl' )); ?>';
            const fallbackImage = '<?php echo $settings['icon']; ?>';
            const filesToCache = [startPage, offlinePage, fallbackImage];
            const neverCacheUrls = [/\/wp-admin/,/\/wp-login/,/preview=true/];

            // Install
            self.addEventListener('install', function(e) {
                console.log('SuperPWA service worker installation');
                e.waitUntil(
                    caches.open(cacheName).then(function(cache) {
                        console.log('SuperPWA service worker caching dependencies');
                        return cache.addAll(filesToCache);
                    })
                );
            });

            // Activate
            self.addEventListener('activate', function(e) {
                console.log('SuperPWA service worker activation');
                e.waitUntil(
                    caches.keys().then(function(keyList) {
                        return Promise.all(keyList.map(function(key) {
                            if ( key !== cacheName ) {
                                console.log('SuperPWA old cache removed', key);
                                return caches.delete(key);
                            }
                        }));
                    })
                );
                return self.clients.claim();
            });

            // Fetch
            self.addEventListener('fetch', function(e) {

                // Return if the current request url is in the never cache list
                if ( ! neverCacheUrls.every(checkNeverCacheList, e.request.url) ) {
                    console.log('SuperPWA: Current page is excluded from cache');
                    return;
                }

                // Return if request url protocal isn't http or https
                if ( ! e.request.url.match(/^(http|https):\/\//i) )
                    return;

                // Return if request url is from an external domain.
                if ( new URL(e.request.url).origin !== location.origin )
                    return;

                // For POST requests, do not use the cache. Serve offline page if offline.
                if ( e.request.method !== 'GET' ) {
                    e.respondWith(
                        fetch(e.request).catch( function() {
                            return caches.match(offlinePage);
                        })
                    );
                    return;
                }

                // Revving strategy
                if ( e.request.mode === 'navigate' && navigator.onLine ) {
                    e.respondWith(
                        fetch(e.request).then(function(response) {
                            return caches.open(cacheName).then(function(cache) {
                                cache.put(e.request, response.clone());
                                return response;
                            });
                        })
                    );
                    return;
                }

                e.respondWith(
                    caches.match(e.request).then(function(response) {
                        return response || fetch(e.request).then(function(response) {
                            return caches.open(cacheName).then(function(cache) {
                                cache.put(e.request, response.clone());
                                return response;
                            });
                        });
                    }).catch(function() {
                        return caches.match(offlinePage);
                    })
                );
            });

            // Check if current url is in the neverCacheUrls list
            function checkNeverCacheList(url) {
                if ( this.match(url) ) {
                    return false;
                }
                return true;
            }

			<?php if ( false ): ?></script><?php endif;

		return ob_get_clean();
	}

	function superpwa_activate_plugin_mu() {
		// Generate manifest with default options
		superpwa_generate_manifest_mu();

		// Generate service worker
		superpwa_generate_sw_mu();

		// Set transient for activation notice
		set_transient( 'superpwa_admin_notice_activation', true, 5 );
	}

	function superpwa_deactivate_plugin_mu() {
		// Delete manifest
		superpwa_delete( str_replace( '.json', superpwa_mu_prefix() . '.json', SUPERPWA_MANIFEST_ABS ) );

		// Delete service worker
		superpwa_delete_sw_mu();
	}


	register_activation_hook( $pwa_plugin_dir, 'superpwa_activate_plugin_mu' );

	// Register deactivatation hook
	register_deactivation_hook( $pwa_plugin_dir, 'superpwa_deactivate_plugin_mu' );

	/**
	 * Todo list after saving admin options
	 *
	 * Regenerate manifest
	 * Regenerate service worker
	 *
	 * @since    1.0
	 */
	function superpwa_after_save_settings_todo_mu() {

		// Regenerate manifest
		superpwa_generate_manifest_mu();

		// Regenerate service worker
		superpwa_generate_sw_mu();
	}

	add_action( 'add_option_superpwa_settings', 'superpwa_after_save_settings_todo_mu' );
	add_action( 'update_option_superpwa_settings', 'superpwa_after_save_settings_todo_mu' );


	add_action( 'init', function () {
		global $pwa_plugin_name;


		remove_action( 'add_option_superpwa_settings', 'superpwa_after_save_settings_todo' );
		remove_action( 'update_option_superpwa_settings', 'superpwa_after_save_settings_todo' );

		/**
		 * Plugin upgrade todo list
		 *
		 * @since    1.4
		 */
		remove_action( 'admin_init', 'superpwa_upgrader' );
		add_action( 'admin_init', function () {
			$current_ver = get_option( 'superpwa_version' );

			// Return if we have already done this todo
			if ( $current_ver == SUPERPWA_VERSION ) {
				return;
			}

			// Return if this is the first time the plugin is installed.
			if ( $current_ver === false ) {

				add_option( 'superpwa_version', SUPERPWA_VERSION );

				return;
			}

			// Re-generate manifest
			superpwa_generate_manifest_mu();

			// Re-generate service worker
			superpwa_generate_sw_mu();

			// Add current version to database
			update_option( 'superpwa_version', SUPERPWA_VERSION );
		} );


		// Register activation hook (this has to be in the main plugin file.)
		if ( has_action( 'activate_' . $pwa_plugin_name, 'superpwa_activate_plugin' ) ) {
			remove_action( 'activate_' . $pwa_plugin_name, 'superpwa_activate_plugin' );
		}

		if ( has_action( 'deactivate_' . $pwa_plugin_name, 'superpwa_deactivate_plugin' ) ) {
			remove_action( 'deactivate_' . $pwa_plugin_name, 'superpwa_deactivate_plugin' );
		}

		remove_action( 'wp_head', 'superpwa_add_manifest_to_header' );
		remove_action( 'admin_init', 'superpwa_register_settings' );
	} );


	/**
	 * Manifest Status
	 *
	 * @since 1.0
	 */
	function superpwa_manifest_status_cb_mu() {

		if ( superpwa_get_contents( str_replace( '.json', superpwa_mu_prefix() . '.json', SUPERPWA_MANIFEST_ABS ) ) ) {

			printf( '<p><span class="dashicons dashicons-yes" style="color: #46b450;"></span> ' . __( 'Manifest generated successfully. You can <a href="%s" target="_blank">see it here &rarr;</a>.', 'super-progressive-web-apps' ) . '</p>', str_replace( '.json', superpwa_mu_prefix() . '.json', SUPERPWA_MANIFEST_SRC ) );
		} else {

			echo '<p><span class="dashicons dashicons-no-alt" style="color: #dc3232;"></span> ' . __( 'Manifest generation failed. Check if WordPress can write to your root folder (the same folder with wp-config.php).', 'super-progressive-web-apps' ) . '</p>';
		}
	}

	/**
	 * Service Worker Status
	 *
	 * @since 1.2
	 */
	function superpwa_sw_status_cb_mu() {

		if ( superpwa_get_contents( str_replace( '.js', superpwa_mu_prefix() . '.js', SUPERPWA_SW_ABS ) ) ) {

			printf( '<p><span class="dashicons dashicons-yes" style="color: #46b450;"></span> ' . __( 'Service worker generated successfully.', 'super-progressive-web-apps' ) . '</p>' );
		} else {

			echo '<p><span class="dashicons dashicons-no-alt" style="color: #dc3232;"></span> ' . __( 'Service worker generation failed. Check if WordPress can write to your root folder (the same folder with wp-config.php).', 'super-progressive-web-apps' ) . '</p>';
		}
	}

	add_action( 'admin_init', function () {
		// Manifest status
		// Register Setting
		register_setting(
			'superpwa_settings_group',            // Group name
			'superpwa_settings',                // Setting name = html form <input> name on settings form
			'superpwa_validater_and_sanitizer'    // Input sanitizer
		);

		// Basic Application Settings
		add_settings_section(
			'superpwa_basic_settings_section',                    // ID
			__return_false(),                                    // Title
			'__return_false',                                    // Callback Function
			'superpwa_basic_settings_section'                    // Page slug
		);

		// Application Name
		add_settings_field(
			'superpwa_app_name',                                    // ID
			__( 'Application Name', 'super-progressive-web-apps' ),    // Title
			'superpwa_app_name_cb',                                    // CB
			'superpwa_basic_settings_section',                        // Page slug
			'superpwa_basic_settings_section'                        // Settings Section ID
		);

		// Application Short Name
		add_settings_field(
			'superpwa_app_short_name',                                // ID
			__( 'Application Short Name', 'super-progressive-web-apps' ),    // Title
			'superpwa_app_short_name_cb',                            // CB
			'superpwa_basic_settings_section',                        // Page slug
			'superpwa_basic_settings_section'                        // Settings Section ID
		);

		// Application Icon
		add_settings_field(
			'superpwa_icons',                                        // ID
			__( 'Application Icon', 'super-progressive-web-apps' ),    // Title
			'superpwa_app_icon_cb',                                    // Callback function
			'superpwa_basic_settings_section',                        // Page slug
			'superpwa_basic_settings_section'                        // Settings Section ID
		);

		// Splash Screen Icon
		add_settings_field(
			'superpwa_splash_icon',                                    // ID
			__( 'Splash Screen Icon', 'super-progressive-web-apps' ),    // Title
			'superpwa_splash_icon_cb',                                // Callback function
			'superpwa_basic_settings_section',                        // Page slug
			'superpwa_basic_settings_section'                        // Settings Section ID
		);

		// Splash Screen Background Color
		add_settings_field(
			'superpwa_background_color',                            // ID
			__( 'Background Color', 'super-progressive-web-apps' ),    // Title
			'superpwa_background_color_cb',                            // CB
			'superpwa_basic_settings_section',                        // Page slug
			'superpwa_basic_settings_section'                        // Settings Section ID
		);

		// Start URL
		add_settings_field(
			'superpwa_start_url',                                    // ID
			__( 'Start Page', 'super-progressive-web-apps' ),            // Title
			'superpwa_start_url_cb',                                // CB
			'superpwa_basic_settings_section',                        // Page slug
			'superpwa_basic_settings_section'                        // Settings Section ID
		);

		// Offline Page
		add_settings_field(
			'superpwa_offline_page',                                // ID
			__( 'Offline Page', 'super-progressive-web-apps' ),        // Title
			'superpwa_offline_page_cb',                                // CB
			'superpwa_basic_settings_section',                        // Page slug
			'superpwa_basic_settings_section'                        // Settings Section ID
		);

		// PWA Status
		add_settings_section(
			'superpwa_pwa_status_section',                    // ID
			__( 'Status', 'super-progressive-web-apps' ),        // Title
			'__return_false',                                // Callback Function
			'superpwa_pwa_status_section'                    // Page slug
		);

		// Manifest status
		add_settings_field(
			'superpwa_manifest_status',                                // ID
			__( 'Manifest', 'super-progressive-web-apps' ),            // Title
			'superpwa_manifest_status_cb_mu',                            // CB
			'superpwa_pwa_status_section',                            // Page slug
			'superpwa_pwa_status_section'                            // Settings Section ID
		);

		// Service Worker status
		add_settings_field(
			'superpwa_sw_status',                                // ID
			__( 'Service Worker', 'super-progressive-web-apps' ),        // Title
			'superpwa_sw_status_cb_mu',                            // CB
			'superpwa_pwa_status_section',                            // Page slug
			'superpwa_pwa_status_section'                            // Settings Section ID
		);

		// HTTPS status
		add_settings_field(
			'superpwa_https_status',                                // ID
			__( 'HTTPS', 'super-progressive-web-apps' ),                // Title
			'superpwa_https_status_cb',                            // CB
			'superpwa_pwa_status_section',                            // Page slug
			'superpwa_pwa_status_section'                            // Settings Section ID
		);
	} );

	function superpwa_register_sw_mu() {

		wp_deregister_script( 'superpwa-register-sw' );
		wp_enqueue_script( 'superpwa-register-sw', SUPERPWA_PATH_SRC . 'public/js/register-sw.js', array(), null, true );
		wp_localize_script( 'superpwa-register-sw', 'superpwa_sw', array(
				'url' => str_replace( '.js', superpwa_mu_prefix() . '.js', SUPERPWA_SW_SRC ),
			)
		);
	}

	add_action( 'wp_enqueue_scripts', 'superpwa_register_sw_mu', 20 );

}
