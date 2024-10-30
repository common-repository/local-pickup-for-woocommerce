<?php
/**
 * Product and category meta related data to use in plguin
 *
 * @link       https://www.thedotstore.com/
 * @since      1.0.0
 *
 * @package    DSLPFW_Local_Pickup_Woocommerce
 * @subpackage DSLPFW_Local_Pickup_Woocommerce/admin
 */

defined( 'ABSPATH' ) or exit;

use DotStore\DSLPFW_Local_Pickup_Woocommerce\Appointments\DSLPFW_Local_Pickup_WooCommerce_Appointment;

/**
 * WooCommerce Products and Product Categories handler for local pickup.
 *
 * @since 1.0.0
 */
class DSLPFW_Local_Pickup_WooCommerce_Order_Items {

    /** @var string meta holding the pickup location ID */
    private $dslpfw_pickup_location_id_meta = '';

    /** @var string meta holding the fallback pickup location name */
    private $dslpfw_pickup_location_name_meta = '';

    /** @var string meta holing the fallback pickup location address */
    private $dslpfw_pickup_location_address_meta = '';

    /** @var string meta holding the fallback pickup location phone */
    private $dslpfw_pickup_location_phone_meta = '';

    /** @var string meta holding the fallback pickup location appointment start */
    private $dslpfw_pickup_appointment_start_meta = '';

    /** @var string meta holding the fallback pickup location appointment end */
    private $dslpfw_pickup_appointment_end_meta = '';

    /** @var string meta holding an array of IDs of corresponding items to pickup */
    private $dslpfw_pickup_items_meta = '';

    /** @var string meta holding temporarily a package ID matching an order line item to be linked to a shipping item */
    private $dslpfw_pickup_package_key_meta = '';
    private $dslpfw_pickup_date_meta = '';
    private $dslpfw_pickup_minimum_hours_meta = '';

    /**
	 * Orders pickup location data handler constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

        // set meta key names
		foreach ( $this->dslpfw_get_order_items_meta_keys() as $prop => $meta_key_name ) {
			$this->$prop = $meta_key_name;
		}

        // Add pickup data to the order shipping items upon new order submission:
		// - step one: record the package key on each order line item that needs to be picked up
		add_action( 'woocommerce_checkout_create_order_line_item', array($this, 'dslpfw_link_order_line_item_to_package' ), 10, 2 );
		// - step two: record the pickup location ID, the pickup date and the originating package key on the shipping order item marked for local pickup
		add_action( 'woocommerce_checkout_create_order_shipping_item', array($this, 'dslpfw_set_order_shipping_item_pickup_data' ), 11, 2 );
		// - step three: after WC objects IDs have been set, scan order line items that need to be picked up and cross link them with shipping items
		add_action( 'woocommerce_checkout_update_order_meta', array($this, 'dslpfw_link_order_line_items_to_order_shipping_items' ) );

        // mark pickup shipping order item meta as hidden so it is no longer directly editable/viewable
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'dslpfw_order_items_hidden_pickup_meta_keys' ) );
    }

    /**
	 * Get the meta keys used in order items to store pickup data.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function dslpfw_get_order_items_meta_keys() {

		return [
			'dslpfw_pickup_location_id_meta'       => '_dslpfw_pickup_location_id',
			'dslpfw_pickup_location_name_meta'     => '_dslpfw_pickup_location_name',
			'dslpfw_pickup_location_address_meta'  => '_dslpfw_pickup_location_address',
			'dslpfw_pickup_location_phone_meta'    => '_dslpfw_pickup_location_phone',
			'dslpfw_pickup_appointment_start_meta' => '_dslpfw_pickup_appointment_start',
			'dslpfw_pickup_appointment_end_meta'   => '_dslpfw_pickup_appointment_end',
			'dslpfw_pickup_items_meta'             => '_dslpfw_pickup_items',
			'dslpfw_pickup_package_key_meta'       => '_dslpfw_pickup_package_key',
			/** legacy keys kept here for filtering {@see \DSLPFW_Local_Pickup_WooCommerce_Order_Items::dslpfw_order_items_hidden_pickup_meta_keys()} */
			'dslpfw_pickup_date_meta'              => '_dslpfw_pickup_date',
			'dslpfw_pickup_minimum_hours_meta'     => '_dslpfw_pickup_minimum_hours',
		];
	}

    /**
	 * Hide some order shipping item keys marked as private.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $item_meta_keys array of item meta keys to be hidden
	 * @return string[]
	 */
	public function dslpfw_order_items_hidden_pickup_meta_keys( $item_meta_keys ) {
        
        return array_merge( $item_meta_keys, array_values( $this->dslpfw_get_order_items_meta_keys() ) );
	}

    /**
	 * Get the chosen pickup location ID for an order shipping item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|\WC_Order_Item_Shipping $order_item an order item ID
	 * @return int|null
	 */
	public function get_order_item_pickup_location_id( $order_item ) {

		try {
			$order_item_id      = $order_item instanceof \WC_Order_Item_Shipping ? $order_item->get_id() : $order_item;
			$pickup_location_id = is_numeric( $order_item_id ) ? wc_get_order_item_meta( (int) $order_item_id, $this->dslpfw_pickup_location_id_meta ) : null;
		} catch ( \Exception $e ) {
			$pickup_location_id = null;
		}

		return is_numeric( $pickup_location_id ) ? (int) $pickup_location_id : null;
	}

    /**
	 * Get the chosen pickup location for an order shipping item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|\WC_Order_Item_Shipping $order_item the shipping item
	 * @return null|\DSLPFW_Local_Pickup_WooCommerce_Pickup_Location
	 */
	public function get_order_item_pickup_location( $order_item ) {

		$pickup_location_id = $this->get_order_item_pickup_location_id( $order_item );
		$pickup_location    = is_numeric( $pickup_location_id ) ? dslpfw_get_pickup_location( (int) $pickup_location_id ) : null;

		return $pickup_location instanceof \DSLPFW_Local_Pickup_WooCommerce_Pickup_Location ? $pickup_location : null;
	}

    /**
	 * Get the chosen pickup location name for an order shipping item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|\WC_Order_Item_Shipping $order_item the shipping item
	 * @return string
	 */
	public function get_order_item_pickup_location_name( $order_item ) {

		try {
			$name = wc_get_order_item_meta( $order_item instanceof \WC_Order_Item_Shipping ? $order_item->get_id() : (int) $order_item, $this->dslpfw_pickup_location_name_meta );
		} catch ( \Exception $e ) {
			$name = '';
		}

		if ( empty ( $name ) ) {

			$pickup_location = $this->get_order_item_pickup_location( $order_item );

			if ( $pickup_location ) {
				$name = $pickup_location->get_name();
			}
		}

		return is_string( $name ) ? $name : '';
	}

    /**
	 * Gets the chosen pickup location name for an order shipping item.
	 *
	 * Pass $output equal to 'string' to get the address formatted in HTML with breaks, or use 'one_line_string' to remove the breaks.
	 *
	 * @since 1.0.0
	 *
	 * @param int|\WC_Order_Item_Shipping $order_item the shipping item
	 * @param string $output one of 'array', 'object', 'html', 'plain'
	 * @return string|array|\DSLPFW_Local_Pickup_Location_Address
	 */
	public function get_order_item_pickup_location_address( $order_item, $output = 'string' ) {

		// ensures returning the requested type
		switch ( $output ) {
			case 'array' :
				$default_value = [];
			break;
			case 'object' :
				$default_value = new \DSLPFW_Local_Pickup_Location_Address( [] );
			break;
			default : // defaults to string for legacy default behavior
				$default_value = '';
			break;
		}

		$address = $default_value;

		try {

			$address = wc_get_order_item_meta( $order_item instanceof \WC_Order_Item_Shipping ? $order_item->get_id() : (int) $order_item, $this->dslpfw_pickup_location_address_meta );
            $pickup_location = $this->get_order_item_pickup_location( $order_item );

			if ( ! empty( $address ) && is_array( $address ) ) {
				$address = new \DSLPFW_Local_Pickup_Location_Address( $address );
			} elseif ( $pickup_location ) {
				$address = $pickup_location->get_address();
			}

		} catch ( \Exception $e ) { }

		if ( $address instanceof \DSLPFW_Local_Pickup_Location_Address ) {

			if ( 'array' === $output ) {
				$address = $address->get_array();
			// accept true to get the address formatted in HTML without line breaks for backwards compatibility
			} elseif ( 'plain' === $output || true === $output ) {
				// get address formatted in HTML as a single line (no breaks)
				$address = $address->get_formatted_html( true );
			} elseif ( 'object' !== $output ) {
				$address = $address->get_formatted_html();
			}
		}

		return $address ?: $default_value;
	}

    /**
	 * Get the chosen pickup location phone for an order shipping item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|\WC_Order_Item_Shipping $order_item the shipping item
	 * @param bool $html whether to output an HTML formatted string (default) or a plaintext one
	 * @return string
	 */
	public function get_order_item_pickup_location_phone( $order_item, $html = true ) {

		try {
			$phone = wc_get_order_item_meta( $order_item instanceof \WC_Order_Item_Shipping ? $order_item->get_id() : (int) $order_item, $this->dslpfw_pickup_location_phone_meta );
		} catch ( \Exception $e ) {
			$phone = '';
		}

		if ( ! empty( $phone ) ) {

			if ( $html ) {
				$phone = '<a href="tel:' . esc_attr( $phone ) . '">' . $phone . '</a>';
			}

		} else {

			$pickup_location = $this->get_order_item_pickup_location( $order_item );

			if ( $pickup_location ) {

				$phone = $pickup_location->get_phone( $html );
			}
		}

		return is_string( $phone ) ? $phone : '';
	}

    /**
	 * Associate an order line item to a package.
	 *
	 * This method sets an order item meta for a line item which holds the package key where the item came from.
	 * In this way we can associate shipping items to order line items and tell which line items are meant for pickup.
	 * @see \DSLPFW_Local_Pickup_WooCommerce_Order_Items::dslpfw_link_order_line_items_to_order_shipping_items()
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order_Item $order_item the new order line item
	 * @param string $cart_item_key the cart item key where the order line item was generated from
	 */
	public function dslpfw_link_order_line_item_to_package( $order_item, $cart_item_key ) {

        $filter = array( 
            'dslpfw_pickup_items' => array(	
                'filter'  => array( FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
                'flags'   => FILTER_REQUIRE_ARRAY
            )
        );
        $cart_data = filter_input_array( INPUT_POST, $filter );

		if ( ! empty( $cart_data['dslpfw_pickup_items'] ) && is_array( $cart_data['dslpfw_pickup_items'] ) ) {

			$cart_item_keys = array();

			foreach ( $cart_data['dslpfw_pickup_items'] as $package_key => $item_keys ) {

				// we always ensure this is an array
				$item_keys = explode( ',', $item_keys );

				foreach ( $item_keys as $item_key ) {
					// prefixing the package key with a string is a conservative workaround to prevent index oddities with index key 0 and data type handling in PHP (so we are sure these are strings now)
					$cart_item_keys[ trim( $item_key ) ] = "package_{$package_key}";
				}
			}

			if ( isset( $cart_item_keys[ $cart_item_key ] ) ) {
				// this sets the meta value as "package_{$package_key}"
				$order_item->update_meta_data( $this->dslpfw_pickup_package_key_meta, $cart_item_keys[ $cart_item_key ] );
			}
		}
	}

    /**
	 * Set pickup data for the order shipping items upon new order creation.
	 *
	 * We set the pickup location ID and the pickup date (if available).
	 * For the pickup items we need two more callbacks handling:
	 * @see \DSLPFW_Local_Pickup_WooCommerce_Order_Items::dslpfw_link_order_line_item_to_package()
	 * @see \DSLPFW_Local_Pickup_WooCommerce_Order_Items::dslpfw_link_order_line_items_to_order_shipping_items()
	 *
	 * @internal
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order_Item_Shipping $shipping_item the shipping item ID
	 * @param string|int $package_key the package key from the package array
	 */
	public function dslpfw_set_order_shipping_item_pickup_data( $shipping_item, $package_key ) {

        $filter = array( 
            '_shipping_method_pickup_location_id' => array(	
                'filter'  => array( FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
                'flags'   => FILTER_REQUIRE_ARRAY
            ),
            '_shipping_method_pickup_date' => array(	
                'filter'  => array( FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
                'flags'   => FILTER_REQUIRE_ARRAY
            ),
            '_shipping_method_pickup_appointment_offset' => array(	
                'filter'  => array( FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
                'flags'   => FILTER_REQUIRE_ARRAY
            ),
        );
        $cart_data = filter_input_array( INPUT_POST, $filter );

		if ( isset( $cart_data['_shipping_method_pickup_location_id'][ $package_key ] ) ) {

			$pickup_location = dslpfw_get_pickup_location( $cart_data['_shipping_method_pickup_location_id'][ $package_key ] );

			if ( $pickup_location && $pickup_location->get_id() > 0 && $this->set_order_item_pickup_location( $shipping_item, $pickup_location ) ) {

				// prefixing the package key with a string is a conservative workaround to prevent index oddities with index key 0 and data type handling in PHP (so we are sure these are strings now)
				$shipping_item->update_meta_data( $this->dslpfw_pickup_package_key_meta, "package_{$package_key}" );

				$pickup_date = isset( $cart_data['_shipping_method_pickup_date'][ $package_key ] ) ? trim( $cart_data['_shipping_method_pickup_date'][ $package_key ] ) : '';

				if ( $pickup_date && 'disabled' !== dslpfw_appointments_mode() ) {

					$appointment_offset  = isset( $cart_data['_shipping_method_pickup_appointment_offset'][ $package_key ] ) ? (int) $cart_data['_shipping_method_pickup_appointment_offset'][ $package_key ] : 0;
					$is_anytime_duration = dslpfw_shipping_method()->is_anytime_appointments_enabled();

					try {

						// anytime appointment with non-zero lead time on the selected date
						if ( $is_anytime_duration && $appointment_offset > 0 ) {

							$start_date = new \DateTime( gmdate( 'Y-m-d H:i:s', strtotime( $pickup_date ) + $appointment_offset ), $pickup_location->get_address()->get_timezone() );
							$end_date   = null;

						// anytime appointment
						} elseif ( $is_anytime_duration ) {

							$start_date = new \DateTime( $pickup_date, $pickup_location->get_address()->get_timezone() );
							$end_date   = ( clone $start_date )->add( new \DateInterval( 'P1D' ) );

						// appointment with a pickup time
						} else {

							$start_date = new \DateTime( gmdate( 'Y-m-d H:i:s', strtotime( $pickup_date ) + $appointment_offset ), $pickup_location->get_address()->get_timezone() );
							$end_date   = ( clone $start_date )->add( new \DateInterval( sprintf( 'PT%dS', $pickup_location->get_appointments()->get_appointment_duration( $start_date ) ) ) );
						}

					} catch ( \Exception $e ) {

						$start_date = null;
						$end_date   = null;
					}

					if ( $start_date ) {

						try {

							$appointment = new DSLPFW_Local_Pickup_WooCommerce_Appointment( $shipping_item );

							$appointment->set_start( $start_date );
							$appointment->set_end( $end_date );
							$appointment->set_pickup_location_id( $pickup_location->get_id() );
							$appointment->set_pickup_location_name( $pickup_location->get_name() );
							$appointment->set_pickup_location_phone( $pickup_location->get_phone() );
							$appointment->set_pickup_location_address( $pickup_location->get_address() );

							$appointment->save();

						} catch ( \Exception $e ) {

							dslpfw()->log( sprintf( 'Could not create appointment for shipping item #%1$ in order #%2$s: %3$s', $shipping_item->get_id(), $shipping_item->get_order_id(), $e->getMessage() ) );
						}
					}
				}
			}
		}
	}

    /**
	 * Sets pickup location data for a pickup order item.
	 *
	 * Important note: you should provide an order item ID if the order item already exists otherwise the order item object for newly created items.
	 *
	 * @since 1.0.0
	 *
	 * @param int|\WC_Order_Item_Shipping $order_item the order item to save the meta for
	 * @param \DSLPFW_Local_Pickup_WooCommerce_Pickup_Location $pickup_location the pickup location or null to wipe the information
	 * @return bool whether the order item meta was successfully added or updated
	 */
	public function set_order_item_pickup_location( $order_item, $pickup_location = null ) {

		if ( ! $pickup_location instanceof \DSLPFW_Local_Pickup_WooCommerce_Pickup_Location ) {
			$pickup_location = null;
		}

		if ( $order_item instanceof \WC_Order_Item_Shipping ) {

			$order_item->add_meta_data( $this->dslpfw_pickup_location_id_meta, $pickup_location ? $pickup_location->get_id() : '', true );

			// add_meta_data() doesn't return a boolean value to indicate whether the value was successfully added or not
			$updated = true;

		} else {

			try {
				$updated = is_numeric( $order_item ) && wc_update_order_item_meta( (int) $order_item, $this->dslpfw_pickup_location_id_meta, $pickup_location ? $pickup_location->get_id() : '' );
			} catch ( \Exception $e ) {
				$updated = false;
			}
		}

		// we check $is_pickup_location instead of $success variable here and always try to update
		// or reset the name, address, or phone number because wc_update_order_item_meta() can return
		// false if the given value is equal to the value already stored in the _pickup_location_id
		$this->set_order_item_pickup_location_name( $order_item, $pickup_location ? $pickup_location->get_name() : '' );
		$this->set_order_item_pickup_location_address( $order_item, $pickup_location ? $pickup_location->get_address()->get_array() : array() );
		$this->set_order_item_pickup_location_phone( $order_item, $pickup_location && $pickup_location->has_phone() ? $pickup_location->get_phone() : '' );

		return $updated;
	}

    /**
	 * Set the pickup location name as a fallback in case the pickup location object is deleted in future.
	 *
	 * Important note: you should provide an order item ID if the order item already exists otherwise the order item object for newly created items.
	 *
	 * @since 1.0.0
	 *
	 * @param int|\WC_Order_Item_Shipping $order_item the order item to save the meta for
	 * @param string $name the pickup location name
	 * @return bool
	 */
	private function set_order_item_pickup_location_name( $order_item, $name = '' ) {

		$success = is_string( $name );

		if ( $order_item instanceof \WC_Order_Item_Shipping ) {

			$order_item->add_meta_data( $this->dslpfw_pickup_location_name_meta, $success ? $name : '', true );

		} else {

			try {
				$success = $success && wc_update_order_item_meta( (int) $order_item, $this->dslpfw_pickup_location_name_meta, $success ? $name : '' );
			} catch ( \Exception $e ) {
				$success = false;
			}
		}

		return $success;
	}

    /**
	 * Set the pickup location address as a fallback in case the pickup location object is deleted in future.
	 *
	 * Important note: you should provide an order item ID if the order item already exists otherwise the order item object for newly created items.
	 *
	 * @since 1.0.0
	 *
	 * @param int|\WC_Order_Item_Shipping $order_item the order item to save the meta for
	 * @param array $address a Local Pickup WooCommerce formatted address array
	 * @return bool
	 */
	private function set_order_item_pickup_location_address( $order_item, array $address ) {

		$success = ! empty( $address );

		if ( $order_item instanceof \WC_Order_Item_Shipping ) {

			$order_item->add_meta_data( $this->dslpfw_pickup_location_address_meta, is_array( $address ) ? $address : array(), true );

		} else {

			try {
				$success = $success && wc_update_order_item_meta( (int) $order_item, $this->dslpfw_pickup_location_address_meta, $address );
			} catch ( \Exception $e ) {
				$success = false;
			}
		}

		return $success;
	}

    /**
	 * Set the pickup location phone as a fallback in case the pickup location object is deleted in future.
	 *
	 * Important note: you should provide an order item ID if the order item already exists otherwise the order item object for newly created items.
	 *
	 * @since 1.0.0
	 *
	 * @param int|\WC_Order_Item_Shipping $order_item the order item to save the meta for
	 * @param string $phone the pickup location phone as a string
	 * @return bool
	 */
	private function set_order_item_pickup_location_phone( $order_item, $phone = '' ) {

		$success = is_string( $phone );

		if ( $order_item instanceof \WC_Order_Item_Shipping ) {

			$order_item->add_meta_data( $this->dslpfw_pickup_location_phone_meta, $success ? $phone : '', true );

		} else {

			try {
				$success = $success && wc_update_order_item_meta( (int) $order_item, $this->dslpfw_pickup_location_phone_meta, $success ? $phone : '' );
			} catch ( \Exception $e ) {
				$success = false;
			}
		}

		return $success;
	}

    /**
	 * Link order line items to order shipping items.
	 *
	 * This runs once an order is created in the post type data store in WooCommerce 3.0 and we have order item IDs set.
	 * Since other hooks do not have yet an order item ID set, it's only later that we can connect order line items to shipping items, by cross referencing the original package key passed on line items.
	 * @see \DSLPFW_Local_Pickup_WooCommerce_Order_Items::dslpfw_link_order_line_item_to_package()
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id \WC_Order ID
	 */
	public function dslpfw_link_order_line_items_to_order_shipping_items( $order_id ) {

		$order = wc_get_order( $order_id );

		// run only if a new order with Local Pickup WooCommerce among its shipping methods
		if ( $order instanceof \WC_Order && $order->has_shipping_method( dslpfw_shipping_method_id() ) ) {

			$order_line_items_in_package       = array();
			$order_line_items_in_shipping_item = array();

			$order_line_items     = $order->get_items( 'line_item' );
			$order_shipping_items = $order->get_items( 'shipping' );

			// loop order line items and gather the package the item came from
			foreach ( $order_line_items as $order_line_item ) {

				try {
					$order_line_item_package_key = wc_get_order_item_meta( $order_line_item->get_id(), $this->dslpfw_pickup_package_key_meta );
				} catch ( \Exception $e ) {
					$order_line_item_package_key = '';
				}
                
				if ( ! empty( $order_line_item_package_key ) ) {

					$order_line_items_in_package[ $order_line_item_package_key ][] = $order_line_item->get_id();

					// the transitory item meta can be safely deleted by this point
					try {
						wc_delete_order_item_meta( $order_line_item->get_id(), $this->dslpfw_pickup_package_key_meta );
					} catch ( \Exception $e ) {}
				}
			}
            
			// cleanup: we use again this variable below for semantic reasons
			unset( $order_line_item_package_key );
            
			// loop the order shipping item and compare the package key
			foreach ( $order_line_items_in_package as $order_line_item_package_key => $order_line_item_ids ) {

				foreach ( $order_shipping_items as $order_shipping_item ) {

					try {
						$order_shipping_item_package_key = wc_get_order_item_meta( $order_shipping_item->get_id(), $this->dslpfw_pickup_package_key_meta );
					} catch ( \Exception $e ) {
						$order_shipping_item_package_key = '';
					}

					// check if the order line item package key and the order shipping item package key match
					if ( (string) $order_line_item_package_key === (string) $order_shipping_item_package_key && ! in_array( $order_shipping_item_package_key, array( '', false, null ), true ) ) {

						$order_line_items_in_shipping_item[] = array( $order_shipping_item->get_id() => $order_line_item_ids );

						// the transitory order item meta can be safely deleted by this point
						try {
							wc_delete_order_item_meta( $order_shipping_item->get_id(), $this->dslpfw_pickup_package_key_meta );
						} catch ( \Exception $e ) {}
					}
				}
			}

			// finally, set a meta on the order shipping items to link the order line items meant for pickup
			if ( ! empty( $order_line_items_in_shipping_item ) ) {

				foreach ( $order_line_items_in_shipping_item as $order_shipping_items ) {

					foreach ( $order_shipping_items as $shipping_item_id => $order_line_items_ids ) {

						$this->set_order_item_pickup_items( $shipping_item_id, (array) $order_line_items_ids );
					}
				}
			}
		}
	}

    /**
	 * Get the items to pickup for an order shipping item.
	 *
	 * @since 1.0.0
	 *
	 * @param int|\WC_Order_Item_Shipping $order_item an order item ID
	 * @return int[] array of order line items IDs meant for pickup
	 */
	public function get_order_item_pickup_items( $order_item ) {

		try {
			$order_item_id = $order_item instanceof \WC_Order_Item ? $order_item->get_id() : $order_item;
			$pickup_items  = is_numeric( $order_item_id ) ? wc_get_order_item_meta( (int) $order_item_id, $this->dslpfw_pickup_items_meta ) : array();
		} catch ( \Exception $e ) {
			$pickup_items  = null;
		}

		return $pickup_items ? array_filter( array_map( 'absint', (array) $pickup_items ) ) : array();
	}

    /**
	 * Set items meant for pickup for a pickup order item.
	 *
	 * Important note: you should provide an order item ID if the order item already exists otherwise the order item object for newly created items.
	 *
	 * @since 1.0.0
	 *
	 * @param int|\WC_Order_Item_Shipping $order_item the order item to save the meta for
	 * @param array $pickup_items array of pickup items
	 * @return bool whether the order item meta was successfully set
	 */
	public function set_order_item_pickup_items( $order_item, array $pickup_items ) {

		$pickup_items = ! empty( $pickup_items ) ? array_filter( array_map( 'absint', $pickup_items ) ) : array();

		if ( $order_item instanceof \WC_Order_Item_Shipping ) {

			$order_item->add_meta_data( $this->dslpfw_pickup_items_meta, $pickup_items, true );

			$success = true;

		} else {

			try {
				$success = is_numeric( $order_item ) && wc_update_order_item_meta( (int) $order_item, $this->dslpfw_pickup_items_meta, $pickup_items );
			} catch ( \Exception $e ) {
				$success = false;
			}
		}

		return $success;
	}
}