<?php
/**
 * BugLens Settings — admin settings page view.
 *
 * @package BugLens
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap buglens-settings-wrap">
    <h1>
        <span class="dashicons dashicons-search" style="font-size: 28px; width: 28px; height: 28px; margin-right: 8px; vertical-align: middle;"></span>
        <?php echo esc_html__( 'BugLens Settings', 'buglens' ); ?>
    </h1>

    <?php settings_errors(); ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
        <?php settings_fields( 'buglens_settings_group' ); ?>
        <?php do_settings_sections( 'buglens-settings' ); ?>
        <?php submit_button(); ?>
    </form>
</div>
