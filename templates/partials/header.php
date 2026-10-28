<?php
/**
 * Screen title, tabs and the result of the last restore.
 *
 * @package Coldstash
 *
 * @var string $tab Current tab: '' for the overview, 'orders' for archived orders.
 * @var array{restored:list<int>, failed:list<array{id:int, message:string}>}|null $notice
 * @var array{running:bool, progress:array{run_id:string, cursor:int, restored:int, failed:int, stopped:bool, finished_gmt:string}|null, stopUrl:string} $bulk
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables scoped to include context.

use Coldstash\Admin\AdminMenu;

defined( 'ABSPATH' ) || exit;
?>
<h1><?php esc_html_e( 'Coldstash', 'coldstash' ); ?></h1>

<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Coldstash sections', 'coldstash' ); ?>">
	<a href="<?php echo esc_url( AdminMenu::url() ); ?>" class="nav-tab<?php echo '' === $tab ? ' nav-tab-active' : ''; ?>"<?php echo '' === $tab ? ' aria-current="page"' : ''; ?>>
		<?php esc_html_e( 'Overview', 'coldstash' ); ?>
	</a>
	<a href="<?php echo esc_url( AdminMenu::url( array( 'tab' => 'orders' ) ) ); ?>" class="nav-tab<?php echo 'orders' === $tab ? ' nav-tab-active' : ''; ?>"<?php echo 'orders' === $tab ? ' aria-current="page"' : ''; ?>>
		<?php esc_html_e( 'Archived orders', 'coldstash' ); ?>
	</a>
	<a href="<?php echo esc_url( AdminMenu::url( array( 'tab' => 'settings' ) ) ); ?>" class="nav-tab<?php echo 'settings' === $tab ? ' nav-tab-active' : ''; ?>"<?php echo 'settings' === $tab ? ' aria-current="page"' : ''; ?>>
		<?php esc_html_e( 'Settings', 'coldstash' ); ?>
	</a>
</nav>

<?php if ( $bulk['running'] ) : ?>
	<div class="notice notice-info inline coldstash__bulk" aria-live="polite">
		<p>
			<span class="coldstash__badge coldstash__badge--running"><?php esc_html_e( 'Restoring all', 'coldstash' ); ?></span>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of orders. */
					_n( '%s order restored so far. Archived orders are being moved back to the live order tables in the background; this page refreshes itself.', '%s orders restored so far. Archived orders are being moved back to the live order tables in the background; this page refreshes itself.', null !== $bulk['progress'] ? $bulk['progress']['restored'] : 0, 'coldstash' ),
					number_format_i18n( null !== $bulk['progress'] ? $bulk['progress']['restored'] : 0 )
				)
			);
			?>
			<a href="<?php echo esc_url( $bulk['stopUrl'] ); ?>" class="button"><?php esc_html_e( 'Stop', 'coldstash' ); ?></a>
		</p>
	</div>
<?php elseif ( null !== $bulk['progress'] && '' !== $bulk['progress']['finished_gmt'] && strtotime( $bulk['progress']['finished_gmt'] . ' UTC' ) > time() - DAY_IN_SECONDS ) : ?>
	<div class="notice <?php echo esc_attr( $bulk['progress']['failed'] > 0 ? 'notice-warning' : 'notice-success' ); ?> inline">
		<p>
			<?php
			echo esc_html(
				$bulk['progress']['stopped']
					? sprintf(
						/* translators: 1: orders restored, 2: orders that failed. */
						__( '"Restore all" was stopped: %1$s restored, %2$s failed. The rest are still archived.', 'coldstash' ),
						number_format_i18n( $bulk['progress']['restored'] ),
						number_format_i18n( $bulk['progress']['failed'] )
					)
					: sprintf(
						/* translators: 1: orders restored, 2: orders that failed. */
						__( '"Restore all" finished: %1$s restored, %2$s failed. Orders that fail stay in the archive.', 'coldstash' ),
						number_format_i18n( $bulk['progress']['restored'] ),
						number_format_i18n( $bulk['progress']['failed'] )
					)
			);
			?>
		</p>
	</div>
<?php endif; ?>

<?php if ( null !== $notice && array() !== $notice['restored'] ) : ?>
	<div class="notice notice-success is-dismissible">
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of orders. */
					_n( '%s order restored to the live order tables.', '%s orders restored to the live order tables.', count( $notice['restored'] ), 'coldstash' ),
					number_format_i18n( count( $notice['restored'] ) )
				)
			);
			?>
			<?php if ( 1 === count( $notice['restored'] ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $notice['restored'][0] ) ); ?>">
					<?php
					/* translators: %d: order number. */
					echo esc_html( sprintf( __( 'Edit order #%d', 'coldstash' ), $notice['restored'][0] ) );
					?>
				</a>
			<?php endif; ?>
		</p>
	</div>
<?php endif; ?>

<?php if ( null !== $notice && array() !== $notice['failed'] ) : ?>
	<div class="notice notice-error">
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of orders. */
					_n( '%s order could not be restored and is still in the archive:', '%s orders could not be restored and are still in the archive:', count( $notice['failed'] ), 'coldstash' ),
					number_format_i18n( count( $notice['failed'] ) )
				)
			);
			?>
		</p>
		<ul class="ul-disc">
			<?php foreach ( $notice['failed'] as $failure ) : ?>
				<li>#<?php echo esc_html( (string) $failure['id'] ); ?>: <?php echo esc_html( $failure['message'] ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>
