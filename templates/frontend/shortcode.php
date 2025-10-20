<?php
/**
 * Frontend shortcode template.
 *
 * @var array  $agentData
 * @var string $modeValue
 * @var string $heightValue
 * @var string $configAttr
 */
?>
<div class="agentos-wrap"
  data-agent="<?php echo esc_attr($agentData['slug']); ?>"
  data-mode="<?php echo esc_attr($modeValue); ?>"
  data-height="<?php echo esc_attr($heightValue); ?>"
  data-config="<?php echo $configAttr; ?>"
  data-show-transcript="<?php echo $transcriptEnabled ? '1' : '0'; ?>"
  style="--agentos-transcript-height: <?php echo esc_attr($heightValue); ?>;">
  <div class="agentos-shell">
    <div class="agentos-pane agentos-pane--controls">
      <div class="agentos-toolbar agentos-bar">
        <div class="agentos-toolbar__group">
          <button class="agentos-btn agentos-btn--primary agentos-start">🎙️ <?php esc_html_e('Start', 'agentos'); ?></button>
          <button class="agentos-btn agentos-btn--ghost agentos-stop" disabled>⏹️ <?php esc_html_e('Stop', 'agentos'); ?></button>
          <?php if ($transcriptEnabled) : ?>
            <button class="agentos-btn agentos-btn--ghost agentos-save" disabled>💾 <?php esc_html_e('Save transcript', 'agentos'); ?></button>
          <?php endif; ?>
        </div>
        <span class="agentos-status" aria-live="polite"></span>
      </div>

      <audio class="agentos-audio" autoplay playsinline></audio>

      <div class="agentos-text-ui">
        <label class="screen-reader-text" for="agentos-text-input-<?php echo esc_attr($agentData['slug']); ?>">
          <?php esc_html_e('Send a message to the agent', 'agentos'); ?>
        </label>
        <textarea
          id="agentos-text-input-<?php echo esc_attr($agentData['slug']); ?>"
          class="agentos-text-input"
          placeholder="<?php esc_attr_e('Type a message and press Send', 'agentos'); ?>"
          rows="3"></textarea>
        <div class="agentos-composer__actions">
          <button class="agentos-btn agentos-btn--accent agentos-text-send">
            <?php esc_html_e('Send', 'agentos'); ?>
          </button>
        </div>
      </div>
    </div>

    <?php if ($transcriptEnabled) : ?>
      <div class="agentos-pane agentos-pane--transcript">
        <div class="agentos-transcript" role="region" aria-live="polite" aria-label="<?php esc_attr_e('Live transcript', 'agentos'); ?>">
          <div class="agentos-transcript__header">
            <span><?php esc_html_e('Transcript', 'agentos'); ?></span>
            <span class="agentos-transcript__status"><?php esc_html_e('Live', 'agentos'); ?></span>
          </div>
          <div class="agentos-transcript-log"></div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
