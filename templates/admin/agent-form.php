<?php
/**
 * Agent form template.
 *
 * @var bool  $is_edit
 * @var array $agent
 * @var string $agent_slug
 * @var array $post_types
 */
if (!defined('ABSPATH')) {
    exit;
}

$title = $is_edit ? __('Edit Agent', 'agentos') : __('Add New Agent', 'agentos');
?>
<div class="wrap">
  <h1><?php echo esc_html($title); ?></h1>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('agentos_save_agent'); ?>
    <input type="hidden" name="action" value="agentos_save_agent">
    <input type="hidden" name="original_slug" value="<?php echo esc_attr($agent_slug); ?>">

    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><label for="agentos-label"><?php esc_html_e('Name', 'agentos'); ?></label></th>
        <td><input type="text" id="agentos-label" name="agent[label]" value="<?php echo esc_attr($agent['label']); ?>" class="regular-text" required></td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-slug"><?php esc_html_e('Slug', 'agentos'); ?></label></th>
        <td>
          <input type="text" id="agentos-slug" name="agent[slug]" value="<?php echo esc_attr($agent['slug']); ?>" class="regular-text" <?php echo $is_edit ? '' : 'required'; ?>>
          <p class="description"><?php esc_html_e('Used in the shortcode id attribute. Letters, numbers, dashes only.', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-mode"><?php esc_html_e('Default Mode', 'agentos'); ?></label></th>
        <td>
          <select id="agentos-mode" name="agent[default_mode]">
            <?php foreach (['voice' => __('Voice only', 'agentos'), 'text' => __('Text only', 'agentos'), 'both' => __('Voice + Text', 'agentos')] as $val => $label) : ?>
              <option value="<?php echo esc_attr($val); ?>" <?php selected($agent['default_mode'], $val); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-default-model"><?php esc_html_e('Default Model', 'agentos'); ?></label></th>
        <td><input type="text" id="agentos-default-model" name="agent[default_model]" value="<?php echo esc_attr($agent['default_model']); ?>" class="regular-text"></td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-default-voice"><?php esc_html_e('Default Voice', 'agentos'); ?></label></th>
        <td><input type="text" id="agentos-default-voice" name="agent[default_voice]" value="<?php echo esc_attr($agent['default_voice']); ?>" class="regular-text"></td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-base-prompt"><?php esc_html_e('Fallback Instructions', 'agentos'); ?></label></th>
        <td>
          <textarea id="agentos-base-prompt" name="agent[base_prompt]" rows="5" class="large-text"><?php echo esc_textarea($agent['base_prompt']); ?></textarea>
          <p class="description"><?php esc_html_e('Used when no system prompt is provided by the mapped fields.', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Show transcript panel', 'agentos'); ?></th>
        <td>
          <label>
            <input type="checkbox" name="agent[show_transcript]" value="1" <?php checked(!empty($agent['show_transcript'])); ?>>
            <?php esc_html_e('Display the transcript view and “Save transcript” option on the front end.', 'agentos'); ?>
          </label>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Enable analysis', 'agentos'); ?></th>
        <td>
          <label>
            <input type="checkbox" name="agent[analysis_enabled]" value="1" <?php checked(!empty($agent['analysis_enabled'])); ?>>
            <?php esc_html_e('Allow this agent to run post-session AI analysis.', 'agentos'); ?>
          </label>
          <p class="description"><?php esc_html_e('When enabled, admins can trigger transcript analysis from the Sessions screen.', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-analysis-model"><?php esc_html_e('Analysis model', 'agentos'); ?></label></th>
        <td>
          <input type="text" id="agentos-analysis-model" name="agent[analysis_model]" value="<?php echo esc_attr($agent['analysis_model']); ?>" class="regular-text">
          <p class="description"><?php esc_html_e('OpenAI model used for transcript analysis (e.g. gpt-4.1-mini). Leave blank to use the default.', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-analysis-prompt"><?php esc_html_e('Analysis system prompt', 'agentos'); ?></label></th>
        <td>
          <textarea id="agentos-analysis-prompt" name="agent[analysis_system_prompt]" rows="5" class="large-text"><?php echo esc_textarea($agent['analysis_system_prompt']); ?></textarea>
          <p class="description"><?php esc_html_e('Base instructions sent to the analysis model. You can override or extend this per session when re-running an analysis.', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Auto-run analysis', 'agentos'); ?></th>
        <td>
          <label>
            <input type="checkbox" name="agent[analysis_auto_run]" value="1" <?php checked(!empty($agent['analysis_auto_run'])); ?>>
            <?php esc_html_e('Queue an analysis automatically after each transcript is saved.', 'agentos'); ?>
          </label>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-post-types"><?php esc_html_e('Allowed Post Types', 'agentos'); ?></label></th>
        <td>
          <select id="agentos-post-types" name="agent[post_types][]" multiple size="5" style="min-width:260px;">
            <?php foreach ($post_types as $slug => $obj) :
              $label = $obj->labels->singular_name ?: $slug;
              ?>
              <option value="<?php echo esc_attr($slug); ?>" <?php selected(in_array($slug, $agent['post_types'], true)); ?>>
                <?php echo esc_html($label); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="description"><?php esc_html_e('Choose the post types this agent supports. Use Ctrl/Cmd + click for multiple.', 'agentos'); ?></p>
        </td>
      </tr>
    </table>

    <h2><?php esc_html_e('Field Mappings', 'agentos'); ?></h2>
    <p><?php esc_html_e('Choose an ACF field or enter a custom meta key for each mapping. Leave empty to fall back to the defaults above.', 'agentos'); ?></p>
    <div id="agentos-field-mappings" data-field-map-target></div>
    <noscript>
      <p><em><?php esc_html_e('Enable JavaScript in your browser to configure field mappings.', 'agentos'); ?></em></p>
    </noscript>

    <?php submit_button($is_edit ? __('Update Agent', 'agentos') : __('Create Agent', 'agentos')); ?>
  </form>
</div>
