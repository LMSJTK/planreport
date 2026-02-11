#!/usr/bin/env php
<?php
/**
 * Cohort Manager Digest (CLI) + Send Logging
 *
 * Sends each cohort manager (roleid=10 by default) an HTML digest email with:
 *   1) Recent enrollments (last N days; default 40)
 *   2) Not completed (latest enrollment within last Y years; default 1)
 *
 * NEW: Logs every attempted send into mdl_cohort_digest_log (auto-creates if missing).
 * Also (best-effort) updates mdl_manager_emails (if present) for last sent timestamps.
 *
 * Options:
 *   --courseid=ID               (required)
 *   --since_days=N              (default 40)
 *   --years_back=N              (default 1)
 *   --manager_userid=ID         (optional; send only to this manager)
 *   --noreply_userid=ID         (default 493)
 *   --roleid_manager=ID         (default 10)
 *   --dryrun=1                  (optional; record as DRYRUN, do not send)
 *   --help or -h
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../moodle/config.php'); // adjust path if needed
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->libdir . '/filelib.php');

list($options, $unrecognized) = cli_get_params(
    [
        'courseid'        => null,
        'since_days'      => 40,
        'years_back'      => 1,
        'manager_userid'  => null,
        'noreply_userid'  => 493,
        'roleid_manager'  => 10,
        'dryrun'          => 0,
        'help'            => 0,
    ],
    ['h' => 'help']
);

if (!empty($unrecognized)) {
    cli_error("Unknown options: " . implode(' ', $unrecognized) . PHP_EOL, 1);
}
if (!empty($options['help']) || empty($options['courseid'])) {
    echo "Usage:
  php report_cohort_digest_cli.php --courseid=42 [--since_days=40] [--years_back=1] [--manager_userid=123] [--noreply_userid=493] [--roleid_manager=10] [--dryrun=1]

Logs every attempted send into mdl_cohort_digest_log.\n";
    exit(0);
}

$courseid       = (int)$options['courseid'];
$since_days     = max(1, (int)$options['since_days']);
$years_back     = max(1, (int)$options['years_back']);
$target_manager = $options['manager_userid'] !== null ? (int)$options['manager_userid'] : null;
$noreply_userid = (int)$options['noreply_userid'];
$ROLEID_MANAGER = (int)$options['roleid_manager'];
$dryrun         = (int)$options['dryrun'] === 1;

// PDO from Moodle config
$dsn = "mysql:host={$CFG->dbhost};dbname={$CFG->dbname};charset=utf8mb4";
$pdo = new PDO($dsn, $CFG->dbuser, $CFG->dbpass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csv_escape($v){
    $v = (string)$v;
    $needs = (strpos($v,'"')!==false)||(strpos($v,',')!==false)||(strpos($v,"\n")!==false)||(strpos($v,"\r")!==false);
    $v = str_replace('"','""',$v);
    return $needs ? "\"$v\"" : $v;
}
function human_date_ts(int $ts): string { return userdate($ts, '%B %e, %Y'); }

$now = time();
$recent_cutoff_ts = $now - ($since_days * 86400);
$year_cutoff_ts   = $now - (int)round($years_back * 365.25 * 86400);

// --- Ensure LOG TABLE exists ---
$pdo->exec("
  CREATE TABLE IF NOT EXISTS mdl_cohort_digest_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    manager_userid BIGINT UNSIGNED NOT NULL,
    manager_email VARCHAR(255) NOT NULL,
    courseid BIGINT UNSIGNED NOT NULL,
    since_days INT NOT NULL,
    years_back INT NOT NULL,
    recent_count INT NOT NULL,
    incomplete_count INT NOT NULL,
    cohorts_csv TEXT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    sent_ok TINYINT(1) NOT NULL DEFAULT 0,
    status_label VARCHAR(32) NOT NULL,       -- e.g., SENT, FAIL, DRYRUN
    error_text TEXT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_manager (manager_userid, sent_at),
    KEY idx_course (courseid, sent_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// --- Identify Managers & Cohorts ---
$sqlManagers = "
  SELECT DISTINCT u.id AS userid, u.firstname, u.lastname, u.email,
         ch.id AS cohortid, ch.name AS cohortname
  FROM mdl_user u
  JOIN mdl_role_assignments ra ON ra.userid = u.id AND ra.roleid = :rid
  JOIN mdl_cohort_members cm   ON cm.userid = u.id
  JOIN mdl_cohort ch           ON ch.id = cm.cohortid
  ORDER BY u.lastname, u.firstname, ch.name
";
$stmt = $pdo->prepare($sqlManagers);
$stmt->execute([':rid' => $ROLEID_MANAGER]);
$mrows = $stmt->fetchAll();

$managers = [];
foreach ($mrows as $r){
    $uid = (int)$r['userid'];
    if ($target_manager !== null && $uid !== $target_manager) continue;
    if (!isset($managers[$uid])){
        $managers[$uid] = [
            'userid'  => $uid,
            'name'    => $r['firstname'].' '.$r['lastname'],
            'email'   => $r['email'],
            'cohorts' => []
        ];
    }
    $managers[$uid]['cohorts'][(int)$r['cohortid']] = $r['cohortname'];
}
if (empty($managers)){
    echo "[INFO] No managers found (role={$ROLEID_MANAGER})".($target_manager?" for userid={$target_manager}":"").".\n";
    exit(0);
}

// --- Queries ---
function fetch_recent_enrollments(PDO $pdo, int $courseid, array $cohortIds, int $since_ts): array {
    if (empty($cohortIds)) return [];
    $in = implode(',', array_fill(0, count($cohortIds), '?'));
    $sql = "
      SELECT u.id AS userid, u.firstname, u.lastname, u.email,
             ch.id AS cohortid, ch.name AS cohortname,
             ue.timecreated AS enroll_ts,
             FROM_UNIXTIME(ue.timecreated,'%Y-%m-%d %H:%i:%s') AS enrollment_date,
             CASE WHEN cc.timecompleted IS NULL THEN 'Not Complete'
                  ELSE FROM_UNIXTIME(cc.timecompleted,'%Y-%m-%d %H:%i:%s') END AS completed_date
      FROM mdl_user u
      JOIN mdl_cohort_members cm  ON cm.userid=u.id
      JOIN mdl_cohort ch          ON ch.id=cm.cohortid
      JOIN mdl_user_enrolments ue ON ue.userid=u.id
      JOIN mdl_enrol e            ON e.id=ue.enrolid AND e.courseid=?
      LEFT JOIN mdl_course_completions cc ON cc.userid=u.id AND cc.course=e.courseid
      WHERE cm.cohortid IN ($in) AND ue.timecreated > ?
      ORDER BY ue.timecreated DESC
    ";
    $params = array_merge([$courseid], array_values($cohortIds), [$since_ts]);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    $now = time();
    foreach ($rows as &$r){ $r['days_since'] = floor(($now - (int)$r['enroll_ts'])/86400); }
    return $rows;
}
function fetch_not_completed(PDO $pdo, int $courseid, array $cohortIds, int $year_cutoff_ts): array {
    if (empty($cohortIds)) return [];
    $in = implode(',', array_fill(0, count($cohortIds), '?'));
    $sql = "
      SELECT u.id AS userid, u.firstname, u.lastname, u.email,
             ch.id AS cohortid, ch.name AS cohortname,
             MAX(ue.timecreated) AS latest_enroll_ts
      FROM mdl_cohort_members cm
      JOIN mdl_cohort ch ON ch.id=cm.cohortid
      JOIN mdl_user u    ON u.id=cm.userid
      LEFT JOIN mdl_course_completions cc ON cc.userid=u.id AND cc.course=?
      JOIN mdl_user_enrolments ue ON ue.userid=u.id
      JOIN mdl_enrol e ON e.id=ue.enrolid AND e.courseid=?
      WHERE cm.cohortid IN ($in) AND cc.timecompleted IS NULL
      GROUP BY u.id, u.firstname, u.lastname, u.email, ch.id, ch.name
      HAVING MAX(ue.timecreated) >= ?
      ORDER BY latest_enroll_ts DESC
    ";
    $params = array_merge([$courseid, $courseid], array_values($cohortIds), [$year_cutoff_ts]);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    $now = time();
    foreach ($rows as &$r){
        $ts = (int)$r['latest_enroll_ts'];
        $r['enrollment_date'] = $ts ? date('Y-m-d H:i:s', $ts) : 'Unknown';
        $r['days_since'] = $ts ? floor(($now - $ts)/86400) : null;
    }
    return $rows;
}

// --- HTML email builder ---
function build_email_html(string $managerName, int $courseid, int $since_days, int $years_back, array $recentRows, array $incompleteRows): string {
    $today = userdate(time(), '%B %e, %Y');
    ob_start(); ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cohort Digest</title></head>
<body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;color:#111;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f6f7fb;padding:20px 0;">
<tr><td align="center">
<table cellpadding="0" cellspacing="0" border="0" width="700" style="max-width:700px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">
<tr><td style="background:linear-gradient(135deg,#0ea5e9,#6366f1);padding:18px 20px;border-radius:8px 8px 0 0;color:#fff;">
  <div style="font-size:18px;font-weight:bold;">Cohort Digest</div>
  <div style="font-size:12px;opacity:.95;">Manager: <strong><?=h($managerName)?></strong> &nbsp;â€¢&nbsp; Course ID: <strong><?= (int)$courseid ?></strong> &nbsp;â€¢&nbsp; Date: <strong><?=h($today)?></strong></div>
</td></tr>

<tr><td style="padding:18px 20px;">
  <div style="font-size:16px;font-weight:bold;margin-bottom:6px;">Recent enrollments (last <?= (int)$since_days ?> days)</div>
  <div style="font-size:12px;color:#555;margin-bottom:12px;">Showing <?= count($recentRows) ?> row(s)</div>
  <?php if (!count($recentRows)): ?>
    <div style="padding:12px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;color:#444;">No recent enrollments found.</div>
  <?php else: ?>
  <table cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;border:1px solid #e5e7eb;">
    <thead><tr style="background:#f3f4f6;">
      <th align="left"  style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:12px;">Cohort</th>
      <th align="left"  style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:12px;">Last</th>
      <th align="left"  style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:12px;">First</th>
      <th align="left"  style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:12px;">Email</th>
      <th align="left"  style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:12px;">Enroll Date</th>
      <th align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:12px;">Days Since</th>
      <th align="left"  style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:12px;">Completed</th>
    </tr></thead>
    <tbody>
    <?php foreach ($recentRows as $r): ?>
      <tr>
        <td style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:13px;"><?=h($r['cohortname'])?></td>
        <td style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:13px;"><?=h($r['lastname'])?></td>
        <td style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:13px;"><?=h($r['firstname'])?></td>
        <td style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:13px;"><?=h($r['email'])?></td>
        <td style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:13px;"><?=h($r['enrollment_date'])?></td>
        <td align="right" style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:13px;"><?= (int)$r['days_since']?></td>
        <td style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:13px;">
          <?php $completed = (string)($r['completed_date'] ?? 'Not Complete'); $done = strtolower($completed)!=='not complete';
            echo $done ? '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:#dcfce7;color:#166534;font-size:12px;">'.h($completed).'</span>'
                       : '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:#fee2e2;color:#991b1b;font-size:12px;">Not Complete</span>'; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</td></tr>

<tr><td style="padding:18px 20px;border-top:1px solid #e5e7eb;">
  <div style="font-size:16px;font-weight:bold;margin-bottom:6px;">Not completed (latest enrollment within last <?= (int)$years_back ?> year<?= $years_back>1?'s':'' ?>)</div>
  <div style="font-size:12px;color:#555;margin-bottom:12px;">Showing <?= count($incompleteRows) ?> row(s)</div>
  <?php if (!count($incompleteRows)): ?>
    <div style="padding:12px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;color:#444;">No in-progress learners within the selected window.</div>
  <?php else: ?>
  <table cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;border:1px solid #e5e7eb;">
    <thead><tr style="background:#f3f4f6;">
      <th align="left"  style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:12px;">Cohort</th>
      <th align="left"  style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:12px;">Last</th>
      <th align="left"  style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:12px;">First</th>
      <th align="left"  style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:12px;">Email</th>
      <th align="left"  style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:12px;">Latest Enroll</th>
      <th align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:12px;">Days Since</th>
    </tr></thead>
    <tbody>
    <?php foreach ($incompleteRows as $r): ?>
      <tr>
        <td style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:13px;"><?=h($r['cohortname'])?></td>
        <td style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:13px;"><?=h($r['lastname'])?></td>
        <td style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:13px;"><?=h($r['firstname'])?></td>
        <td style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:13px;"><?=h($r['email'])?></td>
        <td style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:13px;"><?=h($r['enrollment_date'])?></td>
        <td align="right" style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:13px;"><?= is_null($r['days_since'])?'':(int)$r['days_since'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <div style="font-size:11px;color:#6b7280;margin-top:10px;">This excludes learners whose latest enrollment occurred more than <?= (int)$years_back ?> year<?= $years_back>1?'s':'' ?> ago.</div>
</td></tr>

<tr><td style="padding:14px 20px;border-top:1px solid #e5e7eb;color:#555;font-size:12px;">
  Automated message because you manage one or more cohorts in Course ID <?= (int)$courseid ?>.
</td></tr>
</table>
</td></tr></table>
</body></html>
<?php
    return ob_get_clean();
}

// --- Load sender (noreply)
try {
    $noreply = core_user::get_user($noreply_userid, '*', MUST_EXIST);
} catch (Throwable $e) {
    cli_error("[ERROR] Could not load noreply user id {$noreply_userid}: ".$e->getMessage(), 2);
}

// --- Optional prepared statements (logging & manager_emails upsert) ---
$insLog = $pdo->prepare("
  INSERT INTO mdl_cohort_digest_log
    (manager_userid, manager_email, courseid, since_days, years_back,
     recent_count, incomplete_count, cohorts_csv, subject, sent_ok, status_label, error_text)
  VALUES
    (:manager_userid, :manager_email, :courseid, :since_days, :years_back,
     :recent_count, :incomplete_count, :cohorts_csv, :subject, :sent_ok, :status_label, :error_text)
");

/** Best effort: update your earlier tracking table if it exists */
$hasManagerEmails = false;
try {
    $pdo->query("SELECT 1 FROM mdl_manager_emails LIMIT 1");
    $hasManagerEmails = true;
} catch (Throwable $e) {
    // table not presentâ€”ignore
}
$updMgr = $hasManagerEmails
  ? $pdo->prepare("
        INSERT INTO mdl_manager_emails (userid, recent_lastsentdate, all_lastsentdate)
        VALUES (:uid, NOW(), NOW())
        ON DUPLICATE KEY UPDATE recent_lastsentdate=NOW(), all_lastsentdate=NOW()
     ")
  : null;

// --- Main loop: one email per manager ---
$sentCount = 0;
$skippedNoCohorts = 0;

foreach ($managers as $mid => $m) {
    $cohortIds = array_map('intval', array_keys($m['cohorts']));
    if (empty($cohortIds)) {
        $skippedNoCohorts++;
        echo "[WARN] Manager {$m['name']} (id={$mid}) has no cohorts; skipping.\n";
        continue;
    }

    $recentRows     = fetch_recent_enrollments($pdo, $courseid, $cohortIds, $recent_cutoff_ts);
    $incompleteRows = fetch_not_completed($pdo, $courseid, $cohortIds, $year_cutoff_ts);

    $html    = build_email_html($m['name'], $courseid, $since_days, $years_back, $recentRows, $incompleteRows);
    $plain   = "Cohort Digest for {$m['name']} (Course {$courseid})\nGenerated: ".human_date_ts(time())."\n\n"
             . "Recent enrollments (last {$since_days} days): ".count($recentRows)." row(s)\n"
             . "Not completed (latest enrollment within last {$years_back} year".($years_back>1?'s':'')."): ".count($incompleteRows)." row(s)\n\n"
             . "Open the HTML version for the full tables.";
    $subject = "Cohort Digest â€“ Course {$courseid} â€“ ".userdate(time(), '%B %e, %Y');

    // Attach a single CSV bundle with both sections
    $tempdir = make_temp_directory('cohort_digests');
    $dateforname = userdate(time(), '%Y-%m-%d');

    $csv1 = "Cohort,Last,First,Email,Enroll Date,Days Since,Completed\r\n";
    foreach ($recentRows as $r) {
        $csv1 .= implode(',', array_map('csv_escape', [
            $r['cohortname'] ?? '', $r['lastname'] ?? '', $r['firstname'] ?? '',
            $r['email'] ?? '', $r['enrollment_date'] ?? '',
            isset($r['days_since'])?(int)$r['days_since']:'', $r['completed_date'] ?? '',
        ]))."\r\n";
    }
    $csv2 = "Cohort,Last,First,Email,Latest Enroll,Days Since\r\n";
    foreach ($incompleteRows as $r) {
        $csv2 .= implode(',', array_map('csv_escape', [
            $r['cohortname'] ?? '', $r['lastname'] ?? '', $r['firstname'] ?? '',
            $r['email'] ?? '', $r['enrollment_date'] ?? '',
            isset($r['days_since'])?(int)$r['days_since']:''
        ]))."\r\n";
    }
    $comboName = "CohortDigest_course{$courseid}_manager{$mid}_{$dateforname}.csv";
    $comboPath = $tempdir.'/'.$comboName;
    $combo = "---- Recent Enrollments (last {$since_days} days) ----\r\n{$csv1}\r\n"
           . "---- Not Completed (latest enrollment within {$years_back} year".($years_back>1?'s':'').") ----\r\n{$csv2}";
    file_put_contents($comboPath, $combo);

    $cohortsCsv = implode(', ', array_map(fn($id,$name)=>"$id: $name", array_keys($m['cohorts']), array_values($m['cohorts'])));

    $statusLabel = 'SENT';
    $sentOk      = 0;
    $errText     = null;

    if ($dryrun) {
        $statusLabel = 'DRYRUN';
        $sentOk = 0; // intentionally 0â€”no actual send
        echo "[DRYRUN] Would send to {$m['name']} ({$m['email']}) recents=".count($recentRows).", incomplete=".count($incompleteRows)."\n";
    } else {
        try {
            $to = core_user::get_user($mid, '*', MUST_EXIST);
            $sent = email_to_user($to, $noreply, $subject, $plain, $html, $comboPath, $comboName, true);
            if ($sent) {
                $sentOk = 1;
                echo "[OK] Sent to {$m['name']} ({$m['email']}) recents=".count($recentRows).", incomplete=".count($incompleteRows)."\n";
                $sentCount++;

                // Update your historical tracker if present
                if ($updMgr) {
                    $updMgr->execute([':uid' => $mid]);
                }
            } else {
                $sentOk = 0;
                $statusLabel = 'FAIL';
                $errText = 'email_to_user returned false';
                echo "[ERR] Failed send to {$m['name']} ({$m['email']}).\n";
            }
        } catch (Throwable $e) {
            $sentOk = 0;
            $statusLabel = 'FAIL';
            $errText = $e->getMessage();
            echo "[ERR] Exception sending to {$m['name']} ({$m['email']}): {$errText}\n";
        }
    }

    // Insert LOG row (alwaysâ€”even on DRYRUN or failure)
    $insLog->execute([
        ':manager_userid'   => $mid,
        ':manager_email'    => (string)$m['email'],
        ':courseid'         => $courseid,
        ':since_days'       => $since_days,
        ':years_back'       => $years_back,
        ':recent_count'     => count($recentRows),
        ':incomplete_count' => count($incompleteRows),
        ':cohorts_csv'      => $cohortsCsv,
        ':subject'          => $subject,
        ':sent_ok'          => $sentOk,
        ':status_label'     => $statusLabel,
        ':error_text'       => $errText,
    ]);
}

echo "[DONE] Sent/queued: {$sentCount}; Managers without cohorts: {$skippedNoCohorts}\n";
exit(0);
