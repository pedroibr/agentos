<?php
/** @var array $connections */
/** @var array $connection */
/** @var string $message */
/** @var array $diagnostics */
if (!defined('ABSPATH')) { exit; }
?>
<div class="wrap">
  <h1><?php esc_html_e('MCP Connections', 'agentos'); ?></h1>
  <p><?php esc_html_e('Tools use this connection’s account for everyone who can use the selected agents. Choose tools and account permissions suitable for that audience.', 'agentos'); ?></p>
  <?php if (!empty($connection['id'])) : ?><p><a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'agentos-mcp'], admin_url('admin.php'))); ?>"><?php esc_html_e('Add connection', 'agentos'); ?></a></p><?php endif; ?>
  <?php if ($message === 'saved') : ?><div class="notice notice-success"><p><?php esc_html_e('Connection saved.', 'agentos'); ?></p></div><?php endif; ?>
  <?php if ($message === 'deleted') : ?><div class="notice notice-success"><p><?php esc_html_e('Connection deleted.', 'agentos'); ?></p></div><?php endif; ?>
  <?php if ($message === 'discovered') : ?><div class="notice notice-success"><p><?php esc_html_e('Tools discovered and catalog updated.', 'agentos'); ?></p></div><?php endif; ?>
  <?php if ($message === 'discovery_failed' || $message === 'invalid') : ?><div class="notice notice-error"><p><?php esc_html_e('Could not complete the request. Check the endpoint and server configuration.', 'agentos'); ?></p></div><?php endif; ?>
  <h2><?php echo !empty($connection['id']) ? esc_html__('Edit connection', 'agentos') : esc_html__('Add connection', 'agentos'); ?></h2>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('agentos_mcp_save'); ?>
    <input type="hidden" name="action" value="agentos_mcp_save">
    <input type="hidden" name="connection[id]" value="<?php echo esc_attr($connection['id']); ?>">
    <table class="form-table" role="presentation">
      <tr><th><label for="mcp-label"><?php esc_html_e('Label', 'agentos'); ?></label></th><td><input id="mcp-label" class="regular-text" name="connection[label]" required value="<?php echo esc_attr($connection['label']); ?>"></td></tr>
      <tr><th><label for="mcp-url"><?php esc_html_e('HTTPS endpoint', 'agentos'); ?></label></th><td><input id="mcp-url" type="url" class="large-text" name="connection[url]" required value="<?php echo esc_attr($connection['url']); ?>"><p class="description"><?php esc_html_e('Public HTTPS Streamable HTTP endpoint. Redirects, local addresses, and private IP endpoints are blocked.', 'agentos'); ?></p></td></tr>
      <tr><th><?php esc_html_e('Authentication', 'agentos'); ?></th><td><select name="connection[auth]"><option value="none" <?php selected($connection['auth'], 'none'); ?>><?php esc_html_e('None', 'agentos'); ?></option><option value="bearer" <?php selected($connection['auth'], 'bearer'); ?>><?php esc_html_e('Bearer token', 'agentos'); ?></option></select></td></tr>
      <tr><th><label for="mcp-token"><?php esc_html_e('Bearer token', 'agentos'); ?></label></th><td><input id="mcp-token" type="password" autocomplete="new-password" class="regular-text" name="connection[token]" value="" aria-describedby="mcp-token-help"><p id="mcp-token-help" class="description"><?php echo !empty($connection['has_token']) ? esc_html__('A token is stored. Leave blank to keep it.', 'agentos') : esc_html__('Token is stored encrypted and is never shown again.', 'agentos'); ?></p><?php if (!empty($connection['has_token'])) : ?><label><input type="checkbox" name="connection[clear_token]" value="1"> <?php esc_html_e('Clear stored token', 'agentos'); ?></label><?php endif; ?></td></tr>
      <tr><th><?php esc_html_e('Enabled', 'agentos'); ?></th><td><label><input type="checkbox" name="connection[enabled]" value="1" <?php checked(!empty($connection['enabled'])); ?>> <?php esc_html_e('Allow selected agents to use this connection.', 'agentos'); ?></label></td></tr>
    </table>
    <?php submit_button(__('Save connection', 'agentos')); ?>
  </form>
  <?php if (!empty($connection['id'])) : ?>
    <p><a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'agentos_mcp_discover', 'connection' => $connection['id']], admin_url('admin-post.php')), 'agentos_mcp_discover')); ?>"><?php esc_html_e('Discover tools', 'agentos'); ?></a></p>
    <h3><?php esc_html_e('Discovered tools', 'agentos'); ?></h3>
    <?php if (empty($connection['tools'])) : ?><p><?php esc_html_e('No tools in the catalog.', 'agentos'); ?></p><?php else : ?>
      <ul><?php foreach ($connection['tools'] as $tool) : ?><li><code><?php echo esc_html($tool['name']); ?></code> — <?php echo esc_html($tool['description'] ?? ''); ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <a class="submitdelete" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'agentos_mcp_delete', 'connection' => $connection['id']], admin_url('admin-post.php')), 'agentos_mcp_delete')); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this MCP connection?', 'agentos')); ?>')"><?php esc_html_e('Delete connection', 'agentos'); ?></a>
  <?php endif; ?>
  <hr>
  <h2><?php esc_html_e('Saved connections', 'agentos'); ?></h2>
  <?php if (empty($connections)) : ?><p><?php esc_html_e('No connections configured.', 'agentos'); ?></p><?php else : ?>
    <ul><?php foreach ($connections as $item) : ?><li><a href="<?php echo esc_url(add_query_arg(['page' => 'agentos-mcp', 'connection' => $item['id']], admin_url('admin.php'))); ?>"><?php echo esc_html($item['label']); ?></a> — <?php echo !empty($item['enabled']) ? esc_html__('Enabled', 'agentos') : esc_html__('Disabled', 'agentos'); ?> (<?php echo esc_html(count($item['tools'] ?? [])); ?> <?php esc_html_e('tools', 'agentos'); ?>)</li><?php endforeach; ?></ul>
  <?php endif; ?>
  <hr>
  <h2><?php esc_html_e('Recent MCP failures', 'agentos'); ?></h2>
  <p class="description"><?php esc_html_e('These records contain request references and technical metadata only. They do not store tool arguments, result bodies, endpoint paths, or credentials.', 'agentos'); ?></p>
  <?php if (empty($diagnostics)) : ?><p><?php esc_html_e('No MCP failures recorded.', 'agentos'); ?></p><?php else : ?>
    <table class="widefat striped"><thead><tr>
      <th><?php esc_html_e('Time (UTC)', 'agentos'); ?></th><th><?php esc_html_e('Reference', 'agentos'); ?></th>
      <th><?php esc_html_e('Connection', 'agentos'); ?></th><th><?php esc_html_e('Tool', 'agentos'); ?></th>
      <th><?php esc_html_e('Phase', 'agentos'); ?></th><th><?php esc_html_e('Code', 'agentos'); ?></th>
      <th><?php esc_html_e('HTTP', 'agentos'); ?></th><th><?php esc_html_e('Remote code', 'agentos'); ?></th>
      <th><?php esc_html_e('Fields / rules', 'agentos'); ?></th>
    </tr></thead><tbody>
    <?php foreach ($diagnostics as $entry) : ?>
      <tr>
        <td><?php echo esc_html($entry['time'] ?? ''); ?></td><td><code><?php echo esc_html($entry['reference'] ?? ''); ?></code></td>
        <td><?php echo esc_html($entry['connection'] ?? ''); ?></td><td><code><?php echo esc_html($entry['tool'] ?? ''); ?></code></td>
        <td><code><?php echo esc_html($entry['phase'] ?? ''); ?></code></td><td><code><?php echo esc_html($entry['code'] ?? ''); ?></code></td>
        <td><?php echo esc_html((string) ($entry['http_status'] ?? '')); ?></td><td><?php echo esc_html((string) ($entry['remote_code'] ?? '')); ?></td>
        <td><?php
          $details = [];
          foreach ((array) ($entry['issues'] ?? []) as $issue) {
              if (is_array($issue)) {
                  $details[] = (string) ($issue['field'] ?? '') . ' (' . (string) ($issue['rule'] ?? 'other') . ')';
              }
          }
          if (!$details) {
              $details = (array) ($entry['fields'] ?? []);
          }
          echo esc_html(implode(', ', $details));
        ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</div>
