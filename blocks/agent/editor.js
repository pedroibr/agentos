(function (wp) {
  const { registerBlockType } = wp.blocks;
  const { __ } = wp.i18n;
  const { Fragment, useMemo } = wp.element;
  const { InspectorControls, useBlockProps } = wp.blockEditor;
  const { PanelBody, SelectControl, TextControl, Placeholder } = wp.components;

  const data = window.AgentOSBlockData || {};
  const agents = Array.isArray(data.agents) ? data.agents : [];
  const defaults = data.defaults || {};
  const strings = data.strings || {};

  const modeOptions = [
    { label: strings.optionDefault || __('Use agent default', 'agentos'), value: '' },
    { label: strings.optionVoice || __('Voice only', 'agentos'), value: 'voice' },
    { label: strings.optionText || __('Text only', 'agentos'), value: 'text' },
    { label: strings.optionBoth || __('Voice + Text', 'agentos'), value: 'both' }
  ];

  const agentOptions = [
    { label: __('Select an agent', 'agentos'), value: '' },
    ...agents.map((agent) => ({
      label: agent.label || agent.value,
      value: agent.value
    }))
  ];

  registerBlockType('agentos/agent', {
    edit: (props) => {
      const { attributes, setAttributes } = props;
      const { agentId = '', mode = '', height = defaults.height || '70vh' } = attributes;
      const blockProps = useBlockProps({ className: 'agentos-block-editor__preview' });

      const selectedAgent = useMemo(
        () => agents.find((agent) => agent.value === agentId) || null,
        [agentId, agents]
      );

      const computedMode = mode || (selectedAgent ? selectedAgent.defaultMode : '');

      const previewLabel = selectedAgent ? selectedAgent.label || selectedAgent.value : '';
      const modeLabel = computedMode
        ? (modeOptions.find((option) => option.value === computedMode)?.label || computedMode)
        : (strings.optionDefault || __('Use agent default', 'agentos'));

      const hasAgents = agents.length > 0;

      return (
        <Fragment>
          <InspectorControls>
            <PanelBody title={strings.panelTitle || __('Agent settings', 'agentos')} initialOpen={true}>
              <SelectControl
                label={strings.fieldAgent || __('Agent', 'agentos')}
                value={agentId}
                options={agentOptions}
                onChange={(value) => setAttributes({ agentId: value })}
              />
              <SelectControl
                label={strings.fieldMode || __('Mode (optional)', 'agentos')}
                value={mode}
                options={modeOptions}
                onChange={(value) => setAttributes({ mode: value })}
              />
              <TextControl
                label={strings.fieldHeight || __('Transcript height', 'agentos')}
                help={__('Applies when the transcript panel is enabled.', 'agentos')}
                value={height}
                onChange={(value) => setAttributes({ height: value })}
                placeholder={defaults.height || '70vh'}
              />
            </PanelBody>
          </InspectorControls>

          <div {...blockProps}>
            {!hasAgents && (
              <Placeholder
                label={strings.previewHeading || __('AgentOS Block', 'agentos')}
                instructions={strings.placeholderNoAgents || __('No agents found. Create one under AgentOS → Agents.', 'agentos')}
              />
            )}

            {hasAgents && !agentId && (
              <Placeholder
                label={strings.previewHeading || __('AgentOS Block', 'agentos')}
                instructions={strings.placeholderSelect || __('Choose an AgentOS agent to embed.', 'agentos')}
              />
            )}

            {hasAgents && agentId && (
              <div className="agentos-block-preview-card">
                <h3 className="agentos-block-preview-card__title">
                  {strings.previewHeading || __('AgentOS Block', 'agentos')}
                </h3>
                <p>
                  <strong>{strings.fieldAgent || __('Agent', 'agentos')}:</strong>{' '}
                  {previewLabel || agentId}
                </p>
                <p>
                  <strong>{strings.fieldMode || __('Mode (optional)', 'agentos')}:</strong>{' '}
                  {modeLabel}
                </p>
                <p>
                  <strong>{strings.fieldHeight || __('Transcript height', 'agentos')}:</strong>{' '}
                  {height || defaults.height || '70vh'}
                </p>
              </div>
            )}
          </div>
        </Fragment>
      );
    },
    save: () => null
  });
})(window.wp);
