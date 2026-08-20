/**
 * On-screen narration for a watched run.
 *
 * A headed run shows what the suite does but never why, so a person watching it
 * sees a cursor move and has to already know what was being proved. These captions
 * put the intent on the screen next to the thing being checked, which is what makes
 * a run watchable by someone who did not write the tests.
 *
 * The caption lives in a CLOSED shadow root, deliberately. Playwright's text
 * locators pierce open shadow roots, so an open one would put the narration inside
 * the reach of getByText — a caption mentioning "Refresh" or "ago" would then match
 * assertions meant for the page and quietly make them pass. Closed keeps the two
 * apart: the words are visible to the human and invisible to the test.
 */

/** The last caption shown on each page, so a navigation can restore it. */
const lastCaption = new WeakMap();

/**
 * Runs inside the browser. Kept self-contained: it is serialised across, so it
 * cannot close over anything in this file.
 */
function paint({ message, step, total, tone }) {
    const HOST_ID = '__qa_narration_host__';

    let host = document.getElementById(HOST_ID);
    let root = window.__qaNarrationRoot;

    if (!host || !root) {
        host = document.createElement('div');
        host.id = HOST_ID;
        host.style.cssText = [
            'position:fixed', 'left:0', 'right:0', 'bottom:0',
            'z-index:2147483647', 'pointer-events:none',
        ].join(';');

        // Closed: invisible to the test's own locators. See the note above.
        root = host.attachShadow({ mode: 'closed' });
        window.__qaNarrationRoot = root;

        root.innerHTML = `
            <style>
                .bar {
                    font: 600 17px/1.45 ui-sans-serif, system-ui, "Segoe UI", sans-serif;
                    color: #f8fafc;
                    background: linear-gradient(180deg, rgba(15,23,42,.94), rgba(2,6,23,.98));
                    border-top: 3px solid #38bdf8;
                    padding: 14px 22px;
                    display: flex; align-items: center; gap: 14px;
                    box-shadow: 0 -12px 34px rgba(2,6,23,.4);
                }
                .bar[data-tone="check"] { border-top-color: #34d399; }
                .bar[data-tone="warn"]  { border-top-color: #fbbf24; }
                .step {
                    flex: none; font-variant-numeric: tabular-nums; font-weight: 800;
                    font-size: 12px; letter-spacing: .12em; text-transform: uppercase;
                    color: #0f172a; background: #38bdf8;
                    padding: 5px 11px; border-radius: 999px;
                }
                .bar[data-tone="check"] .step { background: #34d399; }
                .bar[data-tone="warn"]  .step { background: #fbbf24; }
                .text { flex: 1 1 auto; }
            </style>
            <div class="bar"><span class="step"></span><span class="text"></span></div>
        `;

        document.documentElement.appendChild(host);
    }

    root.querySelector('.bar').dataset.tone = tone;
    root.querySelector('.step').textContent = total ? `${step} / ${total}` : String(step);
    root.querySelector('.text').textContent = message;
}

/**
 * A narrator bound to one page.
 *
 * `hold` is what makes it readable: without a pause the caption is replaced before
 * anyone has read it, and the run looks the same as an unnarrated one.
 */
function narrator(page, { total = 0, hold = 1400 } = {}) {
    let step = 0;

    const show = async (message, tone) => {
        step += 1;
        lastCaption.set(page, { message, step, total, tone });

        await page.evaluate(paint, { message, step, total, tone });

        // A silently missing banner is the worst outcome: the run reports PASS
        // while the person who asked to watch it sees nothing and cannot tell the
        // difference. The host element is in the light DOM (only its contents are
        // in the closed root), so it can be checked from here — and must be.
        if (! await page.locator('#__qa_narration_host__').isVisible()) {
            throw new Error('Narration banner did not render — a watched run would be silent.');
        }

        await page.waitForTimeout(hold);
    };

    // A navigation throws the caption away with the old document; put it back.
    page.on('load', () => {
        const caption = lastCaption.get(page);

        if (caption) {
            page.evaluate(paint, caption).catch(() => {});
        }
    });

    return {
        /** What is about to happen. */
        say: (message) => show(message, 'say'),
        /** What has just been proved. */
        check: (message) => show(message, 'check'),
        /** The thing this test exists to catch. */
        warn: (message) => show(message, 'warn'),
    };
}

module.exports = { narrator };
