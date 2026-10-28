<?php
/**
 * Coldstash dashboard.
 *
 * @package Coldstash
 *
 * @var array{archived:int, oldest:?string, newest:?string} $summary
 * @var array{eligible:int, total:int, excluded:array<string,int>} $preview
 * @var bool   $running
 * @var array{batch_id:string, archived:int, failed:int, finished_gmt:string}|null $lastRun
 * @var bool   $started
 * @var bool   $stopped
 * @var array{running:bool, progress:array{run_id:string, cursor:int, restored:int, failed:int, stopped:bool, finished_gmt:string}|null, stopUrl:string} $bulk
 * @var list<array<string, mixed>> $recent
 * @var string $startUrl
 * @var string $stopUrl
 * @var string $tab
 * @var array{restored:list<int>, failed:list<array{id:int, message:string}>}|null $notice
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables scoped to include context.

use Coldstash\Admin\AdminMenu;
use Coldstash\Admin\ArchivedOrdersTable;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap coldstash">
	<?php require __DIR__ . '/partials/header.php'; ?>

	<p class="coldstash__lede">
		<?php esc_html_e( 'Archived orders are moved out of the live order tables but never deleted. Analytics reports are left untouched, so your historical revenue figures do not change.', 'coldstash' ); ?>
	</p>

	<div class="coldstash__stats">
		<div class="coldstash__stat">
			<span class="coldstash__stat-value"><?php echo esc_html( number_format_i18n( $summary['archived'] ) ); ?></span>
			<span class="coldstash__stat-label"><?php esc_html_e( 'Archived', 'coldstash' ); ?></span>
		</div>
		<div class="coldstash__stat">
			<span class="coldstash__stat-value"><?php echo esc_html( number_format_i18n( $preview['eligible'] ) ); ?></span>
			<span class="coldstash__stat-label"><?php esc_html_e( 'Eligible now', 'coldstash' ); ?></span>
		</div>
		<div class="coldstash__stat">
			<span class="coldstash__stat-value"><?php echo esc_html( number_format_i18n( $preview['total'] ) ); ?></span>
			<span class="coldstash__stat-label"><?php esc_html_e( 'Live orders', 'coldstash' ); ?></span>
		</div>
	</div>

	<h2><?php esc_html_e( 'Run', 'coldstash' ); ?></h2>

	<?php if ( $started && $running ) : ?>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'Archiving started. Orders are archived in the background, one batch about every minute; this page refreshes itself until the run is done.', 'coldstash' ); ?></p>
		</div>
	<?php elseif ( $stopped ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'Archiving stopped. Orders archived so far stay archived; nothing else is queued.', 'coldstash' ); ?></p>
		</div>
	<?php endif; ?>

	<p aria-live="polite">
		<?php if ( $running ) : ?>
			<span class="coldstash__badge coldstash__badge--running"><?php esc_html_e( 'Running', 'coldstash' ); ?></span>
			<?php if ( null !== $lastRun ) : ?>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of orders. */
						_n( '%s order archived so far.', '%s orders archived so far.', $lastRun['archived'], 'coldstash' ),
						number_format_i18n( $lastRun['archived'] )
					)
				);
				?>
			<?php endif; ?>
			<a href="<?php echo esc_url( $stopUrl ); ?>" class="button"><?php esc_html_e( 'Stop', 'coldstash' ); ?></a>
		<?php else : ?>
			<span class="coldstash__badge"><?php esc_html_e( 'Idle', 'coldstash' ); ?></span>
			<?php if ( $bulk['running'] ) : ?>
				<?php esc_html_e( 'Archiving is paused while "Restore all" runs.', 'coldstash' ); ?>
			<?php elseif ( 0 === $preview['eligible'] ) : ?>
				<?php esc_html_e( 'Nothing to archive right now.', 'coldstash' ); ?>
			<?php else : ?>
				<a href="<?php echo esc_url( $startUrl ); ?>" class="button button-primary"><?php esc_html_e( 'Start archiving', 'coldstash' ); ?></a>
			<?php endif; ?>
		<?php endif; ?>
	</p>

	<?php if ( ! $running && null !== $lastRun && '' !== $lastRun['finished_gmt'] ) : ?>
		<p class="coldstash__last-run">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: date, 2: number of orders archived, 3: number of failures. */
					__( 'Last run finished %1$s: %2$s archived, %3$s failed. Orders that fail stay in the live tables.', 'coldstash' ),
					get_date_from_gmt( $lastRun['finished_gmt'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
					number_format_i18n( $lastRun['archived'] ),
					number_format_i18n( $lastRun['failed'] )
				)
			);
			?>
		</p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Not eligible', 'coldstash' ); ?></h2>
	<table class="widefat striped">
		<caption class="screen-reader-text"><?php esc_html_e( 'Orders excluded from archiving, by reason', 'coldstash' ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Reason', 'coldstash' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Orders', 'coldstash' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( array_filter( $preview['excluded'] ) as $reason => $count ) : ?>
			<?php $case = \Coldstash\Archive\ExclusionReason::tryFrom( (string) $reason ); ?>
			<tr>
				<th scope="row"><?php echo esc_html( $case?->label() ?? (string) $reason ); ?></th>
				<td><?php echo esc_html( number_format_i18n( (int) $count ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Newest archived orders', 'coldstash' ); ?></h2>
	<table class="widefat striped">
		<caption class="screen-reader-text"><?php esc_html_e( 'Archived orders, newest first', 'coldstash' ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Order', 'coldstash' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Customer', 'coldstash' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Total', 'coldstash' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Created', 'coldstash' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Archived', 'coldstash' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( array() === $recent ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'Nothing archived yet.', 'coldstash' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $recent as $row ) : ?>
			<tr>
				<th scope="row">
					<?php
					$viewUrl = AdminMenu::url(
						array(
							'tab'   => 'orders',
							'order' => (int) $row['id'],
						)
					);
					?>
					<a href="<?php echo esc_url( $viewUrl ); ?>">#<?php echo esc_html( (string) $row['id'] ); ?></a>
				</th>
				<td><?php echo esc_html( (string) ( ! empty( $row['billing_name'] ) ? $row['billing_name'] : $row['billing_email'] ) ); ?></td>
				<td><?php echo wp_kses_post( ArchivedOrdersTable::formatPrice( (float) $row['total_amount'], (string) $row['currency'] ) ); ?></td>
				<td><?php echo esc_html( ArchivedOrdersTable::formatDate( (string) $row['date_created_gmt'] ) ); ?></td>
				<td><?php echo esc_html( ArchivedOrdersTable::formatDate( (string) $row['archived_at'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( array() !== $recent ) : ?>
		<p><a href="<?php echo esc_url( AdminMenu::url( array( 'tab' => 'orders' ) ) ); ?>" class="button"><?php esc_html_e( 'Search and restore archived orders', 'coldstash' ); ?></a></p>
	<?php endif; ?>
</div>
