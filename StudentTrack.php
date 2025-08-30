<?php
/* 
  File: student.php
  Single-file Student Data Portal (HTML + CSS + PHP + MySQL)
  ---------------------------------------------------------
  Features:
  - Beautiful single-page UI (no external libraries)
  - Add Student (name, roll, class, skills)
  - Record Attendance (per day, Present/Absent)
  - Record Marks (subject-wise, term)
  - Dashboard with search + class filter, attendance %, average marks
  - Export CSV (combined student report)
  - Auto-creates database and tables on first run
*/

/* ========= 1) CONFIGURE YOUR MYSQL SETTINGS HERE ========= */
$DB_HOST = "localhost";    // e.g., "127.0.0.1"
$DB_USER = "root";         // your MySQL user
$DB_PASS = "";             // your MySQL password
$DB_NAME = "student_portal";

/* ========= 2) CONNECT (and bootstrap DB if needed) ========= */
function pdo_connect_bootstrap($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME) {
  try {
    // Connect without DB first to create it if missing
    $pdoRoot = new PDO("mysql:host=$DB_HOST;charset=utf8mb4", $DB_USER, $DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `$DB_NAME` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  } catch (PDOException $e) {
    die("<pre style='padding:1rem;color:#ffdddd;background:#5a1e1e;border-radius:12px'>DB bootstrap error: ".$e->getMessage()."</pre>");
  }

  // Connect to the actual DB
  try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  } catch (PDOException $e) {
    die("<pre style='padding:1rem;color:#ffdddd;background:#5a1e1e;border-radius:12px'>DB connect error: ".$e->getMessage()."</pre>");
  }
  return $pdo;
}
$pdo = pdo_connect_bootstrap($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

/* ========= 3) CREATE TABLES IF NOT EXISTS ========= */
$pdo->exec("
CREATE TABLE IF NOT EXISTS students(
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  roll VARCHAR(40) NOT NULL UNIQUE,
  class VARCHAR(40) NOT NULL,
  skills TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS attendance(
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  date DATE NOT NULL,
  status ENUM('Present','Absent') NOT NULL,
  CONSTRAINT fk_att_stu FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_att (student_id, date)
) ENGINE=InnoDB;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS marks(
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  subject VARCHAR(80) NOT NULL,
  term VARCHAR(40) NOT NULL,
  marks DECIMAL(5,2) NOT NULL CHECK (marks >= 0),
  CONSTRAINT fk_mrk_stu FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;
");

/* ========= 4) HELPERS ========= */
function clean($s) {
  return trim($s ?? "");
}
function flash($msg, $type="success") {
  $_SESSION['flash'] = [$msg, $type];
}
session_start();

/* ========= 5) HANDLE ACTIONS (POST) ========= */
$action = $_POST['action'] ?? $_GET['action'] ?? 'dashboard';

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add_student') {
      $name = clean($_POST['name']);
      $roll = clean($_POST['roll']);
      $class = clean($_POST['class']);
      $skills = clean($_POST['skills']);

      if ($name === "" || $roll === "" || $class === "") {
        flash("Please fill Name, Roll, and Class.", "error");
      } else {
        $stmt = $pdo->prepare("INSERT INTO students(name, roll, class, skills) VALUES(?,?,?,?)");
        $stmt->execute([$name, $roll, $class, $skills]);
        flash("Student added successfully!");
      }
      header("Location: ?action=add_student"); exit;
    }

    if ($action === 'add_attendance') {
      $student_id = (int)($_POST['student_id'] ?? 0);
      $date = clean($_POST['date']);
      $status = clean($_POST['status'] ?? "Present");
      if (!$student_id || $date==="") {
        flash("Please choose student and date.", "error");
      } else {
        $stmt = $pdo->prepare("INSERT INTO attendance(student_id, date, status) VALUES(?,?,?) 
                               ON DUPLICATE KEY UPDATE status=VALUES(status)");
        $stmt->execute([$student_id, $date, $status]);
        flash("Attendance saved.");
      }
      header("Location: ?action=attendance"); exit;
    }

    if ($action === 'add_marks') {
      $student_id = (int)($_POST['student_id'] ?? 0);
      $subject = clean($_POST['subject']);
      $term = clean($_POST['term']);
      $marksVal = clean($_POST['marks']);
      if (!$student_id || $subject==="" || $term==="" || $marksVal==="") {
        flash("Please fill all fields.", "error");
      } else {
        $marksVal = (float)$marksVal;
        $stmt = $pdo->prepare("INSERT INTO marks(student_id, subject, term, marks) VALUES(?,?,?,?)");
        $stmt->execute([$student_id, $subject, $term, $marksVal]);
        flash("Marks saved.");
      }
      header("Location: ?action=marks"); exit;
    }

    if ($action === 'export_csv') {
      // Build a combined per-student report (attendance %, average marks)
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename=student_report_'.date('Ymd_His').'.csv');

      $output = fopen('php://output', 'w');
      fputcsv($output, ['ID','Name','Roll','Class','Skills','Attendance %','Average Marks','Last Updated']);

      // Attendance % = Present / Total * 100
      // Avg marks = AVG(marks)
      $sql = "
        SELECT s.id, s.name, s.roll, s.class, s.skills,
               ROUND(IFNULL(present_cnt/NULLIF(total_cnt,0)*100, 0),2) AS attendance_percent,
               ROUND(IFNULL(avg_marks,0),2) AS avg_marks,
               s.created_at
        FROM students s
        LEFT JOIN (
          SELECT student_id,
                 SUM(status='Present') AS present_cnt,
                 COUNT(*) AS total_cnt
          FROM attendance GROUP BY student_id
        ) a ON a.student_id = s.id
        LEFT JOIN (
          SELECT student_id, AVG(marks) AS avg_marks
          FROM marks GROUP BY student_id
        ) m ON m.student_id = s.id
        ORDER BY s.id DESC
      ";
      $rows = $pdo->query($sql)->fetchAll();
      foreach ($rows as $r) {
        fputcsv($output, [
          $r['id'], $r['name'], $r['roll'], $r['class'], $r['skills'],
          $r['attendance_percent'], $r['avg_marks'], $r['created_at']
        ]);
      }
      fclose($output);
      exit;
    }
  }
} catch (PDOException $e) {
  flash("Database error: ".$e->getMessage(), "error");
  header("Location: ?action=dashboard"); exit;
}

/* ========= 6) FETCH DATA FOR UI ========= */
function get_students($pdo, $q = "", $classFilter = "") {
  $where = [];
  $params = [];
  if ($q !== "") {
    $where[] = "(name LIKE ? OR roll LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%";
  }
  if ($classFilter !== "") {
    $where[] = "class = ?";
    $params[] = $classFilter;
  }
  $sql = "SELECT * FROM students";
  if ($where) $sql .= " WHERE ".implode(" AND ", $where);
  $sql .= " ORDER BY id DESC";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll();
}

function get_student_options($pdo) {
  return $pdo->query("SELECT id, name, roll, class FROM students ORDER BY name ASC")->fetchAll();
}

function get_dashboard_rows($pdo, $q="", $classFilter="") {
  // Build dashboard with attendance % and avg marks
  $students = get_students($pdo, $q, $classFilter);
  $ids = array_map(fn($s)=>$s['id'], $students);
  if (empty($ids)) return [];

  $in = implode(",", array_fill(0, count($ids), "?"));
  // Attendance aggregates
  $attMap = [];
  $stmt = $pdo->prepare("SELECT student_id, SUM(status='Present') AS present_cnt, COUNT(*) AS total_cnt
                         FROM attendance WHERE student_id IN ($in) GROUP BY student_id");
  $stmt->execute($ids);
  foreach ($stmt->fetchAll() as $r) {
    $attMap[$r['student_id']] = $r;
  }
  // Marks aggregates
  $markMap = [];
  $stmt = $pdo->prepare("SELECT student_id, AVG(marks) AS avg_marks
                         FROM marks WHERE student_id IN ($in) GROUP BY student_id");
  $stmt->execute($ids);
  foreach ($stmt->fetchAll() as $r) {
    $markMap[$r['student_id']] = $r;
  }

  // Compose
  $rows = [];
  foreach ($students as $s) {
    $att = $attMap[$s['id']] ?? ['present_cnt'=>0,'total_cnt'=>0];
    $avg = $markMap[$s['id']]['avg_marks'] ?? 0;
    $att_percent = 0;
    if ($att['total_cnt'] > 0) $att_percent = round(($att['present_cnt']/$att['total_cnt'])*100,2);

    $rows[] = [
      'id'=>$s['id'],
      'name'=>$s['name'],
      'roll'=>$s['roll'],
      'class'=>$s['class'],
      'skills'=>$s['skills'],
      'attendance_percent'=>$att_percent,
      'avg_marks'=>round($avg,2),
      'created_at'=>$s['created_at']
    ];
  }
  return $rows;
}

$q = clean($_GET['q'] ?? "");
$classFilter = clean($_GET['class'] ?? "");
$dashboardRows = get_dashboard_rows($pdo, $q, $classFilter);
$studentOptions = get_student_options($pdo);

// simple unique class options
$classOptions = $pdo->query("SELECT DISTINCT class FROM students ORDER BY class ASC")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Student Portal — Single File</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  :root{
    --bg:#0b0f1a;
    --card:#0f172a;
    --muted:#94a3b8;
    --text:#e5e7eb;
    --accent:#7c3aed;
    --accent2:#06b6d4;
    --error:#ef4444;
    --success:#22c55e;
    --glass: rgba(255,255,255,0.06);
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:radial-gradient(1000px 400px at 10% -10%,rgba(124,58,237,0.15),transparent),
                           radial-gradient(800px 360px at 110% -20%,rgba(6,182,212,0.16),transparent),
                           var(--bg); color:var(--text); font-family:ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Cantarell, Noto Sans, Ubuntu, Helvetica, Arial;}
  a{color:inherit}
  .container{max-width:1100px;margin:40px auto;padding:0 16px}
  .shell{background:linear-gradient(145deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
         border:1px solid rgba(255,255,255,0.12); border-radius:22px; box-shadow:0 10px 30px rgba(0,0,0,0.35); overflow:hidden;}
  .header{display:flex;gap:14px;align-items:center;padding:22px 22px;border-bottom:1px solid rgba(255,255,255,0.08);
          backdrop-filter: blur(8px);}
  .logo{width:44px;height:44px;border-radius:14px;background:
        conic-gradient(from 210deg, var(--accent), var(--accent2), var(--accent)); box-shadow:0 0 30px rgba(124,58,237,0.35);}
  .title{font-size:20px;font-weight:700; letter-spacing:0.3px}
  .tabs{display:flex;gap:8px; margin-left:auto; flex-wrap:wrap}
  .tab{padding:10px 14px;border:1px solid rgba(255,255,255,0.12); border-radius:12px; text-decoration:none; font-size:14px;
       background:linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));}
  .tab.active{border-color:transparent; background:linear-gradient(180deg, rgba(124,58,237,0.22), rgba(6,182,212,0.18));}
  .content{padding:22px}
  .grid{display:grid;gap:18px}
  @media(min-width:900px){ .grid-2{grid-template-columns: 1fr 1fr} }

  .card{background:var(--glass); border:1px solid rgba(255,255,255,0.10); border-radius:18px; padding:18px;}
  .card h3{margin:0 0 12px 0; font-size:18px}
  .muted{color:var(--muted); font-size:13px}
  .row{display:flex; gap:12px; flex-wrap:wrap}
  .input, select, .btn{
    width:100%; padding:12px 12px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.16);
    color:var(--text); border-radius:12px; outline:none; font-size:14px;
  }
  .input:focus, select:focus{border-color:var(--accent2); box-shadow:0 0 0 4px rgba(6,182,212,0.18)}
  .btn{cursor:pointer; font-weight:600; letter-spacing:.2px}
  .btn.primary{background:linear-gradient(90deg, var(--accent), var(--accent2)); border-color:transparent}
  .btn.ghost{background:transparent}
  .btn.success{background:linear-gradient(90deg, #16a34a, #22c55e); border-color:transparent}
  .btn.error{background:linear-gradient(90deg, #b91c1c, #ef4444); border-color:transparent}
  .table{width:100%; border-collapse: collapse; overflow:hidden; border-radius:14px; font-size:14px}
  .table th,.table td{padding:12px 10px; border-bottom:1px dashed rgba(255,255,255,0.08); text-align:left}
  .table th{font-size:12px; text-transform:uppercase; letter-spacing:.6px; color:var(--muted)}
  .badge{padding:6px 10px; border-radius:999px; font-size:12px; border:1px solid rgba(255,255,255,0.16)}
  .ok{color:#22c55e}
  .bad{color:#ef4444}
  .flash{margin:0 22px 18px; padding:12px 14px; border-radius:12px; font-size:14px; border:1px solid rgba(255,255,255,0.16)}
  .flash.success{background:rgba(34,197,94,0.12); border-color:rgba(34,197,94,0.35)}
  .flash.error{background:rgba(239,68,68,0.12); border-color:rgba(239,68,68,0.35)}
  .row .col{flex:1 1 220px}
  .footer{padding:18px 22px; border-top:1px solid rgba(255,255,255,0.08); font-size:12px; color:var(--muted)}
  .searchbar{display:flex; gap:10px; flex-wrap:wrap; align-items:center}
  .pill{display:inline-flex; align-items:center; gap:8px; padding:10px 12px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); border-radius:999px}
  .pill input{background:transparent; border:none; color:var(--text); outline:none; width:200px}
  .hint{font-size:12px; color:var(--muted); margin-top:6px}
</style>
</head>
<body>
  <div class="container">
    <div class="shell">
      <div class="header">
        <div class="logo"></div>
        <div class="title">Student Portal — Single File</div>
        <div class="tabs">
          <a class="tab <?= $action==='dashboard' ? 'active':'' ?>" href="?action=dashboard">Dashboard</a>
          <a class="tab <?= $action==='add_student' ? 'active':'' ?>" href="?action=add_student">Add Student</a>
          <a class="tab <?= $action==='attendance' ? 'active':'' ?>" href="?action=attendance">Attendance</a>
          <a class="tab <?= $action==='marks' ? 'active':'' ?>" href="?action=marks">Marks</a>
          <form method="post" action="?action=export_csv" style="display:inline">
            <button class="tab" style="border:none" type="submit" name="action" value="export_csv">Export CSV</button>
          </form>
        </div>
      </div>

      <?php if(isset($_SESSION['flash'])): [$msg,$type]=$_SESSION['flash']; unset($_SESSION['flash']); ?>
        <div class="flash <?= htmlspecialchars($type) ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <div class="content">
        <?php if ($action==='dashboard'): ?>
          <div class="card">
            <h3>Overview</h3>
            <div class="searchbar" style="margin:8px 0 14px">
              <form method="get" class="row" style="width:100%">
                <input type="hidden" name="action" value="dashboard">
                <div class="pill">
                  🔎
                  <input type="text" name="q" placeholder="Search by name or roll..." value="<?= htmlspecialchars($q) ?>">
                </div>
                <div class="pill">
                  🏷️
                  <select name="class" style="background:transparent; border:none; color:var(--text); outline:none;">
                    <option value="">All Classes</option>
                    <?php foreach($classOptions as $co): ?>
                      <option value="<?= htmlspecialchars($co['class']) ?>" <?= $classFilter===$co['class']?'selected':'' ?>>
                        <?= htmlspecialchars($co['class']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <button class="btn" style="padding:10px 16px">Apply</button>
              </form>
              <div class="hint">Tip: Use the <b>Export CSV</b> tab to download the combined report.</div>
            </div>

            <div class="table-wrap">
              <table class="table">
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Roll</th>
                  <th>Class</th>
                  <th>Skills</th>
                  <th>Attendance %</th>
                  <th>Average Marks</th>
                  <th>Health</th>
                </tr>
                <?php if (!$dashboardRows): ?>
                  <tr><td colspan="8" class="muted">No students yet. Add some from the <b>Add Student</b> tab.</td></tr>
                <?php else: ?>
                  <?php foreach($dashboardRows as $r): 
                    $health = ($r['attendance_percent']>=75 && $r['avg_marks']>=60) ? 'Good' :
                              (($r['attendance_percent']>=60 || $r['avg_marks']>=50) ? 'Average' : 'Needs Attention');
                  ?>
                    <tr>
                      <td><?= htmlspecialchars($r['id']) ?></td>
                      <td><?= htmlspecialchars($r['name']) ?></td>
                      <td><?= htmlspecialchars($r['roll']) ?></td>
                      <td><span class="badge"><?= htmlspecialchars($r['class']) ?></span></td>
                      <td class="muted"><?= htmlspecialchars($r['skills']) ?></td>
                      <td><?= number_format($r['attendance_percent'],2) ?>%</td>
                      <td><?= number_format($r['avg_marks'],2) ?></td>
                      <td>
                        <?php if($health==='Good'): ?>
                          <span class="ok">●</span> Good
                        <?php elseif($health==='Average'): ?>
                          ◐ Average
                        <?php else: ?>
                          <span class="bad">●</span> Needs Attention
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($action==='add_student'): ?>
          <div class="grid grid-2">
            <div class="card">
              <h3>Add Student</h3>
              <form method="post" class="grid">
                <input type="hidden" name="action" value="add_student">
                <div class="row">
                  <div class="col">
                    <label class="muted">Full Name</label>
                    <input class="input" type="text" name="name" placeholder="e.g., Aditi Sharma" required>
                  </div>
                  <div class="col">
                    <label class="muted">Roll</label>
                    <input class="input" type="text" name="roll" placeholder="e.g., 23CS1042" required>
                  </div>
                </div>
                <div class="row">
                  <div class="col">
                    <label class="muted">Class</label>
                    <input class="input" type="text" name="class" placeholder="e.g., CSE-3A" required>
                  </div>
                  <div class="col">
                    <label class="muted">Skills (comma-separated)</label>
                    <input class="input" type="text" name="skills" placeholder="Python, C++, Leadership">
                  </div>
                </div>
                <div class="row">
                  <div class="col">
                    <button class="btn primary" type="submit">Save Student</button>
                  </div>
                </div>
              </form>
              <div class="hint">Note: Roll must be unique.</div>
            </div>

            <div class="card">
              <h3>Quick Tips</h3>
              <div class="muted">
                • After adding students, go to <b>Attendance</b> tab to mark daily presence.<br>
                • Use <b>Marks</b> tab to add subject-wise scores for a term (e.g., Midterm, Finals).<br>
                • The <b>Dashboard</b> shows Attendance % and Average Marks, and lets you filter by class.<br>
                • <b>Export CSV</b> downloads a combined report for all students.
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($action==='attendance'): ?>
          <div class="card">
            <h3>Record Attendance</h3>
            <form method="post" class="grid">
              <input type="hidden" name="action" value="add_attendance">
              <div class="row">
                <div class="col">
                  <label class="muted">Student</label>
                  <select name="student_id" class="input" required>
                    <option value="">Select Student</option>
                    <?php foreach($studentOptions as $s): ?>
                      <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']." — ".$s['roll']." (".$s['class'].")") ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col">
                  <label class="muted">Date</label>
                  <input type="date" name="date" class="input" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col">
                  <label class="muted">Status</label>
                  <select name="status" class="input">
                    <option>Present</option>
                    <option>Absent</option>
                  </select>
                </div>
              </div>
              <div class="row">
                <div class="col">
                  <button class="btn primary" type="submit">Save Attendance</button>
                </div>
              </div>
            </form>
            <div class="hint">Re-saving the same date will update the status (no duplicates).</div>
          </div>
        <?php endif; ?>

        <?php if ($action==='marks'): ?>
          <div class="card">
            <h3>Record Marks</h3>
            <form method="post" class="grid">
              <input type="hidden" name="action" value="add_marks">
              <div class="row">
                <div class="col">
                  <label class="muted">Student</label>
                  <select name="student_id" class="input" required>
                    <option value="">Select Student</option>
                    <?php foreach($studentOptions as $s): ?>
                      <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']." — ".$s['roll']." (".$s['class'].")") ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col">
                  <label class="muted">Subject</label>
                  <input class="input" type="text" name="subject" placeholder="e.g., Mathematics" required>
                </div>
              </div>
              <div class="row">
                <div class="col">
                  <label class="muted">Term</label>
                  <input class="input" type="text" name="term" placeholder="e.g., Midterm 1" required>
                </div>
                <div class="col">
                  <label class="muted">Marks</label>
                  <input class="input" type="number" step="0.01" name="marks" placeholder="e.g., 78.5" required>
                </div>
              </div>
              <div class="row">
                <div class="col">
                  <button class="btn primary" type="submit">Save Marks</button>
                </div>
              </div>
            </form>
          </div>
        <?php endif; ?>
      </div>

      <div class="footer">
        © <?= date('Y') ?> Student Portal • Single-file demo • HTML + CSS + PHP + MySQL
      </div>
    </div>
  </div>
</body>
</html>
