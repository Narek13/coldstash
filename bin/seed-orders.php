<?php
/**
 * Create test WooCommerce orders spread across past years.
 *
 * Run:  wp eval-file bin/seed-orders.php [count] [years]
 *       ddev seed-orders 1000 6
 *
 * Gives the archive something to chew on: a realistic spread of dates and
 * statuses, plus a few refunds and in-progress orders so the exclusion rules
 * actually get exercised rather than every order qualifying.
 *
 * Not a benchmark seeder. Orders are created through WC_Order so they are
 * correct, which caps throughput at roughly 50-100/sec. The 500k-order
 * benchmark in the spec (M5) needs a bulk-SQL seeder instead.
 *
 * No strict_types declaration: wp eval-file runs this through eval(), where it is not allowed.
 *
 * @package Coldstash
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce is not active.' );
}

/** @var array<int, string> $args */
$count = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 300;
$years = isset( $args[1] ) ? max( 1, (int) $args[1] ) : 4;

// No transactional email while seeding — unregistering the email classes is
// the only reliable way; per-email filters still leave some paths live.
add_filter( 'woocommerce_email_classes', '__return_empty_array' );

// Seeding backdated orders should not touch stock or coupon usage counts.
add_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );

$sku        = 'coldstash-test-product';
$product_id = wc_get_product_id_by_sku( $sku );

if ( ! $product_id ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Coldstash Test Product' );
	$product->set_sku( $sku );
	$product->set_regular_price( '24.00' );
	$product->set_manage_stock( false );
	$product->set_catalog_visibility( 'hidden' );
	$product_id = $product->save();
	WP_CLI::log( "Created test product #{$product_id}." );
}

$product = wc_get_product( $product_id );

// Weighted so most orders are archivable but a meaningful slice is not —
// otherwise the preview screen's exclusion counts are all zero and you learn
// nothing from a test run.
$statuses = array_merge(
	array_fill( 0, 60, 'completed' ),
	array_fill( 0, 10, 'cancelled' ),
	array_fill( 0, 5, 'refunded' ),
	array_fill( 0, 5, 'failed' ),
	array_fill( 0, 15, 'processing' ),
	array_fill( 0, 5, 'on-hold' )
);

$first_names = array( 'Ana', 'Bruno', 'Chiara', 'Dmitri', 'Elif', 'Farid', 'Greta', 'Hugo', 'Ines', 'Jonas' );
$last_names  = array( 'Moreau', 'Silva', 'Kovacs', 'Petrov', 'Yilmaz', 'Haddad', 'Berger', 'Novak', 'Costa', 'Lindqvist' );
$countries   = array( 'DE', 'FR', 'NL', 'ES', 'IT', 'PL', 'SE', 'AM' );

$oldest  = strtotime( "-{$years} years" );
$newest  = time();
$span    = max( 1, $newest - $oldest );
$created = 0;
$start   = microtime( true );

$progress = \WP_CLI\Utils\make_progress_bar( "Creating {$count} orders", $count );

for ( $i = 0; $i < $count; $i++ ) {
	try {
		$when  = $oldest + wp_rand( 0, $span );
		$date  = new WC_DateTime( '@' . $when );
		$first = $first_names[ array_rand( $first_names ) ];
		$last  = $last_names[ array_rand( $last_names ) ];

		$order = new WC_Order();
		$order->add_product( $product, wp_rand( 1, 4 ) );
		$order->set_address(
			array(
				'first_name' => $first,
				'last_name'  => $last,
				'email'      => strtolower( "{$first}.{$last}" ) . "+{$i}@example.test",
				'address_1'  => wp_rand( 1, 200 ) . ' Test Street',
				'city'       => 'Testville',
				'postcode'   => (string) wp_rand( 10000, 99999 ),
				'country'    => $countries[ array_rand( $countries ) ],
			),
			'billing'
		);
		$order->set_payment_method( 'bacs' );
		$order->set_payment_method_title( 'Direct bank transfer' );
		$order->set_created_via( 'coldstash-seeder' );
		$order->set_date_created( $date );
		$order->calculate_totals();

		$status = $statuses[ array_rand( $statuses ) ];
		$order->set_status( $status );

		if ( in_array( $status, array( 'completed', 'refunded' ), true ) ) {
			$order->set_date_paid( $date );
			$order->set_date_completed( $date );
		}

		$order->add_order_note( 'Seeded by bin/seed-orders.php.' );
		$order_id = $order->save();

		// A handful of partial refunds, so the parent/refund pairing rule is
		// exercised rather than assumed.
		if ( 'refunded' === $status && 0 === $i % 3 ) {
			wc_create_refund(
				array(
					'order_id' => $order_id,
					'amount'   => '5.00',
					'reason'   => 'Seeded partial refund.',
				)
			);
		}

		++$created;
	} catch ( Throwable $e ) {
		WP_CLI::warning( "Order {$i} failed: " . $e->getMessage() );
	}

	$progress->tick();
}

$progress->finish();

$elapsed = max( 0.01, microtime( true ) - $start );

WP_CLI::success(
	sprintf(
		'Created %d order(s) across %d years in %.1fs (%.0f/sec).',
		$created,
		$years,
		$elapsed,
		$created / $elapsed
	)
);
