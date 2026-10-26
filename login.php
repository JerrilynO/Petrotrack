<?php
session_start();
include 'config/db_connect.php';

$error   = "";
$success = "";

if (isset($_GET['msg']) && $_GET['msg'] == 'deactivated') {
    $success = "Your account has been deactivated successfully. Contact your logistics manager to reactivate it.";
}
if (isset($_GET['suspended'])) {
    $reason = urldecode($_GET['reason'] ?? 'Your account has been suspended pending investigation.');
    $error  = "🚫 Account Suspended: <strong>" . htmlspecialchars($reason) . "</strong> Please contact the administrator.";
}
if (isset($_GET['pw_reset']) && $_GET['pw_reset'] == 'success') {
    $success = "✅ Password reset successfully. You can now log in with your new password.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email    = $_POST['email'];
    $password = $_POST['password'];

    $sql  = "SELECT id, name, password, role, station_id, status, status_reason FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        if (password_verify($password, $row['password'])) {
            if ($row['status'] == 'inactive') {
                $reason = !empty($row['status_reason']) ? $row['status_reason'] : 'No reason provided.';
                $error  = "Your account has been deactivated. Reason: <strong>" . htmlspecialchars($reason) . "</strong>. Please contact your logistics manager.";
            } elseif ($row['status'] == 'suspended') {
                $reason = !empty($row['status_reason']) ? $row['status_reason'] : 'No reason provided.';
                $error  = "Your account has been suspended. Reason: <strong>" . htmlspecialchars($reason) . "</strong>. Please contact your logistics manager.";
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id']    = $row['id'];
                $_SESSION['name']       = $row['name'];
                $_SESSION['role']       = $row['role'];
                $_SESSION['station_id'] = $row['station_id'];

                if ($row['role'] == 'admin')      { header("Location: admin_dashboard.php");      exit(); }
                elseif ($row['role'] == 'driver')     { header("Location: driver_dashboard.php");     exit(); }
                elseif ($row['role'] == 'supervisor') { header("Location: supervisor_dashboard.php"); exit(); }
                else { $_SESSION = array(); $error = "This account role is not supported. Contact your administrator."; }
            }
        } else {
            $error = "Invalid password.";
        }
    } else {
        $error = "No user found with that email.";
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Petroleum Logistics System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #603274;
            --primary-dark: #0369a1;
            --secondary: #0f172a;
            --bg-body: #f8fafc;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --shadow: 0 20px 60px rgba(0,0,0,0.1);
            --radius: 12px;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background-color:var(--bg-body); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }

        .login-container { background:white; border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; max-width:1000px; width:100%; display:grid; grid-template-columns:1fr 1fr; animation:slideUp .6s ease-out; }
        @keyframes slideUp { from{opacity:0;transform:translateY(30px)} to{opacity:1;transform:translateY(0)} }

        .login-left { background:linear-gradient(135deg,var(--primary) 0%,var(--primary-dark) 100%); padding:60px 40px; color:white; display:flex; flex-direction:column; justify-content:center; position:relative; overflow:hidden; }
        .login-left::before { content:''; position:absolute; width:300px; height:300px; background:rgba(255,255,255,.1); border-radius:50%; top:-100px; right:-100px; }
        .login-left::after  { content:''; position:absolute; width:200px; height:200px; background:rgba(255,255,255,.1); border-radius:50%; bottom:-50px; left:-50px; }
        .brand-logo  { font-size:48px; margin-bottom:10px; z-index:1; }
        .brand-title { font-size:32px; font-weight:700; margin-bottom:15px; z-index:1; letter-spacing:-0.5px; }
        .brand-description { font-size:15px; line-height:1.7; opacity:.95; z-index:1; margin-bottom:30px; font-weight:300; }
        .feature-list { list-style:none; z-index:1; }
        .feature-list li { padding:12px 0; display:flex; align-items:center; gap:12px; font-size:14px; }
        .feature-list li::before { content:'✓'; background:rgba(255,255,255,.2); width:24px; height:24px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; }

        .login-right  { padding:60px 50px; background:white; }
        .login-header { margin-bottom:40px; }
        .login-header h2 { font-size:28px; color:var(--secondary); margin-bottom:8px; font-weight:700; }
        .login-header p  { color:var(--text-muted); font-size:14px; }

        .alert-success { background:#dcfce7; color:#166534; padding:12px 16px; border-radius:8px; margin-bottom:20px; border-left:4px solid #22c55e; font-size:14px; display:flex; align-items:center; gap:10px; }
        .error-message { background:#fef2f2; color:#b91c1c; padding:12px 16px; border-radius:8px; margin-bottom:20px; border-left:4px solid #ef4444; font-size:14px; display:flex; align-items:center; gap:10px; }

        .form-group { margin-bottom:25px; position:relative; }
        .form-group label { display:block; margin-bottom:8px; color:var(--secondary); font-weight:600; font-size:13px; text-transform:uppercase; letter-spacing:.5px; }
        .form-group input { width:100%; padding:14px 16px; border:1px solid var(--border); border-radius:8px; font-size:15px; transition:all .2s; background:white; color:var(--text-main); font-family:'Inter',sans-serif; }
        .form-group input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(96,50,116,.1); }

        .password-wrapper { position:relative; }
        .password-toggle-circle { position:absolute; right:10px; top:50%; transform:translateY(-50%); width:36px; height:36px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; border:1px solid transparent; transition:background .2s; color:var(--text-muted); }
        .password-toggle-circle:hover { background:#e2e8f0; color:var(--secondary); }
        .password-toggle-circle svg { width:18px; height:18px; }

        .label-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
        .label-row label { margin-bottom:0; }
        .forgot-link { font-size:12px; color:var(--primary); font-weight:600; text-decoration:none; cursor:pointer; background:none; border:none; padding:0; font-family:'Inter',sans-serif; }
        .forgot-link:hover { text-decoration:underline; }

        .btn-login { width:100%; padding:14px; background:var(--primary); color:white; border:none; border-radius:8px; font-size:16px; font-weight:600; cursor:pointer; transition:all .3s; margin-top:10px; font-family:'Inter',sans-serif; }
        .btn-login:hover { background:var(--primary-dark); transform:translateY(-1px); box-shadow:0 4px 12px rgba(2,132,199,.25); }
        .btn-login.loading { pointer-events:none; opacity:.7; position:relative; }
        .btn-login.loading::after { content:''; position:absolute; width:16px; height:16px; border:2px solid white; border-top-color:transparent; border-radius:50%; right:20px; top:50%; transform:translateY(-50%); animation:spin .6s linear infinite; }
        @keyframes spin { to{transform:translateY(-50%) rotate(360deg)} }

        .login-link { text-align:center; margin-top:24px; font-size:14px; color:var(--text-muted); }
        .login-link a { color:var(--primary); text-decoration:none; font-weight:600; }
        .login-link a:hover { text-decoration:underline; }

        @media(max-width:768px) {
            .login-container { grid-template-columns:1fr; max-width:500px; }
            .login-left { padding:40px 30px; text-align:center; }
            .brand-title { font-size:24px; }
            .feature-list { display:none; }
            .login-right { padding:40px 30px; }
        }

        /* ── MODAL ── */
        .modal-overlay { display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,.5); align-items:center; justify-content:center; padding:20px; }
        .modal-overlay.open { display:flex; }
        .modal-box { background:white; border-radius:16px; padding:36px; width:100%; max-width:440px; box-shadow:0 25px 60px rgba(0,0,0,.2); animation:slideUp .35s ease; position:relative; }
        .modal-close { position:absolute; top:16px; right:18px; font-size:22px; cursor:pointer; color:var(--text-muted); background:none; border:none; line-height:1; }
        .modal-close:hover { color:var(--secondary); }
        .modal-box h3 { font-size:20px; font-weight:700; color:var(--secondary); margin-bottom:6px; }
        .modal-box p  { font-size:13px; color:var(--text-muted); margin-bottom:24px; line-height:1.6; }

        /* STEP INDICATOR */
        .steps { display:flex; gap:8px; margin-bottom:24px; }
        .step { flex:1; height:4px; border-radius:2px; background:#e2e8f0; transition:.3s; }
        .step.done { background:var(--primary); }
        .step.active { background:var(--primary); opacity:.5; }

        .modal-group { margin-bottom:18px; }
        .modal-group label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); margin-bottom:7px; }
        .modal-group input, .modal-group select { width:100%; padding:12px 14px; border:1px solid var(--border); border-radius:8px; font-size:14px; font-family:'Inter',sans-serif; color:var(--text-main); outline:none; transition:.2s; }
        .modal-group input:focus, .modal-group select:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(96,50,116,.1); }

        .modal-error   { background:#fef2f2; color:#991b1b; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:13px; border-left:3px solid #ef4444; }
        .modal-success { background:#f0fdf4; color:#166534; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:13px; border-left:3px solid #22c55e; }

        .btn-modal { width:100%; padding:12px; background:var(--primary); color:white; border:none; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; transition:.2s; font-family:'Inter',sans-serif; margin-top:4px; }
        .btn-modal:hover { background:#4a2560; transform:translateY(-1px); }
        .btn-back { background:none; border:none; color:var(--text-muted); font-size:13px; cursor:pointer; margin-top:12px; width:100%; font-family:'Inter',sans-serif; }
        .btn-back:hover { color:var(--secondary); }

        .q-display { background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:12px 14px; font-size:14px; font-weight:600; color:var(--secondary); margin-bottom:14px; }
    </style>
</head>
<body>

<div class="login-container">
    <!-- Left Side -->
    <div class="login-left">
        <div class="brand-logo"></div>
        <h1 class="brand-title">🛢️PetroTrack</h1>
        <p class="brand-description">
            Take control of your petroleum logistics with real-time system updates, automated alerts, intelligent delivery monitoring and compliance tracking. A smart solution designed for the Nigerian environment to reduce diversion and ensure accountability across every stage of distribution.
        </p>
        <ul class="feature-list">
            <li>Simulated Tracking</li>
            <li>Automated delivery reports</li>
            <li>Role-based access control</li>
            <li>Fleet Digitalization</li>
            <li>Operational Reporting &amp; Insights</li>
        </ul>
    </div>

    <!-- Right Side -->
    <div class="login-right">
        <div class="login-header">
            <h2>Welcome Back</h2>
            <p>Enter your credentials to access your account</p>
        </div>

        <?php if ($success): ?>
        <div class="alert-success"><span>✅</span><span><?php echo $success; ?></span></div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="error-message"><span>⚠</span><span><?php echo $error; ?></span></div>
        <?php endif; ?>

        <form method="POST" action="login.php" id="loginForm">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="Enter your email" required autofocus>
            </div>

            <div class="form-group">
                <div class="label-row">
                    <label for="password">Password</label>
                </div>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    <span class="password-toggle-circle" onclick="togglePassword()">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11 8z"></path><circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </span>
                </div>
            </div>

            <!-- Forgot password link moved above login button -->
            <div style="text-align: right; margin-top: -15px; margin-bottom: 15px;">
                <button type="button" class="forgot-link" onclick="openForgot()">Forgot password?</button>
            </div>

            <button type="submit" class="btn-login" id="loginBtn">Login</button>
        </form>

        <div class="login-link">Don't have an account? <a href="register.php">Register here</a></div>
        <div style="text-align:center;margin-top:16px;">
            <a href="index.php" style="color:#64748b;font-size:14px;text-decoration:none;">← Back to Home</a>
        </div>
    </div>
</div>

<!-- ── FORGOT PASSWORD MODAL ── -->
<div class="modal-overlay" id="forgotModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeForgot()">&times;</button>

        <!-- STEP INDICATORS -->
        <div class="steps">
            <div class="step active" id="step-bar-1"></div>
            <div class="step" id="step-bar-2"></div>
            <div class="step" id="step-bar-3"></div>
        </div>

        <!-- STEP 1: Enter Email -->
        <div id="fp-step1">
            <h3>Reset Password</h3>
            <p>Enter the email address on your account and we'll verify your identity.</p>
            <div id="fp-err1" style="display:none;" class="modal-error"></div>
            <div class="modal-group">
                <label>Email Address</label>
                <input type="email" id="fp-email" placeholder="Enter your email address">
            </div>
            <button class="btn-modal" onclick="fpStep1()">Continue →</button>
        </div>

        <!-- STEP 2: Answer Security Question -->
        <div id="fp-step2" style="display:none;">
            <h3>Security Question</h3>
            <p>Answer your security question to verify your identity.</p>
            <div id="fp-err2" style="display:none;" class="modal-error"></div>
            <div class="q-display" id="fp-question-display">—</div>
            <div class="modal-group">
                <label>Your Answer</label>
                <input type="text" id="fp-answer" placeholder="Type your answer...">
            </div>
            <button class="btn-modal" onclick="fpStep2()">Verify →</button>
            <button class="btn-back" onclick="goStep(1)">← Back</button>
        </div>

        <!-- STEP 3: Set New Password -->
        <div id="fp-step3" style="display:none;">
            <h3>Set New Password</h3>
            <p>Choose a strong new password for your account.</p>
            <div id="fp-err3" style="display:none;" class="modal-error"></div>
            <div class="modal-group">
                <label>New Password</label>
                <input type="password" id="fp-newpw" placeholder="At least 8 characters">
            </div>
            <div class="modal-group">
                <label>Confirm New Password</label>
                <input type="password" id="fp-confirmpw" placeholder="Repeat new password">
            </div>
            <button class="btn-modal" onclick="fpStep3()">Reset Password</button>
        </div>

    </div>
</div>

<script>
// ── LOGIN HELPERS ─────────────────────────────────────────────────────────────
function togglePassword() {
    const f = document.getElementById('password');
    const b = document.querySelector('.password-toggle-circle');
    if (f.type === 'password') {
        f.type = 'text';
        b.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>`;
    } else {
        f.type = 'password';
        b.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11 8z"></path><circle cx="12" cy="12" r="3"></circle></svg>`;
    }
}
document.getElementById('loginForm').addEventListener('submit', function() {
    const btn = document.getElementById('loginBtn');
    btn.classList.add('loading');
    btn.textContent = 'Logging in...';
});

// ── FORGOT PASSWORD ───────────────────────────────────────────────────────────
var fpResetToken = ''; // stores user id after identity verified

function openForgot()  { document.getElementById('forgotModal').classList.add('open'); goStep(1); }
function closeForgot() { document.getElementById('forgotModal').classList.remove('open'); }
window.onclick = function(e) { if (e.target.id === 'forgotModal') closeForgot(); };

function goStep(n) {
    [1,2,3].forEach(function(i) {
        document.getElementById('fp-step' + i).style.display = i === n ? '' : 'none';
        var bar = document.getElementById('step-bar-' + i);
        bar.className = 'step' + (i < n ? ' done' : (i === n ? ' active' : ''));
    });
    ['fp-err1','fp-err2','fp-err3'].forEach(function(id) {
        var el = document.getElementById(id); if(el) { el.style.display='none'; el.textContent=''; }
    });
}

function showErr(id, msg) {
    var el = document.getElementById(id);
    el.textContent = msg;
    el.style.display = 'block';
}

// STEP 1 — verify email exists and has a security question set
function fpStep1() {
    var email = document.getElementById('fp-email').value.trim();
    if (!email) { showErr('fp-err1', 'Please enter your email address.'); return; }

    fetch('forgot_password.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=check_email&email=' + encodeURIComponent(email)
    })
    .then(function(r){ return r.json(); })
    .then(function(d) {
        if (d.success) {
            document.getElementById('fp-question-display').textContent = d.question;
            goStep(2);
        } else {
            showErr('fp-err1', d.message);
        }
    });
}

// STEP 2 — verify security answer
function fpStep2() {
    var email  = document.getElementById('fp-email').value.trim();
    var answer = document.getElementById('fp-answer').value.trim();
    if (!answer) { showErr('fp-err2', 'Please enter your answer.'); return; }

    fetch('forgot_password.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=verify_answer&email=' + encodeURIComponent(email) + '&answer=' + encodeURIComponent(answer)
    })
    .then(function(r){ return r.json(); })
    .then(function(d) {
        if (d.success) {
            fpResetToken = d.token;
            goStep(3);
        } else {
            showErr('fp-err2', d.message);
        }
    });
}

// STEP 3 — set new password
function fpStep3() {
    var pw  = document.getElementById('fp-newpw').value;
    var cpw = document.getElementById('fp-confirmpw').value;
    if (pw.length < 8)   { showErr('fp-err3', 'Password must be at least 8 characters.'); return; }
    if (pw !== cpw)       { showErr('fp-err3', 'Passwords do not match.'); return; }

    fetch('forgot_password.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=reset_password&token=' + encodeURIComponent(fpResetToken) + '&password=' + encodeURIComponent(pw)
    })
    .then(function(r){ return r.json(); })
    .then(function(d) {
        if (d.success) {
            closeForgot();
            window.location.href = 'login.php?pw_reset=success';
        } else {
            showErr('fp-err3', d.message);
        }
    });
}
</script>
</body>
</html>