/**
 * Protocol diagrams in the protocol info plugin.
 *
 * Each diagram is a self-contained Archify HTML file (Resources/Public/Diagrams)
 * shown in an iframe. This script switches the frame to Archify's embed mode,
 * keeps its theme in step with the page (desiderio sets `.dark` on <html> from
 * the stored choice or the system setting) and sizes the frame to the diagram,
 * so the page scrolls instead of the frame. The frame is same-origin, which is
 * what lets the page read its height.
 */

const pageTheme = () => (document.documentElement.classList.contains('dark') ? 'dark' : 'light');

const withParams = (base, params) => {
  const url = new URL(base, window.location.href);
  for (const [key, value] of Object.entries(params)) url.searchParams.set(key, value);
  return url.toString();
};

function setUp(figure) {
  const frame = figure.querySelector('[data-anx-diagram-frame]');
  const open = figure.querySelector('[data-anx-diagram-open]');
  const base = frame?.dataset.anxDiagramSrc;
  if (!frame || !base) return;

  let observer = null;
  const fit = () => {
    const body = frame.contentDocument?.body;
    if (!body) return;
    const height = Math.ceil(body.getBoundingClientRect().height);
    if (height > 0) frame.style.height = `${height}px`;
  };

  frame.addEventListener('load', () => {
    observer?.disconnect();
    const body = frame.contentDocument?.body;
    if (!body) return;
    fit();
    observer = new ResizeObserver(fit);
    observer.observe(body);
  });

  const show = () => {
    const theme = pageTheme();
    if (frame.dataset.anxDiagramTheme === theme) return;
    frame.dataset.anxDiagramTheme = theme;
    frame.src = withParams(base, { embed: '1', theme });
    if (open) open.href = withParams(base, { theme });
  };

  show();
  new MutationObserver(show).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
}

document.querySelectorAll('[data-anx-diagram]').forEach(setUp);
