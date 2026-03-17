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
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('agentos_delete_agent'); ?>
      <input type="hidden" name="action" value="agentos_delete_agent">
      <div class="tablenav top">
        <div class="alignleft actions bulkactions">
          <label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e('Select bulk action', 'agentos'); ?></label>
          <select name="bulk_action" id="bulk-action-selector-top">
            <option value="-1"><?php esc_html_e('Bulk actions', 'agentos'); ?></option>
            <option value="delete"><?php esc_html_e('Delete', 'agentos'); ?></option>
          </select>
          <button type="submit" class="button action" onclick="return document.getElementById('bulk-action-selector-top').value === 'delete' && confirm('<?php echo esc_js(__('Delete selected agents?', 'agentos')); ?>');"><?php esc_html_e('Apply', 'agentos'); ?></button>
        </div>
      </div>
      <table class="wp-list-table widefat fixed striped table-view-list">
        <thead>
          <tr>
            <td class="manage-column column-cb check-column"><input type="checkbox" aria-label="<?php esc_attr_e('Select all agents', 'agentos'); ?>"></td>
            <th><?php esc_html_e('Name', 'agentos'); ?></th>
            <th><?php esc_html_e('Slug', 'agentos'); ?></th>
            <th><?php esc_html_e('Mode', 'agentos'); ?></th>
            <th><?php esc_html_e('Post Types', 'agentos'); ?></th>
            <th><?php esc_html_e('Shortcode', 'agentos'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($agents as $agent) : ?>
            <?php
            $editUrl = add_query_arg(['page' => 'agentos', 'action' => 'edit', 'agent' => $agent['slug']], admin_url('admin.php'));
            $deleteUrl = wp_nonce_url(admin_url('admin-post.php?action=agentos_delete_agent&agent=' . $agent['slug']), 'agentos_delete_agent');
            ?>
            <tr>
              <th scope="row" class="check-column">
                <input type="checkbox" name="agents[]" value="<?php echo esc_attr($agent['slug']); ?>">
              </th>
              <td class="column-primary">
                <strong><a href="<?php echo esc_url($editUrl); ?>"><?php echo esc_html($agent['label'] ?: $agent['slug']); ?></a></strong>
                <div class="row-actions">
                  <span class="edit"><a href="<?php echo esc_url($editUrl); ?>"><?php esc_html_e('Edit', 'agentos'); ?></a></span> |
                  <span class="delete"><a href="<?php echo esc_url($deleteUrl); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this agent?', 'agentos')); ?>');"><?php esc_html_e('Delete', 'agentos'); ?></a></span>
                </div>
              </td>
              <td><code><?php echo esc_html($agent['slug']); ?></code></td>
              <td><?php echo esc_html(ucfirst($agent['default_mode'] ?? 'voice')); ?></td>
              <td><?php echo esc_html(implode(', ', $agent['post_types'] ?? [])); ?></td>
              <td><code>[agentos id="<?php echo esc_attr($agent['slug']); ?>"]</code></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </form>
  <?php endif; ?>
</div>
