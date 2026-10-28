<?php
/**
 * Settings tab.
 *
 * @package Coldstash
 *
 * @var \Coldstash\Settings\Settings $settings
 * @var int    $archived Number of orders in the archive.
 * @var bool   $dropData Whether archive data is removed on uninstall.
 * @var bool   $saved    Whether the form was just saved.
 * @var string $tab
 * @var array{restored:list<int>, failed:list<array{id:int, message:string}>}|null $notice
 * @var array{running:bool, progress:array{run_id:string, cursor:int, restored:int, failed:int, stopped:bool, finished_gmt:string}|null, stopUrl:string} $bulk
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables scoped to include context.

use Coldstash\Admin\AdminMenu;
use Coldstash\Admin\ArchivedOrdersTable;
use Coldstash\Settings\Settings;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap coldstash">
	<?php require __DIR__ . '/partials/header.php'; ?>

	<?php if ( $saved ) : ?>
		<div class="notice notice-success inline is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'coldstash' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="coldstash_save_settings" />
		<?php wp_nonce_field( 'coldstash_save_settings' ); ?>

		<h2><?php esc_html_e( 'Which orders to archive', 'coldstash' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="coldstash-age"><?php esc_html_e( 'Archive orders older than', 'coldstash' ); ?></label></th>
				<td>
					<input type="number" id="coldstash-age" name="age_months" class="small-text" min="<?php echo esc_attr( (string) Settings::MINIMUM_AGE_MONTHS ); ?>" max="<?php echo esc_attr( (string) Settings::MAXIMUM_AGE_MONTHS ); ?>" value="<?php echo esc_attr( (string) $settings->ageMonths ); ?>" aria-describedby="coldstash-age-help" />
					<?php esc_html_e( 'months', 'coldstash' ); ?>
					<p class="description" id="coldstash-age-help">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: minimum number of months. */
								__( 'At least %d months, so orders that may still be returned, disputed or refunded stay live.', 'coldstash' ),
								Settings::MINIMUM_AGE_MONTHS
							)
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Order statuses', 'coldstash' ); ?></th>
				<td>
					<fieldset aria-describedby="coldstash-statuses-help">
						<legend class="screen-reader-text"><?php esc_html_e( 'Order statuses to archive', 'coldstash' ); ?></legend>
						<?php foreach ( Settings::ARCHIVABLE_STATUSES as $archivableStatus ) : ?>
							<label>
								<input type="checkbox" name="statuses[]" value="<?php echo esc_attr( $archivableStatus ); ?>" <?php checked( in_array( $archivableStatus, $settings->statuses, true ) ); ?> />
								<?php echo esc_html( ArchivedOrdersTable::statusLabel( $archivableStatus ) ); ?>
							</label><br />
						<?php endforeach; ?>
						<p class="description" id="coldstash-statuses-help">
							<?php esc_html_e( 'Orders that are pending, processing or on hold are never archived. Refunded orders and orders with refunds stay live for now.', 'coldstash' ); ?>
						</p>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Downloads', 'coldstash' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="exclude_active_downloads" value="1" <?php checked( $settings->excludeActiveDownloads ); ?> />
						<?php esc_html_e( 'Keep orders live while the customer can still download their files', 'coldstash' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'When you delete the plugin', 'coldstash' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Archive data', 'coldstash' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="drop_data_on_uninstall" value="1" <?php checked( $dropData ); ?> <?php disabled( $archived > 0 ); ?> aria-describedby="coldstash-uninstall-help" />
						<?php esc_html_e( 'Remove the archive tables when the plugin is deleted', 'coldstash' ); ?>
					</label>
					<p class="description" id="coldstash-uninstall-help">
						<?php if ( $archived > 0 ) : ?>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: number of archived orders. */
									_n( '%s order is still archived. Restore it first, so no order data can be lost:', '%s orders are still archived. Restore them first, so no order data can be lost:', $archived, 'coldstash' ),
									number_format_i18n( $archived )
								)
							);
							?>
							<a href="<?php echo esc_url( AdminMenu::url( array( 'tab' => 'orders' ) ) ); ?>"><?php esc_html_e( 'Archived orders → Restore all', 'coldstash' ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'The archive is empty. Without this, deleting the plugin leaves its empty tables behind.', 'coldstash' ); ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save settings', 'coldstash' ) ); ?>
	</form>
</div>
