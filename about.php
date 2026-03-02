<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

$speedMph = (float)app_setting('speed_mph', '25');
$bufferMinutes = (float)app_setting('buffer_minutes', '1');
$minConfidence = (int)app_setting('min_confidence', '70');
$policyThreshold = (float)app_setting('policy_cut_through_percent', '25');

render_head('About');
?>
<section class="card">
  <h1>Neighborhood Cut-through Analysis Tool (N-CAT)</h1>
  <p class="small">Official tool name used for traffic study workflows and reporting.</p>
</section>

<section class="card" style="margin-top:1rem;">
  <h1>About Matching</h1>
  <p class="small">This page explains exactly how N-CAT matches vehicles and classifies traffic.</p>
  <p class="small">All times are Eastern Time (ET).</p>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>Policy Reference</h2>
  <p class="small">RCOC Board Regulation No. 47, Installation of Speed Humps.</p>
  <p><a class="btn secondary" href="RCOC%20Board%20Regulation%20No.%2047%2C%20Installation%20of%20Speed%20Humps.pdf" target="_blank" rel="noopener">Open Regulation PDF</a></p>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>Current Matching Settings</h2>
  <table>
    <thead><tr><th>Setting</th><th>Value</th><th>How it is used</th></tr></thead>
    <tbody>
      <tr><td>Speed</td><td><?= h(number_format($speedMph, 0)) ?> mph</td><td>Expected travel time = distance / speed.</td></tr>
      <tr><td>Buffer</td><td><?= h(number_format($bufferMinutes, 2)) ?> min</td><td>Accept window is expected time ± buffer.</td></tr>
      <tr><td>Min Confidence</td><td><?= h((string)$minConfidence) ?></td><td>Candidate match must be at least this score.</td></tr>
      <tr><td>Policy Threshold</td><td><?= h(number_format($policyThreshold, 0)) ?>%</td><td>Used for policy status KPI.</td></tr>
    </tbody>
  </table>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>How Matching Works</h2>
  <table>
    <thead><tr><th>Step</th><th>Rule</th><th>Notes</th></tr></thead>
    <tbody>
      <tr><td>1</td><td>Filter events to selected site + date + study period (AM/PM).</td><td>Dashboard/Details use only that window.</td></tr>
      <tr><td>2</td><td>Split events into <code class="inline">In</code> and <code class="inline">Out</code>.</td><td>Only In-to-Out pairs are considered.</td></tr>
      <tr><td>3</td><td>Build candidate pairs and apply hard checks.</td><td>Different checkpoints, Out after In, distance exists, elapsed inside window.</td></tr>
      <tr><td>4</td><td>Score confidence for each candidate.</td><td>Plate 65%, type 20%, color 15%.</td></tr>
      <tr><td>5</td><td>Sort candidates and keep best first.</td><td>Highest confidence first, then closest elapsed vs expected.</td></tr>
      <tr><td>6</td><td>One-to-one assignment.</td><td>Each In and each Out event can be used at most once.</td></tr>
      <tr><td>7</td><td>Classify leftovers.</td><td>Unmatched In = local arrival. Unmatched Out = local departure.</td></tr>
    </tbody>
  </table>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>Confidence Formula</h2>
  <p class="small"><code class="inline">score = plate*0.65 + type*0.20 + color*0.15</code></p>
  <p class="small">Plate is required for new entries (first 3 characters). Blank plates are rejected.</p>
  <p class="small">Exact plate matches have a minimum confidence of 80, even if type/color disagree.</p>
  <p class="small">Plates are normalized first (for example: <code class="inline">O -&gt; 0</code>, <code class="inline">Q -&gt; 0</code>, <code class="inline">I -&gt; 1</code>). This means <code class="inline">Q</code>, <code class="inline">O</code>, and <code class="inline">0</code> are treated as the same value during matching. Fuzzy aliases also treat lookalikes as similar (<code class="inline">L/1</code>, <code class="inline">S/5</code>, <code class="inline">Z/2</code>, <code class="inline">B/8</code>).</p>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>Confidence Examples (100 to 0)</h2>
  <p class="small">These examples show how the formula behaves. “Plate Similarity” is 0–100, Type/Color are either match (100) or mismatch (0). Final score is rounded to an integer.</p>
  <table>
    <thead><tr><th>Plate Similarity</th><th>Type Match?</th><th>Color Match?</th><th>Confidence</th></tr></thead>
    <tbody>
      <tr><td>100</td><td>Yes</td><td>Yes</td><td>100</td></tr>
      <tr><td>80</td><td>Yes</td><td>Yes</td><td>87</td></tr>
      <tr><td>100</td><td>Yes</td><td>No</td><td>85</td></tr>
      <tr><td>100</td><td>No</td><td>Yes</td><td>80</td></tr>
      <tr><td>60</td><td>Yes</td><td>Yes</td><td>74</td></tr>
      <tr><td>100</td><td>No</td><td>No</td><td>80</td></tr>
      <tr><td>40</td><td>Yes</td><td>Yes</td><td>61</td></tr>
      <tr><td>20</td><td>Yes</td><td>Yes</td><td>48</td></tr>
      <tr><td>0</td><td>Yes</td><td>Yes</td><td>35</td></tr>
      <tr><td>0</td><td>Yes</td><td>No</td><td>20</td></tr>
      <tr><td>0</td><td>No</td><td>Yes</td><td>15</td></tr>
      <tr><td>0</td><td>No</td><td>No</td><td>0</td></tr>
    </tbody>
  </table>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>All Matching Scenarios</h2>
  <table>
    <thead><tr><th>Scenario</th><th>Example</th><th>Result</th></tr></thead>
    <tbody>
      <tr><td>Valid cut-through match</td><td>In at CP1 8:00, Out at CP2 8:03, distance/time valid, confidence >= threshold.</td><td>Matched as cut-through.</td></tr>
      <tr><td>Same checkpoint</td><td>In at CP2 and Out at CP2.</td><td>Rejected from matching (not cut-through candidate).</td></tr>
      <tr><td>Out before In</td><td>Out timestamp earlier than In timestamp.</td><td>Rejected from matching.</td></tr>
      <tr><td>No configured distance</td><td>CP1 to CP3 has no distance row.</td><td>Rejected from matching.</td></tr>
      <tr><td>Too fast / too slow</td><td>Elapsed outside expected ± buffer.</td><td>Rejected from matching.</td></tr>
      <tr><td>Low confidence</td><td>Elapsed valid but score below min confidence.</td><td>Candidate dropped.</td></tr>
      <tr><td>Two Outs compete for one In</td><td>Both pass checks for same In event.</td><td>Best-scored candidate wins; other remains unmatched.</td></tr>
      <tr><td>Two Ins compete for one Out</td><td>Both pass checks for same Out event.</td><td>Best-scored candidate wins; other remains unmatched.</td></tr>
      <tr><td>Unmatched In</td><td>No valid Out pair remains.</td><td>Classified as local arrival (destination).</td></tr>
      <tr><td>Unmatched Out</td><td>No valid In pair remains.</td><td>Classified as local departure (origin).</td></tr>
    </tbody>
  </table>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>How Totals Are Calculated</h2>
  <table>
    <thead><tr><th>Metric</th><th>Definition</th></tr></thead>
    <tbody>
      <tr><td>Total Volume (Two-Way)</td><td><code class="inline">matches + unmatched_in + unmatched_out</code></td></tr>
      <tr><td>Cut-Through Vehicles</td><td>Number of matched In/Out pairs.</td></tr>
      <tr><td>Leg % (each checkpoint pair)</td><td><code class="inline">(pair_match_count / total_volume) * 100</code></td></tr>
      <tr><td>Top Leg % (dashboard KPI)</td><td>The highest leg % among all checkpoint pairs in the selected period.</td></tr>
      <tr><td>Cut-Through / Highest Two-Way</td><td><code class="inline">(cut_through_count / highest_checkpoint_two_way) * 100</code>. Dashboard shows both raw ratio and percent.</td></tr>
      <tr><td>Policy Status</td><td>Uses per-leg endpoint ratio. For each leg A→B: <code class="inline">leg_percent = leg_cut_through_count / max(two_way_at_A, two_way_at_B) * 100</code>. Policy checks the highest leg % against <?= h(number_format($policyThreshold, 0)) ?>%.</td></tr>
      <tr><td>Repeat Cut-Through Vehicles (AM + PM)</td><td>Count of likely repeat vehicles that were first classified as cut-throughs in both Morning and Afternoon on the same study date, then paired AM-to-PM with the same plate/type/color confidence logic used by the normal matcher. This count is not doubled: one accepted AM-to-PM pair counts as one repeat vehicle.</td></tr>
    </tbody>
  </table>
</section>

<section class="card" style="margin-top:1rem;">
  <h2>How Repeat Cut-Through Vehicles Are Calculated</h2>
  <ol>
    <li>The system loads the exact same study date for both the Morning Study and Afternoon Study.</li>
    <li>Morning and Afternoon are each run through the normal cut-through matcher first. Only records that already qualify as cut-through matches remain eligible for the repeat metric.</li>
    <li>Local arrivals, local departures, same-checkpoint pairs, and different-checkpoint pairs outside the timing window are not part of the repeat cut-through pool.</li>
    <li>For each matched cut-through, the repeat logic builds a candidate vehicle using the normalized 3-character plate prefix plus the recorded vehicle type and vehicle color.</li>
    <li>Every Morning cut-through candidate is compared against every Afternoon cut-through candidate using the same confidence model as checkpoint matching:
      <code class="inline">plate 65% + type 20% + color 15%</code>.
    </li>
    <li>Route is ignored for this metric. A vehicle can be counted as a repeat even if the Morning route and Afternoon route are different.</li>
    <li>Candidate AM-to-PM pairs are ranked by best score first. The matcher then assigns pairs one-to-one, so a single Morning cut-through cannot be used twice and a single Afternoon cut-through cannot be used twice.</li>
    <li>An AM-to-PM pair is accepted only if its confidence score meets the current minimum confidence setting from setup.</li>
    <li>Each accepted AM-to-PM pair counts as <strong>1</strong> Repeat Cut-Through Vehicle. It is not counted as 2 just because it appears once in Morning and once in Afternoon.</li>
    <li>Example: if one vehicle cut through once in Morning and once in Afternoon, the repeat metric is <strong>1</strong>. Morning cut-through count is still <strong>1</strong> and Afternoon cut-through count is still <strong>1</strong>.</li>
    <li>If a vehicle has multiple cut-throughs in one period, the current repeat logic still uses one-to-one pairing. That means one Morning cut-through can match at most one Afternoon cut-through for this metric.</li>
    <li>If a matched cut-through does not have a usable normalized plate prefix, it is excluded from repeat analysis and reported as a skipped repeat candidate.</li>
    <li>This is still a likely-repeat metric, not a full vehicle identity guarantee, because the system stores only the first 3 plate characters.</li>
  </ol>
</section>
<?php render_foot(); ?>
