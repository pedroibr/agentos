<?php
/**
 * User form template.
 *
 * @var bool        $is_edit
 * @var string      $user_key
 * @var array       $meta
 * @var array       $plans
 * @var array       $subscriptions
 * @var WP_User|nil $wp_user
 */

if (!defined('ABSPATH')) {
    exit;
}

$title = $is_edit ? __('Edit User', 'agentos') : __('Add User', 'agentos');
$actionUrl = admin_url('admin-post.php');
$defaultSubscription = '';
?>
<div class="wrap">
  <h1><?php echo esc_html($title); ?></h1>
  <form method="post" action="<?php echo esc_url($actionUrl); ?>" class="agentos-user-form">
    <?php wp_nonce_field('agentos_save_user'); ?>
    <input type="hidden" name="action" value="agentos_save_user">
    <input type="hidden" name="user[original_key]" value="<?php echo esc_attr($user_key); ?>">

    <?php if ($is_edit) : ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><?php esc_html_e('User key', 'agentos'); ?></th>
          <td><code><?php echo esc_html($user_key); ?></code></td>
        </tr>
        <?php if ($wp_user instanceof WP_User) : ?>
          <tr>
            <th scope="row"><?php esc_html_e('Linked WordPress user', 'agentos'); ?></th>
            <td>
              <a href="<?php echo esc_url(get_edit_user_link($wp_user->ID)); ?>">
                <?php echo esc_html($wp_user->display_name ?: $wp_user->user_login); ?>
              </a>
            </td>
          </tr>
        <?php endif; ?>
      </table>
    <?php endif; ?>

    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><label for="agentos-user-email"><?php esc_html_e('Email', 'agentos'); ?></label></th>
        <td>
          <input type="email" id="agentos-user-email" name="user[email]" class="regular-text" value="<?php echo esc_attr($meta['email']); ?>" required>
          <p class="description"><?php esc_html_e('If the email matches an existing WordPress user, the account will be linked automatically.', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-user-notes"><?php esc_html_e('Notes', 'agentos'); ?></label></th>
        <td>
          <textarea id="agentos-user-notes" name="user[notes]" rows="4" class="large-text"><?php echo esc_textarea($meta['notes']); ?></textarea>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-user-subscription"><?php esc_html_e('Assign subscription', 'agentos'); ?></label></th>
        <td>
          <select id="agentos-user-subscription" name="user[subscription]">
            <option value=""><?php esc_html_e('None', 'agentos'); ?></option>
            <?php foreach ($plans as $slug => $plan) : ?>
              <option value="<?php echo esc_attr($slug); ?>" <?php selected($slug, $defaultSubscription); ?>>
                <?php echo esc_html($plan['label'] ?: $slug); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="description"><?php esc_html_e('Optional: assign a subscription immediately after saving. You can manage assignments later from the user detail page.', 'agentos'); ?></p>
        </td>
      </tr>
    </table>

    <?php submit_button($is_edit ? __('Update User', 'agentos') : __('Create User', 'agentos')); ?>
    <a href="<?php echo esc_url(add_query_arg(['page' => 'agentos-users'], admin_url('admin.php'))); ?>" class="button button-secondary">
      <?php esc_html_e('Cancel', 'agentos'); ?>
    </a>
  </form>
</div>
