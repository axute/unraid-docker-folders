/**
 * A service widget: the one-line summary of a service on its container's
 * card, read from the container's gethomepage labels (`homepage.widget.*`).
 * Must match what `ServiceWidgets::forContainers()` returns.
 */

/** How a field's value is shown. `text` is a value the service preformatted. */
export type ServiceWidgetFormat = 'number' | 'bytes' | 'bitrate' | 'ms' | 'text';

export interface ServiceWidgetField {
  key: string;
  label: string;
  value: number | string;
  format: ServiceWidgetFormat;
}

export interface ServiceWidget {
  type: string;
  fields?: ServiceWidgetField[];
  /** Set when the widget has no values: a code, and a message for the user. */
  error?: string;
  message?: string;
  /** The base URL the server called, without the key. */
  target?: string;
}

/** The label that marks a container as having a widget. */
export const SERVICE_WIDGET_TYPE_LABEL = 'homepage.widget.type';
