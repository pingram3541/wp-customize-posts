<?php
/**
 * Customize Post Setting class.
 *
 * @package WordPress
 * @subpackage Customize
 */

/**
 * Class WP_Customize_Post_Setting
 */
class WP_Customize_Post_Setting extends WP_Customize_Setting {

	const SETTING_ID_PATTERN = '/^post\[(?P<post_type>[^\]]+)\]\[(?P<post_id>-?\d+)\]$/';

	const TYPE = 'post';

	/**
	 * Type of setting.
	 *
	 * @access public
	 * @var string
	 */
	public $type = self::TYPE;

	/**
	 * Post ID.
	 *
	 * @access public
	 * @var string
	 */
	public $post_id;

	/**
	 * Post type.
	 *
	 * @access public
	 * @var string
	 */
	public $post_type;

	/**
	 * Posts component.
	 *
	 * @var WP_Customize_Posts
	 */
	public $posts_component;

	/**
	 * Default post data.
	 *
	 * @var array
	 */
	public $default = array(
		'post_author' => 0,
		'post_name' => '',
		'post_date' => '',
		'post_date_gmt' => '',
		'post_mime_type' => '',
		'post_modified' => '',
		'post_modified_gmt' => '',
		'post_content' => '',
		'post_content_filtered' => '',
		'post_title' => '',
		'post_excerpt' => '',
		'post_status' => 'draft',
		'comment_status' => '',
		'ping_status' => '',
		'post_password' => '',
		'post_parent' => 0,
		'menu_order' => 0,
		'guid' => '',
	);

	/**
	 * WP_Customize_Post_Setting constructor.
	 *
	 * @param WP_Customize_Manager $manager Manager.
	 * @param string               $id      Setting ID.
	 * @param array                $args    Setting args.
	 * @throws Exception If the ID is in an invalid format.
	 */
	public function __construct( WP_Customize_Manager $manager, $id, $args = array() ) {
		if ( ! preg_match( self::SETTING_ID_PATTERN, $id, $matches ) ) {
			throw new Exception( 'Illegal setting id: ' . $id );
		}
		$args['post_id'] = intval( $matches['post_id'] );
		$args['post_type'] = $matches['post_type'];
		$post_type_obj = get_post_type_object( $args['post_type'] );
		if ( ! $post_type_obj ) {
			throw new Exception( 'Unrecognized post type: ' . $args['post_type'] );
		}
		if ( empty( $manager->posts ) || ! ( $manager->posts instanceof WP_Customize_Posts ) ) {
			throw new Exception( 'Posts component not instantiated.' );
		}
		$this->posts_component = $manager->posts;
		$update = $args['post_id'] > 0;
		$post_type_obj = get_post_type_object( $args['post_type'] );

		// Determine capability for editing this.
		$can_edit = false;
		if ( $update ) {
			$can_edit = $this->posts_component->current_user_can_edit_post( $args['post_id'] );
		} elseif ( $post_type_obj ) {
			$can_edit = current_user_can( $post_type_obj->cap->edit_posts );
		}
		if ( $can_edit ) {
			$args['capability'] = $post_type_obj->cap->edit_posts;
		} else {
			$args['capability'] = 'do_not_allow';
		}

		parent::__construct( $manager, $id, $args );

		if ( empty( $this->default['post_author'] ) ) {
			$this->default['post_author'] = get_current_user_id();
		}
	}

	/**
	 * Get setting ID for a given post.
	 *
	 * @param WP_Post $post Post.
	 * @return string Setting ID.
	 */
	static function get_post_setting_id( WP_Post $post ) {
		return sprintf( 'post[%s][%d]', $post->post_type, $post->ID );
	}

	/**
	 * Apply customized post override to a post.
	 *
	 * @param WP_Post $post Post.
	 * @return bool False if the post data did not apply.
	 */
	public function override_post_data( WP_Post &$post ) {
		if ( $post->ID !== $this->post_id ) {
			return false;
		}
		if ( ! isset( $this->posts_component->preview->previewed_post_settings[ $post->ID ] ) ) {
			return false;
		}
		$post_value = $this->post_value( null );
		if ( ! is_array( $post_value ) ) {
			return false;
		}

		$post_data_keys = array_keys( $this->default );
		foreach ( $post_value as $key => $value ) {
			if ( in_array( $key, $post_data_keys, true ) ) {
				$post->$key = $value;
			}
		}
		return true;
	}

	/**
	 * Makes sure that the $post_data has the proper types.
	 *
	 * @param array $post_data Post data.
	 *
	 * @return array
	 */
	protected function normalize_post_data( array $post_data ) {
		$int_properties = array(
			'menu_order',
			'post_author',
			'post_parent',
		);
		foreach ( $int_properties as $key ) {
			$post_data[ $key ] = intval( $post_data[ $key ] );
		}

		/*
		 * For some reason WordPress stores newlines in DB as CRLF when saving via
		 * the WP Admin, but normally a textarea just represents newlines as LF.
		 */
		if ( isset( $post_data['post_content'] ) ) {
			$post_data['post_content'] = preg_replace( '/\r\n/', "\n", $post_data['post_content'] );
		}
		if ( isset( $post_data['post_excerpt'] ) ) {
			$post_data['post_excerpt'] = preg_replace( '/\r\n/', "\n", $post_data['post_excerpt'] );
		}

		return $post_data;
	}

	/**
	 * Return a post's setting value.
	 *
	 * @return array Post data.
	 */
	public function value() {
		$post = get_post( $this->post_id );
		if ( $post ) {
			$post_data = $this->get_post_data( $post );
		} else {
			$post_data = $this->default;
		}

		if ( $this->is_previewed ) {
			$post_data = array_merge( $post_data, $this->post_value( array() ) );
		}

		$post_data = $this->normalize_post_data( $post_data );
		return $post_data;
	}

	/**
	 * Get the post data to be used in a setting value.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public function get_post_data( WP_Post $post ) {
		$post_data = wp_array_slice_assoc(
			$post->to_array(),
			array_keys( $this->default )
		);
		$post_data = $this->normalize_post_data( $post_data );
		return $post_data;
	}

	/**
	 * Determines whether the incoming post data conflicts with the existing post data.
	 *
	 * @param WP_Post $existing_post      Existing post.
	 * @param array   $incoming_post_data Incoming post data.
	 *
	 * @return bool
	 */
	protected function is_post_data_conflicted( WP_Post $existing_post, array $incoming_post_data ) {
		unset( $incoming_post_data['post_modified_gmt'] );
		unset( $incoming_post_data['post_modified'] );
		$existing_post_data = $this->get_post_data( $existing_post );
		foreach ( $incoming_post_data as $field_id => $field_value ) {
			if ( isset( $existing_post_data[ $field_id ] ) && $field_value !== $existing_post_data[ $field_id ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sanitize (and validate) an input.
	 *
	 * @see wp_insert_post()
	 *
	 * @param array $post_data   The value to sanitize.
	 * @param bool  $strict      Whether validation is being done. This is part of the proposed patch in in #34893.
	 * @return string|array|null|WP_Error Null if an input isn't valid, otherwise the sanitized value. WP_Error returned if `$strict`.
	 */
	public function sanitize( $post_data, $strict = false ) {
		global $wpdb;

		$post_data = array_merge( $this->default, $post_data );

		// The customize_validate_settings action is part of the Customize Setting Validation plugin.
		if ( ! $strict && doing_action( 'customize_validate_settings' ) ) {
			$strict = true;
		}

		$update = ( $this->post_id > 0 );
		$post_type_obj = get_post_type_object( $this->post_type );

		if ( $strict && ! empty( $post_data['post_type'] ) && $post_data['post_type'] !== $this->post_type ) {
			return new WP_Error( 'bad_post_type' );
		}
		$post_data['post_type'] = $this->post_type;

		if ( $strict && $update ) {
			// Check post lock.
			$locked_user = wp_check_post_lock( $this->post_id );
			if ( $locked_user ) {
				$user = get_user_by( 'ID', $locked_user );
				$error_message = sprintf(
					__( 'Post is currently locked by %s.', 'customize-posts' ),
					$user ? $user->display_name : __( '(unknown user)', 'customize-posts' )
				);
				return new WP_Error( 'post_locked', $error_message );
			}

			// Check post update conflict.
			$post = get_post( $this->post_id );
			$is_update_conflict = (
				! empty( $post )
				&&
				! empty( $post_data['post_modified_gmt'] )
				&&
				$post_data['post_modified_gmt'] < $post->post_modified_gmt
				&&
				$this->is_post_data_conflicted( $post, $post_data )
			);
			if ( $is_update_conflict ) {
				$user = get_user_by( 'ID', get_post_meta( $this->post_id, '_edit_last', true ) );
				$error_message = sprintf(
					__( 'Conflict due to concurrent post update by %s.', 'customize-posts' ),
					$user ? $user->display_name : __( '(unknown user)', 'customize-posts' )
				);
				$this->posts_component->update_conflicted_settings[ $this->id ] = $this;
				return new WP_Error( 'post_update_conflict', $error_message );
			}
		}

		$post_data = wp_slash( $post_data );
		$unsanitized_post_data = $post_data;
		$post_data = sanitize_post( $post_data, 'db' );
		$initial_sanitized_post_data = $post_data;

		$maybe_empty = 'attachment' !== $this->post_type
			&& empty( $post_data['post_content'] ) && empty( $post_data['post_title'] ) && empty( $post_data['post_excerpt'] )
			&& post_type_supports( $this->post_type, 'editor' )
			&& post_type_supports( $this->post_type, 'title' )
			&& post_type_supports( $this->post_type, 'excerpt' );

		/** This filter is documented in wp-includes/post.php */
		if ( $strict && apply_filters( 'wp_insert_post_empty_content', $maybe_empty, $post_data ) ) {
			return new WP_Error( 'empty_content', __( 'Content, title, and excerpt are empty.', 'customize-posts' ) );
		}

		if ( empty( $post_data['post_status'] ) ) {
			$post_data['post_status'] = 'draft';
		}
		if ( 'attachment' === $this->post_type && ! in_array( $post_data['post_status'], array( 'inherit', 'private', 'trash' ), true ) ) {
			$post_data['post_status'] = 'inherit';
		}

		// Don't allow contributors to set the post slug for pending review posts.
		if ( 'pending' === $post_data['post_status'] && ! current_user_can( 'publish_posts' ) ) {
			$post_data['post_name'] = '';
		}

		/*
		 * Create a valid post name. Drafts and pending posts are allowed to have
		 * an empty post name.
		 */
		if ( empty( $post_data['post_name'] ) ) {
			if ( ! in_array( $post_data['post_status'], array( 'draft', 'pending', 'auto-draft' ), true ) ) {
				$post_data['post_name'] = sanitize_title( $post_data['post_title'] );
			}
		} else {
			// On updates, we need to check to see if it's using the old, fixed sanitization context.
			$check_name = sanitize_title( $post_data['post_name'], '', 'old-save' );
			if ( $update && strtolower( urlencode( $post_data['post_name'] ) ) === $post_data['post_name'] && get_post_field( 'post_name', $this->post_id ) === $check_name ) {
				$post_data['post_name'] = $check_name;
			} else {
				// New post, or slug has changed.
				$post_data['post_name'] = sanitize_title( $post_data['post_name'] );
			}
		}

		/*
		 * If the post date is empty (due to having been new or a draft) and status
		 * is not 'draft' or 'pending', set date to now.
		 */
		if ( empty( $post_data['post_date'] ) || '0000-00-00 00:00:00' === $post_data['post_date'] ) {
			if ( empty( $post_data['post_date_gmt'] ) || '0000-00-00 00:00:00' === $post_data['post_date_gmt'] ) {
				$post_data['post_date'] = current_time( 'mysql' );
			} else {
				$post_data['post_date'] = get_date_from_gmt( $post_data['post_date_gmt'] );
			}
		}

		// Validate the date.
		$mm = substr( $post_data['post_date'], 5, 2 );
		$jj = substr( $post_data['post_date'], 8, 2 );
		$aa = substr( $post_data['post_date'], 0, 4 );
		$valid_date = wp_checkdate( $mm, $jj, $aa, $post_data['post_date'] );
		if ( ! $valid_date ) {
			if ( $strict ) {
				return new WP_Error( 'invalid_date', __( 'Whoops, the provided date is invalid.', 'customize-posts' ) );
			} else {
				$post_data['post_date'] = '';
			}
		}

		if ( empty( $post_data['post_date_gmt'] ) || '0000-00-00 00:00:00' === $post_data['post_date_gmt'] ) {
			if ( ! in_array( $post_data['post_status'], array( 'draft', 'pending', 'auto-draft' ), true ) ) {
				$post_data['post_date_gmt'] = get_gmt_from_date( $post_data['post_date'] );
			} else {
				$post_data['post_date_gmt'] = '0000-00-00 00:00:00';
			}
		}

		if ( $update || '0000-00-00 00:00:00' === $post_data['post_date'] ) {
			$post_data['post_modified']     = current_time( 'mysql' );
			$post_data['post_modified_gmt'] = current_time( 'mysql', 1 );
		} else {
			$post_data['post_modified']     = $post_data['post_date'];
			$post_data['post_modified_gmt'] = $post_data['post_date_gmt'];
		}

		if ( 'attachment' !== $this->post_type ) {
			if ( 'publish' === $post_data['post_status'] ) {
				$now = gmdate( 'Y-m-d H:i:59' );
				if ( mysql2date( 'U', $post_data['post_date_gmt'], false ) > mysql2date( 'U', $now, false ) ) {
					$post_data['post_status'] = 'future';
				}
			} elseif ( 'future' === $post_data['post_status'] ) {
				$now = gmdate( 'Y-m-d H:i:59' );
				if ( mysql2date( 'U', $post_data['post_date_gmt'], false ) <= mysql2date( 'U', $now, false ) ) {
					$post_data['post_status'] = 'publish';
				}
			}
		}

		// Comment status.
		if ( empty( $post_data['comment_status'] ) ) {
			if ( $update ) {
				$post_data['comment_status'] = 'closed';
			} else {
				$post_data['comment_status'] = get_default_comment_status( $this->post_type );
			}
		}

		// Ping status.
		if ( empty( $post_data['ping_status'] ) ) {
			if ( $update ) {
				$post_data['ping_status'] = 'closed';
			} else {
				$post_data['ping_status'] = get_default_comment_status( $this->post_type, 'pingback' );
			}
		}

		if ( empty( $post_data['post_author'] ) || ( ! current_user_can( $post_type_obj->cap->edit_others_posts ) && intval( $post_data['post_author'] ) !== get_current_user_id() ) ) {
			$post_data['post_author'] = get_current_user_id();
		}
		if ( empty( $post_data['menu_order'] ) ) {
			$post_data['menu_order'] = 0;
		}
		$post_data['menu_order'] = intval( $post_data['menu_order'] );

		if ( 'private' === $post_data['post_status'] ) {
			$post_data['post_password'] = '';
		}
		if ( empty( $post_data['post_parent'] ) ) {
			$post_data['post_parent'] = 0;
		}
		$post_data['post_parent'] = intval( $post_data['post_parent'] );

		/** This filter is documented in wp-includes/post.php */
		$post_data['post_parent'] = apply_filters( 'wp_insert_post_parent', $post_data['post_parent'], $this->post_id, $post_data, $initial_sanitized_post_data );

		// @todo Note that wp_unique_post_slug is not currently being applied.
		$emoji_fields = array( 'post_title', 'post_content', 'post_excerpt' );
		foreach ( $emoji_fields as $emoji_field ) {
			if ( isset( $post_data[ $emoji_field ] ) ) {
				$charset = $wpdb->get_col_charset( $wpdb->posts, $emoji_field );
				if ( 'utf8' === $charset ) {
					$post_data[ $emoji_field ] = wp_encode_emoji( $post_data[ $emoji_field ] );
				}
			}
		}

		if ( 'attachment' === $this->post_type ) {
			/** This filter is documented in wp-includes/post.php */
			$post_data = apply_filters( 'wp_insert_attachment_data', $post_data, $unsanitized_post_data );
		} else {
			/** This filter is documented in wp-includes/post.php */
			$post_data = apply_filters( 'wp_insert_post_data', $post_data, $unsanitized_post_data );
		}
		$post_data = wp_unslash( $post_data );

		$post_data = $this->normalize_post_data( $post_data );

		unset( $post_data['post_type'] );
		return $post_data;
	}

	/**
	 * Flag this setting as one to be previewed.
	 *
	 * Note that the previewing logic is handled by WP_Customize_Posts_Preview.
	 *
	 * @return bool
	 */
	public function preview() {
		if ( $this->is_previewed ) {
			return true;
		}
		$this->posts_component->preview->previewed_post_settings[ $this->post_id ] = $this;
		$this->posts_component->preview->add_preview_filters();
		$this->is_previewed = true;
		return true;
	}

	/**
	 * Update the post.
	 *
	 * Please note that the capability check will have already been done.
	 *
	 * @see WP_Customize_Setting::save()
	 *
	 * @param string $data The value to update.
	 * @return bool The result of saving the value.
	 */
	protected function update( $data ) {

		// Inserts are not supported yet.
		if ( $this->post_id < 0 ) {
			return false;
		}

		$data['ID'] = $this->post_id;
		$data['post_type'] = $this->post_type;

		$result = wp_update_post( wp_slash( $data ), true );

		if ( is_wp_error( $result ) ) {
			// @todo Amend customize_save_response
			return false;
		}
		return true;
	}
}
