<?php
/**
 * Subscription form template.
 *
 * @var bool  $is_edit
 * @var array $plan
 * @var string $plan_slug
 * @var array $agents
 */

if (!defined('ABSPATH')) {
    exit;
}

$limits = $plan['limits'] ?? ['realtime_tokens' => 0, 'text_tokens' => 0, 'sessions' => 0];
$title = $is_edit ? __('Edit Subscription', 'agentos') : __('Add New Subscription', 'agentos');
?>
<div class="wrap">
  <h1><?php echo esc_html($title); ?></h1>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('agentos_save_subscription'); ?>
    <input type="hidden" name="action" value="agentos_save_subscription">
    <input type="hidden" name="original_slug" value="<?php echo esc_attr($plan_slug); ?>">

    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><label for="agentos-plan-label"><?php esc_html_e('Name', 'agentos'); ?></label></th>
        <td><input type="text" id="agentos-plan-label" name="subscription[label]" value="<?php echo esc_attr($plan['label']); ?>" class="regular-text" required></td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-plan-slug"><?php esc_html_e('Slug', 'agentos'); ?></label></th>
        <td>
          <input type="text" id="agentos-plan-slug" name="subscription[slug]" value="<?php echo esc_attr($plan['slug']); ?>" class="regular-text" <?php echo $is_edit ? '' : 'required'; ?>>
          <p class="description"><?php esc_html_e('Identifier used internally. Letters, numbers, and dashes only.', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-plan-period"><?php esc_html_e('Usage window', 'agentos'); ?></label></th>
        <td>
          <select id="agentos-plan-period" name="subscription[period_hours]">
            <?php
$periodOptions = [
              24 => __('Per day (24h rolling window)', 'agentos'),
              168 => __('Per week (7 days)', 'agentos'),
              720 => __('Per month (30 days)', 'agentos'),
            ];
$periodValue = (int) ($plan['period_hours'] ?? 24);
            if ($periodValue && !isset($periodOptions[$periodValue])) {
                $periodOptions[$periodValue] = sprintf(__('Custom (%d hours)', 'agentos'), $periodValue);
            }
            foreach ($periodOptions as $hours => $label) :
            ?>
              <option value="<?php echo esc_attr($hours); ?>" <?php selected($periodValue, $hours); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
          <p class="description"><?php esc_html_e('The window length used to calculate consumption totals. Choose a preset or enter a custom number of hours.', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-plan-period-custom"><?php esc_html_e('Custom hours', 'agentos'); ?></label></th>
        <td>
          <?php $customValue = in_array($periodValue, [24, 168, 720], true) ? '' : $periodValue; ?>
          <input type="number" id="agentos-plan-period-custom" name="subscription[period_hours_custom]" value="<?php echo esc_attr($customValue); ?>" class="small-text" min="1" placeholder="<?php echo esc_attr($periodValue); ?>">
          <p class="description"><?php esc_html_e('Optional: enter a custom number of hours if the presets above do not match.', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Allowed agents', 'agentos'); ?></th>
        <td>
          <select name="subscription[allowed_agents][]" multiple size="6" style="min-width:260px;">
            <?php foreach ($agents as $slug => $agentData) :
                $label = $agentData['label'] ?: $slug;
                ?>
              <option value="<?php echo esc_attr($slug); ?>" <?php selected(in_array($slug, (array) $plan['allowed_agents'], true)); ?>>
                <?php echo esc_html($label); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="description"><?php esc_html_e('Leave empty to allow this subscription on every agent.', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Per-period limits', 'agentos'); ?></th>
        <td>
          <fieldset>
            <label>
              <?php esc_html_e('Realtime tokens', 'agentos'); ?>
              <input type="number" name="subscription[limits][realtime_tokens]" value="<?php echo esc_attr((int) ($limits['realtime_tokens'] ?? 0)); ?>" min="0" class="small-text">
            </label>
            <br>
            <label>
              <?php esc_html_e('Text tokens', 'agentos'); ?>
              <input type="number" name="subscription[limits][text_tokens]" value="<?php echo esc_attr((int) ($limits['text_tokens'] ?? 0)); ?>" min="0" class="small-text">
            </label>
            <br>
            <label>
              <?php esc_html_e('Sessions', 'agentos'); ?>
              <input type="number" name="subscription[limits][sessions]" value="<?php echo esc_attr((int) ($limits['sessions'] ?? 0)); ?>" min="0" class="small-text">
            </label>
            <p class="description"><?php esc_html_e('Set to 0 for unlimited usage within the window.', 'agentos'); ?></p>
          </fieldset>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-plan-session-cap"><?php esc_html_e('Session token cap', 'agentos'); ?></label></th>
        <td>
          <input type="number" id="agentos-plan-session-cap" name="subscription[session_token_cap]" value="<?php echo esc_attr((int) ($plan['session_token_cap'] ?? 0)); ?>" class="small-text" min="0">
          <p class="description"><?php esc_html_e('Maximum tokens allowed per session when this subscription is active. Set to 0 for no cap.', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Block on overage', 'agentos'); ?></th>
        <td>
          <label>
            <input type="checkbox" name="subscription[block_on_overage]" value="1" <?php checked(!empty($plan['block_on_overage'])); ?>>
            <?php esc_html_e('Prevent new sessions once a limit is reached. When unchecked, users receive warnings but may continue.', 'agentos'); ?>
          </label>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-plan-notes"><?php esc_html_e('Notes', 'agentos'); ?></label></th>
        <td>
          <textarea id="agentos-plan-notes" name="subscription[notes]" rows="4" class="large-text"><?php echo esc_textarea($plan['notes'] ?? ''); ?></textarea>
        </td>
      </tr>
    </table>

    <?php submit_button($is_edit ? __('Update Subscription', 'agentos') : __('Create Subscription', 'agentos')); ?>
  </form>
</div>
