<?php
/**
 * Sessions list template.
 *
 * @var array $transcripts
 * @var array $agents
 * @var array $filters
 * @var int   $limit
 * @var string $message
 */

if (!defined('ABSPATH')) {
    exit;
}

$agentOptions = [];
foreach ($agents as $slug => $agent) {
    $agentOptions[$slug] = $agent['label'] ?: $slug;
}

$statuses = [
    '' => __('All statuses', 'agentos'),
    'queued' => __('Queued', 'agentos'),
    'running' => __('Running', 'agentos'),
    'succeeded' => __('Completed', 'agentos'),
    'failed' => __('Failed', 'agentos'),
    'idle' => __('Idle', 'agentos'),
];
?>
<div class="wrap">
  <h1 class="wp-heading-inline"><?php esc_html_e('Sessions', 'agentos'); ?></h1>
  <hr class="wp-header-end">

  <?php if ($message === 'missing') : ?>
    <div class="notice notice-error"><p><?php esc_html_e('Transcript not found.', 'agentos'); ?></p></div>
  <?php elseif ($message === 'analysis_queued') : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Analysis queued. Refresh this page in a few moments for results.', 'agentos'); ?></p></div>
  <?php elseif ($message === 'analysis_failed') : ?>
    <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Unable to queue analysis. Check the logs for details.', 'agentos'); ?></p></div>
  <?php endif; ?>

  <form method="get" class="tablenav top" style="margin-bottom:20px;">
    <input type="hidden" name="page" value="agentos-sessions">
    <label for="agentos-filter-agent" style="margin-right:10px;">
      <span class="screen-reader-text"><?php esc_html_e('Filter by agent', 'agentos'); ?></span>
      <select id="agentos-filter-agent" name="agent">
        <option value=""><?php esc_html_e('All agents', 'agentos'); ?></option>
        <?php foreach ($agentOptions as $slug => $label) : ?>
          <option value="<?php echo esc_attr($slug); ?>" <?php selected($filters['agent_id'], $slug); ?>>
            <?php echo esc_html($label); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label for="agentos-filter-status" style="margin-right:10px;">
      <span class="screen-reader-text"><?php esc_html_e('Filter by status', 'agentos'); ?></span>
      <select id="agentos-filter-status" name="status">
        <?php foreach ($statuses as $value => $label) : ?>
          <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['status'], $value); ?>>
            <?php echo esc_html($label); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label for="agentos-filter-post" style="margin-right:10px;">
      <span class="screen-reader-text"><?php esc_html_e('Filter by post ID', 'agentos'); ?></span>
      <input type="number" id="agentos-filter-post" name="post_id" value="<?php echo $filters['post_id'] ? esc_attr((int) $filters['post_id']) : ''; ?>" placeholder="<?php esc_attr_e('Post ID', 'agentos'); ?>">
    </label>
    <label for="agentos-filter-email" style="margin-right:10px;">
      <span class="screen-reader-text"><?php esc_html_e('Filter by user email', 'agentos'); ?></span>
      <input type="email" id="agentos-filter-email" name="user_email" value="<?php echo esc_attr($filters['user_email']); ?>" placeholder="<?php esc_attr_e('User email', 'agentos'); ?>">
    </label>
    <label for="agentos-filter-limit" style="margin-right:10px;">
      <span class="screen-reader-text"><?php esc_html_e('Results limit', 'agentos'); ?></span>
      <input type="number" id="agentos-filter-limit" name="limit" value="<?php echo esc_attr($limit); ?>" min="1" max="200" style="width:80px;">
    </label>
    <button type="submit" class="button"><?php esc_html_e('Filter', 'agentos'); ?></button>
    <a href="<?php echo esc_url(add_query_arg(['page' => 'agentos-sessions'], admin_url('admin.php'))); ?>" class="button button-secondary" style="margin-left:8px;"><?php esc_html_e('Reset', 'agentos'); ?></a>
  </form>

  <?php if (empty($transcripts)) : ?>
    <p><?php esc_html_e('No transcripts found for the selected filters.', 'agentos'); ?></p>
  <?php else : ?>
    <table class="widefat striped">
      <thead>
        <tr>
          <th><?php esc_html_e('ID', 'agentos'); ?></th>
          <th><?php esc_html_e('Post', 'agentos'); ?></th>
          <th><?php esc_html_e('Agent', 'agentos'); ?></th>
          <th><?php esc_html_e('User Email', 'agentos'); ?></th>
          <th><?php esc_html_e('Created', 'agentos'); ?></th>
          <th><?php esc_html_e('Status', 'agentos'); ?></th>
          <th><?php esc_html_e('Last Run', 'agentos'); ?></th>
          <th><?php esc_html_e('Actions', 'agentos'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($transcripts as $row) :
            $post = get_post($row['post_id']);
            $postTitle = $post ? get_the_title($post) : sprintf(__('Post #%d', 'agentos'), (int) $row['post_id']);
            $agentLabel = $agentOptions[$row['agent_id']] ?? $row['agent_id'];
            $status = $row['analysis_status'] ?: 'idle';
            $created = $row['created_at'] ? esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $row['created_at'])) : '';
            $completedAt = $row['analysis_completed_at'] ? esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $row['analysis_completed_at'])) : __('—', 'agentos');
            $editLink = get_edit_post_link($row['post_id']);
            $viewUrl = add_query_arg([
                'page' => 'agentos-sessions',
                'action' => 'view',
                'transcript' => $row['id'],
            ], admin_url('admin.php'));
            ?>
          <tr>
            <td><?php echo esc_html((int) $row['id']); ?></td>
            <td>
              <?php if ($editLink) : ?>
                <a href="<?php echo esc_url($editLink); ?>"><?php echo esc_html($postTitle); ?></a>
              <?php else : ?>
                <?php echo esc_html($postTitle); ?>
              <?php endif; ?>
            </td>
            <td><?php echo esc_html($agentLabel); ?></td>
            <td><?php echo esc_html($row['user_email']); ?></td>
            <td><?php echo $created; ?></td>
            <td>
              <span class="agentos-status agentos-status--<?php echo esc_attr($status); ?>">
                <?php echo esc_html(ucfirst($status)); ?>
              </span>
            </td>
            <td><?php echo $completedAt; ?></td>
            <td><a href="<?php echo esc_url($viewUrl); ?>"><?php esc_html_e('View', 'agentos'); ?></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
