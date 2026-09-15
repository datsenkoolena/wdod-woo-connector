<?php
/**
 * Admin order screen: WDOD details, rendered after the shipping address.
 *
 * Available variables (see Order_Display::render_admin_box()):
 *
 * @var array $view {
 *     @type \WC_Order $order
 *     @type string    $delivery_date Formatted date or ''.
 *     @type string    $gift_note
 *     @type string    $sync_status   '', 'pending', 'synced' or 'failed'.
 *     @type string    $sync_label
 *     @type int       $attempts
 *     @type string    $last_error
 *     @type string    $synced_at     Formatted date-time or ''.
 * }
 *
 * @package WDOD\WooConnector
 */

defined( 'ABSPATH' ) || exit;

$wdod_woo_connector_badge_status = '' !== $view['sync_status'] ? $view['sync_status'] : 'none';
?>
<div class="wdod-order-meta" style="clear:both;padding-top:12px;">
	<h3><?php esc_html_e( 'WDOD Connector', 'wdod-woo-connector' ); ?></h3>

	<p>
		<strong><?php esc_html_e( 'Preferred delivery date:', 'wdod-woo-connector' ); ?></strong>
		<?php echo '' !== $view['delivery_date'] ? esc_html( $view['delivery_date'] ) : '&mdash;'; ?>
	</p>

	<p>
		<strong><?php esc_html_e( 'Gift note:', 'wdod-woo-connector' ); ?></strong><br>
		<?php echo '' !== $view['gift_note'] ? nl2br( esc_html( $view['gift_note'] ) ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped before nl2br(). ?>
	</p>

	<p>
		<strong><?php esc_html_e( 'Sync status:', 'wdod-woo-connector' ); ?></strong>
		<mark class="wdod-sync-badge wdod-sync-badge--<?php echo esc_attr( $wdod_woo_connector_badge_status ); ?>"><?php echo esc_html( $view['sync_label'] ); ?></mark>
		<?php if ( $view['attempts'] > 0 ) : ?>
			<small>
				<?php
				/* translators: %d: number of attempts */
				echo esc_html( sprintf( _n( '%d attempt', '%d attempts', $view['attempts'], 'wdod-woo-connector' ), $view['attempts'] ) );
				?>
			</small>
		<?php endif; ?>
	</p>

	<?php if ( '' !== $view['synced_at'] ) : ?>
		<p>
			<strong><?php esc_html_e( 'Last synced:', 'wdod-woo-connector' ); ?></strong>
			<?php echo esc_html( $view['synced_at'] ); ?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $view['last_error'] ) : ?>
		<p class="wdod-order-meta__error" style="color:#d63638;">
			<strong><?php esc_html_e( 'Last error:', 'wdod-woo-connector' ); ?></strong>
			<?php echo esc_html( $view['last_error'] ); ?>
		</p>
	<?php endif; ?>
</div>
