/**
 * What the two A2UI backend screens share: the core classes the renderer adds
 * so a surface looks like the rest of the backend, and the renderer's labels
 * in the editor's language.
 */
import labels from '~labels/agent_nexus.a2ui';

export const BACKEND_CLASSES = {
  input: 'form-control',
  check: 'form-check-input',
  checkLabel: 'form-check-label',
  checkRow: 'form-check',
  label: 'form-label',
  button: 'btn',
  buttonPrimary: 'btn-primary',
  buttonDefault: 'btn-default',
  buttonBorderless: 'btn-link',
  card: 'card',
  cardBody: 'card-body',
};

export function rendererLabels() {
  const keys = ['required', 'close', 'dialog', 'invalid', 'checkFields', 'filter', 'noMedia'];
  return Object.fromEntries(keys.map((key) => [key, labels.get('js.renderer.' + key)]));
}
