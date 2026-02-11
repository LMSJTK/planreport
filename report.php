<?php
/******************************************************
 * Moodle Cohort Course Reports + Email CSV (single file)
 * - Two reports with a toggle:
 *   1) Recent Enrollments (last N days) + status
 *   2) Not Completed (all in cohort who haven't finished)
 * - Uses vanilla PHP + PDO for reads
 * - Enforces Moodle login; site admins see all, others are scoped
 *   to cohorts they manage (role id = 10).
 * - NEW: â€œEmail me this report (CSV)â€ button
 ******************************************************/

// --- Moodle bootstrap ---
require_once('/var/www/html/moodle/config.php');
require_login();
global $CFG, $DB, $USER;

require_once($CFG->libdir.'/moodlelib.php'); // email_to_user, etc.
require_once($CFG->libdir.'/filelib.php');   // make_temp_directory, etc.

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
function get_int($key, $default = null) {
  if (!isset($_REQUEST[$key])) return $default;
  $v = trim($_REQUEST[$key]);
  if ($v === '') return $default;
  return ctype_digit($v) ? (int)$v : $default;
}
function get_str($key, $default = '') {
  return isset($_REQUEST[$key]) ? trim((string)$_REQUEST[$key]) : $default;
}
function csv_escape($v) {
  $v = (string)$v;
  $needs = (strpos($v, '"') !== false) || (strpos($v, ',') !== false) || (strpos($v, "\n") !== false) || (strpos($v, "\r") !== false);
  // Guard against spreadsheet formula injection
  if (isset($v[0]) && in_array($v[0], ['=', '+', '-', '@'], true)) {
    $needs = true;
  }
  $v = str_replace('"', '""', $v);
  return $needs ? "\"$v\"" : $v;
}
function human_date($ts) {
  return userdate($ts, '%B %e, %Y'); // e.g., October 6, 2025
}

// --- Inputs & defaults ---
$report       = in_array(get_str('report','recent'), ['recent','incomplete']) ? get_str('report','recent') : 'recent';
$courseid     = get_int('courseid', 0);            // REQUIRED
$cohortid     = get_int('cohortid', 0);            // Optional; 0 = all visible cohorts
$since_days   = max(1, get_int('since_days', 30)); // For Recent
$years_back   = max(1, get_int('years_back', 1)); // For Not Completed
$q            = get_str('q', '');                  // Client-side search
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
$ROLEID_COHORT_MANAGER = 10;
$allManagers = [];
$allCohorts  = [];
if ($isAdmin) {
  $sqlManagers = "
    SELECT DISTINCT u.id AS userid, u.firstname, u.lastname, u.email,
           ch.id AS cohortid, ch.name AS cohortname
    FROM mdl_user u
    JOIN mdl_role_assignments ra ON ra.userid = u.id AND ra.roleid = :rid
    JOIN mdl_cohort_members cm ON cm.userid = u.id
    JOIN mdl_cohort ch ON ch.id = cm.cohortid
    JOIN mdl_context ctx ON ctx.id = ra.contextid
         AND (ctx.contextlevel = 10 OR ctx.id = ch.contextid)
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
  $sqlMyCohorts = "
    SELECT DISTINCT ch.id AS cohortid, ch.name AS cohortname
    FROM mdl_role_assignments ra
    JOIN mdl_cohort_members cm ON cm.userid = ra.userid
    JOIN mdl_cohort ch ON ch.id = cm.cohortid
    JOIN mdl_context ctx ON ctx.id = ra.contextid
         AND (ctx.contextlevel = 10 OR ctx.id = ch.contextid)
    WHERE ra.roleid = :rid AND ra.userid = :uid
    ORDER BY ch.name
  ";
  $stmt = $pdo->prepare($sqlMyCohorts);
  $stmt->execute([':rid' => $ROLEID_COHORT_MANAGER, ':uid' => $USER->id]);
  $rows = $stmt->fetchAll();
  foreach ($rows as $r) {
    $allCohorts[(int)$r['cohortid']] = $r['cohortname'];
  }
}
if ($isAdmin && $manager_userid && isset($allManagers[$manager_userid])) {
  $allCohorts = $allManagers[$manager_userid]['cohorts'];
}
if ($cohortid && !isset($allCohorts[$cohortid])) {
  $cohortid = 0; // fallback
}

// Compute cutoff timestamps
$since_ts = time() - ($since_days * 24 * 60 * 60);
$year_cutoff_ts = time() - (int)round($years_back * 365.25 * 86400);

// --- Query builders ---
function fetch_recent_enrollments(PDO $pdo, int $courseid, array $cohortIds, int $since_ts): array {
  if (empty($cohortIds)) return [];
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
  $now = time();
  foreach ($rows as &$r) {
    $r['days_since'] = floor(($now - (int)$r['enroll_ts']) / 86400);
  }
  return $rows;
}
function fetch_not_completed(PDO $pdo, int $courseid, array $cohortIds, int $year_cutoff_ts): array {
  if (empty($cohortIds)) return [];
  $in = implode(',', array_fill(0, count($cohortIds), '?'));
  $sql = "
    SELECT
      u.id AS userid,
      u.firstname, u.lastname, u.email,
      ch.id AS cohortid, ch.name AS cohortname,
      MAX(ue.timecreated) AS latest_enroll_ts
    FROM mdl_cohort_members cm
    JOIN mdl_cohort ch          ON ch.id = cm.cohortid
    JOIN mdl_user u             ON u.id = cm.userid
    LEFT JOIN mdl_course_completions cc ON cc.userid = u.id AND cc.course = ?
    JOIN mdl_user_enrolments ue ON ue.userid = u.id
    JOIN mdl_enrol e            ON e.id = ue.enrolid AND e.courseid = ?
    WHERE cm.cohortid IN ($in)
      AND (cc.timecompleted IS NULL)
    GROUP BY u.id, u.firstname, u.lastname, u.email, ch.id, ch.name
    HAVING MAX(ue.timecreated) >= ?
    ORDER BY latest_enroll_ts DESC
  ";
  $params = array_merge([$courseid, $courseid], array_values($cohortIds), [$year_cutoff_ts]);
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

// --- Fetch data for both (for fast tab switch) ---
$recentRows = fetch_recent_enrollments($pdo, $courseid, $visibleCohortIds, $since_ts);
$incompleteRows = fetch_not_completed($pdo, $courseid, $visibleCohortIds, $year_cutoff_ts);

// ---------- EMAIL HANDLER ----------
$email_status = '';
if (optional_param('send_email', 0, PARAM_INT)) {
  require_sesskey();

  // Which dataset?
  $rows = ($report === 'recent') ? $recentRows : $incompleteRows;

  // Build CSV header + rows
  if ($report === 'recent') {
    $headers = ['Cohort','Last','First','Email','Enroll Date','Days Since','Completed'];
  } else {
    $headers = ['Cohort','Last','First','Email','Latest Enroll','Days Since'];
  }
  $csv = implode(',', array_map('csv_escape', $headers)) . "\r\n";
  foreach ($rows as $r) {
    if ($report === 'recent') {
      $line = [
        $r['cohortname'] ?? '',
        $r['lastname'] ?? '',
        $r['firstname'] ?? '',
        $r['email'] ?? '',
        $r['enrollment_date'] ?? '',
        isset($r['days_since']) ? (int)$r['days_since'] : '',
        $r['completed_date'] ?? ''
      ];
    } else {
      $line = [
        $r['cohortname'] ?? '',
        $r['lastname'] ?? '',
        $r['firstname'] ?? '',
        $r['email'] ?? '',
        $r['enrollment_date'] ?? '',
        isset($r['days_since']) ? (int)$r['days_since'] : ''
      ];
    }
    $csv .= implode(',', array_map('csv_escape', $line)) . "\r\n";
  }

  // Write to a temp file Moodle can read for attachment
  $tempdir = make_temp_directory('cohort_reports');
  $dateforname = userdate(time(), '%Y-%m-%d');
  $cleanreportname = ($report === 'recent') ? 'Recent_Enrollments' : 'Not_Completed';
  $fname = "{$cleanreportname}_course{$courseid}_{$dateforname}.csv";
  $fpath = $tempdir . '/' . $fname;
  file_put_contents($fpath, $csv);

  // Prepare sender (no-reply user id = 493 as requested)
  $noreply = core_user::get_user(493, '*', MUST_EXIST);
  // Subject per your example
  // â€œLast Month Not Completed - October 6th 2025â€ â€” weâ€™ll derive a friendly name:
  $reportLongName = ($report === 'recent')
      ? "Recent Enrollments (Last {$since_days} Days)"
      : "Not Completed";
  $subject = $reportLongName.' - '.userdate(time(), '%B %e %Y');

  // Message bodies
  $cohortlabel = $cohortid ? ($allCohorts[$cohortid] ?? 'Selected cohort') : 'All visible cohorts';
  $plain = "Report: {$reportLongName}\nCourse ID: {$courseid}\nCohorts: {$cohortlabel}\nRows: ".count($rows)."\n\nA CSV copy is attached.";
  $html = "<p><strong>Report:</strong> {$reportLongName}<br>
           <strong>Course ID:</strong> {$courseid}<br>
           <strong>Cohorts:</strong> ".s($cohortlabel)."<br>
           <strong>Rows:</strong> ".count($rows)."</p>
           <p>A CSV copy is attached.</p>";

  // Send email to the viewer
  $sent = email_to_user($USER, $noreply, $subject, $plain, $html, $fpath, $fname, true);
  if ($sent) {
    $email_status = 'success';
  } else {
    $email_status = 'fail';
  }
}

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
    --ok:#16a34a; --err:#b91c1c;
  }
  * { box-sizing:border-box }
  body { margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; color:var(--text); background:var(--bg); }
  header { background:linear-gradient(135deg,#0ea5e9,#6366f1); color:#fff; padding:20px; }
  header h1 { margin:0; font-size:20px; }
  .wrap { max-width:1200px; margin:0 auto; padding:16px; }
  .filters, .panel, .alert { background:var(--card); border:1px solid var(--border); border-radius:14px; box-shadow:0 10px 20px rgba(0,0,0,.05); margin:16px 0; }
  .filters { padding:16px; }
  .grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap:12px; }
  label { font-size:12px; color:var(--muted); display:block; margin-bottom:6px; }
  input, select { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; color:var(--text); }
  .row { display:flex; gap:10px; flex-wrap:wrap; align-items:end; }
  button, .tab { border:0; border-radius:10px; padding:10px 14px; cursor:pointer; background:var(--accent2); color:#fff; }
  .secondary { background:#374151; }
  .tabs { display:flex; gap:8px; padding:12px; border-bottom:1px solid var(--border); }
  .tab { background:#e5e7eb; color:#111; }
  .tab.active { background:#111827; color:#fff; }
  .panel .inner { padding:16px; }
  table { width:100%; border-collapse:collapse; }
  th, td { text-align:left; padding:10px; border-bottom:1px solid var(--border); font-size:14px; }
  th { user-select:none; cursor:pointer; position:sticky; top:0; background:#fff; z-index:1; }
  .muted { color:var(--muted); font-size:12px; }
  .pill { display:inline-block; padding:4px 8px; border-radius:999px; background:#eef2ff; color:#3730a3; font-size:12px; }
  .okpill { background:#dcfce7; color:#166534; }
  .warn { background:#fee2e2; color:#991b1b; }
  .toolbar { display:flex; gap:10px; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; }
  .right { margin-left:auto; }
  .hint { font-size:12px; color:var(--muted); margin-top:6px; }
  .alert { padding:12px 16px; }
  .alert.ok { border-left:6px solid var(--ok); }
  .alert.err { border-left:6px solid var(--err); }
</style>
</head>
<body>
<header>
  <div class="wrap">
    <h1><?= htmlspecialchars($title) ?></h1>
    <div class="muted">Course ID: <b><?= (int)$courseid ?></b> â€¢ Viewer: <b><?= htmlspecialchars(fullname($USER)) ?></b> <?= $isAdmin ? 'â€¢ <span class="pill okpill">Admin</span>' : '' ?></div>
  </div>
</header>

<div class="wrap">

  <?php if ($email_status === 'success'): ?>
    <div class="alert ok">âœ… Email sent to <strong><?= s($USER->email) ?></strong> with CSV attached.</div>
  <?php elseif ($email_status === 'fail'): ?>
    <div class="alert err">âŒ Email could not be sent. Please check mail settings and user #493.</div>
  <?php endif; ?>

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

      <div <?= $report==='incomplete' ? '' : "style='opacity:.6'" ?>>
        <label>Years back (Not Completed)</label>
        <input type="number" name="years_back" value="<?= (int)$years_back ?>" min="1">
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

  <!-- TABS + EMAIL BUTTON -->
  <div class="panel">
    <div class="tabs">
      <button type="button" class="tab <?= $report==='recent'?'active':'' ?>" onclick="switchReport('recent')">Recent Enrollments</button>
      <button type="button" class="tab <?= $report==='incomplete'?'active':'' ?>" onclick="switchReport('incomplete')">Not Completed</button>
      <div class="right">
        <form method="post" style="display:inline" onsubmit="return confirm('Send this report to your email with a CSV attachment?')">
          <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
          <input type="hidden" name="send_email" value="1">
          <input type="hidden" name="report" value="<?= s($report) ?>">
          <input type="hidden" name="courseid" value="<?= (int)$courseid ?>">
          <input type="hidden" name="cohortid" value="<?= (int)$cohortid ?>">
          <input type="hidden" name="since_days" value="<?= (int)$since_days ?>">
          <input type="hidden" name="years_back" value="<?= (int)$years_back ?>">
          <input type="hidden" name="manager_userid" value="<?= (int)$manager_userid ?>">
          <button type="submit" class="secondary">Email me this report (CSV)</button>
        </form>
      </div>
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
                    <?php if (($r['completed_date'] ?? '') === 'Not Complete'): ?>
                      <span class="pill warn">Not Complete</span>
                    <?php else: ?>
                      <span class="pill okpill"><?= htmlspecialchars($r['completed_date']) ?></span>
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
          <div><span class="pill warn">Showing: Not Completed (enrolled within last <?= (int)$years_back ?> year<?= $years_back > 1 ? 's' : '' ?>)</span></div>
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
// Tab switch
function switchReport(which) {
  document.getElementById('reportInput').value = which;
  document.getElementById('recent').style.display     = (which === 'recent') ? '' : 'none';
  document.getElementById('incomplete').style.display = (which === 'incomplete') ? '' : 'none';
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  const idx = (which==='recent') ? 0 : 1;
  document.querySelectorAll('.tab')[idx].classList.add('active');
  const url = new URL(window.location.href);
  url.searchParams.set('report', which);
  history.replaceState(null, '', url.toString());
}
// Client-side search
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
    filterRows();
  }
})();
// Click-to-sort
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
    const frag = document.createDocumentFragment();
    rows.forEach(r=>frag.appendChild(r));
    table.tBodies[0].appendChild(frag);
    th.setAttribute('data-sort', asc ? 'asc' : 'desc');
    [...th.parentNode.children].forEach(o=>{ if(o!==th) o.removeAttribute('data-sort'); });
  });
});
</script>
</body>
</html>
