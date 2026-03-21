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
        <th scope="row"><label for="agentos-realtime-model"><?php esc_html_e('Realtime model', 'agentos'); ?></label></th>
        <td>
          <input type="text" id="agentos-realtime-model" name="agent[realtime_model]" value="<?php echo esc_attr($agent['realtime_model'] ?? $agent['default_model']); ?>" class="regular-text">
          <p class="description"><?php esc_html_e('Used for voice sessions and the current hybrid realtime flow.', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-text-model"><?php esc_html_e('Text model', 'agentos'); ?></label></th>
        <td>
          <input type="text" id="agentos-text-model" name="agent[text_model]" value="<?php echo esc_attr($agent['text_model'] ?? ''); ?>" class="regular-text">
          <p class="description"><?php esc_html_e('Used for text-only conversations through the OpenAI Responses API.', 'agentos'); ?></p>
        </td>
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
        <th scope="row"><?php esc_html_e('Voice advanced', 'agentos'); ?></th>
        <td>
          <fieldset>
            <label for="agentos-voice-turn-detection"><?php esc_html_e('Turn detection type', 'agentos'); ?></label><br>
            <select id="agentos-voice-turn-detection" name="agent[voice_turn_detection]">
              <option value="" <?php selected($agent['voice_turn_detection'] ?? '', ''); ?>><?php esc_html_e('Use default', 'agentos'); ?></option>
              <option value="semantic_vad" <?php selected($agent['voice_turn_detection'] ?? '', 'semantic_vad'); ?>><?php esc_html_e('semantic_vad', 'agentos'); ?></option>
              <option value="server_vad" <?php selected($agent['voice_turn_detection'] ?? '', 'server_vad'); ?>><?php esc_html_e('server_vad', 'agentos'); ?></option>
            </select>
            <p class="description"><?php esc_html_e('Controls how the agent decides that the user finished speaking. Default: semantic_vad.', 'agentos'); ?></p>

            <label for="agentos-voice-turn-eagerness"><?php esc_html_e('Turn eagerness', 'agentos'); ?></label><br>
            <select id="agentos-voice-turn-eagerness" name="agent[voice_turn_eagerness]">
              <option value="" <?php selected($agent['voice_turn_eagerness'] ?? '', ''); ?>><?php esc_html_e('Use default', 'agentos'); ?></option>
              <option value="low" <?php selected($agent['voice_turn_eagerness'] ?? '', 'low'); ?>><?php esc_html_e('low', 'agentos'); ?></option>
              <option value="medium" <?php selected($agent['voice_turn_eagerness'] ?? '', 'medium'); ?>><?php esc_html_e('medium', 'agentos'); ?></option>
              <option value="high" <?php selected($agent['voice_turn_eagerness'] ?? '', 'high'); ?>><?php esc_html_e('high', 'agentos'); ?></option>
            </select>
            <p class="description"><?php esc_html_e('Only used with semantic_vad. Higher values respond faster; lower values wait longer. Default: medium.', 'agentos'); ?></p>

            <label for="agentos-voice-noise-reduction"><?php esc_html_e('Microphone environment', 'agentos'); ?></label><br>
            <select id="agentos-voice-noise-reduction" name="agent[voice_noise_reduction]">
              <option value="" <?php selected($agent['voice_noise_reduction'] ?? '', ''); ?>><?php esc_html_e('Use default', 'agentos'); ?></option>
              <option value="near_field" <?php selected($agent['voice_noise_reduction'] ?? '', 'near_field'); ?>><?php esc_html_e('Headset / Close mic', 'agentos'); ?></option>
              <option value="far_field" <?php selected($agent['voice_noise_reduction'] ?? '', 'far_field'); ?>><?php esc_html_e('Room / Laptop', 'agentos'); ?></option>
              <option value="off" <?php selected($agent['voice_noise_reduction'] ?? '', 'off'); ?>><?php esc_html_e('Off', 'agentos'); ?></option>
            </select>
            <p class="description"><?php esc_html_e('Controls background-noise handling for voice input. Default: Headset / Close mic (near_field).', 'agentos'); ?></p>

            <label for="agentos-speech-language-hint"><?php esc_html_e('Speech language hint', 'agentos'); ?></label><br>
            <select id="agentos-speech-language-hint" name="agent[speech_language_hint]">
              <option value="" <?php selected($agent['speech_language_hint'] ?? '', ''); ?>><?php esc_html_e('Auto', 'agentos'); ?></option>
              <option value="en" <?php selected($agent['speech_language_hint'] ?? '', 'en'); ?>><?php esc_html_e('English', 'agentos'); ?></option>
              <option value="pt" <?php selected($agent['speech_language_hint'] ?? '', 'pt'); ?>><?php esc_html_e('Portuguese', 'agentos'); ?></option>
            </select>
            <p class="description"><?php esc_html_e('Optional hint for speech transcription when this agent usually speaks one language. Default: Auto.', 'agentos'); ?></p>

            <label for="agentos-transcription-hint"><?php esc_html_e('Transcription hint', 'agentos'); ?></label><br>
            <textarea id="agentos-transcription-hint" name="agent[transcription_hint]" rows="3" class="large-text"><?php echo esc_textarea($agent['transcription_hint'] ?? ''); ?></textarea>
            <p class="description"><?php esc_html_e('Optional context for the transcription model, such as keywords, lesson topic, product names, or expected accents. Prefer short keywords instead of full sentences. Default: empty.', 'agentos'); ?></p>
          </fieldset>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-context-params"><?php esc_html_e('Allowed URL context parameters', 'agentos'); ?></label></th>
        <td>
          <input type="text" id="agentos-context-params" name="agent[context_params]" value="<?php echo esc_attr(implode(',', $agent['context_params'] ?? [])); ?>" class="regular-text" style="width:420px">
          <p class="description"><?php esc_html_e('Comma separated list of query-string parameters this agent can read from the current URL.', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Show post image in sidebar', 'agentos'); ?></th>
        <td>
          <label>
            <input type="checkbox" name="agent[show_post_image]" value="1" <?php checked(!empty($agent['show_post_image'])); ?>>
            <?php esc_html_e('Display the current post featured image above the conversations list.', 'agentos'); ?>
          </label>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Show post title in sidebar', 'agentos'); ?></th>
        <td>
          <label>
            <input type="checkbox" name="agent[show_post_title]" value="1" <?php checked(!empty($agent['show_post_title'])); ?>>
            <?php esc_html_e('Display the current post title below the sidebar image or by itself when no image is shown.', 'agentos'); ?>
          </label>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-sidebar-back-url"><?php esc_html_e('Sidebar back URL', 'agentos'); ?></label></th>
        <td>
          <input type="url" id="agentos-sidebar-back-url" name="agent[sidebar_back_url]" value="<?php echo esc_attr($agent['sidebar_back_url'] ?? ''); ?>" class="regular-text" style="width:420px">
          <p class="description"><?php esc_html_e('Optional link shown at the bottom of the sidebar.', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-sidebar-back-label"><?php esc_html_e('Sidebar back label', 'agentos'); ?></label></th>
        <td>
          <input type="text" id="agentos-sidebar-back-label" name="agent[sidebar_back_label]" value="<?php echo esc_attr($agent['sidebar_back_label'] ?? ''); ?>" class="regular-text">
          <p class="description"><?php esc_html_e('Optional label for the sidebar back link. Defaults to “Go back”.', 'agentos'); ?></p>
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
        <th scope="row"><?php esc_html_e('Require subscription', 'agentos'); ?></th>
        <td>
          <label>
            <input type="checkbox" name="agent[require_subscription]" value="1" <?php checked(!empty($agent['require_subscription'])); ?>>
            <?php esc_html_e('Only visitors with an active subscription may start this agent.', 'agentos'); ?>
          </label>
          <p class="description"><?php esc_html_e('Leave unchecked to allow anyone to use the agent (limits may still apply via per-session caps).', 'agentos'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="agentos-session-cap"><?php esc_html_e('Session token cap', 'agentos'); ?></label></th>
        <td>
          <input type="number" id="agentos-session-cap" name="agent[session_token_cap]" value="<?php echo esc_attr((int) ($agent['session_token_cap'] ?? 0)); ?>" class="small-text" min="0">
          <p class="description"><?php esc_html_e('Maximum tokens allowed for a single session. Set to 0 to leave uncapped.', 'agentos'); ?></p>
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
