<?php
/******************************************************
 * Moodle Cohort Course Reports (single file)
 * - Shows two reports with a toggle:
 *   1) Recent Enrollments (last N days) + status
 *   2) Not Completed (all in cohort who haven't finished)
 * - Uses vanilla PHP + PDO for DB reads
 * - Enforces Moodle login; site admins see all, others are scoped
 *   to cohorts they manage (role id = 10).
 *
 * Deep links:
 *   ?report=recent        -> Recent Enrollments report
 *   ?report=incomplete    -> Not Completed report
 *
 * Filters:
 *   courseid (required)
 *   manager_userid (admin only; optional)
 *   cohortid (optional - if omitted, includes all visible cohorts)
 *   since_days (for recent report; default 30)
 *   q (simple client-side search box)
 ******************************************************/

// --- Moodle bootstrap ---
require_once('/var/www/html/moodle/config.php');
require_login();
global $CFG, $DB, $USER;

// --- PDO from Moodle config ---
$host = $CFG->dbhost;
$db   = $CFG->dbname;
$user = $CFG->dbuser;
$pass = $CFG->dbpass;
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$opt = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];
$pdo = new PDO($dsn, $user, $pass, $opt);

// --- Helpers ---
function is_admin_user(): bool {
  global $USER;
  return function_exists('is_siteadmin') ? is_siteadmin($USER->id) : false;
}

// Simple integer getter
function get_int($key, $default = null) {
  if (!isset($_GET[$key])) return $default;
  $v = trim($_GET[$key]);
  if ($v === '') return $default;
  return ctype_digit($v) ? (int)$v : $default;
}

// Clean string (for basic inputs)
function get_str($key, $default = '') {
  return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

// --- Inputs & defaults ---
$report       = in_array(get_str('report','recent'), ['recent','incomplete']) ? get_str('report','recent') : 'recent';
$courseid     = get_int('courseid', 0);            // REQUIRED; no sensible default
$cohortid     = get_int('cohortid', 0);            // Optional; 0 = all visible cohorts
$since_days   = max(1, get_int('since_days', 30)); // For Recent report
$q            = get_str('q', '');                  // Client-side search box
$manager_userid = get_int('manager_userid', 0);    // Admin-only filter

$isAdmin = is_admin_user();

// --- Validate courseid early ---
if ($courseid <= 0) {
  $title = "Cohort Course Reports";
  echo "<!doctype html><html lang='en'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>
  <title>{$title}</title>
  <style>body{font-family:Arial,Helvetica,sans-serif;padding:24px;background:#f6f7fb;color:#222} .card{max-width:960px;margin:24px auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 6px 16px rgba(0,0,0,.06)} .card h1{margin:0;padding:20px;border-bottom:1px solid #eee;font-size:20px} .card .content{padding:20px} label{display:block;margin:.5rem 0 .25rem;color:#444} input,select{width:100%;max-width:380px;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px} button{margin-top:12px;padding:10px 14px;border:0;border-radius:8px;background:#111827;color:#fff;cursor:pointer} .note{color:#555;margin-top:12px} </style></head><body>";
  echo "<div class='card'><h1>Set up your report</h1><div class='content'>
        <form method='get'>
          <label>Course ID (required)</label>
          <input type='number' name='courseid' min='1' required>
          <label>Report</label>
          <select name='report'>
            <option value='recent'>Recent Enrollments</option>
            <option value='incomplete'>Not Completed</option>
          </select>
          <label>Days back (for Recent)</label>
          <input type='number' name='since_days' min='1' value='30'>
          <button type='submit'>Open Reports</button>
          <p class='note'>Tip: Once loaded, you can toggle between reports at the top, filter by cohort, and live-search the results.</p>
        </form></div></div></body></html>";
  exit;
}

// --- Determine visible cohorts for the viewer ---
// We rely on your described pattern: cohort managers (roleid=10) are also members of the cohort.
// Admins: can see all managers/cohorts. Non-admins: only cohorts they manage (roleid=10).
$ROLEID_COHORT_MANAGER = 10;

// (A) Fetch all cohorts + managers (admin view)
$allManagers = [];
$allCohorts  = [];
if ($isAdmin) {
  // Managers and the cohorts they belong to:
  $sqlManagers = "
    SELECT DISTINCT u.id AS userid, u.firstname, u.lastname, u.email,
           ch.id AS cohortid, ch.name AS cohortname
    FROM mdl_user u
    JOIN mdl_role_assignments ra ON ra.userid = u.id AND ra.roleid = :rid
    JOIN mdl_cohort_members cm ON cm.userid = u.id
    JOIN mdl_cohort ch ON ch.id = cm.cohortid
    ORDER BY u.lastname, u.firstname, ch.name
  ";
  $stmt = $pdo->prepare($sqlManagers);
  $stmt->execute([':rid' => $ROLEID_COHORT_MANAGER]);
  $rows = $stmt->fetchAll();

  foreach ($rows as $r) {
    $uid = (int)$r['userid'];
    if (!isset($allManagers[$uid])) {
      $allManagers[$uid] = [
        'userid' => $uid,
        'name'   => $r['firstname'].' '.$r['lastname'],
        'email'  => $r['email'],
        'cohorts'=> []
      ];
    }
    $allManagers[$uid]['cohorts'][(int)$r['cohortid']] = $r['cohortname'];
    $allCohorts[(int)$r['cohortid']] = $r['cohortname'];
  }
} else {
  // (B) Non-admin: restrict to cohorts managed by current user
  $sqlMyCohorts = "
    SELECT DISTINCT ch.id AS cohortid, ch.name AS cohortname
    FROM mdl_role_assignments ra
    JOIN mdl_cohort_members cm ON cm.userid = ra.userid
    JOIN mdl_cohort ch ON ch.id = cm.cohortid
    WHERE ra.roleid = :rid AND ra.userid = :uid
    ORDER BY ch.name
  ";
  $stmt = $pdo->prepare($sqlMyCohorts);
  $stmt->execute([':rid' => $ROLEID_COHORT_MANAGER, ':uid' => $USER->id]);
  $rows = $stmt->fetchAll();
  foreach ($rows as $r) {
    $allCohorts[(int)$r['cohortid']] = $r['cohortname'];
  }
  // If they don't manage any cohort, show empty state later
}

// If admin selected a manager, narrow cohorts to that manager's cohorts
if ($isAdmin && $manager_userid && isset($allManagers[$manager_userid])) {
  $allCohorts = $allManagers[$manager_userid]['cohorts'];
}

// If a specific cohort is requested but not visible, ignore it
if ($cohortid && !isset($allCohorts[$cohortid])) {
  $cohortid = 0; // fallback to all visible
}

// Compute cutoff timestamp for "recent"
$since_ts = time() - ($since_days * 24 * 60 * 60);

// --- Query builders ---

/**
 * Report 1: Recent Enrollments in the last N days + completion status
 * - If $cohortid=0, include all visible cohorts; else just the chosen cohort.
 * - Shows newest enrollments first.
 */
function fetch_recent_enrollments(PDO $pdo, int $courseid, array $cohortIds, int $since_ts): array {
  if (empty($cohortIds)) return [];
  // We'll return rows across cohorts; include cohort info
  $in = implode(',', array_fill(0, count($cohortIds), '?'));

  $sql = "
    SELECT
      u.id AS userid,
      u.firstname, u.lastname, u.email,
      ch.id AS cohortid, ch.name AS cohortname,
      ue.timecreated AS enroll_ts,
      FROM_UNIXTIME(ue.timecreated, '%Y-%m-%d %H:%i:%s') AS enrollment_date,
      CASE
        WHEN cc.timecompleted IS NULL THEN 'Not Complete'
        ELSE FROM_UNIXTIME(cc.timecompleted, '%Y-%m-%d %H:%i:%s')
      END AS completed_date
    FROM mdl_user u
    JOIN mdl_cohort_members cm      ON cm.userid = u.id
    JOIN mdl_cohort ch              ON ch.id = cm.cohortid
    JOIN mdl_user_enrolments ue     ON ue.userid = u.id
    JOIN mdl_enrol e                ON e.id = ue.enrolid AND e.courseid = ?
    LEFT JOIN mdl_course_completions cc
                                    ON cc.userid = u.id AND cc.course = e.courseid
    WHERE cm.cohortid IN ($in)
      AND ue.timecreated > ?
    ORDER BY ue.timecreated DESC
  ";
  $params = array_merge([$courseid], array_values($cohortIds), [$since_ts]);
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();

  // Add days since enrollment
  $now = time();
  foreach ($rows as &$r) {
    $r['days_since'] = floor(($now - (int)$r['enroll_ts']) / 86400);
  }
  return $rows;
}

/**
 * Report 2: Not Completed in cohort(s)
 * - Latest enrollment time per user for this course (for display)
 * - Only where completion time is NULL
 */
function fetch_not_completed(PDO $pdo, int $courseid, array $cohortIds): array {
  if (empty($cohortIds)) return [];
  $in = implode(',', array_fill(0, count($cohortIds), '?'));

  // Latest enrollment timestamp per user for this course
  $sql = "
    SELECT
      u.id AS userid,
      u.firstname, u.lastname, u.email,
      ch.id AS cohortid, ch.name AS cohortname,
      MAX(ue.timecreated) AS latest_enroll_ts
    FROM mdl_cohort_members cm
    JOIN mdl_cohort ch          ON ch.id = cm.cohortid
    JOIN mdl_user u             ON u.id = cm.userid
    LEFT JOIN mdl_course_completions cc
                                 ON cc.userid = u.id AND cc.course = ?
    JOIN mdl_user_enrolments ue ON ue.userid = u.id
    JOIN mdl_enrol e            ON e.id = ue.enrolid AND e.courseid = ?
    WHERE cm.cohortid IN ($in)
      AND (cc.timecompleted IS NULL)
    GROUP BY u.id, u.firstname, u.lastname, u.email, ch.id, ch.name
    ORDER BY latest_enroll_ts DESC
  ";
  $params = array_merge([$courseid, $courseid], array_values($cohortIds));
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();

  $now = time();
  foreach ($rows as &$r) {
    $ts = (int)$r['latest_enroll_ts'];
    $r['enrollment_date'] = $ts ? date('Y-m-d H:i:s', $ts) : 'Unknown';
    $r['days_since'] = $ts ? floor(($now - $ts) / 86400) : null;
  }
  return $rows;
}

// --- Build cohort scope list for queries ---
$visibleCohortIds = array_map('intval', array_keys($allCohorts));
if ($cohortid) {
  $visibleCohortIds = [$cohortid];
}

// --- Fetch data for both reports (so switching tabs is instant) ---
$recentRows = fetch_recent_enrollments($pdo, $courseid, $visibleCohortIds, $since_ts);
$incompleteRows = fetch_not_completed($pdo, $courseid, $visibleCohortIds);

// --- Render ---
$title = "Cohort Course Reports";

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($title) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<style>
  :root {
    --bg:#f6f7fb; --card:#ffffff; --text:#0f172a;
    --muted:#6b7280; --border:#e5e7eb; --accent:#0ea5e9; --accent2:#111827;
  }
  * { box-sizing:border-box }
  body { margin:0; font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; color:var(--text); background:var(--bg); }
  header { background:linear-gradient(135deg, #0ea5e9, #6366f1); color:#fff; padding:20px; }
  header h1 { margin:0; font-size:20px; }
  .wrap { max-width:1200px; margin:0 auto; padding:16px; }
  .filters, .panel { background:var(--card); border:1px solid var(--border); border-radius:14px; box-shadow:0 10px 20px rgba(0,0,0,.05); margin:16px 0; }
  .filters { padding:16px; }
  .grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap:12px; }
  label { font-size:12px; color:var(--muted); display:block; margin-bottom:6px; }
  input, select { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; color:var(--text); }
  .row { display:flex; gap:10px; flex-wrap:wrap; align-items:end; }
  button, .tab { border:0; border-radius:10px; padding:10px 14px; cursor:pointer; background:var(--accent2); color:#fff; }
  .tabs { display:flex; gap:8px; padding:12px; border-bottom:1px solid var(--border); }
  .tab { background:#e5e7eb; color:#111; }
  .tab.active { background:#111827; color:#fff; }
  .panel .inner { padding:16px; }
  table { width:100%; border-collapse:collapse; }
  th, td { text-align:left; padding:10px; border-bottom:1px solid var(--border); font-size:14px; }
  th { user-select:none; cursor:pointer; position:sticky; top:0; background:#fff; z-index:1; }
  .muted { color:var(--muted); font-size:12px; }
  .pill { display:inline-block; padding:4px 8px; border-radius:999px; background:#eef2ff; color:#3730a3; font-size:12px; }
  .ok { background:#dcfce7; color:#166534; }
  .warn { background:#fee2e2; color:#991b1b; }
  .toolbar { display:flex; gap:10px; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; }
  .right { margin-left:auto; }
  .hint { font-size:12px; color:var(--muted); margin-top:6px; }
</style>
</head>
<body>
<header>
  <div class="wrap">
    <h1><?= htmlspecialchars($title) ?></h1>
    <div class="muted">Course ID: <b><?= (int)$courseid ?></b> â€¢ Viewer: <b><?= htmlspecialchars(fullname($USER)) ?></b> <?= $isAdmin ? 'â€¢ <span class="pill ok">Admin</span>' : '' ?></div>
  </div>
</header>

<div class="wrap">
  <!-- FILTER BAR -->
  <form class="filters" method="get" id="filtersForm">
    <input type="hidden" name="report" id="reportInput" value="<?= htmlspecialchars($report) ?>">
    <div class="grid">
      <div>
        <label>Course ID</label>
        <input type="number" name="courseid" value="<?= (int)$courseid ?>" min="1" required>
      </div>

      <?php if ($isAdmin && !empty($allManagers)): ?>
      <div>
        <label>Manager (admin only)</label>
        <select name="manager_userid" id="managerSelect" onchange="document.getElementById('filtersForm').submit()">
          <option value="0">â€” All managers â€”</option>
          <?php foreach ($allManagers as $mid => $m): ?>
            <option value="<?= (int)$mid ?>" <?= $manager_userid===$mid?'selected':'' ?>>
              <?= htmlspecialchars($m['name'].' ('.$m['email'].')') ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="hint">Selecting a manager narrows the Cohort dropdown.</div>
      </div>
      <?php endif; ?>

      <div>
        <label>Cohort</label>
        <select name="cohortid" onchange="document.getElementById('filtersForm').submit()">
          <option value="0">â€” All visible cohorts â€”</option>
          <?php foreach ($allCohorts as $cid => $cname): ?>
            <option value="<?= (int)$cid ?>" <?= $cohortid===$cid?'selected':'' ?>>
              <?= (int)$cid ?> â€” <?= htmlspecialchars($cname) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div <?= $report==='recent' ? '' : "style='opacity:.6'" ?>>
        <label>Days back (Recent Enrollments)</label>
        <input type="number" name="since_days" value="<?= (int)$since_days ?>" min="1">
      </div>

      <div>
        <label>Search (client-side)</label>
        <input type="text" name="q" id="q" value="<?= htmlspecialchars($q) ?>" placeholder="Type to filter rowsâ€¦">
      </div>
    </div>

    <div class="row" style="margin-top:10px">
      <button type="submit">Apply Filters</button>
    </div>
  </form>

  <!-- TABS -->
  <div class="panel">
    <div class="tabs">
      <button type="button" class="tab <?= $report==='recent'?'active':'' ?>" onclick="switchReport('recent')">Recent Enrollments</button>
      <button type="button" class="tab <?= $report==='incomplete'?'active':'' ?>" onclick="switchReport('incomplete')">Not Completed</button>
    </div>

    <!-- REPORT CONTENT -->
    <div class="inner">
      <?php if (empty($allCohorts)): ?>
        <p class="muted">No cohorts available for your account. If you expect access, ensure the viewer has role <b>10</b> within the target cohorts, or view as a site admin.</p>
      <?php endif; ?>

      <!-- Recent Enrollments -->
      <section id="recent" style="<?= $report==='recent'?'':'display:none' ?>">
        <div class="toolbar">
          <div><span class="pill">Showing: Recent enrollments (last <?= (int)$since_days ?> days)</span></div>
          <div class="muted right"><?= count($recentRows) ?> row(s)</div>
        </div>
        <div style="overflow:auto; max-height:65vh;">
          <table id="recentTable">
            <thead>
              <tr>
                <th data-col="cohortname">Cohort</th>
                <th data-col="lastname">Last</th>
                <th data-col="firstname">First</th>
                <th data-col="email">Email</th>
                <th data-col="enrollment_date">Enroll Date</th>
                <th data-col="days_since">Days Since</th>
                <th data-col="completed_date">Completed</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentRows as $r): ?>
                <tr>
                  <td><span class="pill"><?= htmlspecialchars($r['cohortname']) ?></span></td>
                  <td><?= htmlspecialchars($r['lastname']) ?></td>
                  <td><?= htmlspecialchars($r['firstname']) ?></td>
                  <td><?= htmlspecialchars($r['email']) ?></td>
                  <td><?= htmlspecialchars($r['enrollment_date']) ?></td>
                  <td><?= (int)$r['days_since'] ?></td>
                  <td>
                    <?php if ($r['completed_date']==='Not Complete'): ?>
                      <span class="pill warn">Not Complete</span>
                    <?php else: ?>
                      <span class="pill ok"><?= htmlspecialchars($r['completed_date']) ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Not Completed -->
      <section id="incomplete" style="<?= $report==='incomplete'?'':'display:none' ?>">
        <div class="toolbar">
          <div><span class="pill warn">Showing: Not Completed</span></div>
          <div class="muted right"><?= count($incompleteRows) ?> row(s)</div>
        </div>
        <div style="overflow:auto; max-height:65vh;">
          <table id="incompleteTable">
            <thead>
              <tr>
                <th data-col="cohortname">Cohort</th>
                <th data-col="lastname">Last</th>
                <th data-col="firstname">First</th>
                <th data-col="email">Email</th>
                <th data-col="enrollment_date">Latest Enroll</th>
                <th data-col="days_since">Days Since</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($incompleteRows as $r): ?>
                <tr>
                  <td><span class="pill"><?= htmlspecialchars($r['cohortname']) ?></span></td>
                  <td><?= htmlspecialchars($r['lastname']) ?></td>
                  <td><?= htmlspecialchars($r['firstname']) ?></td>
                  <td><?= htmlspecialchars($r['email']) ?></td>
                  <td><?= htmlspecialchars($r['enrollment_date']) ?></td>
                  <td><?= is_null($r['days_since']) ? '' : (int)$r['days_since'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

    </div>
  </div>
</div>

<script>
// Tab switch keeps filters by updating a hidden input and re-submitting if desired
function switchReport(which) {
  document.getElementById('reportInput').value = which;
  // Only toggle visibility; no immediate submit so user can live-search
  document.getElementById('recent').style.display     = (which === 'recent') ? '' : 'none';
  document.getElementById('incomplete').style.display = (which === 'incomplete') ? '' : 'none';
  // Visual tab state
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  const idx = (which==='recent') ? 0 : 1;
  document.querySelectorAll('.tab')[idx].classList.add('active');
  // Push state to URL for deep linking
  const url = new URL(window.location.href);
  url.searchParams.set('report', which);
  history.replaceState(null, '', url.toString());
}

// Client-side search across visible table
(function(){
  const q = document.getElementById('q');
  const filterRows = () => {
    const term = (q.value || '').toLowerCase();
    const active = (document.getElementById('recent').style.display !== 'none') ? 'recentTable' : 'incompleteTable';
    const tbody = document.querySelector('#'+active+' tbody');
    if (!tbody) return;
    [...tbody.rows].forEach(tr=>{
      const txt = tr.innerText.toLowerCase();
      tr.style.display = txt.includes(term) ? '' : 'none';
    });
  };
  if (q) {
    q.addEventListener('input', filterRows);
    // Run once on load with server-provided q
    filterRows();
  }
})();

// Click-to-sort by column (simple text/number)
document.querySelectorAll('th[data-col]').forEach(th=>{
  th.addEventListener('click', ()=>{
    const table = th.closest('table');
    const idx = [...th.parentNode.children].indexOf(th);
    const isNumber = th.getAttribute('data-col').match(/days_since/);
    const rows = [...table.tBodies[0].rows];

    const asc = th.getAttribute('data-sort') !== 'asc';
    rows.sort((a,b)=>{
      const A = a.cells[idx].innerText.trim();
      const B = b.cells[idx].innerText.trim();
      if (isNumber) {
        const nA = parseInt(A||'0',10), nB = parseInt(B||'0',10);
        return asc ? (nA - nB) : (nB - nA);
      } else {
        return asc ? A.localeCompare(B) : B.localeCompare(A);
      }
    });
    // render
    const frag = document.createDocumentFragment();
    rows.forEach(r=>frag.appendChild(r));
    table.tBodies[0].appendChild(frag);

    // flip flag
    th.setAttribute('data-sort', asc ? 'asc' : 'desc');
    // clear others
    [...th.parentNode.children].forEach(o=>{ if(o!==th) o.removeAttribute('data-sort'); });
  });
});
</script>
</body>
</html>
