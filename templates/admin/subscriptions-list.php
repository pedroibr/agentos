<?php
/**
 * Subscriptions list template.
 *
 * @var array  $plans
 * @var array  $agents
 * @var string $message
 */

if (!defined('ABSPATH')) {
    exit;
}

$agentLabels = [];
foreach ($agents as $slug => $agent) {
    $agentLabels[$slug] = $agent['label'] ?: $slug;
}
?>
<div class="wrap">
  <h1 class="wp-heading-inline"><?php esc_html_e('Subscriptions', 'agentos'); ?></h1>
  <a href="<?php echo esc_url(add_query_arg(['page' => 'agentos-subscriptions', 'action' => 'new'], admin_url('admin.php'))); ?>" class="page-title-action"><?php esc_html_e('Add New', 'agentos'); ?></a>
  <hr class="wp-header-end">

  <?php if ($message === 'saved') : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Subscription saved.', 'agentos'); ?></p></div>
  <?php elseif ($message === 'deleted') : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Subscription deleted.', 'agentos'); ?></p></div>
  <?php endif; ?>

  <?php if (empty($plans)) : ?>
    <p><?php esc_html_e('No subscriptions yet. Create your first plan to control agent usage limits.', 'agentos'); ?></p>
  <?php else : ?>
    <table class="widefat striped">
      <thead>
        <tr>
          <th><?php esc_html_e('Name', 'agentos'); ?></th>
          <th><?php esc_html_e('Slug', 'agentos'); ?></th>
          <th><?php esc_html_e('Period', 'agentos'); ?></th>
          <th><?php esc_html_e('Limits', 'agentos'); ?></th>
          <th><?php esc_html_e('Allowed Agents', 'agentos'); ?></th>
          <th><?php esc_html_e('Session Cap', 'agentos'); ?></th>
          <th><?php esc_html_e('Actions', 'agentos'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($plans as $plan) :
            $limits = $plan['limits'] ?? [];
            $limitParts = [];
            if (!empty($limits['realtime_tokens'])) {
                $limitParts[] = sprintf(__('Realtime: %s', 'agentos'), number_format_i18n((int) $limits['realtime_tokens']));
            }
            if (!empty($limits['text_tokens'])) {
                $limitParts[] = sprintf(__('Text: %s', 'agentos'), number_format_i18n((int) $limits['text_tokens']));
            }
            if (!empty($limits['sessions'])) {
                $limitParts[] = sprintf(__('Sessions: %d', 'agentos'), (int) $limits['sessions']);
            }
            if (!$limitParts) {
                $limitParts[] = __('Unlimited', 'agentos');
            }
            $allowed = $plan['allowed_agents'] ?? [];
            if (!$allowed) {
                $allowedLabel = __('All agents', 'agentos');
            } else {
                $labels = [];
                foreach ($allowed as $slug) {
                    $labels[] = $agentLabels[$slug] ?? $slug;
                }
                $allowedLabel = implode(', ', $labels);
            }
            $editUrl = add_query_arg([
                'page' => 'agentos-subscriptions',
                'action' => 'edit',
                'subscription' => $plan['slug'],
            ], admin_url('admin.php'));
            $deleteUrl = wp_nonce_url(
                add_query_arg([
                    'action' => 'agentos_delete_subscription',
                    'subscription' => $plan['slug'],
                ], admin_url('admin-post.php')),
                'agentos_delete_subscription'
            );
            ?>
          <tr>
            <td><?php echo esc_html($plan['label'] ?: $plan['slug']); ?></td>
            <td><code><?php echo esc_html($plan['slug']); ?></code></td>
            <td><?php echo esc_html(sprintf(_n('%d hour', '%d hours', (int) $plan['period_hours'], 'agentos'), (int) $plan['period_hours'])); ?></td>
            <td><?php echo esc_html(implode(' · ', $limitParts)); ?></td>
            <td><?php echo esc_html($allowedLabel); ?></td>
            <td><?php echo !empty($plan['session_token_cap']) ? esc_html(number_format_i18n((int) $plan['session_token_cap'])) : __('None', 'agentos'); ?></td>
            <td>
              <a href="<?php echo esc_url($editUrl); ?>"><?php esc_html_e('Edit', 'agentos'); ?></a> |
              <a href="<?php echo esc_url($deleteUrl); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this subscription?', 'agentos')); ?>');"><?php esc_html_e('Delete', 'agentos'); ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
