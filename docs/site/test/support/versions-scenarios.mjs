// versions.json files the writer produces, one per publish scenario of the decision table (plan U5). The schema test
// and the frozen-reader test check every one of them.
import { decide } from '../../lib/versions.mjs';

export const TOOLING = 'a'.repeat(40);

function publish(stored, tag) {
  const decision = decide(stored, { tag, builtWith: TOOLING });
  if (decision.action !== 'publish') throw new Error(`scenario publish of ${tag} skipped: ${decision.reason}`);
  return decision.versions;
}
const storeOf = (...tags) => tags.reduce(publish, null);

export const WRITER_OUTPUTS = Object.freeze([
  ['first rc', storeOf('v2.1.0-rc.1')],
  ['AE2: final plus a newer rc', storeOf('v2.1.0', 'v2.2.0-rc.1')],
  ['final replaces rc', storeOf('v2.1.0-rc.1', 'v2.1.0')],
  ['patch of an older minor', storeOf('v2.1.0', 'v2.2.0', 'v2.1.1')],
  ['re-run', storeOf('v2.1.0', 'v2.1.0')],
  ['first copy of an older minor', storeOf('v2.2.0', 'v2.1.0-rc.1')],
  ['rcs only', storeOf('v2.1.0-rc.1', 'v2.2.0-rc.1')],
  ['multi-digit minors', storeOf('v2.9.0', 'v2.10.0-rc.10', 'v10.0.0')],
]);
