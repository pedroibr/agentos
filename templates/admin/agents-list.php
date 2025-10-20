<?php
/**
 * Agent list template.
 *
 * @var array  $agents
 * @var string $message
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
  <h1 class="wp-heading-inline"><?php esc_html_e('Agents', 'agentos'); ?></h1>
  <a href="<?php echo esc_url(add_query_arg(['page' => 'agentos', 'action' => 'new'], admin_url('admin.php'))); ?>" class="page-title-action"><?php esc_html_e('Add New', 'agentos'); ?></a>
  <hr class="wp-header-end">
  <?php if ($message === 'saved') : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Agent saved.', 'agentos'); ?></p></div>
  <?php elseif ($message === 'deleted') : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Agent deleted.', 'agentos'); ?></p></div>
  <?php endif; ?>
  <?php if (empty($agents)) : ?>
    <p><?php esc_html_e('No agents yet. Create your first agent below.', 'agentos'); ?></p>
  <?php else : ?>
    <table class="widefat striped">
      <thead>
        <tr>
          <th><?php esc_html_e('Name', 'agentos'); ?></th>
          <th><?php esc_html_e('Slug', 'agentos'); ?></th>
          <th><?php esc_html_e('Mode', 'agentos'); ?></th>
          <th><?php esc_html_e('Post Types', 'agentos'); ?></th>
          <th><?php esc_html_e('Shortcode', 'agentos'); ?></th>
          <th><?php esc_html_e('Actions', 'agentos'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($agents as $agent) : ?>
          <tr>
            <td><?php echo esc_html($agent['label'] ?: $agent['slug']); ?></td>
            <td><code><?php echo esc_html($agent['slug']); ?></code></td>
            <td><?php echo esc_html(ucfirst($agent['default_mode'] ?? 'voice')); ?></td>
            <td><?php echo esc_html(implode(', ', $agent['post_types'] ?? [])); ?></td>
            <td><code>[agentos id="<?php echo esc_attr($agent['slug']); ?>"]</code></td>
            <td>
              <?php
              $editUrl = add_query_arg(['page' => 'agentos', 'action' => 'edit', 'agent' => $agent['slug']], admin_url('admin.php'));
              $deleteUrl = wp_nonce_url(admin_url('admin-post.php?action=agentos_delete_agent&agent=' . $agent['slug']), 'agentos_delete_agent');
              ?>
              <a href="<?php echo esc_url($editUrl); ?>"><?php esc_html_e('Edit', 'agentos'); ?></a> |
              <a href="<?php echo esc_url($deleteUrl); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this agent?', 'agentos')); ?>');"><?php esc_html_e('Delete', 'agentos'); ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
