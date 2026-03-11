<?php
/**
 * BugLens Files — file browser admin view.
 *
 * @package BugLens
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap buglens-files-wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html__( 'BugLens Files', 'buglens' ); ?></h1>

    <div class="buglens-files-breadcrumb" id="buglens-breadcrumb">
        <span class="buglens-files-breadcrumb__item" data-path="/">/buglens/</span>
    </div>

    <div class="buglens-files-layout">
        <div class="buglens-files-tree" id="buglens-tree">
            <div class="buglens-files-tree__loading"><?php echo esc_html__( 'Loading...', 'buglens' ); ?></div>
        </div>
        <div class="buglens-files-content" id="buglens-content">
            <div class="buglens-files-empty"><?php echo esc_html__( 'Select a file to view its contents', 'buglens' ); ?></div>
        </div>
    </div>

    <div class="buglens-files-toolbar" id="buglens-toolbar" style="display:none;">
        <span class="buglens-files-toolbar__name" id="buglens-file-name"></span>
        <span class="buglens-files-toolbar__size" id="buglens-file-size"></span>
        <div class="buglens-files-toolbar__actions">
            <button type="button" class="button" id="buglens-file-download">
                <span class="dashicons dashicons-download"></span> <?php echo esc_html__( 'Download', 'buglens' ); ?>
            </button>
            <button type="button" class="button button-primary" id="buglens-file-save" style="display:none;">
                <span class="dashicons dashicons-saved"></span> <?php echo esc_html__( 'Save', 'buglens' ); ?>
            </button>
        </div>
    </div>
</div>
