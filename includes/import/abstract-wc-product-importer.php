<?php
/**
 * Abstract Product importer
 *
 * @author   Automattic
 * @category Admin
 * @package  WooCommerce/Import
 * @version  3.1.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Include dependencies.
 */
if ( ! class_exists( 'WC_Importer_Interface', false ) ) {
	include_once( WC_ABSPATH . 'includes/interfaces/class-wc-importer-interface.php' );
}

/**
 * WC_Product_Importer Class.
 */
abstract class WC_Product_Importer implements WC_Importer_Interface {

	/**
	 * CSV file.
	 *
	 * @var string
	 */
	protected $file = '';

	/**
	 * The file position after the last read.
	 *
	 * @var int
	 */
	protected $file_position = 0;

	/**
	 * Importer parameters.
	 *
	 * @var array
	 */
	protected $params = array();

	/**
	 * Raw keys - CSV raw headers.
	 *
	 * @var array
	 */
	protected $raw_keys = array();

	/**
	 * Mapped keys - CSV headers.
	 *
	 * @var array
	 */
	protected $mapped_keys = array();

	/**
	 * Raw data.
	 *
	 * @var array
	 */
	protected $raw_data = array();

	/**
	 * Parsed data.
	 *
	 * @var array
	 */
	protected $parsed_data = array();

	/**
	 * Get file raw headers.
	 *
	 * @return array
	 */
	public function get_raw_keys() {
		return $this->raw_keys;
	}

	/**
	 * Get file mapped headers.
	 *
	 * @return array
	 */
	public function get_mapped_keys() {
		return ! empty( $this->mapped_keys ) ? $this->mapped_keys : $this->raw_keys;
	}

	/**
	 * Get raw data.
	 *
	 * @return array
	 */
	public function get_raw_data() {
		return $this->raw_data;
	}

	/**
	 * Get parsed data.
	 *
	 * @return array
	 */
	public function get_parsed_data() {
		return apply_filters( 'woocommerce_product_importer_parsed_data', $this->parsed_data, $this->get_raw_data() );
	}

	/**
	 * Get file pointer position from the last read.
	 *
	 * @return int
	 */
	public function get_file_position() {
		return $this->file_position;
	}

	/**
	 * Get file pointer position as a percentage of file size.
	 *
	 * @return int
	 */
	public function get_percent_complete() {
		$size = filesize( $this->file );
		if ( ! $size ) {
			return 0;
		}

		return absint( min( round( ( $this->file_position / $size ) * 100 ), 100 ) );
	}

	/**
	 * Process a single item and save.
	 *
	 * @param  array $data Raw CSV data.
	 * @return array|WC_Error
	 */
	protected function process_item( $data ) {
		try {
			$object   = $this->get_product_object( $data );
			$updating = false;

			if ( is_wp_error( $object ) ) {
				return $object;
			}

			if ( $object->get_id() && 'importing' !== $object->get_status() ) {
				$updating = true;
			}

			if ( 'variation' === $object->get_type() ) {
				$object = $this->save_variation_data( $object, $data );
			} else {
				$object = $this->save_product_data( $object, $data );
			}

			$object = apply_filters( 'woocommerce_product_import_pre_insert_product_object', $object, $data );
			$object->save();

			return array(
				'id'      => $object->get_id(),
				'updated' => $updating,
			);
		} catch ( WC_Data_Exception $e ) {
			return new WP_Error( $e->getErrorCode(), $e->getMessage(), $e->getErrorData() );
		} catch ( Exception $e ) {
			return new WP_Error( 'woocommerce_product_importer_error', $e->getMessage(), array( 'status' => $e->getCode() ) );
		}
	}

	/**
	 * Prepare a single product for create or update.
	 *
	 * @param  array $data     Row data.
	 * @return WC_Product|WP_Error
	 */
	protected function get_product_object( $data ) {
		$id = isset( $data['id'] ) ? absint( $data['id'] ) : 0;

		// Type is the most important part here because we need to be using the correct class and methods.
		if ( isset( $data['type'] ) ) {
			$types   = array_keys( wc_get_product_types() );
			$types[] = 'variation';

			if ( ! in_array( $data['type'], $types, true ) ) {
				return new WP_Error( 'woocommerce_product_importer_invalid_type', __( 'Invalid product type.', 'woocommerce' ), array( 'status' => 401 ) );
			}

			$classname = WC_Product_Factory::get_classname_from_product_type( $data['type'] );

			if ( ! class_exists( $classname ) ) {
				$classname = 'WC_Product_Simple';
			}

			$product = new $classname( $id );
		} elseif ( isset( $data['id'] ) ) {
			$product = wc_get_product( $id );

			if ( ! $product ) {
				return new WP_Error( 'woocommerce_product_csv_importer_invalid_id', sprintf( __( 'Invalid product ID %d.', 'woocommerce' ), $id ), array( 'id' => $id, 'status' => 401 ) );
			}
		} else {
			$product = new WC_Product_Simple( $id );
		}

		return apply_filters( 'woocommerce_product_import_get_product_object', $product, $data );
	}

	/**
	 * Set product data.
	 *
	 * @param WC_Product $product Product instance.
	 * @param array      $data    Row data.
	 *
	 * @return WC_Product
	 */
	protected function save_product_data( $product, $data ) {

		// Name.
		if ( isset( $data['name'] ) ) {
			$product->set_name( wp_filter_post_kses( $data['name'] ) );
		}

		// Description.
		if ( isset( $data['description'] ) ) {
			$product->set_description( wp_filter_post_kses( $data['description'] ) );
		}

		// Short description.
		if ( isset( $data['short_description'] ) ) {
			$product->set_short_description( wp_filter_post_kses( $data['short_description'] ) );
		}

		// Status.
		if ( isset( $data['published'] ) ) {
			$product->set_status( $data['published'] ? 'publish' : 'draft' );
		} elseif ( 'importing' === $product->get_status() ) {
			$product->set_status( 'publish' );
		}

		// Slug.
		if ( isset( $data['slug'] ) ) {
			$product->set_slug( $data['slug'] );
		}

		// Comment status.
		if ( isset( $data['reviews_allowed'] ) ) {
			$product->set_reviews_allowed( $data['reviews_allowed'] );
		}

		// Virtual.
		if ( isset( $data['virtual'] ) ) {
			$product->set_virtual( $data['virtual'] );
		}

		// Tax status.
		if ( isset( $data['tax_status'] ) ) {
			$product->set_tax_status( $data['tax_status'] );
		}

		// Tax Class.
		if ( isset( $data['tax_class'] ) ) {
			$product->set_tax_class( $data['tax_class'] );
		}

		// Catalog Visibility.
		if ( isset( $data['catalog_visibility'] ) ) {
			$product->set_catalog_visibility( $data['catalog_visibility'] );
		}

		// Purchase Note.
		if ( isset( $data['purchase_note'] ) ) {
			$product->set_purchase_note( $data['purchase_note'] );
		}

		// Featured Product.
		if ( isset( $data['featured'] ) ) {
			$product->set_featured( $data['featured'] );
		}

		// Shipping data.
		$product = $this->save_product_shipping_data( $product, $data );

		// SKU.
		if ( isset( $data['sku'] ) ) {
			$product->set_sku( $data['sku'] );
		}

		// Attributes.
		if ( isset( $data['attributes'] ) ) {
			$attributes         = array();
			$default_attributes = array();

			foreach ( $data['attributes'] as $position => $attribute ) {
				// Get ID if is a global attribute.
				$attribute_id = wc_attribute_taxonomy_id_by_name( $attribute['name'] );

				// Set attribute visibility.
				if ( isset( $attribute['visible'] ) ) {
					$is_visible = $attribute['visible'];
				} else {
					$is_visible = 1;
				}

				// Set if is a variation attribute.
				$is_variation = 0;

				if ( $attribute_id ) {
					$attribute_name = wc_attribute_taxonomy_name_by_id( $attribute_id );

					if ( isset( $attribute['value'] ) ) {
						$options = array_map( 'wc_sanitize_term_text_based', $attribute['value'] );
						$options = array_filter( $options, 'strlen' );
					} else {
						$options = array();
					}

					// Check for default attributes and set "is_variation".
					if ( ! empty( $attribute['default'] ) && in_array( $attribute['default'], $options ) ) {
						$default_term = get_term_by( 'name', $attribute['default'], $attribute_name );

						if ( $default_term && ! is_wp_error( $default_term ) ) {
							$default = $default_term->slug;
						} else {
							$default = sanitize_title( $attribute['default'] );
						}

						$default_attributes[ $attribute_name ] = $default;
						$is_variation = 1;
					}

					if ( ! empty( $options ) ) {
						$attribute_object = new WC_Product_Attribute();
						$attribute_object->set_id( $attribute_id );
						$attribute_object->set_name( $attribute_name );
						$attribute_object->set_options( $options );
						$attribute_object->set_position( $position );
						$attribute_object->set_visible( $is_visible );
						$attribute_object->set_variation( $is_variation );
						$attributes[] = $attribute_object;
					}
				} elseif ( isset( $attribute['value'] ) ) {
					// Check for default attributes and set "is_variation".
					if ( ! empty( $attribute['default'] ) && in_array( $attribute['default'], $attribute['value'] ) ) {
						$default_attributes[ sanitize_title( $attribute['name'] ) ] = $attribute['default'];
						$is_variation = 1;
					}

					$attribute_object = new WC_Product_Attribute();
					$attribute_object->set_name( $attribute['name'] );
					$attribute_object->set_options( $attribute['value'] );
					$attribute_object->set_position( $position );
					$attribute_object->set_visible( $is_visible );
					$attribute_object->set_variation( $is_variation );
					$attributes[] = $attribute_object;
				}
			}

			$product->set_attributes( $attributes );

			// Set variable default attributes.
			if ( $product->is_type( 'variable' ) ) {
				$product->set_default_attributes( $default_attributes );
			}
		}

		// Sales and prices.
		if ( in_array( $product->get_type(), array( 'variable', 'grouped' ), true ) ) {
			$product->set_regular_price( '' );
			$product->set_sale_price( '' );
			$product->set_date_on_sale_to( '' );
			$product->set_date_on_sale_from( '' );
			$product->set_price( '' );
		} else {
			// Regular Price.
			if ( isset( $data['regular_price'] ) ) {
				$product->set_regular_price( $data['regular_price'] );
			}

			// Sale Price.
			if ( isset( $data['sale_price'] ) ) {
				$product->set_sale_price( $data['sale_price'] );
			}

			if ( isset( $data['date_on_sale_from'] ) ) {
				$product->set_date_on_sale_from( $data['date_on_sale_from'] );
			}

			if ( isset( $data['date_on_sale_to'] ) ) {
				$product->set_date_on_sale_to( $data['date_on_sale_to'] );
			}
		}

		// Product parent ID for groups.
		if ( isset( $data['parent_id'] ) ) {
			$product->set_parent_id( $data['parent_id'] );
		}

		// Sold individually.
		if ( isset( $data['sold_individually'] ) ) {
			$product->set_sold_individually( $data['sold_individually'] );
		}

		// Stock status.
		if ( isset( $data['stock_status'] ) ) {
			$stock_status = $data['stock_status'] ? 'instock' : 'outofstock';
		} else {
			$stock_status = $product->get_stock_status();
		}

		// Stock data.
		if ( 'yes' === get_option( 'woocommerce_manage_stock' ) ) {
			// Manage stock.
			if ( isset( $data['manage_stock'] ) ) {
				$product->set_manage_stock( $data['manage_stock'] );
			}

			// Backorders.
			if ( isset( $data['backorders'] ) ) {
				$product->set_backorders( $data['backorders'] );
			}

			if ( $product->is_type( 'grouped' ) ) {
				$product->set_manage_stock( false );
				$product->set_backorders( false );
				$product->set_stock_quantity( '' );
				$product->set_stock_status( $stock_status );
			} elseif ( $product->is_type( 'external' ) ) {
				$product->set_manage_stock( false );
				$product->set_backorders( false );
				$product->set_stock_quantity( '' );
				$product->set_stock_status( 'instock' );
			} elseif ( $product->get_manage_stock() ) {
				// Stock status is always determined by children so sync later.
				if ( ! $product->is_type( 'variable' ) ) {
					$product->set_stock_status( $stock_status );
				}

				// Stock quantity.
				if ( isset( $data['stock_quantity'] ) ) {
					$product->set_stock_quantity( wc_stock_amount( $data['stock_quantity'] ) );
				}
			} else {
				// Don't manage stock.
				$product->set_manage_stock( false );
				$product->set_stock_quantity( '' );
				$product->set_stock_status( $stock_status );
			}
		} elseif ( ! $product->is_type( 'variable' ) ) {
			$product->set_stock_status( $stock_status );
		}

		// Upsells.
		if ( isset( $data['upsell_ids'] ) ) {
			$product->set_upsell_ids( $data['upsell_ids'] );
		}

		// Cross sells.
		if ( isset( $data['cross_sell_ids'] ) ) {
			$product->set_cross_sell_ids( $data['cross_sell_ids'] );
		}

		// Product categories.
		if ( isset( $data['category_ids'] ) ) {
			$product->set_category_ids( $data['category_ids'] );
		}

		// Product tags.
		if ( isset( $data['tag_ids'] ) ) {
			$product->set_tag_ids( $data['tag_ids'] );
		}

		// Downloadable.
		if ( isset( $data['downloadable'] ) ) {
			$product->set_downloadable( $data['downloadable'] );
		}

		// Downloadable options.
		if ( $product->get_downloadable() ) {

			// Downloadable files.
			if ( isset( $data['downloads'] ) ) {
				$product = $this->save_downloadable_files( $product, $data['downloads'] );
			}

			// Download limit.
			if ( isset( $data['download_limit'] ) ) {
				$product->set_download_limit( $data['download_limit'] );
			}

			// Download expiry.
			if ( isset( $data['download_expiry'] ) ) {
				$product->set_download_expiry( $data['download_expiry'] );
			}
		}

		// Product url and button text for external products.
		if ( $product->is_type( 'external' ) ) {
			if ( isset( $data['external_url'] ) ) {
				$product->set_product_url( $data['external_url'] );
			}

			if ( isset( $data['button_text'] ) ) {
				$product->set_button_text( $data['button_text'] );
			}
		}

		// Featured image.
		if ( isset( $data['image_id'] ) ) {
			$image_id = $data['image_id'] ? $this->get_attachment_id( $data['image_id'], $product->get_id() ) : '';
			$product->set_image_id( $image_id );
		}

		// Gallery.
		if ( isset( $data['gallery_image_ids'] ) ) {
			$gallery_image_ids = array();

			foreach ( $data['gallery_image_ids'] as $url ) {
				if ( empty( $url ) ) {
					continue;
				}

				$gallery_image_ids[] = $this->get_attachment_id( $url, $product->get_id() );
			}

			$product->set_gallery_image_ids( array_filter( $gallery_image_ids ) );
		}

		// Allow set meta_data.
		if ( isset( $data['meta_data'] ) ) {
			foreach ( $data['meta_data'] as $meta ) {
				$product->update_meta_data( $meta['key'], $meta['value'] );
			}
		}

		return $product;
	}

	/**
	 * Set variation data.
	 *
	 * @param WC_Product $variation Product instance.
	 * @param array      $data    Row data.
	 *
	 * @return WC_Product|WP_Error
	 */
	protected function save_variation_data( $variation, $data ) {
		$parent = false;

		// Check if parent exist.
		if ( isset( $data['parent_id'] ) ) {
			$parent = wc_get_product( $data['parent_id'] );

			if ( $parent ) {
				$variation->set_parent_id( $parent->get_id() );
			}
		}

		// Stop if parent does not exists.
		if ( ! $parent ) {
			return new WP_Error( 'woocommerce_product_importer_missing_variation_parent_id', __( 'Missing parent ID or parent does not exist.', 'woocommerce' ), array( 'status' => 401 ) );
		}

		// Status.
		if ( isset( $data['published'] ) ) {
			$variation->set_status( $data['published'] ? 'publish' : 'draft' );
		} elseif ( 'importing' === $variation->get_status() ) {
			$variation->set_status( 'publish' );
		}

		// SKU.
		if ( isset( $data['sku'] ) ) {
			$variation->set_sku( wc_clean( $data['sku'] ) );
		}

		// Featured image.
		if ( isset( $data['image_id'] ) ) {
			$image_id = $data['image_id'] ? $this->get_attachment_id( $data['image_id'], $variation->get_id() ) : '';
			$variation->set_image_id( $image_id );
		}

		// Virtual variation.
		if ( isset( $data['virtual'] ) ) {
			$variation->set_virtual( $data['virtual'] );
		}

		// Downloadable variation.
		if ( isset( $data['downloadable'] ) ) {
			$variation->set_downloadable( $data['downloadable'] );
		}

		// Downloads.
		if ( $variation->get_downloadable() ) {
			// Downloadable files.
			if ( isset( $data['downloads'] ) ) {
				$variation = $this->save_downloadable_files( $variation, $data['downloads'] );
			}

			// Download limit.
			if ( isset( $data['download_limit'] ) ) {
				$variation->set_download_limit( $data['download_limit'] );
			}

			// Download expiry.
			if ( isset( $data['download_expiry'] ) ) {
				$variation->set_download_expiry( $data['download_expiry'] );
			}
		}

		// Shipping data.
		$variation = $this->save_product_shipping_data( $variation, $data );

		// Stock handling.
		if ( isset( $data['stock_status'] ) ) {
			$variation->set_stock_status( $data['stock_status'] ? 'instock' : 'outofstock' );
		}

		if ( 'yes' === get_option( 'woocommerce_manage_stock' ) ) {
			if ( isset( $data['manage_stock'] ) ) {
				$variation->set_manage_stock( $data['manage_stock'] );
			}

			if ( isset( $data['backorders'] ) ) {
				$variation->set_backorders( $data['backorders'] );
			}

			if ( $variation->get_manage_stock() ) {
				if ( isset( $data['stock_quantity'] ) ) {
					$variation->set_stock_quantity( $data['stock_quantity'] );
				}
			} else {
				$variation->set_backorders( 'no' );
				$variation->set_stock_quantity( '' );
			}
		}

		// Regular Price.
		if ( isset( $data['regular_price'] ) ) {
			$variation->set_regular_price( $data['regular_price'] );
		}

		// Sale Price.
		if ( isset( $data['sale_price'] ) ) {
			$variation->set_sale_price( $data['sale_price'] );
		}

		if ( isset( $data['date_on_sale_from'] ) ) {
			$variation->set_date_on_sale_from( $data['date_on_sale_from'] );
		}

		if ( isset( $data['date_on_sale_from_gmt'] ) ) {
			$variation->set_date_on_sale_from( $data['date_on_sale_from_gmt'] ? strtotime( $data['date_on_sale_from_gmt'] ) : null );
		}

		if ( isset( $data['date_on_sale_to'] ) ) {
			$variation->set_date_on_sale_to( $data['date_on_sale_to'] );
		}

		if ( isset( $data['date_on_sale_to_gmt'] ) ) {
			$variation->set_date_on_sale_to( $data['date_on_sale_to_gmt'] ? strtotime( $data['date_on_sale_to_gmt'] ) : null );
		}

		// Tax class.
		if ( isset( $data['tax_class'] ) ) {
			$variation->set_tax_class( $data['tax_class'] );
		}

		// Description.
		if ( isset( $data['description'] ) ) {
			$variation->set_description( wp_kses_post( $data['description'] ) );
		}

		// Update taxonomies.
		if ( isset( $data['attributes'] ) ) {
			$attributes        = array();
			$parent_attributes = $this->get_variation_parent_attributes( $data['attributes'], $parent );

			foreach ( $data['attributes'] as $attribute ) {
				// Get ID if is a global attribute.
				$attribute_id = wc_attribute_taxonomy_id_by_name( $attribute['name'] );

				if ( $attribute_id ) {
					$attribute_name = wc_attribute_taxonomy_name_by_id( $attribute_id );
				} else {
					$attribute_name = sanitize_title( $attribute['name'] );
				}

				if ( ! isset( $parent_attributes[ $attribute_name ] ) || ! $parent_attributes[ $attribute_name ]->get_variation() ) {
					continue;
				}

				$attribute_key   = sanitize_title( $parent_attributes[ $attribute_name ]->get_name() );
				$attribute_value = isset( $attribute['value'] ) ? current( $attribute['value'] ) : '';

				if ( $parent_attributes[ $attribute_name ]->is_taxonomy() ) {
					// If dealing with a taxonomy, we need to get the slug from the name posted to the API.
					$term = get_term_by( 'name', $attribute_value, $attribute_name );

					if ( $term && ! is_wp_error( $term ) ) {
						$attribute_value = $term->slug;
					} else {
						$attribute_value = sanitize_title( $attribute_value );
					}
				}

				$attributes[ $attribute_key ] = $attribute_value;
			}

			$variation->set_attributes( $attributes );
		}

		// Meta data.
		if ( isset( $data['meta_data'] ) ) {
			foreach ( $data['meta_data'] as $meta ) {
				$variation->update_meta_data( $meta['key'], $meta['value'] );
			}
		}

		return $variation;
	}

	/**
	 * Get variation parent attributes and set "is_variation".
	 *
	 * @param  array      $attributes Attributes list.
	 * @param  WC_Product $parent     Parent product data.
	 * @return array
	 */
	protected function get_variation_parent_attributes( $attributes, $parent ) {
		$parent_attributes = $parent->get_attributes();
		$require_save      = false;

		foreach ( $attributes as $attribute ) {
			// Get ID if is a global attribute.
			$attribute_id = wc_attribute_taxonomy_id_by_name( $attribute['name'] );

			if ( $attribute_id ) {
				$attribute_name = wc_attribute_taxonomy_name_by_id( $attribute_id );
			} else {
				$attribute_name = sanitize_title( $attribute['name'] );
			}

			// Check if attribute handle variations.
			if ( isset( $parent_attributes[ $attribute_name ] ) && ! $parent_attributes[ $attribute_name ]->get_variation() ) {
				// Re-create the attribute to CRUD save and genarate again.
				$parent_attributes[ $attribute_name ] = clone $parent_attributes[ $attribute_name ];
				$parent_attributes[ $attribute_name ]->set_variation( 1 );

				$require_save = true;
			}
		}

		// Save variation attributes.
		if ( $require_save ) {
			$parent->set_attributes( array_values( $parent_attributes ) );
			$parent->save();
		}

		return $parent_attributes;
	}

	/**
	 * Get attachment ID.
	 *
	 * @param  string $url        Attachment URL.
	 * @param  int    $product_id Product ID.
	 * @return int
	 */
	protected function get_attachment_id( $url, $product_id ) {
		if ( empty( $url ) ) {
			return 0;
		}

		$id         = 0;
		$upload_dir = wp_upload_dir();
		$base_url   = $upload_dir['baseurl'] . '/';

		// Check first if attachment is on WordPress uploads directory.
		if ( false !== strpos( $url, $base_url ) ) {
			// Search for yyyy/mm/slug.extension
			$file = str_replace( $base_url, '', $url );
			$args = array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_query'  => array(
					array(
						'value'   => $file,
						'compare' => 'LIKE',
						'key'     => '_wp_attachment_metadata',
					),
				),
			);

			if ( $ids = get_posts( $args ) ) {
				$id = current( $ids );
			}
		} else {
			$args = array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_query'  => array(
					array(
						'value' => $url,
						'key'   => '_wc_attachment_source',
					),
				),
			);

			if ( $ids = get_posts( $args ) ) {
				$id = current( $ids );
			}
		}

		// Upload if attachment does not exists.
		if ( ! $id ) {
			$upload = wc_rest_upload_image_from_url( $url );

			if ( is_wp_error( $upload ) ) {
				throw new Exception( $upload->get_error_message(), 400 );
			}

			$id = wc_rest_set_uploaded_image_as_attachment( $upload, $product_id );

			if ( ! wp_attachment_is_image( $id ) ) {
				throw new Exception( sprintf( __( 'Not able to attach "%s".', 'woocommerce' ), $url ), 400 );
			}

			// Save attachment source for future reference.
			update_post_meta( $id, '_wc_attachment_source', $url );
		}

		return $id;
	}

	/**
	 * Save product shipping data.
	 *
	 * @param WC_Product $product Product instance.
	 * @param array      $data    Shipping data.
	 * @return WC_Product
	 */
	protected function save_product_shipping_data( $product, $data ) {
		// Virtual.
		if ( $product->is_virtual() ) {
			$product->set_weight( '' );
			$product->set_height( '' );
			$product->set_length( '' );
			$product->set_width( '' );
		} else {
			if ( isset( $data['weight'] ) ) {
				$product->set_weight( $data['weight'] );
			}

			// Height.
			if ( isset( $data['height'] ) ) {
				$product->set_height( $data['height'] );
			}

			// Width.
			if ( isset( $data['width'] ) ) {
				$product->set_width( $data['width'] );
			}

			// Length.
			if ( isset( $data['length'] ) ) {
				$product->set_length( $data['length'] );
			}
		}

		// Shipping class.
		if ( isset( $data['shipping_class_id'] ) ) {
			$product->set_shipping_class_id( $data['shipping_class_id'] );
		}

		return $product;
	}

	/**
	 * Save downloadable files.
	 *
	 * @param WC_Product $product   Product instance.
	 * @param array      $downloads Downloads data.
	 * @return WC_Product
	 */
	protected function save_downloadable_files( $product, $data ) {
		$downloads = array();
		foreach ( $data as $key => $file ) {
			if ( empty( $file['url'] ) ) {
				continue;
			}

			$downloads[] = array(
				'name' => $file['name'] ? $file['name'] : wc_get_filename_from_url( $file['url'] ),
				'file' => apply_filters( 'woocommerce_file_download_path', $file['url'], $product, $key ),
			);
		}
		$product->set_downloads( $downloads );

		return $product;
	}
}