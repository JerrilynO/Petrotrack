<?php
include 'config/db_connect.php'; 

$message = "";
$msgType = ""; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Capture data
    $name = $_POST['name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $role = trim($_POST['role']);
    $password = $_POST['password'];
    $station_id = ($role === 'supervisor' && !empty($_POST['station_id'])) ? $_POST['station_id'] : NULL;

    // Validation
    if (!in_array($role, ['driver', 'supervisor'], true)) {
        $message = "Please select a valid role.";
        $msgType = "error";
    } elseif (strlen($password) < 8) {
        $message = "Password must be at least 8 characters.";
        $msgType = "error";
    } elseif ($password !== $_POST['confirm_password']) {
        $message = "Passwords do not match!";
        $msgType = "error";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (name, email, phone, role, password, station_id) VALUES (?, ?, ?, ?, ?, ?)";
        if (!$stmt = $conn->prepare($sql)) {
            die("SQL Error: " . $conn->error);
        }
        $stmt->bind_param("sssssi", $name, $email, $phone, $role, $hashed_password, $station_id);
            
        if ($stmt->execute()) {
            // Set success message in session to display on login page
            session_start();
            $_SESSION['registration_success'] = "Registration successful! Please login to continue.";
            
            // Redirect to login page
            header("Location: login.php");
            exit(); // Make sure to call exit after header redirect
        } else {
            $message = "Error: " . $stmt->error;
            $msgType = "error";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Petroleum Logistics System</title>
    <!-- Importing Inter Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Matching the Home/Login Variables */
            --primary: #603274;
            --primary-dark: #0369a1;
            --secondary: #0f172a;
            --bg-body: #f8fafc;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            --radius: 12px;
        }

        /* --- CORE STYLES --- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            max-width: 1000px;
            width: 100%;
            display: grid;
            grid-template-columns: 1fr 1fr;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Left Side - Branding */
        .login-left {
            /* Updated to Blue Gradient */
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            padding: 60px 40px;
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .login-left::before {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            top: -100px;
            right: -100px;
        }

        .login-left::after {
            content: '';
            position: absolute;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            bottom: -50px;
            left: -50px;
        }

        .brand-logo {
            font-size: 48px;
            margin-bottom: 10px;
            z-index: 1;
        }

        .brand-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 15px;
            z-index: 1;
            letter-spacing: -0.5px;
        }

        .brand-description {
            font-size: 15px;
            line-height: 1.7;
            opacity: 0.95;
            z-index: 1;
            margin-bottom: 30px;
            font-weight: 300;
        }

        /* Right Side - Form */
        .login-right {
            padding: 40px 50px;
            background: white;
            overflow-y: auto;
            max-height: 90vh;
        }

        .login-header {
            margin-bottom: 30px;
            text-align: center;
        }

        .login-header h2 {
            font-size: 28px;
            color: var(--secondary);
            margin-bottom: 8px;
            font-weight: 700;
        }

        .login-header p {
            color: var(--text-muted);
            font-size: 14px;
        }

        /* Message Styles */
        .alert-box {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success {
            background: #dcfce7;
            color: #166534;
            border-left: 4px solid #22c55e;
        }
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--secondary);
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.2s ease;
            background: #ffffff;
            appearance: none;
            color: var(--text-main);
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
        }

        /* Feature List */
        .feature-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 12px;
            z-index: 1;
        }

        .feature-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }

        .feature-list li::before {
            content: '✓';
            background: rgba(255, 255, 255, 0.2);
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            flex-shrink: 0;
        }

        /* Custom Dropdown Arrow */
        .select-wrapper {
            position: relative;
        }
        .select-wrapper::after {
            content: '▼';
            font-size: 10px;
            color: var(--text-muted);
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .btn-register {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .btn-register:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
        }

        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: var(--text-muted);
        }

        .login-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .login-container {
                grid-template-columns: 1fr;
            }

            .login-left {
                padding: 40px 30px;
                text-align: center;
                display: none; /* Hide branding on mobile to focus on form */
            }

            .login-right {
                padding: 30px 20px;
                max-height: none;
            }
        }
    </style>
</head>
<body>

<div class="login-container">
    <!-- Left Side - Branding -->
    <div class="login-left">
        <div class="brand-logo">🛢️</div>
        <h1 class="brand-title">PetroTrack</h1>
        <p class="brand-description">
            Secure logistics management for the Nigerian downstream sector. 
            Track tankers from depot to filling stations efficiently.
        </p>
        <ul class="feature-list">
           <li>Simulated Tracking</li>
            <li>Automated delivery reports</li>
            <li>Role-based access control</li>
            <li>Fleet Digitalization</li>
            <li>Operational Reporting & Insights</li>
        </ul>
    </div>

    <!-- Right Side - Register Form -->
    <div class="login-right">
        <div class="login-header">
            <h2>Create Account</h2>
            <p>Register to access the portal</p>
        </div>

        <!-- Message Display (Success/Error) -->
        <?php if ($message): ?>
            <div class="alert-box alert-<?php echo $msgType; ?>">
                <span><?php echo $msgType == 'success' ? '✅' : '⚠️'; ?></span>
                <span><?php echo $message; ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php" id="registerForm">
            
            <div class="form-group">
                <label for="name">Full Name</label>
                <input type="text" id="name" name="name" placeholder="e.g. Chinedu Okafor" required>
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="name@company.com" required>
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" placeholder="+234 80X XXX XXXX" required>
            </div>

            <div class="form-group">
                <label for="role">Role</label>
                <div class="select-wrapper">
                    <select id="role" name="role" required onchange="toggleStation(this.value)">
                        <option value="" disabled selected>Select your role</option>
                        <option value="driver">Tanker Driver</option>
                        <option value="supervisor">Station Supervisor</option>
                    </select>
                </div>
            </div>

            <!-- Station dropdown — only shows when supervisor is selected -->
            <div class="form-group" id="stationGroup" style="display: none;">
                <label for="station_id">Your Station</label>
                <div class="select-wrapper">
                    <select id="station_id" name="station_id">
                        <option value="" disabled selected>Select your station</option>
                        <?php
                        $stations_res = $conn->query("SELECT id, name, location FROM stations ORDER BY name");
                        while($s = $stations_res->fetch_assoc()):
                        ?>
                            <option value="<?php echo $s['id']; ?>">
                                <?php echo htmlspecialchars($s['name'] . ' — ' . $s['location']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Create a password" required>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" placeholder="Retype password" required>
            </div>

            <button type="submit" class="btn-register">
                Create Account
            </button>

        </form>

        <div class="login-link">
            Already registered? <a href="login.php">Login here</a>
        </div>

        <!-- Back to Home -->
        <div style="text-align:center; margin-top:16px;">
            <a href="index.php" style="display:inline-flex; align-items:center; gap:6px; color:#64748b; font-size:14px; text-decoration:none; transition:color 0.2s;">
                ← Back to Home
            </a>
        </div>
    </div>
</div>

<script>
    // Client-side Password Validation
    document.getElementById('registerForm').addEventListener('submit', function(e) {
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirm_password').value;

        if (password !== confirmPassword) {
            e.preventDefault();
            alert('Passwords do not match.');
            return;
        }
    });

    // Toggle Station Visibility
    function toggleStation(role) {
        var stationGroup = document.getElementById('stationGroup');
        var stationSelect = document.getElementById('station_id');
        if (role === 'supervisor') {
            stationGroup.style.display = 'block';
            stationSelect.required = true;
        } else {
            stationGroup.style.display = 'none';
            stationSelect.required = false;
        }
    }
</script>

</body>
</html>