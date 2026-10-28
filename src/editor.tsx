import * as React from 'react'
import { Fragment, useState } from 'react'
import {
  registerBlockType,
  InspectorControls,
  useBlockProps,
  Button,
  Notice,
  PanelBody,
  SelectControl,
  TextControl,
  __,
} from './wordpress'
import metadata from './block.json'
import { LiveblogIcon } from './logo'

type Attributes = { liveblogId: string; mode: string }
type Settings = {
  renderOrigin: string
  defaultMode: string
  modes: { ssr: string; js: string }
  settingsUrl: string
}

declare global {
  interface Window {
    liveblogWordPress: Settings
  }
}

const settings = window.liveblogWordPress

function Edit({
  attributes,
  setAttributes,
}: {
  attributes: Attributes
  setAttributes: (attributes: Partial<Attributes>) => void
}) {
  const blockProps = useBlockProps({ className: 'liveblog-editor' })
  const [previewId, setPreviewId] = useState('')
  const [previewRevision, setPreviewRevision] = useState(0)
  const id = attributes.liveblogId
  const valid = /^[A-Za-z0-9_-]{1,128}$/.test(id)
  const mode = attributes.mode || settings.defaultMode
  const previewVisible =
    valid && previewId === id && settings.renderOrigin !== ''

  return (
    <Fragment>
      <InspectorControls>
        <PanelBody title={__('LiveblogAI settings', 'liveblog')}>
          <SelectControl
            label={__('Display mode', 'liveblog')}
            value={attributes.mode}
            options={[
              { label: __('Use site default', 'liveblog'), value: '' },
              {
                label: __('Server-rendered content', 'liveblog'),
                value: settings.modes.ssr,
              },
              {
                label: __('JavaScript embed', 'liveblog'),
                value: settings.modes.js,
              },
            ]}
            onChange={(value) => setAttributes({ mode: value })}
          />
          <p>
            {mode === settings.modes.ssr
              ? __(
                  'Includes the first posts in your page HTML, then receives live updates. This page must be excluded from full-page caching.',
                  'liveblog'
                )
              : __(
                  'Loads current coverage in the browser. Works with full-page caching and requires JavaScript.',
                  'liveblog'
                )}
          </p>
          {settings.settingsUrl && (
            <a href={settings.settingsUrl} target="_blank" rel="noreferrer">
              {__('Open LiveblogAI settings', 'liveblog')}
            </a>
          )}
        </PanelBody>
      </InspectorControls>
      <div {...blockProps}>
        <div className="liveblog-editor-heading">
          <strong>{__('LiveblogAI', 'liveblog')}</strong>
          <span>
            {mode === settings.modes.ssr
              ? __('Server-rendered', 'liveblog')
              : __('JavaScript', 'liveblog')}
          </span>
        </div>
        <TextControl
          label={__('LiveblogAI ID', 'liveblog')}
          value={id}
          placeholder="lb-0000000000000001"
          help={__(
            'Copy the liveblog ID from LiveblogAI. Content and appearance remain managed there.',
            'liveblog'
          )}
          onChange={(value) => {
            setPreviewId('')
            setAttributes({ liveblogId: value.trim() })
          }}
        />
        {id && !valid && (
          <Notice status="warning" isDismissible={false}>
            {__(
              'Use a liveblog ID with letters, numbers, underscores or hyphens, up to 128 characters.',
              'liveblog'
            )}
          </Notice>
        )}
        {!settings.renderOrigin && (
          <Notice status="error" isDismissible={false}>
            {__(
              'The Render service is not configured correctly. Contact your site administrator.',
              'liveblog'
            )}
          </Notice>
        )}
        <div className="liveblog-editor-actions">
          <Button
            variant="secondary"
            disabled={!valid || !settings.renderOrigin}
            onClick={() => {
              setPreviewId(id)
              setPreviewRevision((revision) => revision + 1)
            }}
          >
            {previewVisible
              ? __('Refresh preview', 'liveblog')
              : __('Preview LiveblogAI', 'liveblog')}
          </Button>
          {previewVisible && (
            <Button variant="tertiary" onClick={() => setPreviewId('')}>
              {__('Close preview', 'liveblog')}
            </Button>
          )}
        </div>
        <p className="liveblog-editor-help">
          {__(
            'Preview connects to LiveblogAI and shows public coverage. Preview your post to check how it fits your site.',
            'liveblog'
          )}
        </p>
        {previewVisible && (
          <iframe
            key={`${previewId}-${previewRevision}`}
            className="liveblog-editor-preview"
            src={`${settings.renderOrigin}/iframe/${encodeURIComponent(previewId)}`}
            title={__('LiveblogAI content preview', 'liveblog')}
            sandbox="allow-scripts allow-same-origin"
            referrerPolicy="no-referrer"
          />
        )}
      </div>
    </Fragment>
  )
}

registerBlockType<Attributes>(metadata.name, {
  ...metadata,
  icon: <LiveblogIcon />,
  title: __('LiveblogAI', 'liveblog'),
  description: __('Embed live coverage from LiveblogAI.', 'liveblog'),
  edit: Edit,
  save: () => null,
})
