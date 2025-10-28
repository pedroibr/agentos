<?php
/**
 * User detail template.
 *
 * @var string      $user_key
 * @var array       $meta
 * @var array       $subscriptions
 * @var array       $plans
 * @var array       $usage_summary
 * @var array       $usage_entries
 * @var array       $transcripts
 * @var WP_User|nil $wp_user
 */

if (!defined('ABSPATH')) {
    exit;
}

$backUrl = add_query_arg(['page' => 'agentos-users'], admin_url('admin.php'));
$displayName = $meta['name'] ?: ($meta['email'] ?: $user_key);
$assignUrl = admin_url('admin-post.php');
$planLabels = [];
foreach ($plans as $slug => $plan) {
    $planLabels[$slug] = $plan['label'] ?: $slug;
}
?>
<div class="wrap agentos-user-detail">
  <h1 class="wp-heading-inline"><?php echo esc_html(sprintf(__('User: %s', 'agentos'), $displayName)); ?></h1>
  <a href="<?php echo esc_url($backUrl); ?>" class="page-title-action">&larr; <?php esc_html_e('Back to Users', 'agentos'); ?></a>
  <hr class="wp-header-end">

  <?php if (!empty($message)) : ?>
    <?php if ($message === 'user_saved') : ?>
      <div class="notice notice-success is-dismissible"><p><?php esc_html_e('User saved.', 'agentos'); ?></p></div>
    <?php elseif ($message === 'user_removed') : ?>
      <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Subscription removed.', 'agentos'); ?></p></div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="card" style="max-width:760px;">
    <h2><?php esc_html_e('Profile', 'agentos'); ?></h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><?php esc_html_e('User key', 'agentos'); ?></th>
        <td><code><?php echo esc_html($user_key); ?></code></td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Email', 'agentos'); ?></th>
        <td><?php echo $meta['email'] ? esc_html($meta['email']) : __('—', 'agentos'); ?></td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Notes', 'agentos'); ?></th>
        <td><?php echo $meta['notes'] ? esc_html($meta['notes']) : __('—', 'agentos'); ?></td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Linked WordPress user', 'agentos'); ?></th>
        <td>
          <?php if ($wp_user instanceof WP_User) : ?>
            <a href="<?php echo esc_url(get_edit_user_link($wp_user->ID)); ?>"><?php echo esc_html($wp_user->display_name ?: $wp_user->user_login); ?></a>
          <?php else : ?>
            <?php esc_html_e('None', 'agentos'); ?>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Usage (last 30 days)', 'agentos'); ?></th>
        <td>
          <div><?php printf(esc_html__('Realtime: %s tokens', 'agentos'), number_format_i18n((int) $usage_summary['tokens_realtime'])); ?></div>
          <div><?php printf(esc_html__('Text: %s tokens', 'agentos'), number_format_i18n((int) $usage_summary['tokens_text'])); ?></div>
          <div><strong><?php printf(esc_html__('Total: %s tokens across %s sessions', 'agentos'), number_format_i18n((int) $usage_summary['tokens_total']), number_format_i18n((int) $usage_summary['sessions'])); ?></strong></div>
        </td>
      </tr>
    </table>
  </div>

  <h2><?php esc_html_e('Subscriptions', 'agentos'); ?></h2>
  <?php if (empty($subscriptions)) : ?>
    <p><?php esc_html_e('No subscriptions assigned.', 'agentos'); ?></p>
  <?php else : ?>
    <table class="widefat striped" style="max-width:820px;">
      <thead>
        <tr>
          <th><?php esc_html_e('Subscription', 'agentos'); ?></th>
          <th><?php esc_html_e('Period', 'agentos'); ?></th>
          <th><?php esc_html_e('Limits', 'agentos'); ?></th>
          <th><?php esc_html_e('Usage (current window)', 'agentos'); ?></th>
          <th><?php esc_html_e('Actions', 'agentos'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($subscriptions as $row) :
            $plan = $row['plan'] ?? null;
            $assignment = $row['assignment'];
            $usage = $row['usage'];
            $periodLabel = isset($plan['period_hours']) ? sprintf(_n('%d hour', '%d hours', (int) $plan['period_hours'], 'agentos'), (int) $plan['period_hours']) : __('Custom', 'agentos');
            $limits = [];
            if (!empty($plan['limits']['realtime_tokens'])) {
                $limits[] = sprintf(__('Realtime: %s', 'agentos'), number_format_i18n((int) $plan['limits']['realtime_tokens']));
            }
            if (!empty($plan['limits']['text_tokens'])) {
                $limits[] = sprintf(__('Text: %s', 'agentos'), number_format_i18n((int) $plan['limits']['text_tokens']));
            }
            if (!empty($plan['limits']['sessions'])) {
                $limits[] = sprintf(__('Sessions: %s', 'agentos'), number_format_i18n((int) $plan['limits']['sessions']));
            }
            if (!$limits) {
                $limits[] = __('Unlimited', 'agentos');
            }
            $expires = $assignment['expires_at'] ? esc_html(mysql2date(get_option('date_format'), $assignment['expires_at'])) : __('Does not expire', 'agentos');
            ?>
          <tr>
            <td><strong><?php echo esc_html($row['label']); ?></strong><br><small><?php echo esc_html($expires); ?></small></td>
            <td><?php echo esc_html($periodLabel); ?></td>
            <td><?php echo esc_html(implode(' · ', $limits)); ?></td>
            <td>
              <div><?php printf(esc_html__('Realtime: %s', 'agentos'), number_format_i18n((int) $usage['tokens_realtime'])); ?></div>
              <div><?php printf(esc_html__('Text: %s', 'agentos'), number_format_i18n((int) $usage['tokens_text'])); ?></div>
              <div><strong><?php printf(esc_html__('Total: %s', 'agentos'), number_format_i18n((int) $usage['tokens_total'])); ?></strong></div>
              <div><?php printf(esc_html__('Sessions: %s', 'agentos'), number_format_i18n((int) $usage['sessions'])); ?></div>
            </td>
            <td>
              <form method="post" action="<?php echo esc_url($assignUrl); ?>" onsubmit="return confirm('<?php echo esc_js(__('Remove this subscription?', 'agentos')); ?>');">
                <?php wp_nonce_field('agentos_remove_subscription'); ?>
                <input type="hidden" name="action" value="agentos_remove_subscription">
                <input type="hidden" name="user_key" value="<?php echo esc_attr($user_key); ?>">
                <input type="hidden" name="subscription_slug" value="<?php echo esc_attr($row['slug']); ?>">
                <button type="submit" class="button-link-delete"><?php esc_html_e('Remove', 'agentos'); ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h3><?php esc_html_e('Assign subscription', 'agentos'); ?></h3>
  <?php if (empty($plans)) : ?>
    <p><?php esc_html_e('Create a subscription before assigning one to this user.', 'agentos'); ?></p>
  <?php else : ?>
    <form method="post" action="<?php echo esc_url($assignUrl); ?>" class="agentos-user-assign-form">
      <?php wp_nonce_field('agentos_assign_subscription'); ?>
      <input type="hidden" name="action" value="agentos_assign_subscription">
      <input type="hidden" name="user_key" value="<?php echo esc_attr($user_key); ?>">
      <label for="agentos-user-assign-subscription"><?php esc_html_e('Subscription', 'agentos'); ?></label>
      <select id="agentos-user-assign-subscription" name="subscription_slug">
        <?php foreach ($plans as $slug => $plan) : ?>
          <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($plan['label'] ?: $slug); ?></option>
        <?php endforeach; ?>
      </select>
      <label for="agentos-user-assign-expiry" style="margin-left:12px;">
        <?php esc_html_e('Expires on', 'agentos'); ?>
      </label>
      <input id="agentos-user-assign-expiry" type="date" name="expires_at" value="">
      <button type="submit" class="button button-secondary" style="margin-left:8px;">
        <?php esc_html_e('Assign', 'agentos'); ?>
      </button>
    </form>
  <?php endif; ?>

  <h2 style="margin-top:32px;"> <?php esc_html_e('Recent usage', 'agentos'); ?></h2>
  <?php if (empty($usage_entries)) : ?>
    <p><?php esc_html_e('No usage recorded yet for this user.', 'agentos'); ?></p>
  <?php else : ?>
    <table class="widefat striped" style="max-width:820px;">
      <thead>
        <tr>
          <th><?php esc_html_e('Session', 'agentos'); ?></th>
          <th><?php esc_html_e('Subscription', 'agentos'); ?></th>
          <th><?php esc_html_e('Tokens', 'agentos'); ?></th>
          <th><?php esc_html_e('Duration', 'agentos'); ?></th>
          <th><?php esc_html_e('Updated', 'agentos'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usage_entries as $entry) : ?>
          <tr>
            <td><code><?php echo esc_html($entry['session_id']); ?></code></td>
            <td><?php echo esc_html($planLabels[$entry['subscription_slug']] ?? $entry['subscription_slug'] ?: __('—', 'agentos')); ?></td>
            <td>
              <div><?php printf(esc_html__('Realtime: %s', 'agentos'), number_format_i18n((int) $entry['tokens_realtime'])); ?></div>
              <div><?php printf(esc_html__('Text: %s', 'agentos'), number_format_i18n((int) $entry['tokens_text'])); ?></div>
              <div><strong><?php printf(esc_html__('Total: %s', 'agentos'), number_format_i18n((int) $entry['tokens_total'])); ?></strong></div>
            </td>
            <td><?php echo esc_html(gmdate('H:i:s', max(0, (int) $entry['duration_seconds']))); ?></td>
            <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $entry['updated_at'])); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h2 style="margin-top:32px;"> <?php esc_html_e('Recent sessions', 'agentos'); ?></h2>
  <?php if (empty($transcripts)) : ?>
    <p><?php esc_html_e('No transcripts recorded for this user.', 'agentos'); ?></p>
  <?php else : ?>
    <table class="widefat striped" style="max-width:820px;">
      <thead>
        <tr>
          <th><?php esc_html_e('ID', 'agentos'); ?></th>
          <th><?php esc_html_e('Post', 'agentos'); ?></th>
          <th><?php esc_html_e('Agent', 'agentos'); ?></th>
          <th><?php esc_html_e('Created', 'agentos'); ?></th>
          <th><?php esc_html_e('Status', 'agentos'); ?></th>
          <th><?php esc_html_e('Actions', 'agentos'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($transcripts as $row) :
            $viewUrl = add_query_arg([
                'page' => 'agentos-sessions',
                'action' => 'view',
                'transcript' => $row['id'],
            ], admin_url('admin.php'));
            $post = get_post($row['post_id']);
            ?>
          <tr>
            <td><?php echo esc_html((int) $row['id']); ?></td>
            <td>
              <?php if ($post) : ?>
                <a href="<?php echo esc_url(get_permalink($post)); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(get_the_title($post)); ?></a>
              <?php else : ?>
                <?php echo esc_html(__('(deleted)', 'agentos')); ?>
              <?php endif; ?>
            </td>
            <td><?php echo esc_html($row['agent_id']); ?></td>
            <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $row['created_at'] ?? $row['created'] ?? '')); ?></td>
            <td><?php echo esc_html(ucfirst($row['analysis_status'] ?? 'idle')); ?></td>
            <td><a href="<?php echo esc_url($viewUrl); ?>"><?php esc_html_e('View', 'agentos'); ?></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
