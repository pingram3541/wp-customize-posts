<?php
/**
 * Customize Posts Preview Class
 *
 * Implements post management in the Customizer.
 *
 * @package WordPress
 * @subpackage Customize
 */

/**
 * Class WP_Customize_Posts_Preview
 */
final class WP_Customize_Posts_Preview {

	/**
	 * WP_Customize_Posts instance.
	 *
	 * @access public
	 * @var WP_Customize_Posts
	 */
	public $component;

	/**
	 * Previewed post settings by post ID.
	 *
	 * @var WP_Customize_Post_Setting[]
	 */
	public $previewed_post_settings = array();

	/**
	 * Previewed postmeta settings by post ID and meta key.
	 *
	 * @var WP_Customize_Postmeta_Setting[]
	 */
	public $previewed_postmeta_settings = array();

	/**
	 * Whether the preview filters have been added.
	 *
	 * @see WP_Customize_Posts_Preview::add_preview_filters()
	 * @var bool
	 */
	protected $has_preview_filters = false;

	/**
	 * Initial loader.
	 *
	 * @access public
	 *
	 * @param WP_Customize_Posts $component Component.
	 */
	public function __construct( WP_Customize_Posts $component ) {
		$this->component = $component;

		add_action( 'customize_preview_init', array( $this, 'customize_preview_init' ) );
	}

	/**
	 * Setup the customizer preview.
	 */
	public function customize_preview_init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'customize_dynamic_partial_args', array( $this, 'filter_customize_dynamic_partial_args' ), 10, 2 );
		add_filter( 'customize_dynamic_partial_class', array( $this, 'filter_customize_dynamic_partial_class' ), 10, 3 );
		add_filter( 'the_posts', array( $this, 'filter_the_posts_to_add_dynamic_post_settings_and_sections' ), 1000 );
		add_filter( 'comments_open', array( $this, 'filter_preview_comments_open' ), 10, 2 );
		add_filter( 'pings_open', array( $this, 'filter_preview_pings_open' ), 10, 2 );
		add_filter( 'get_post_metadata', array( $this, 'filter_get_post_meta_to_add_dynamic_postmeta_settings' ), 1000, 4 );
		add_action( 'wp_footer', array( $this, 'export_preview_data' ), 10 );
		add_filter( 'edit_post_link', array( $this, 'filter_edit_post_link' ), 10, 2 );
		add_filter( 'get_edit_post_link', array( $this, 'filter_get_edit_post_link' ), 10, 2 );
		add_filter( 'get_avatar', array( $this, 'filter_get_avatar' ), 10, 6 );
		add_filter( 'infinite_scroll_results', array( $this, 'export_registered_settings' ), 10 );
		add_filter( 'customize_render_partials_response', array( $this, 'export_registered_settings' ), 10 );
	}

	/**
	 * Add preview filters for post and postmeta settings.
	 */
	public function add_preview_filters() {
		if ( $this->has_preview_filters ) {
			return false;
		}
		add_filter( 'the_posts', array( $this, 'filter_the_posts_to_preview_settings' ), 1000 );
		add_action( 'the_post', array( $this, 'preview_setup_postdata' ) );
		add_filter( 'get_post_metadata', array( $this, 'filter_get_post_meta_to_preview' ), 1000, 4 );
		$this->has_preview_filters = true;
		return true;
	}

	/**
	 * Enqueue scripts for the customizer preview.
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 'customize-post-field-partial' );
		wp_enqueue_script( 'customize-preview-posts' );
	}

	/**
	 * Override calls to setup_postdata with the previewed post_data. In most
	 * cases, the get_posts filter above should already set this up as expected
	 * but if a post os fetched via get_post() or by some other means, then
	 * this will ensure that it gets supplied with the previewed data when
	 * the post data is setup.
	 *
	 * @todo The WP_Post class does not provide any facility to filter post fields.
	 *
	 * @param WP_Post $post Post.
	 */
	public function preview_setup_postdata( WP_Post $post ) {
		static $prevent_setup_postdata_recursion = false;
		if ( $prevent_setup_postdata_recursion ) {
			return;
		}

		$setting_id = WP_Customize_Post_Setting::get_post_setting_id( $post );
		$setting = $this->component->manager->get_setting( $setting_id );
		if ( $setting instanceof WP_Customize_Post_Setting ) {
			$prevent_setup_postdata_recursion = true;
			$setting->override_post_data( $post );
			setup_postdata( $post );
			$prevent_setup_postdata_recursion = false;
		}
	}

	/**
	 * Create dynamic post settings and sections for posts queried in the page.
	 *
	 * @param array $posts Posts.
	 * @return array
	 */
	public function filter_the_posts_to_add_dynamic_post_settings_and_sections( array $posts ) {
		foreach ( $posts as &$post ) {
			$post_setting_id = WP_Customize_Post_Setting::get_post_setting_id( $post );
			$this->component->manager->add_dynamic_settings( array( $post_setting_id ) );
			$setting = $this->component->manager->get_setting( $post_setting_id );
			if ( $setting instanceof WP_Customize_Post_Setting && ! $this->component->manager->get_section( $setting->id ) ) {
				$section = new WP_Customize_Post_Section( $this->component->manager, $setting->id, array(
					'panel' => sprintf( 'posts[%s]', $setting->post_type ),
					'post_setting' => $setting,
				) );
				$this->component->manager->add_section( $section );
			}
		}
		return $posts;
	}

	/**
	 * Override post data for previewed settings.
	 *
	 * @param array $posts Posts.
	 * @return array
	 */
	public function filter_the_posts_to_preview_settings( array $posts ) {
		foreach ( $posts as &$post ) {
			$post_setting_id = WP_Customize_Post_Setting::get_post_setting_id( $post );
			$setting = $this->component->manager->get_setting( $post_setting_id );
			if ( $setting instanceof WP_Customize_Post_Setting && isset( $this->previewed_post_settings[ $post->ID ] ) ) {
				$setting->override_post_data( $post );
			}
		}
		return $posts;
	}

	/**
	 * Filter the comments open for a previewed post.
	 *
	 * @param bool        $open Pings status.
	 * @param int|WP_Post $post Post.
	 *
	 * @return bool Whether the comments are open.
	 */
	public function filter_preview_comments_open( $open, $post ) {
		$post_id = ( $post instanceof WP_Post ? $post->ID : $post );
		if ( isset( $this->previewed_post_settings[ $post_id ] ) ) {
			$setting = $this->previewed_post_settings[ $post_id ];
			$post_data = $setting->value();
			$open = 'open' === $post_data['comment_status'];
		}
		return $open;
	}

	/**
	 * Filter the pings open for a previewed post.
	 *
	 * @param bool        $open Pings status.
	 * @param int|WP_Post $post Post.
	 *
	 * @return bool Whether the pings are open.
	 */
	public function filter_preview_pings_open( $open, $post ) {
		$post_id = ( $post instanceof WP_Post ? $post->ID : $post );
		if ( isset( $this->previewed_post_settings[ $post_id ] ) ) {
			$setting = $this->previewed_post_settings[ $post_id ];
			$post_data = $setting->value();
			$open = 'open' === $post_data['ping_status'];
		}
		return $open;
	}

	/**
	 * Filter postmeta to dynamically add postmeta settings.
	 *
	 * @param null|array|string $value     The value get_metadata() should return - a single metadata value, or an array of values.
	 * @param int               $object_id Object ID.
	 * @param string            $meta_key  Meta key.
	 * @return mixed Value.
	 */
	public function filter_get_post_meta_to_add_dynamic_postmeta_settings( $value, $object_id, $meta_key ) {
		$post = get_post( $object_id );
		if ( ! $post || ! isset( $this->component->registered_post_meta[ $post->post_type ] ) ) {
			return $value;
		}

		if ( '' === $meta_key ) {
			if ( null !== $value ) {
				$meta_keys = array_keys( $value );
			} else {
				$meta_keys = array();
			}
		} else {
			$meta_keys = array( $meta_key );
		}

		$setting_ids = array();
		if ( ! empty( $meta_keys ) ) {
			foreach ( $meta_keys as $key ) {
				if ( isset( $this->component->registered_post_meta[ $post->post_type ][ $key ] ) ) {
					$setting_ids[] = WP_Customize_Postmeta_Setting::get_post_meta_setting_id( $post, $key );
				}
			}
		}
		$this->component->manager->add_dynamic_settings( $setting_ids );

		return $value;
	}

	/**
	 * Filter postmeta to inject customized post meta values.
	 *
	 * @param null|array|string $value     The value get_metadata() should return - a single metadata value, or an array of values.
	 * @param int               $object_id Object ID.
	 * @param string            $meta_key  Meta key.
	 * @param bool              $single    Whether to return only the first value of the specified $meta_key.
	 * @return mixed Value.
	 */
	public function filter_get_post_meta_to_preview( $value, $object_id, $meta_key, $single ) {
		static $is_recursing = false;
		$should_short_circuit = (
			$is_recursing
			||
			// Abort if another filter has already short-circuited.
			null !== $value
			||
			// Abort if the post has no meta previewed.
			! isset( $this->previewed_postmeta_settings[ $object_id ] )
			||
			( '' !== $meta_key && ! isset( $this->previewed_postmeta_settings[ $object_id ][ $meta_key ] ) )
		);
		if ( $should_short_circuit ) {
			if ( is_null( $value ) ) {
				return null;
			} elseif ( ! $single && ! is_array( $value ) ) {
				return array( $value );
			} else {
				return $value;
			}
		}

		/**
		 * Setting.
		 *
		 * @var WP_Customize_Postmeta_Setting $postmeta_setting
		 */

		$post_values = $this->component->manager->unsanitized_post_values();

		if ( '' !== $meta_key ) {
			$postmeta_setting = $this->previewed_postmeta_settings[ $object_id ][ $meta_key ];
			$can_preview = (
				$postmeta_setting
				&&
				array_key_exists( $postmeta_setting->id, $post_values )
			);
			if ( $can_preview ) {
				$value = $postmeta_setting->post_value();
			}

			return $single ? $value : array( $value );
		} else {

			$is_recursing = true;
			$meta_values = get_post_meta( $object_id, '', $single );
			$is_recursing = false;

			foreach ( $this->previewed_postmeta_settings[ $object_id ] as $postmeta_setting ) {
				if ( ! array_key_exists( $postmeta_setting->id, $post_values ) ) {
					continue;
				}
				$meta_value = $postmeta_setting->post_value();
				$meta_value = maybe_serialize( $meta_value );

				// Note that $single has no effect when $meta_key is ''.
				$meta_values[ $postmeta_setting->meta_key ] = array( $meta_value );
			}
			return $meta_values;
		}
	}

	/**
	 * Recognize partials for posts appearing in preview.
	 *
	 * @param false|array $args Partial args.
	 * @param string      $id   Partial ID.
	 *
	 * @return array|false
	 */
	public function filter_customize_dynamic_partial_args( $args, $id ) {
		if ( preg_match( WP_Customize_Post_Field_Partial::ID_PATTERN, $id, $matches ) ) {
			$post_type_obj = get_post_type_object( $matches['post_type'] );
			if ( ! $post_type_obj ) {
				return $args;
			}
			if ( false === $args ) {
				$args = array();
			}
			$args['type'] = WP_Customize_Post_Field_Partial::TYPE;

			$field_id = $matches['field_id'];
			if ( ! empty( $matches['placement'] ) ) {
				$field_id .= '[' . $matches['placement'] . ']';
			}
			$schema = $this->get_post_field_partial_schema( $field_id );
			if ( ! empty( $schema ) ) {
				$args = array_merge( $args, $schema );
			}
		}
		return $args;
	}

	/**
	 * Filters the class used to construct post field partials.
	 *
	 * @param string $partial_class WP_Customize_Partial or a subclass.
	 * @param string $partial_id    ID for dynamic partial.
	 * @param array  $partial_args  The arguments to the WP_Customize_Partial constructor.
	 * @return string Class.
	 */
	function filter_customize_dynamic_partial_class( $partial_class, $partial_id, $partial_args ) {
		unset( $partial_id );
		if ( isset( $partial_args['type'] ) && WP_Customize_Post_Field_Partial::TYPE === $partial_args['type'] ) {
			$partial_class = 'WP_Customize_Post_Field_Partial';
		}
		return $partial_class;
	}

	/**
	 * Filters get_edit_post_link to short-circuits if post cannot be edited in Customizer.
	 *
	 * @param string $url The edit link.
	 * @param int    $post_id Post ID.
	 * @return string|null
	 */
	function filter_get_edit_post_link( $url, $post_id ) {
		$edit_post = get_post( $post_id );
		if ( ! $edit_post ) {
			return null;
		}
		if ( ! $this->component->current_user_can_edit_post( $edit_post ) ) {
			return null;
		}
		$setting_id = WP_Customize_Post_Setting::get_post_setting_id( $edit_post );
		if ( ! $this->component->manager->get_setting( $setting_id ) ) {
			return null;
		}
		return $url;
	}

	/**
	 * Filter the post edit link so it can open the post in the Customizer.
	 *
	 * @param string $link    Anchor tag for the edit link.
	 * @param int    $post_id Post ID.
	 * @return string Edit link.
	 */
	function filter_edit_post_link( $link, $post_id ) {
		$edit_post = get_post( $post_id );
		$setting_id = WP_Customize_Post_Setting::get_post_setting_id( $edit_post );
		$data_attributes = sprintf( ' data-customize-post-setting-id="%s"', $setting_id );
		$link = preg_replace( '/(?<=<a\s)/', $data_attributes, $link );
		return $link;
	}

	/**
	 * Filter the avatar to inject the args as context data.
	 *
	 * @param string $avatar      &lt;img&gt; tag for the user's avatar.
	 * @param mixed  $id_or_email The Gravatar to retrieve. Accepts a user_id, gravatar md5 hash,
	 *                            user email, WP_User object, WP_Post object, or WP_Comment object.
	 * @param int    $size        Square avatar width and height in pixels to retrieve.
	 * @param int    $default     Default.
	 * @param string $alt         Alternative text to use in the avatar image tag.
	 *                                       Default empty.
	 * @param array  $args        Arguments passed to get_avatar_data(), after processing.
	 * @return string Avatar.
	 */
	function filter_get_avatar( $avatar, $id_or_email, $size, $default, $alt, $args ) {
		unset( $id_or_email, $size, $default, $alt );

		// Strip out $found_avatar and any other args amended when get_avatar() is called.
		$args = wp_array_slice_assoc( $args, array(
			'size',
			'height',
			'width',
			'default',
			'force_default',
			'rating',
			'scheme',
			'alt',
			'class',
			'force_display',
			'extra_attr',
		) );
		$data_attribute = sprintf( ' data-customize-partial-placement-context="%s"', esc_attr( wp_json_encode( $args ) ) );
		$avatar = preg_replace( '/(?<=<img\s)/', $data_attribute, $avatar );
		return $avatar;
	}

	/**
	 * Get the schema for dynamically registered partials.
	 *
	 * @param string $field_id The partial field ID.
	 * @return array
	 */
	public function get_post_field_partial_schema( $field_id = '' ) {
		$schema = array(
			'post_title' => array(
				'selector' => '.entry-title',
			),
			'post_content' => array(
				'selector' => '.entry-content',
			),
			'post_excerpt' => array(
				'selector' => '.entry-summary',
			),
			'comment_status[comments-area]' => array(
				'selector' => '.comments-area',
				'body_selector' => true,
				'singular_only' => true,
				'container_inclusive' => true,
			),
			'comment_status[comments-link]' => array(
				'selector' => '.comments-link',
				'archive_only' => true,
				'container_inclusive' => true,
			),
			'ping_status' => array(
				'selector' => '.comments-area',
				'body_selector' => true,
				'singular_only' => true,
				'container_inclusive' => true,
			),
			'post_author[byline]' => array(
				'selector' => '.vcard a.fn',
				'container_inclusive' => true,
				'fallback_refresh' => false,
			),
			'post_author[avatar]' => array(
				'selector' => '.vcard img.avatar',
				'container_inclusive' => true,
				'fallback_refresh' => false,
			),
		);

		/**
		 * Filter the schema for dynamically registered partials.
		 *
		 * @param array $schema Partial schema.
		 * @return array
		 */
		$schema = apply_filters( 'customize_posts_partial_schema', $schema );

		// Return specific schema based on the field_id & placement.
		if ( ! empty( $field_id ) ) {
			if ( isset( $schema[ $field_id ] ) ) {
				return $schema[ $field_id ];
			} else {
				return array();
			}
		}

		return $schema;
	}

	/**
	 * Export data into the customize preview.
	 */
	public function export_preview_data() {
		$queried_post_id = 0; // Can't be null due to wp.customize.Value.
		if ( get_queried_object() instanceof WP_Post ) {
			$queried_post_id = get_queried_object_id();
		}

		$setting_properties = array();
		foreach ( $this->component->manager->settings() as $setting ) {
			if ( $setting instanceof WP_Customize_Post_Setting || $setting instanceof WP_Customize_Postmeta_Setting ) {
				if ( ! $setting->check_capabilities() ) {
					continue;
				}

				// Note that the value and dirty properties are already exported in wp.customize.settings.
				$setting_properties[ $setting->id ] = array(
					'transport' => $setting->transport,
					'type' => $setting->type,
				);
			}
		}

		$exported_partial_schema = array();
		foreach ( $this->get_post_field_partial_schema() as $key => $schema ) {
			unset( $schema['render_callback'] ); // PHP callbacks are generally not JSON-serializable.
			$exported_partial_schema[ $key ] = $schema;
		}

		$exported = array(
			'isPostPreview' => is_preview(),
			'isSingular' => is_singular(),
			'queriedPostId' => $queried_post_id,
			'settingProperties' => $setting_properties,
			'partialSchema' => $exported_partial_schema,
		);

		$data = sprintf( 'var _wpCustomizePreviewPostsData = %s;', wp_json_encode( $exported ) );
		wp_scripts()->add_data( 'customize-preview-posts', 'data', $data );
	}

	/**
	 * Amend an array with all of the registered post and postmeta settings.
	 *
	 * Filter for the Partial Render and Infinite Scroll results.
	 *
	 * @param array $results Array of Infinite Scroll results.
	 * @return array $results Results.
	 */
	public function export_registered_settings( $results ) {

		$results['customize_post_settings'] = array();
		foreach ( $this->component->manager->settings() as $setting ) {
			/**
			 * Setting.
			 *
			 * @var WP_Customize_Setting $setting
			 */
			if ( ! $setting->check_capabilities() ) {
				continue;
			}
			if ( $setting instanceof WP_Customize_Post_Setting || $setting instanceof WP_Customize_Postmeta_Setting ) {
				$results['customize_post_settings'][ $setting->id ] = array(
					'value' => $setting->value(),
					'transport' => $setting->transport,
					'dirty' => $setting->dirty,
					'type' => $setting->type,
				);
			}
		}

		return $results;
	}
}
