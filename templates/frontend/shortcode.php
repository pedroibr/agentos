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
  data-show-transcript="<?php echo $transcriptEnabled ? '1' : '0'; ?>">
  <div class="agentos-left">
    <div class="agentos-bar">
      <button class="agentos-start">🎙️ <?php esc_html_e('Start', 'agentos'); ?></button>
      <button class="agentos-stop" disabled>⏹️ <?php esc_html_e('Stop', 'agentos'); ?></button>
      <?php if ($transcriptEnabled) : ?>
        <button class="agentos-save" disabled>💾 <?php esc_html_e('Save transcript', 'agentos'); ?></button>
      <?php endif; ?>
      <small class="agentos-status" style="margin-left:auto"></small>
    </div>
    <audio class="agentos-audio" autoplay playsinline></audio>
    <div class="agentos-text-ui" style="margin-top:10px;display:none">
      <textarea class="agentos-text-input" rows="2" style="width:100%" placeholder="<?php esc_attr_e('Type a message and press Send', 'agentos'); ?>"></textarea>
      <button class="agentos-text-send" style="margin-top:6px"><?php esc_html_e('Send', 'agentos'); ?></button>
    </div>
  </div>

  <?php if ($transcriptEnabled) : ?>
    <div class="agentos-right" style="width:360px;max-width:40vw">
      <div class="agentos-transcript" style="border:1px solid #e9e9e9;border-radius:12px;padding:12px;height:<?php echo esc_attr($heightValue); ?>;overflow:auto;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.03);font:14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Arial">
        <h4 style="margin:0 0 8px;font-size:12px;opacity:.7;font-weight:600"><?php esc_html_e('Transcript (live)', 'agentos'); ?></h4>
        <div class="agentos-transcript-log"></div>
      </div>
    </div>
  <?php endif; ?>
</div>
