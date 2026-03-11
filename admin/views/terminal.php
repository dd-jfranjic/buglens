<?php
/**
 * BugLens Terminal — admin terminal view.
 *
 * @package BugLens
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap buglens-terminal-wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html__( 'BugLens Terminal', 'buglens' ); ?></h1>

    <div class="buglens-terminal-toolbar">
        <button type="button" class="button" id="buglens-term-clear" title="<?php echo esc_attr__( 'Clear terminal output', 'buglens' ); ?>">
            <span class="dashicons dashicons-dismiss" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span>
            <?php echo esc_html__( 'Clear', 'buglens' ); ?>
        </button>
        <button type="button" class="button" id="buglens-term-restart" title="<?php echo esc_attr__( 'Start a new session', 'buglens' ); ?>">
            <span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span>
            <?php echo esc_html__( 'New Session', 'buglens' ); ?>
        </button>
        <span class="buglens-terminal-info" id="buglens-term-info"></span>
    </div>

    <div id="buglens-terminal" class="buglens-terminal-container"></div>
</div>

<!-- Warning dialog shown on first visit -->
<div id="buglens-terminal-warning" class="buglens-terminal-warning" style="display:none;">
    <div class="buglens-terminal-warning__backdrop"></div>
    <div class="buglens-terminal-warning__dialog">
        <h2><?php echo esc_html__( 'Live Server Terminal', 'buglens' ); ?></h2>
        <p><?php
            echo wp_kses(
                __( 'This is a <strong>real shell</strong> on this server. Commands you run here have real effects.', 'buglens' ),
                [ 'strong' => [] ]
            );
        ?></p>
        <p><?php echo esc_html__( 'The terminal runs as the web server user. Use with caution.', 'buglens' ); ?></p>
        <label>
            <input type="checkbox" id="buglens-term-accept" />
            <?php echo esc_html__( 'I understand and accept the risks', 'buglens' ); ?>
        </label>
        <div class="buglens-terminal-warning__actions">
            <button type="button" class="button button-primary" id="buglens-term-continue" disabled>
                <?php echo esc_html__( 'Continue', 'buglens' ); ?>
            </button>
            <a href="<?php echo esc_url( admin_url() ); ?>" class="button">
                <?php echo esc_html__( 'Go Back', 'buglens' ); ?>
            </a>
        </div>
    </div>
</div>
