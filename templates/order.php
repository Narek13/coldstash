<?php
/**
 * Read-only view of one archived order.
 *
 * @package Coldstash
 *
 * @var \Coldstash\Archive\ArchivedOrder|null $order
 * @var int    $orderId
 * @var string $error
 * @var string $tab
 * @var array{restored:list<int>, failed:list<array{id:int, message:string}>}|null $notice
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables scoped to include context.

use Coldstash\Admin\AdminMenu;
use Coldstash\Admin\ArchivedOrdersTable;

defined( 'ABSPATH' ) || exit;

$backUrl = AdminMenu::url( array( 'tab' => 'orders' ) );
?>
<div class="wrap coldstash">
	<?php require __DIR__ . '/partials/header.php'; ?>

	<p><a href="<?php echo esc_url( $backUrl ); ?>">&larr; <?php esc_html_e( 'All archived orders', 'coldstash' ); ?></a></p>

	<?php if ( null === $order ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $error ); ?></p></div>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<h2 class="coldstash__order-title">
		<?php
		/* translators: %d: order number. */
		echo esc_html( sprintf( __( 'Order #%d', 'coldstash' ), $order->id() ) );
		?>
	</h2>

	<div class="coldstash__banner">
		<p>
			<strong><?php esc_html_e( 'This order is archived and read-only.', 'coldstash' ); ?></strong>
			<?php esc_html_e( 'Restore it to edit it, refund it or resend emails. It comes back with the same order number.', 'coldstash' ); ?>
		</p>
		<p>
			<a href="<?php echo esc_url( AdminMenu::restoreUrl( $order->id() ) ); ?>" class="button button-primary"><?php esc_html_e( 'Restore order', 'coldstash' ); ?></a>
		</p>
	</div>

	<?php if ( ! $order->checksumValid ) : ?>
		<div class="notice notice-error inline">
			<p><?php esc_html_e( 'The stored copy of this order does not match its checksum, so it cannot be restored. The data shown may be incomplete.', 'coldstash' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="coldstash__columns">
		<section class="coldstash__panel" aria-labelledby="coldstash-general">
			<h3 id="coldstash-general"><?php esc_html_e( 'General', 'coldstash' ); ?></h3>
			<dl class="coldstash__details">
				<dt><?php esc_html_e( 'Status', 'coldstash' ); ?></dt>
				<dd><?php echo esc_html( ArchivedOrdersTable::statusLabel( $order->status() ) ); ?></dd>
				<dt><?php esc_html_e( 'Created', 'coldstash' ); ?></dt>
				<dd><?php echo esc_html( ArchivedOrdersTable::formatDate( $order->dateCreatedGmt() ) ); ?></dd>
				<dt><?php esc_html_e( 'Archived', 'coldstash' ); ?></dt>
				<dd><?php echo esc_html( ArchivedOrdersTable::formatDate( $order->archivedAtGmt() ) ); ?></dd>
				<?php if ( '' !== $order->paymentMethodTitle() ) : ?>
					<dt><?php esc_html_e( 'Payment method', 'coldstash' ); ?></dt>
					<dd><?php echo esc_html( $order->paymentMethodTitle() ); ?></dd>
				<?php endif; ?>
				<?php if ( '' !== $order->transactionId() ) : ?>
					<dt><?php esc_html_e( 'Transaction ID', 'coldstash' ); ?></dt>
					<dd><?php echo esc_html( $order->transactionId() ); ?></dd>
				<?php endif; ?>
			</dl>
		</section>

		<?php
		foreach (
			array(
				'billing'  => __( 'Billing', 'coldstash' ),
				'shipping' => __( 'Shipping', 'coldstash' ),
			) as $addressType => $heading
		) :
			$address = $order->address( $addressType );
			?>
			<section class="coldstash__panel" aria-labelledby="coldstash-<?php echo esc_attr( $addressType ); ?>">
				<h3 id="coldstash-<?php echo esc_attr( $addressType ); ?>"><?php echo esc_html( $heading ); ?></h3>
				<?php if ( array() === $address ) : ?>
					<p><?php esc_html_e( 'No address.', 'coldstash' ); ?></p>
				<?php else : ?>
					<address>
						<?php
						$name  = trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) );
						$lines = array_filter(
							array(
								$name,
								$address['company'] ?? '',
								$address['address_1'] ?? '',
								$address['address_2'] ?? '',
								trim( ( $address['postcode'] ?? '' ) . ' ' . ( $address['city'] ?? '' ) ),
								trim( ( $address['state'] ?? '' ) . ' ' . ( $address['country'] ?? '' ) ),
							)
						);
						echo implode( '<br />', array_map( 'esc_html', $lines ) );
						?>
					</address>
					<?php if ( isset( $address['email'] ) ) : ?>
						<p><a href="<?php echo esc_url( 'mailto:' . $address['email'] ); ?>"><?php echo esc_html( $address['email'] ); ?></a></p>
					<?php endif; ?>
					<?php if ( isset( $address['phone'] ) ) : ?>
						<p><?php echo esc_html( $address['phone'] ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</section>
		<?php endforeach; ?>
	</div>

	<?php if ( '' !== $order->customerNote() ) : ?>
		<h3><?php esc_html_e( 'Customer note', 'coldstash' ); ?></h3>
		<p><?php echo esc_html( $order->customerNote() ); ?></p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Items', 'coldstash' ); ?></h3>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Product', 'coldstash' ); ?></th>
				<th scope="col" class="coldstash__num"><?php esc_html_e( 'Quantity', 'coldstash' ); ?></th>
				<th scope="col" class="coldstash__num"><?php esc_html_e( 'Total', 'coldstash' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( array() === $order->items() ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'No line items.', 'coldstash' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $order->items() as $item ) : ?>
				<tr>
					<td><?php echo esc_html( $item['name'] ); ?></td>
					<td class="coldstash__num"><?php echo esc_html( number_format_i18n( $item['quantity'] ) ); ?></td>
					<td class="coldstash__num"><?php echo wp_kses_post( ArchivedOrdersTable::formatPrice( $item['total'], $order->currency() ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
		<tfoot>
			<tr>
				<th scope="row" colspan="2"><?php esc_html_e( 'Order total', 'coldstash' ); ?></th>
				<td class="coldstash__num"><strong><?php echo wp_kses_post( ArchivedOrdersTable::formatPrice( $order->total(), $order->currency() ) ); ?></strong></td>
			</tr>
		</tfoot>
	</table>

	<h3><?php esc_html_e( 'Order notes', 'coldstash' ); ?></h3>
	<?php if ( array() === $order->notes() ) : ?>
		<p><?php esc_html_e( 'No notes.', 'coldstash' ); ?></p>
	<?php else : ?>
		<ol class="coldstash__notes">
			<?php foreach ( $order->notes() as $note ) : ?>
				<li>
					<div><?php echo wp_kses_post( wpautop( $note['content'] ) ); ?></div>
					<p class="coldstash__note-meta">
						<?php echo esc_html( ArchivedOrdersTable::formatDate( $note['date_gmt'] ) ); ?>
						<?php if ( $note['customer'] ) : ?>
							&middot; <?php esc_html_e( 'Note to customer', 'coldstash' ); ?>
						<?php endif; ?>
					</p>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</div>
