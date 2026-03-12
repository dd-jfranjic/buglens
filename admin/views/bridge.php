<?php
/**
 * BugLens Bridge — security settings + AI agent config snippets.
 *
 * @package BugLens
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$buglens_bridge = BugLens_Bridge_Security::get_settings();
$buglens_api_key = get_option( 'buglens_api_key', '' );
$buglens_lifetime_options = [
    900   => __( '15 minutes', 'buglens' ),
    1800  => __( '30 minutes', 'buglens' ),
    3600  => __( '1 hour', 'buglens' ),
    7200  => __( '2 hours', 'buglens' ),
    14400 => __( '4 hours', 'buglens' ),
    28800 => __( '8 hours', 'buglens' ),
    86400 => __( '24 hours', 'buglens' ),
];
?>
<div class="wrap buglens-bridge-wrap">
    <h1>
        <span class="dashicons dashicons-rest-api" style="font-size: 28px; width: 28px; height: 28px; margin-right: 8px; vertical-align: middle;"></span>
        <?php echo esc_html__( 'BugLens Bridge', 'buglens' ); ?>
    </h1>

    <?php settings_errors(); ?>

    <?php if ( ! is_ssl() ) : ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php echo esc_html__( 'Warning:', 'buglens' ); ?></strong>
                <?php echo esc_html__( 'Your site is not using HTTPS. Bridge API credentials will be transmitted in plain text. It is strongly recommended to enable SSL/TLS before using the Bridge API.', 'buglens' ); ?>
            </p>
        </div>
    <?php endif; ?>

    <!-- Section 1: Bridge Security Settings -->
    <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
        <?php settings_fields( 'buglens_bridge_group' ); ?>

        <table class="form-table" role="presentation">
            <!-- Enable Bridge API -->
            <tr>
                <th scope="row"><?php echo esc_html__( 'Enable Bridge API', 'buglens' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="buglens_bridge_settings[bridge_enabled]"
                               value="1"
                               <?php checked( $buglens_bridge['bridge_enabled'] ); ?> />
                        <?php echo esc_html__( 'Allow AI agents to access the Bridge REST API', 'buglens' ); ?>
                    </label>
                </td>
            </tr>

            <!-- IP Whitelist -->
            <tr>
                <th scope="row">
                    <label for="buglens-ip-whitelist"><?php echo esc_html__( 'IP Whitelist', 'buglens' ); ?></label>
                </th>
                <td>
                    <input type="text"
                           id="buglens-ip-whitelist"
                           name="buglens_bridge_settings[ip_whitelist]"
                           value="<?php echo esc_attr( $buglens_bridge['ip_whitelist'] ); ?>"
                           class="regular-text"
                           placeholder="88.1.2.3, 192.168.1.1" />
                    <p class="description">
                        <?php echo esc_html__( 'Comma-separated list of allowed IP addresses. Leave empty to allow all IPs.', 'buglens' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Time-Limited Tokens -->
            <tr>
                <th scope="row"><?php echo esc_html__( 'Time-Limited Tokens', 'buglens' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="buglens_bridge_settings[token_enabled]"
                               value="1"
                               <?php checked( $buglens_bridge['token_enabled'] ); ?> />
                        <?php echo esc_html__( 'Require a time-limited token in addition to the API key', 'buglens' ); ?>
                    </label>
                    <br /><br />
                    <label for="buglens-token-lifetime"><?php echo esc_html__( 'Token lifetime:', 'buglens' ); ?></label>
                    <select id="buglens-token-lifetime" name="buglens_bridge_settings[token_lifetime]">
                        <?php foreach ( $buglens_lifetime_options as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $buglens_bridge['token_lifetime'], $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <!-- Path Restrictions -->
            <tr>
                <th scope="row"><?php echo esc_html__( 'Path Restrictions', 'buglens' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="buglens_bridge_settings[path_restrictions]"
                               value="1"
                               <?php checked( $buglens_bridge['path_restrictions'] ); ?> />
                        <?php echo esc_html__( 'Restrict file access to specific paths', 'buglens' ); ?>
                    </label>
                    <br /><br />
                    <label for="buglens-allowed-paths"><?php echo esc_html__( 'Allowed paths:', 'buglens' ); ?></label><br />
                    <input type="text"
                           id="buglens-allowed-paths"
                           name="buglens_bridge_settings[allowed_paths]"
                           value="<?php echo esc_attr( $buglens_bridge['allowed_paths'] ); ?>"
                           class="regular-text"
                           placeholder="wp-content/themes, wp-content/plugins" />
                    <p class="description">
                        <?php echo esc_html__( 'Comma-separated path prefixes the agent is allowed to access. Only used when path restrictions are enabled.', 'buglens' ); ?>
                    </p>
                    <br />
                    <label for="buglens-blocked-paths"><?php echo esc_html__( 'Blocked paths:', 'buglens' ); ?></label><br />
                    <input type="text"
                           id="buglens-blocked-paths"
                           name="buglens_bridge_settings[blocked_paths]"
                           value="<?php echo esc_attr( $buglens_bridge['blocked_paths'] ); ?>"
                           class="regular-text"
                           placeholder="wp-config.php, .htaccess, .htpasswd" />
                    <p class="description">
                        <?php echo esc_html__( 'Comma-separated file patterns that are always blocked (uses fnmatch). Applied regardless of path restrictions setting.', 'buglens' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Read-Only Mode -->
            <tr>
                <th scope="row"><?php echo esc_html__( 'Read-Only Mode', 'buglens' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="buglens_bridge_settings[read_only]"
                               value="1"
                               <?php checked( $buglens_bridge['read_only'] ); ?> />
                        <?php echo esc_html__( 'Disable all write operations (file create, edit, delete, move)', 'buglens' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <hr />

    <!-- Section 2: Connect Your AI Agent -->
    <h2><?php echo esc_html__( 'Connect Your AI Agent', 'buglens' ); ?></h2>
    <p class="description">
        <?php echo esc_html__( 'Use one of the configuration snippets below to connect your AI coding tool to this WordPress site via the BugLens Bridge MCP server.', 'buglens' ); ?>
    </p>

    <div id="buglens-bridge-snippets"
         class="buglens-bridge-snippets"
         data-site-url="<?php echo esc_attr( site_url() ); ?>"
         data-api-key="<?php echo esc_attr( $buglens_api_key ); ?>">

        <!-- 1. Claude Code -->
        <div class="buglens-snippet" data-tool="claude-code">
            <div class="buglens-snippet__header">
                <h3><?php echo esc_html__( 'Claude Code', 'buglens' ); ?></h3>
                <button type="button" class="button buglens-snippet__copy">
                    <span class="dashicons dashicons-clipboard"></span>
                    <?php echo esc_html__( 'Copy', 'buglens' ); ?>
                </button>
            </div>
            <p class="description"><?php echo esc_html__( 'Run this command in your terminal to add the BugLens MCP server to Claude Code.', 'buglens' ); ?></p>
            <pre class="buglens-snippet__code"></pre>
        </div>

        <!-- 2. Claude Desktop -->
        <div class="buglens-snippet" data-tool="claude-desktop">
            <div class="buglens-snippet__header">
                <h3><?php echo esc_html__( 'Claude Desktop', 'buglens' ); ?></h3>
                <button type="button" class="button buglens-snippet__copy">
                    <span class="dashicons dashicons-clipboard"></span>
                    <?php echo esc_html__( 'Copy', 'buglens' ); ?>
                </button>
            </div>
            <p class="description">
                <?php
                echo wp_kses(
                    __( 'Add to your <code>claude_desktop_config.json</code> file.', 'buglens' ),
                    [ 'code' => [] ]
                );
                ?>
            </p>
            <pre class="buglens-snippet__code"></pre>
        </div>

        <!-- 3. Cursor -->
        <div class="buglens-snippet" data-tool="cursor">
            <div class="buglens-snippet__header">
                <h3><?php echo esc_html__( 'Cursor', 'buglens' ); ?></h3>
                <button type="button" class="button buglens-snippet__copy">
                    <span class="dashicons dashicons-clipboard"></span>
                    <?php echo esc_html__( 'Copy', 'buglens' ); ?>
                </button>
            </div>
            <p class="description">
                <?php
                echo wp_kses(
                    __( 'Add to your <code>.cursor/mcp.json</code> file.', 'buglens' ),
                    [ 'code' => [] ]
                );
                ?>
            </p>
            <pre class="buglens-snippet__code"></pre>
        </div>

        <!-- 4. Windsurf -->
        <div class="buglens-snippet" data-tool="windsurf">
            <div class="buglens-snippet__header">
                <h3><?php echo esc_html__( 'Windsurf', 'buglens' ); ?></h3>
                <button type="button" class="button buglens-snippet__copy">
                    <span class="dashicons dashicons-clipboard"></span>
                    <?php echo esc_html__( 'Copy', 'buglens' ); ?>
                </button>
            </div>
            <p class="description">
                <?php
                echo wp_kses(
                    __( 'Add to your <code>~/.codeium/windsurf/mcp_config.json</code> file.', 'buglens' ),
                    [ 'code' => [] ]
                );
                ?>
            </p>
            <pre class="buglens-snippet__code"></pre>
        </div>

        <!-- 5. OpenAI Codex -->
        <div class="buglens-snippet" data-tool="openai-codex">
            <div class="buglens-snippet__header">
                <h3><?php echo esc_html__( 'OpenAI Codex', 'buglens' ); ?></h3>
                <button type="button" class="button buglens-snippet__copy">
                    <span class="dashicons dashicons-clipboard"></span>
                    <?php echo esc_html__( 'Copy', 'buglens' ); ?>
                </button>
            </div>
            <p class="description">
                <?php
                echo wp_kses(
                    __( 'Add to your <code>codex.toml</code> configuration file.', 'buglens' ),
                    [ 'code' => [] ]
                );
                ?>
            </p>
            <pre class="buglens-snippet__code"></pre>
        </div>

        <!-- 6. Gemini CLI / Antigravity -->
        <div class="buglens-snippet" data-tool="gemini-cli">
            <div class="buglens-snippet__header">
                <h3><?php echo esc_html__( 'Gemini CLI / Antigravity', 'buglens' ); ?></h3>
                <button type="button" class="button buglens-snippet__copy">
                    <span class="dashicons dashicons-clipboard"></span>
                    <?php echo esc_html__( 'Copy', 'buglens' ); ?>
                </button>
            </div>
            <p class="description">
                <?php
                echo wp_kses(
                    __( 'Add to your <code>~/.gemini/settings.json</code> file.', 'buglens' ),
                    [ 'code' => [] ]
                );
                ?>
            </p>
            <pre class="buglens-snippet__code"></pre>
        </div>

        <!-- 7. VS Code (Copilot) -->
        <div class="buglens-snippet" data-tool="vscode">
            <div class="buglens-snippet__header">
                <h3><?php echo esc_html__( 'VS Code (Copilot)', 'buglens' ); ?></h3>
                <button type="button" class="button buglens-snippet__copy">
                    <span class="dashicons dashicons-clipboard"></span>
                    <?php echo esc_html__( 'Copy', 'buglens' ); ?>
                </button>
            </div>
            <p class="description">
                <?php
                echo wp_kses(
                    __( 'Add to your VS Code <code>settings.json</code> or <code>.vscode/mcp.json</code> file.', 'buglens' ),
                    [ 'code' => [] ]
                );
                ?>
            </p>
            <pre class="buglens-snippet__code"></pre>
        </div>

        <!-- 8. Cline / Continue / JetBrains -->
        <div class="buglens-snippet" data-tool="cline">
            <div class="buglens-snippet__header">
                <h3><?php echo esc_html__( 'Cline / Continue / JetBrains', 'buglens' ); ?></h3>
                <button type="button" class="button buglens-snippet__copy">
                    <span class="dashicons dashicons-clipboard"></span>
                    <?php echo esc_html__( 'Copy', 'buglens' ); ?>
                </button>
            </div>
            <p class="description">
                <?php echo esc_html__( 'Standard MCP configuration format used by Cline, Continue, and JetBrains AI Assistant.', 'buglens' ); ?>
            </p>
            <pre class="buglens-snippet__code"></pre>
        </div>

    </div>
</div>
