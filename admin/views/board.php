<?php
/**
 * BugLens Board — Kanban-style admin view.
 *
 * @package BugLens
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap buglens-board-wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html__( 'BugLens Board', 'buglens' ); ?></h1>

    <div class="buglens-board-filters">
        <label for="buglens-filter-page"><?php echo esc_html__( 'Filter by page:', 'buglens' ); ?></label>
        <select id="buglens-filter-page">
            <option value=""><?php echo esc_html__( 'All pages', 'buglens' ); ?></option>
        </select>
    </div>

    <div class="buglens-board" id="buglens-board">
        <?php
        $columns = [
            'open'        => __( 'Open', 'buglens' ),
            'in_progress' => __( 'In Progress', 'buglens' ),
            'resolved'    => __( 'Resolved', 'buglens' ),
            'closed'      => __( 'Closed', 'buglens' ),
        ];
        foreach ( $columns as $status => $label ) :
            ?>
            <div class="buglens-board__column" data-status="<?php echo esc_attr( $status ); ?>">
                <div class="buglens-board__column-header">
                    <h3><?php echo esc_html( $label ); ?></h3>
                    <span class="buglens-board__count" data-count-for="<?php echo esc_attr( $status ); ?>">0</span>
                </div>
                <div class="buglens-board__cards" data-status="<?php echo esc_attr( $status ); ?>"></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Card detail modal -->
<div class="buglens-modal" id="buglens-card-modal" style="display:none;">
    <div class="buglens-modal__backdrop"></div>
    <div class="buglens-modal__content">
        <button class="buglens-modal__close" type="button" aria-label="<?php echo esc_attr__( 'Close', 'buglens' ); ?>">&times;</button>
        <div class="buglens-modal__body" id="buglens-modal-body"></div>
    </div>
</div>
