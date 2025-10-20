<?php
/**
 * Plugin Name: AgentOS – Dynamic AI Agents for WordPress
 * Description: Load customizable AI agents (text/voice) per post type. Map ACF/meta fields to agent parameters. Provides shortcodes and REST endpoints.
 * Version: 0.1.0
 * Author: Pedro Raimundo
 * Text Domain: agentos
 */

if (!defined('ABSPATH')) exit;

class AgentOS_Plugin {
  const OPT_KEY = 'agentos_settings'; // stored as array
  const SLUG    = 'agentos';

  public function __construct() {
    // Admin
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'register_settings']);

    // Front
    add_action('init', [$this, 'register_shortcodes']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    // REST
    add_action('rest_api_init', [$this, 'register_routes']);

    // (Optional) DB: create table on activation if you want transcripts server-side
    register_activation_hook(__FILE__, [$this, 'maybe_create_transcript_table']);
  }

  /* -----------------------------
   * Settings & Admin UI
   * --------------------------- */
  public function admin_menu() {
    add_options_page('AgentOS', 'AgentOS', 'manage_options', self::SLUG, [$this, 'render_settings_page']);
  }

  public function register_settings() {
    register_setting(self::OPT_KEY, self::OPT_KEY, [
      'type' => 'array',
      'sanitize_callback' => [$this, 'sanitize_settings'],
      'default' => [
        'api_key_source' => 'constant', // 'env' | 'constant' | 'manual'
        'api_key_manual' => '',
        'default_model'  => 'gpt-realtime-mini-2025-10-06',
        'default_voice'  => 'alloy',
        'default_mode'   => 'voice', // voice | text | both
        'post_types'     => ['post'],
        // Field map per post type:
        // Each entry can map to ACF field name or plain meta key.
        // Keys: model, voice, system_prompt, user_prompt, lesson_title, lesson_story, lesson_questions
        'field_maps' => [
          'post' => [
            'model'          => 'agent_voice_model',
            'voice'          => 'agent_voice_voice',
            'system_prompt'  => 'agent_voice_instructions',
            'user_prompt'    => '', // optional
            'lesson_title'   => 'agent_story_title',
            'lesson_story'   => 'agent_story',
            'lesson_questions' => 'agent_questions',
          ],
        ],
        'context_params' => ['nome','produto','etapa'], // query-string keys passed to the agent
      ]
    ]);
  }

  public function sanitize_settings($input) {
    $out = is_array($input) ? $input : [];
    $out['api_key_source']  = in_array(($out['api_key_source'] ?? 'constant'), ['env','constant','manual']) ? $out['api_key_source'] : 'constant';
    $out['api_key_manual']  = sanitize_text_field($out['api_key_manual'] ?? '');
    $out['default_model']   = sanitize_text_field($out['default_model'] ?? 'gpt-realtime-mini-2025-10-06');
    $out['default_voice']   = sanitize_text_field($out['default_voice'] ?? 'alloy');
    $out['default_mode']    = in_array(($out['default_mode'] ?? 'voice'), ['voice','text','both']) ? $out['default_mode'] : 'voice';

    // post types
    $pts = $out['post_types'] ?? ['post'];
    $out['post_types'] = array_values(array_filter(array_map('sanitize_text_field', (array)$pts)));

    // field maps
    $fm = $out['field_maps'] ?? [];
    $clean = [];
    foreach ((array)$fm as $pt => $map) {
      $ptc = sanitize_text_field($pt);
      $clean[$ptc] = [
        'model' => sanitize_text_field($map['model'] ?? ''),
        'voice' => sanitize_text_field($map['voice'] ?? ''),
        'system_prompt' => sanitize_text_field($map['system_prompt'] ?? ''),
        'user_prompt' => sanitize_text_field($map['user_prompt'] ?? ''),
        'lesson_title' => sanitize_text_field($map['lesson_title'] ?? ''),
        'lesson_story' => sanitize_text_field($map['lesson_story'] ?? ''),
        'lesson_questions' => sanitize_text_field($map['lesson_questions'] ?? ''),
      ];
    }
    $out['field_maps'] = $clean;

    // context params
    $ctx = $out['context_params'] ?? ['nome','produto','etapa'];
    if (is_string($ctx)) {
      $ctx = array_map('trim', explode(',', $ctx));
    }
    $ctx = (array)$ctx;
    $out['context_params'] = array_values(array_filter(array_map(function($k){
      $san = preg_replace('/[^a-z0-9_\-]/i','', $k);
      return $san;
    }, $ctx)));

    return $out;
  }

  private function get_settings() {
    return get_option(self::OPT_KEY, []);
  }

  private function resolve_api_key() {
    $s = $this->get_settings();
    $src = $s['api_key_source'] ?? 'constant';
    if ($src === 'env') {
      return getenv('OPENAI_API_KEY') ?: '';
    } elseif ($src === 'manual') {
      return $s['api_key_manual'] ?? '';
    }
    // constant
    return defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
  }

  public function render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $s = $this->get_settings();
    $pts = get_post_types(['public' => true], 'names');
    ?>
    <div class="wrap">
      <h1>AgentOS</h1>
      <form method="post" action="options.php">
        <?php settings_fields(self::OPT_KEY); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">OpenAI API Key Source</th>
            <td>
              <label><input type="radio" name="<?= self::OPT_KEY ?>[api_key_source]" value="env" <?= ($s['api_key_source']??'')==='env'?'checked':''?>> ENV (OPENAI_API_KEY)</label><br>
              <label><input type="radio" name="<?= self::OPT_KEY ?>[api_key_source]" value="constant" <?= ($s['api_key_source']??'constant')==='constant'?'checked':''?>> PHP constant (OPENAI_API_KEY)</label><br>
              <label><input type="radio" name="<?= self::OPT_KEY ?>[api_key_source]" value="manual" <?= ($s['api_key_source']??'')==='manual'?'checked':''?>> Manual</label>
              <p><input type="text" style="width:420px" name="<?= self::OPT_KEY ?>[api_key_manual]" value="<?= esc_attr($s['api_key_manual']??''); ?>" placeholder="sk-..." /></p>
            </td>
          </tr>
          <tr>
            <th scope="row">Defaults</th>
            <td>
              <p>Default Model: <input type="text" name="<?= self::OPT_KEY ?>[default_model]" value="<?= esc_attr($s['default_model']??'gpt-realtime-mini-2025-10-06'); ?>"></p>
              <p>Default Voice: <input type="text" name="<?= self::OPT_KEY ?>[default_voice]" value="<?= esc_attr($s['default_voice']??'alloy'); ?>"></p>
              <p>Default Mode:
                <select name="<?= self::OPT_KEY ?>[default_mode]">
                  <?php foreach (['voice'=>'Voice only','text'=>'Text only','both'=>'Voice + Text'] as $k=>$lab): ?>
                    <option value="<?= $k ?>" <?= ($s['default_mode']??'voice')===$k?'selected':'' ?>><?= esc_html($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </p>
              <p>Context params (query-string, comma separated):<br>
                <input type="text" style="width:420px" name="<?= self::OPT_KEY ?>[context_params]" value="<?= esc_attr(implode(',', $s['context_params']??['nome','produto','etapa'])); ?>">
              </p>
            </td>
          </tr>
          <tr>
            <th scope="row">Post Types</th>
            <td>
              <?php foreach ($pts as $pt): ?>
                <label style="display:inline-block;margin-right:12px">
                  <input type="checkbox" name="<?= self::OPT_KEY ?>[post_types][]" value="<?= esc_attr($pt) ?>" <?= in_array($pt, $s['post_types']??[])?'checked':''; ?>> <?= esc_html($pt) ?>
                </label>
              <?php endforeach; ?>
              <p class="description">Pick where agents will be available.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Field Maps (per post type)</th>
            <td>
              <?php foreach (($s['post_types']??[]) as $pt): 
                $m = $s['field_maps'][$pt] ?? [];
              ?>
                <fieldset style="border:1px solid #ddd;padding:10px;margin:10px 0;border-radius:8px">
                  <legend><strong><?= esc_html($pt) ?></strong></legend>
                  <p>Model field: <input type="text" name="<?= self::OPT_KEY ?>[field_maps][<?= esc_attr($pt) ?>][model]" value="<?= esc_attr($m['model']??''); ?>" placeholder="agent_voice_model"></p>
                  <p>Voice field: <input type="text" name="<?= self::OPT_KEY ?>[field_maps][<?= esc_attr($pt) ?>][voice]" value="<?= esc_attr($m['voice']??''); ?>" placeholder="agent_voice_voice"></p>
                  <p>System Prompt field: <input type="text" name="<?= self::OPT_KEY ?>[field_maps][<?= esc_attr($pt) ?>][system_prompt]" value="<?= esc_attr($m['system_prompt']??''); ?>"></p>
                  <p>User Prompt field: <input type="text" name="<?= self::OPT_KEY ?>[field_maps][<?= esc_attr($pt) ?>][user_prompt]" value="<?= esc_attr($m['user_prompt']??''); ?>"></p>
                  <p>Lesson Title field: <input type="text" name="<?= self::OPT_KEY ?>[field_maps][<?= esc_attr($pt) ?>][lesson_title]" value="<?= esc_attr($m['lesson_title']??''); ?>"></p>
                  <p>Lesson Story field: <input type="text" name="<?= self::OPT_KEY ?>[field_maps][<?= esc_attr($pt) ?>][lesson_story]" value="<?= esc_attr($m['lesson_story']??''); ?>"></p>
                  <p>Lesson Questions field: <input type="text" name="<?= self::OPT_KEY ?>[field_maps][<?= esc_attr($pt) ?>][lesson_questions]" value="<?= esc_attr($m['lesson_questions']??''); ?>"></p>
                  <p class="description">You can map to ACF field name or plain meta key. Leave blank to use defaults.</p>
                </fieldset>
              <?php endforeach; ?>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }

  /* -----------------------------
   * Assets
   * --------------------------- */
  public function enqueue_assets() {
    // only enqueue on single posts for configured post types
    if (!is_singular()) return;
    $s = $this->get_settings();
    $pt = get_post_type(get_the_ID());
    if (!in_array($pt, $s['post_types'] ?? [])) return;

    wp_register_script('agentos-embed', plugins_url('assets/agentos-embed.js', __FILE__), [], '0.1.0', true);
    wp_enqueue_script('agentos-embed');

    wp_localize_script('agentos-embed', 'AgentOSCfg', [
      'rest' => rest_url('agentos/v1'),
      'post_id' => get_the_ID(),
      'default_mode' => $s['default_mode'] ?? 'voice',
      'context_params' => $s['context_params'] ?? ['nome','produto','etapa'],
      'nonce' => wp_create_nonce('wp_rest'),
    ]);
  }

  /* -----------------------------
   * Shortcodes
   * --------------------------- */
  public function register_shortcodes() {
    add_shortcode('agentos', [$this, 'shortcode_agentos']);
  }

  public function shortcode_agentos($atts) {
    $atts = shortcode_atts([
      'mode' => '',  // voice | text | both (overrides default)
      'theme' => 'light',
      'height' => '70vh'
    ], $atts, 'agentos');

    ob_start(); ?>
    <div class="agentos-wrap" data-mode="<?php echo esc_attr($atts['mode']); ?>" data-height="<?php echo esc_attr($atts['height']); ?>">
      <div class="agentos-left">
        <div class="agentos-bar">
          <button class="agentos-start">🎙️ Start</button>
          <button class="agentos-stop" disabled>⏹️ Stop</button>
          <button class="agentos-save" disabled>💾 Save transcript</button>
          <small class="agentos-status" style="margin-left:auto"></small>
        </div>
        <audio class="agentos-audio" autoplay playsinline></audio>
        <div class="agentos-text-ui" style="margin-top:10px;display:none">
          <textarea class="agentos-text-input" rows="2" style="width:100%" placeholder="Type a message and press Send"></textarea>
          <button class="agentos-text-send" style="margin-top:6px">Send</button>
        </div>
      </div>

      <div class="agentos-right" style="width:360px;max-width:40vw">
        <div class="agentos-transcript" style="border:1px solid #e9e9e9;border-radius:12px;padding:12px;height:<?php echo esc_attr($atts['height']); ?>;overflow:auto;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.03);font:14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Arial">
          <h4 style="margin:0 0 8px;font-size:12px;opacity:.7;font-weight:600">Transcript (live)</h4>
          <div class="agentos-transcript-log"></div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  /* -----------------------------
   * REST API
   * --------------------------- */
  public function register_routes() {
    register_rest_route('agentos/v1', '/realtime-token', [
      'methods' => 'POST',
      'permission_callback' => [$this, 'permission_nonce'],
      'callback' => [$this, 'route_realtime_token']
    ]);

    register_rest_route('agentos/v1', '/transcript-db', [
      'methods' => WP_REST_Server::CREATABLE,
      'permission_callback' => [$this, 'permission_nonce'],
      'callback' => [$this, 'route_transcript_save']
    ]);

    register_rest_route('agentos/v1', '/transcript-db', [
      'methods' => WP_REST_Server::READABLE,
      'permission_callback' => [$this, 'permission_transcript_list'],
      'callback' => [$this, 'route_transcript_list']
    ]);
  }

  private function get_field_val($post_id, $key) {
    if (!$key) return '';
    // prefer ACF if present
    if (function_exists('get_field')) {
      $v = get_field($key, $post_id);
      if ($v !== null && $v !== '') return $v;
    }
    $v = get_post_meta($post_id, $key, true);
    return $v ?: '';
  }

  private function collect_post_config($post_id) {
    $s = $this->get_settings();
    $pt = get_post_type($post_id);
    $map = $s['field_maps'][$pt] ?? [];

    $model  = $this->get_field_val($post_id, $map['model'] ?? '') ?: ($s['default_model'] ?? 'gpt-realtime-mini-2025-10-06');
    $voice  = $this->get_field_val($post_id, $map['voice'] ?? '') ?: ($s['default_voice'] ?? 'alloy');
    $sys    = $this->get_field_val($post_id, $map['system_prompt'] ?? '');
    $userp  = $this->get_field_val($post_id, $map['user_prompt'] ?? '');

    $title  = wp_strip_all_tags($this->get_field_val($post_id, $map['lesson_title'] ?? ''));
    $story  = wp_strip_all_tags($this->get_field_val($post_id, $map['lesson_story'] ?? ''));
    $qsRaw  = $this->get_field_val($post_id, $map['lesson_questions'] ?? '');
    $questions = [];
    if ($qsRaw) {
      foreach (preg_split('/\r\n|\r|\n/',$qsRaw) as $line) {
        $line = trim(wp_strip_all_tags($line));
        if ($line!=='') $questions[] = $line;
      }
    }

    return compact('model','voice','sys','userp','title','story','questions');
  }

  private function check_rest_nonce(WP_REST_Request $request) {
    $nonce = $request->get_header('X-WP-Nonce');
    if (!$nonce) {
      $nonce = $request->get_param('_wpnonce');
    }
    if (!$nonce) {
      return false;
    }
    $nonce = sanitize_text_field($nonce);
    return (bool) wp_verify_nonce($nonce, 'wp_rest');
  }

  public function permission_nonce(WP_REST_Request $request) {
    if ($this->check_rest_nonce($request)) {
      return true;
    }
    return new WP_Error('rest_forbidden', __('Invalid or missing security nonce.', 'agentos'), [
      'status' => rest_authorization_required_code(),
    ]);
  }

  public function permission_transcript_list(WP_REST_Request $request) {
    if (!$this->check_rest_nonce($request)) {
      return new WP_Error('rest_forbidden', __('Invalid or missing security nonce.', 'agentos'), [
        'status' => rest_authorization_required_code(),
      ]);
    }
    if (!current_user_can('manage_options')) {
      return new WP_Error('rest_forbidden', __('You do not have permission to view transcripts.', 'agentos'), [
        'status' => 403,
      ]);
    }
    return true;
  }

  public function route_realtime_token(WP_REST_Request $req) {
    $post_id = intval($req->get_param('post_id') ?: 0);
    if (!$post_id) return new WP_Error('no_post', 'post_id required', ['status'=>400]);

    $cfg = $this->collect_post_config($post_id);
    $api_key = $this->resolve_api_key();
    if (!$api_key) return new WP_Error('no_key', 'OpenAI key not configured', ['status'=>500]);

    $s = $this->get_settings();
    $ctx_in = $req->get_param('ctx');
    $ctxPairs = [];
    if (is_array($ctx_in)) {
      $allowed = $s['context_params'] ?? [];
      foreach ($ctx_in as $k=>$v) {
        if (!in_array($k, $allowed)) continue;
        $kk = preg_replace('/[^a-z0-9_\-]/i','', $k);
        $vv = sanitize_text_field($v);
        $ctxPairs[] = "$kk=$vv";
      }
    }

    $instructions = $cfg['sys'] ?: "You are a helpful, concise AI agent. Speak naturally. Use Portuguese if the user does.";
    if ($ctxPairs) $instructions .= "\n\nContext: " . implode(', ', $ctxPairs) . ".";

    $payload = [
      'model' => $cfg['model'],
      'voice' => $cfg['voice'],
      'modalities' => ['audio','text'],
      'instructions' => $instructions
    ];

    $resp = wp_remote_post('https://api.openai.com/v1/realtime/sessions', [
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type'  => 'application/json'
      ],
      'body' => wp_json_encode($payload),
      'timeout' => 25
    ]);
    if (is_wp_error($resp)) return new WP_Error('openai_transport', $resp->get_error_message(), ['status'=>500]);

    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);
    if ($code >= 400 || !is_array($json)) {
      return new WP_Error('openai_http', $body ?: 'Unknown error', ['status' => $code ?: 500]);
    }

    $client_secret = $json['client_secret']['value'] ?? null;
    if (!$client_secret) return new WP_Error('openai_no_secret', 'Ephemeral token missing', ['status'=>500]);

    return [
      'client_secret' => $client_secret,
      'id' => $json['id'] ?? null,
      'model' => $cfg['model'],
      'voice' => $cfg['voice'],
      'user_prompt' => $cfg['userp'],
      'lesson' => [
        'title' => $cfg['title'],
        'story' => $cfg['story'],
        'questions' => $cfg['questions']
      ]
    ];
  }

  /* -----------------------------
   * Transcript DB (optional)
   * --------------------------- */
  public function maybe_create_transcript_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'agentos_transcripts';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      post_id BIGINT UNSIGNED NOT NULL,
      session_id VARCHAR(64) NOT NULL,
      anon_id VARCHAR(64) DEFAULT '',
      model VARCHAR(128) DEFAULT '',
      voice VARCHAR(64) DEFAULT '',
      user_agent TEXT,
      transcript LONGTEXT,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY post_id (post_id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
  }

  public function route_transcript_save(WP_REST_Request $req) {
    global $wpdb;
    $table = $wpdb->prefix . 'agentos_transcripts';

    $post_id    = intval($req->get_param('post_id') ?: 0);
    $session_id = sanitize_text_field($req->get_param('session_id') ?: '');
    $anon_id    = sanitize_text_field($req->get_param('anon_id') ?: '');
    $model      = sanitize_text_field($req->get_param('model') ?: '');
    $voice      = sanitize_text_field($req->get_param('voice') ?: '');
    $ua         = $req->get_param('user_agent') ?: '';
    $transcript = $req->get_param('transcript');

    if (!$post_id || !$session_id || !is_array($transcript)) {
      return new WP_Error('bad_payload', 'Missing fields', ['status'=>400]);
    }
    $ok = $wpdb->insert($table, [
      'post_id' => $post_id,
      'session_id' => $session_id,
      'anon_id' => $anon_id,
      'model' => $model,
      'voice' => $voice,
      'user_agent' => maybe_serialize($ua),
      'transcript' => wp_json_encode($transcript),
      'created_at' => current_time('mysql'),
    ]);
    if (!$ok) return new WP_Error('db_insert', 'DB insert failed', ['status'=>500]);

    return ['id' => intval($wpdb->insert_id)];
  }

  public function route_transcript_list(WP_REST_Request $req) {
    global $wpdb;
    $table = $wpdb->prefix . 'agentos_transcripts';
    $post_id = intval($req->get_param('post_id') ?: 0);
    $limit   = min(100, max(1, intval($req->get_param('limit') ?: 10)));
    if (!$post_id) return new WP_Error('no_post', 'post_id required', ['status'=>400]);
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE post_id=%d ORDER BY id DESC LIMIT %d", $post_id, $limit), ARRAY_A);
    return array_map(function($r){
      $r['transcript'] = json_decode($r['transcript'], true);
      return $r;
    }, $rows ?: []);
  }
}

new AgentOS_Plugin();
