<?php
/**
 * Users list template.
 *
 * @var array  $users
 * @var array  $assignments
 * @var array  $plans
 * @var string $search
 * @var int    $limit
 * @var string $message
 */

if (!defined('ABSPATH')) {
    exit;
}

$planLabels = [];
foreach ($plans as $slug => $plan) {
    $planLabels[$slug] = $plan['label'] ?: $slug;
}

$addUrl = add_query_arg(
    [
        'page' => 'agentos-users',
        'action' => 'add',
    ],
    admin_url('admin.php')
);
?>
<div class="wrap">
  <h1 class="wp-heading-inline"><?php esc_html_e('Users', 'agentos'); ?></h1>
  <a href="<?php echo esc_url($addUrl); ?>" class="page-title-action"><?php esc_html_e('Add New', 'agentos'); ?></a>
  <hr class="wp-header-end">

  <?php if ($message === 'user_saved') : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('User saved.', 'agentos'); ?></p></div>
  <?php elseif ($message === 'user_removed') : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Subscription removed from user.', 'agentos'); ?></p></div>
  <?php elseif ($message === 'user_deleted') : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('User deleted.', 'agentos'); ?></p></div>
  <?php elseif ($message === 'user_invalid') : ?>
    <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Please provide a valid email address.', 'agentos'); ?></p></div>
  <?php endif; ?>

  <form method="get" class="search-form wp-clearfix" style="margin-bottom:20px;">
    <input type="hidden" name="page" value="agentos-users">
    <label class="screen-reader-text" for="agentos-user-search"><?php esc_html_e('Search users', 'agentos'); ?></label>
    <input id="agentos-user-search" type="search" name="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search by email or user key', 'agentos'); ?>" class="regular-text">
    <label class="screen-reader-text" for="agentos-user-limit"><?php esc_html_e('Results per page', 'agentos'); ?></label>
    <input id="agentos-user-limit" type="number" name="limit" value="<?php echo esc_attr($limit); ?>" min="1" max="500" style="width:80px; margin-left:8px;">
    <button type="submit" class="button" style="margin-left:8px;"><?php esc_html_e('Filter', 'agentos'); ?></button>
    <a href="<?php echo esc_url(add_query_arg(['page' => 'agentos-users'], admin_url('admin.php'))); ?>" class="button button-secondary" style="margin-left:8px;"><?php esc_html_e('Reset', 'agentos'); ?></a>
  </form>

  <?php if (empty($users)) : ?>
    <p><?php esc_html_e('No users found yet. Add one to manage subscriptions and usage limits.', 'agentos'); ?></p>
  <?php else : ?>
    <table class="widefat fixed striped">
      <thead>
        <tr>
          <th scope="col"><?php esc_html_e('User', 'agentos'); ?></th>
          <th scope="col"><?php esc_html_e('Email', 'agentos'); ?></th>
          <th scope="col"><?php esc_html_e('Sessions', 'agentos'); ?></th>
          <th scope="col"><?php esc_html_e('Usage (tokens)', 'agentos'); ?></th>
          <th scope="col"><?php esc_html_e('Last activity', 'agentos'); ?></th>
          <th scope="col"><?php esc_html_e('Subscriptions', 'agentos'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user) :
            $userKey = $user['user_key'];
            $meta = $user['meta'] ?? ['name' => '', 'email' => '', 'notes' => '', 'wp_user_id' => 0];
            $displayName = $meta['name'] ?: ($meta['email'] ?: $userKey);
            $email = $meta['email'] ?: $user['user_email'];
            $viewUrl = add_query_arg(['page' => 'agentos-users', 'action' => 'view', 'user' => $userKey], admin_url('admin.php'));
            $editUrl = add_query_arg(['page' => 'agentos-users', 'action' => 'edit', 'user' => $userKey], admin_url('admin.php'));
            $subscriptionsForUser = $assignments[$userKey] ?? [];
            $subscriptionLabels = [];
            foreach ($subscriptionsForUser as $assignment) {
                $slug = $assignment['subscription_slug'];
                $subscriptionLabels[] = $planLabels[$slug] ?? $slug;
            }
            $deleteFormId = 'delete-user-' . md5($userKey);
            ?>
          <tr>
            <td>
              <strong><a href="<?php echo esc_url($viewUrl); ?>"><?php echo esc_html($displayName); ?></a></strong>
              <div class="row-actions">
                <span class="view"><a href="<?php echo esc_url($viewUrl); ?>"><?php esc_html_e('View', 'agentos'); ?></a></span> |
                <span class="edit"><a href="<?php echo esc_url($editUrl); ?>"><?php esc_html_e('Edit', 'agentos'); ?></a></span> |
                <span class="delete">
                  <form id="<?php echo esc_attr($deleteFormId); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <?php wp_nonce_field('agentos_delete_user'); ?>
                    <input type="hidden" name="action" value="agentos_delete_user">
                    <input type="hidden" name="user_key" value="<?php echo esc_attr($userKey); ?>">
                    <button type="submit" class="button-link-delete" onclick="return confirm('<?php echo esc_js(__('Delete this user and their assignments?', 'agentos')); ?>');"><?php esc_html_e('Delete', 'agentos'); ?></button>
                  </form>
                </span>
              </div>
            </td>
            <td><?php echo $email ? esc_html($email) : __('—', 'agentos'); ?></td>
            <td><?php echo esc_html(number_format_i18n((int) $user['sessions'])); ?></td>
            <td>
              <div><?php printf(esc_html__('Realtime: %s', 'agentos'), number_format_i18n((int) $user['tokens_realtime'])); ?></div>
              <div><?php printf(esc_html__('Text: %s', 'agentos'), number_format_i18n((int) $user['tokens_text'])); ?></div>
              <div><strong><?php printf(esc_html__('Total: %s', 'agentos'), number_format_i18n((int) $user['tokens_total'])); ?></strong></div>
            </td>
            <td>
              <?php
              if (!empty($user['last_activity'])) {
                  echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $user['last_activity']));
              } else {
                  esc_html_e('—', 'agentos');
              }
              ?>
            </td>
            <td>
              <?php if ($subscriptionLabels) : ?>
                <?php echo esc_html(implode(', ', $subscriptionLabels)); ?>
              <?php else : ?>
                <em><?php esc_html_e('None', 'agentos'); ?></em>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
