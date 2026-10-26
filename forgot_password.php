<?php
session_start();
include 'config/db_connect.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

// ── STEP 1: CHECK EMAIL & RETURN SECURITY QUESTION ───────────────────────────
if ($action === 'check_email') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Email is required.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, name, security_question FROM users WHERE email = ? AND status = 'active'");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'We could not start a reset for that email. Check it, or contact your administrator.']);
        exit;
    }

    if (empty($row['security_question'])) {
        echo json_encode(['success' => false, 'message' => 'We could not start a reset for that email. Check it, or contact your administrator.']);
        exit;
    }

    echo json_encode([
        'success'  => true,
        'question' => $row['security_question']
    ]);
    exit;
}

// ── STEP 2: VERIFY SECURITY ANSWER ───────────────────────────────────────────
if ($action === 'verify_answer') {
    // Lock out after repeated wrong answers (per session)
    if (($_SESSION['fp_lock_until'] ?? 0) > time()) {
        echo json_encode(['success' => false, 'message' => 'Too many incorrect attempts. Please try again in 10 minutes.']);
        exit;
    }
    $email  = trim($_POST['email']  ?? '');
    $answer = trim($_POST['answer'] ?? '');

    if (empty($email) || empty($answer)) {
        echo json_encode(['success' => false, 'message' => 'Email and answer are required.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, security_answer FROM users WHERE email = ? AND status = 'active'");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Incorrect answer. Please try again.']);
        exit;
    }

    // Compare lowercased answer against stored hash
    if (!password_verify(strtolower($answer), (string) $row['security_answer'])) {
        $_SESSION['fp_attempts'] = ($_SESSION['fp_attempts'] ?? 0) + 1;
        if ($_SESSION['fp_attempts'] >= 5) {
            $_SESSION['fp_lock_until'] = time() + 600;
            $_SESSION['fp_attempts'] = 0;
        }
        echo json_encode(['success' => false, 'message' => 'Incorrect answer. Please try again.']);
        exit;
    }

    unset($_SESSION['fp_attempts'], $_SESSION['fp_lock_until']);

    // Generate a short-lived token tied to the user id and store in session
    $token = bin2hex(random_bytes(16));
    $_SESSION['pw_reset_token']   = $token;
    $_SESSION['pw_reset_user_id'] = $row['id'];
    $_SESSION['pw_reset_expiry']  = time() + 600; // 10 minutes

    echo json_encode(['success' => true, 'token' => $token]);
    exit;
}

// ── STEP 3: RESET PASSWORD ────────────────────────────────────────────────────
if ($action === 'reset_password') {
    $token    = $_POST['token']    ?? '';
    $password = $_POST['password'] ?? '';

    // Validate token
    if (empty($_SESSION['pw_reset_token']) ||
        $_SESSION['pw_reset_token'] !== $token ||
        time() > ($_SESSION['pw_reset_expiry'] ?? 0)) {
        echo json_encode(['success' => false, 'message' => 'Reset session expired or invalid. Please start again.']);
        exit;
    }

    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
        exit;
    }

    $user_id = $_SESSION['pw_reset_user_id'];
    $hashed  = password_hash($password, PASSWORD_DEFAULT);

    $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $upd->bind_param("si", $hashed, $user_id);

    if ($upd->execute()) {
        // Clear reset session data
        unset($_SESSION['pw_reset_token'], $_SESSION['pw_reset_user_id'], $_SESSION['pw_reset_expiry']);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating password. Please try again.']);
    }
    $upd->close();
    exit;
}

// Unknown action
echo json_encode(['success' => false, 'message' => 'Invalid request.']);