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
  data-feedback-mode="inline"
  data-session-state="idle"
  style="--agentos-transcript-height: <?php echo esc_attr($heightValue); ?>;">
  <div class="agentos-shell">
    <aside
      id="agentos-sidebar-<?php echo esc_attr($agentData['slug']); ?>"
      class="agentos-sidebar"
      aria-label="<?php esc_attr_e('Saved conversations', 'agentos'); ?>">
      <div class="agentos-sidebar__header">
        <div class="agentos-sidebar__topbar">
          <div>
            <div class="agentos-sidebar__eyebrow"><?php esc_html_e('Chats', 'agentos'); ?></div>
            <h2 class="agentos-sidebar__title"><?php esc_html_e('Conversations', 'agentos'); ?></h2>
          </div>
          <button
            class="agentos-sidebar__dismiss"
            type="button"
            aria-label="<?php esc_attr_e('Hide conversations', 'agentos'); ?>">
            <span class="agentos-sidebar__dismiss-icon" aria-hidden="true"></span>
          </button>
        </div>
        <button class="agentos-sidebar__new" type="button">
          <?php esc_html_e('New chat', 'agentos'); ?>
        </button>
      </div>
      <div class="agentos-session-list" role="list">
        <p class="agentos-session-list__empty"><?php esc_html_e('Saved sessions will appear here.', 'agentos'); ?></p>
      </div>
    </aside>
    <button
      class="agentos-sidebar-backdrop"
      type="button"
      aria-label="<?php esc_attr_e('Close conversations panel', 'agentos'); ?>"></button>

    <div class="agentos-workspace">
      <div class="agentos-voice-stage">
        <div class="agentos-voice-stage__header">
          <div class="agentos-workspace__inner agentos-workspace__inner--header">
            <div class="agentos-voice-stage__lead">
              <button
                class="agentos-sidebar-toggle"
                type="button"
                aria-expanded="true"
                aria-controls="agentos-sidebar-<?php echo esc_attr($agentData['slug']); ?>"
                aria-label="<?php esc_attr_e('Toggle conversations sidebar', 'agentos'); ?>">
                <span class="agentos-sidebar-toggle__icon" aria-hidden="true"></span>
              </button>
              <div>
                <div class="agentos-voice-stage__eyebrow"><?php esc_html_e('Agent', 'agentos'); ?></div>
                <h2 class="agentos-voice-stage__workspace-title"><?php esc_html_e('Current session', 'agentos'); ?></h2>
              </div>
            </div>
            <div class="agentos-session-meta">
              <div class="agentos-session-meta__label"><?php esc_html_e('Session details', 'agentos'); ?></div>
              <div class="agentos-session-meta__value agentos-session-meta__summary"><?php esc_html_e('Current live session', 'agentos'); ?></div>
            </div>
          </div>
        </div>

        <div class="agentos-workspace__body">
          <div class="agentos-workspace__main">
            <div class="agentos-workspace__inner agentos-workspace__inner--main">
              <?php if ($transcriptEnabled) : ?>
                <div class="agentos-transcript" role="region" aria-live="polite" aria-label="<?php esc_attr_e('Conversation transcript', 'agentos'); ?>">
                  <div class="agentos-transcript__header">
                    <span class="agentos-transcript__title"><?php esc_html_e('Conversation', 'agentos'); ?></span>
                    <span class="agentos-transcript__status"><?php esc_html_e('Live', 'agentos'); ?></span>
                  </div>
                  <div class="agentos-transcript-log"></div>
                  <div class="agentos-transcript__hint">
                    <p class="agentos-transcript__hint-text"><?php esc_html_e('Save a session to review transcript analysis here.', 'agentos'); ?></p>
                  </div>
                </div>
              <?php endif; ?>

              <div class="agentos-feedback" aria-live="polite">
                <h3 class="agentos-feedback__title"><?php esc_html_e('Feedback', 'agentos'); ?></h3>
                <div class="agentos-feedback__content">
                  <p class="agentos-feedback__placeholder"><?php esc_html_e('Save a session to review transcript analysis here.', 'agentos'); ?></p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <audio class="agentos-audio" autoplay playsinline></audio>

        <div class="agentos-toolbar agentos-bar">
          <div class="agentos-workspace__inner agentos-workspace__inner--toolbar">
            <div class="agentos-toolbar__header">
              <span class="agentos-status" aria-live="polite"></span>
              <div class="agentos-toolbar__group">
                <button class="agentos-btn agentos-btn--primary agentos-start">
                  <span class="agentos-btn__icon" aria-hidden="true"></span>
                  <span><?php esc_html_e('Start voice', 'agentos'); ?></span>
                </button>
                <button class="agentos-btn agentos-btn--ghost agentos-stop" disabled>
                  <span class="agentos-btn__icon agentos-btn__icon--stop" aria-hidden="true"></span>
                  <span><?php esc_html_e('Stop', 'agentos'); ?></span>
                </button>
                <?php if ($transcriptEnabled) : ?>
                  <button class="agentos-btn agentos-btn--ghost agentos-save" disabled>
                    <span class="agentos-btn__icon agentos-btn__icon--save" aria-hidden="true"></span>
                    <span><?php esc_html_e('Save', 'agentos'); ?></span>
                  </button>
                <?php endif; ?>
              </div>
            </div>

            <div class="agentos-text-ui">
              <label class="screen-reader-text" for="agentos-text-input-<?php echo esc_attr($agentData['slug']); ?>">
                <?php esc_html_e('Send a message to the agent', 'agentos'); ?>
              </label>
              <textarea
                id="agentos-text-input-<?php echo esc_attr($agentData['slug']); ?>"
                class="agentos-text-input"
                placeholder="<?php esc_attr_e('Message AgentOS', 'agentos'); ?>"
                rows="3"></textarea>
              <div class="agentos-composer__actions">
                <button class="agentos-btn agentos-btn--accent agentos-text-send">
                  <?php esc_html_e('Send', 'agentos'); ?>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
