<?php
/**
 * Session detail template.
 *
 * @var array      $transcript
 * @var \WP_Post   $post
 * @var array|null $agent
 * @var string     $message
 */

if (!defined('ABSPATH')) {
    exit;
}

$row = $transcript;
$agentLabel = $agent['label'] ?? ($row['agent_id'] ?? '');
$postTitle = $post ? get_the_title($post) : sprintf(__('Post #%d', 'agentos'), (int) $row['post_id']);
$status = $row['analysis_status'] ?: 'idle';
$created = $row['created_at'] ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $row['created_at']) : '';
$requestedAt = $row['analysis_requested_at'] ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $row['analysis_requested_at']) : __('—', 'agentos');
$completedAt = $row['analysis_completed_at'] ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $row['analysis_completed_at']) : __('—', 'agentos');
$analysisModel = $row['analysis_model'] ?: ($agent['analysis_model'] ?? '');
$analysisPrompt = $row['analysis_prompt'] ?? '';
$analysisError = $row['analysis_error'] ?? '';
$analysisFeedback = $row['analysis_feedback'] ?? '';
$backUrl = add_query_arg(['page' => 'agentos-sessions'], admin_url('admin.php'));
$postEdit = $post ? get_edit_post_link($post) : '';

?>
<div class="wrap">
  <h1 class="wp-heading-inline">
    <?php printf(esc_html__('Session #%d', 'agentos'), (int) $row['id']); ?>
  </h1>
  <a href="<?php echo esc_url($backUrl); ?>" class="page-title-action"><?php esc_html_e('Back to sessions', 'agentos'); ?></a>
  <hr class="wp-header-end">

  <?php if ($message === 'analysis_queued') : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Analysis queued. Refresh in a moment to see the new results.', 'agentos'); ?></p></div>
  <?php elseif ($message === 'analysis_failed') : ?>
    <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Unable to queue analysis. Please check the settings and try again.', 'agentos'); ?></p></div>
  <?php endif; ?>

  <table class="widefat striped" style="margin-bottom:20px;">
    <tbody>
      <tr>
        <th scope="row"><?php esc_html_e('Post', 'agentos'); ?></th>
        <td>
          <?php if ($postEdit) : ?>
            <a href="<?php echo esc_url($postEdit); ?>"><?php echo esc_html($postTitle); ?></a>
          <?php else : ?>
            <?php echo esc_html($postTitle); ?>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Agent', 'agentos'); ?></th>
        <td><?php echo esc_html($agentLabel); ?></td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('User email', 'agentos'); ?></th>
        <td><?php echo esc_html($row['user_email']); ?></td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Created at', 'agentos'); ?></th>
        <td><?php echo esc_html($created); ?></td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Analysis status', 'agentos'); ?></th>
        <td>
          <span class="agentos-status agentos-status--<?php echo esc_attr($status); ?>">
            <?php echo esc_html(ucfirst($status)); ?>
          </span>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Analysis model', 'agentos'); ?></th>
        <td><?php echo esc_html($analysisModel ?: __('Default', 'agentos')); ?></td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Requested at', 'agentos'); ?></th>
        <td><?php echo esc_html($requestedAt); ?></td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Completed at', 'agentos'); ?></th>
        <td><?php echo esc_html($completedAt); ?></td>
      </tr>
      <?php if ($analysisError) : ?>
        <tr>
          <th scope="row"><?php esc_html_e('Last error', 'agentos'); ?></th>
          <td><code><?php echo esc_html($analysisError); ?></code></td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <h2><?php esc_html_e('Re-run analysis', 'agentos'); ?></h2>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:720px;margin-bottom:30px;">
    <?php wp_nonce_field('agentos_run_analysis'); ?>
    <input type="hidden" name="action" value="agentos_run_analysis">
    <input type="hidden" name="transcript_id" value="<?php echo esc_attr($row['id']); ?>">

    <p>
      <label for="agentos-custom-model"><strong><?php esc_html_e('Model override', 'agentos'); ?></strong></label><br>
      <input type="text" id="agentos-custom-model" name="custom_model" class="regular-text" value="<?php echo esc_attr($analysisModel); ?>" placeholder="<?php esc_attr_e('Leave blank for default model', 'agentos'); ?>">
    </p>

    <p>
      <label for="agentos-custom-prompt"><strong><?php esc_html_e('Additional instructions', 'agentos'); ?></strong></label><br>
      <textarea id="agentos-custom-prompt" name="custom_prompt" rows="6" class="large-text" placeholder="<?php esc_attr_e('Optional: add or override guidance for this analysis run.', 'agentos'); ?>"><?php echo esc_textarea($analysisPrompt); ?></textarea>
    </p>

    <p>
      <button type="submit" class="button button-primary"><?php esc_html_e('Queue analysis', 'agentos'); ?></button>
    </p>
  </form>

  <div style="display:flex;gap:30px;flex-wrap:wrap;">
    <div style="flex:1;min-width:320px;">
      <h2><?php esc_html_e('Transcript', 'agentos'); ?></h2>
      <?php if (!empty($row['transcript'])) : ?>
        <ol class="agentos-transcript-list">
          <?php foreach ($row['transcript'] as $entry) :
              if (!is_array($entry)) {
                  continue;
              }
              $role = $entry['role'] ?? 'user';
              $text = isset($entry['text']) ? $entry['text'] : '';
              if ($text === '') {
                  continue;
              }
              $label = $role === 'assistant' ? __('Tutor', 'agentos') : __('Learner', 'agentos');
              ?>
            <li>
              <strong><?php echo esc_html($label); ?>:</strong>
              <span><?php echo nl2br(esc_html($text)); ?></span>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php else : ?>
        <p><?php esc_html_e('No transcript entries recorded.', 'agentos'); ?></p>
      <?php endif; ?>
    </div>

    <div style="flex:1;min-width:320px;">
      <h2><?php esc_html_e('Analysis feedback', 'agentos'); ?></h2>
      <?php if ($status === 'running' || $status === 'queued') : ?>
        <p><?php esc_html_e('Analysis in progress. Refresh to see the latest feedback.', 'agentos'); ?></p>
      <?php elseif ($analysisFeedback) : ?>
        <div class="agentos-analysis-feedback" style="background:#fff;border:1px solid #ccd0d4;padding:16px;border-radius:4px;">
          <?php echo wp_kses_post(nl2br(esc_html($analysisFeedback))); ?>
        </div>
      <?php else : ?>
        <p><?php esc_html_e('No feedback recorded yet.', 'agentos'); ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>
