<?php
/**
 * Archived orders: the archive browser.
 *
 * @package Coldstash
 *
 * @var \Coldstash\Admin\ArchivedOrdersTable $table
 * @var string $tab
 * @var array{restored:list<int>, failed:list<array{id:int, message:string}>}|null $notice
 * @var array{running:bool, progress:array{run_id:string, cursor:int, restored:int, failed:int, stopped:bool, finished_gmt:string}|null, stopUrl:string} $bulk
 * @var int    $archivedCount
 * @var string $restoreAllUrl
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables scoped to include context.

use Coldstash\Admin\AdminMenu;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap coldstash">
	<?php require __DIR__ . '/partials/header.php'; ?>

	<p class="coldstash__lede">
		<?php esc_html_e( 'Search by order number, customer name or email, product name, order key or transaction ID. Restoring an order moves it back to the live order tables with its original order number.', 'coldstash' ); ?>
	</p>

	<?php if ( $archivedCount > 0 && ! $bulk['running'] ) : ?>
		<p>
			<a href="<?php echo esc_url( $restoreAllUrl ); ?>" class="button" data-coldstash-confirm="<?php echo esc_attr( sprintf( /* translators: %s: number of archived orders. */ _n( 'Restore %s archived order to the live order tables?', 'Restore all %s archived orders to the live order tables? Any running archive is stopped first.', $archivedCount, 'coldstash' ), number_format_i18n( $archivedCount ) ) ); ?>">
				<?php esc_html_e( 'Restore all', 'coldstash' ); ?>
			</a>
			<span class="description"><?php esc_html_e( 'Moves every archived order back to the live order tables, in the background. Do this before regenerating WooCommerce Analytics or removing the plugin.', 'coldstash' ); ?></span>
		</p>
	<?php endif; ?>

	<?php $table->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( AdminMenu::PAGE ); ?>" />
		<input type="hidden" name="tab" value="orders" />
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, carried over.
		if ( isset( $_GET['status'] ) ) :
			?>
			<input type="hidden" name="status" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>" />
		<?php endif; ?>
		<?php $table->search_box( __( 'Search archived orders', 'coldstash' ), 'coldstash-search' ); ?>
		<?php $table->display(); ?>
	</form>
</div>
