<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home | PetroTrack Logistics</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* --- VARIABLES --- */
        :root {
            --primary: #4f0470;
            --primary-dark: #0369a1;
            --secondary: #0f172a;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --shadow-lg: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            --radius: 12px;
            --radius-lg: 20px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f3e8ff 50%, #f8fafc 100%);
            background-size: 200% 200%; /* ← add this */
            animation: shimmer 4s ease infinite; /* ← add this */
            color: var(--text-main);
            line-height: 1.5;
            overflow-x: hidden;
       }

       @keyframes shimmer {
            0%   { background-position: 0% 0%; }
            50%  { background-position: 100% 100%; }
            100% { background-position: 0% 0%; }
        }

        /* --- NAV --- */
        nav {
            /* GRADIENT ADDED: Glass morphism with gradient */
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.98) 0%, rgba(245, 240, 255, 0.98) 100%);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .nav-logo {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--secondary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .nav-logo span { color: var(--primary); }

        .nav-links {
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--text-muted);
            font-weight: 500;
            font-size: 0.95rem;
            transition: 0.2s;
        }
        .nav-links a:hover { color: var(--primary); }

        /* RESTORED: Original Login Button Style */
        .btn-nav {
            background: var(--secondary);
            color: white !important;
            padding: 0.6rem 1.5rem;
            border-radius: 50px;
            font-size: 0.9rem;
            transition: 0.2s;
        }
        .btn-nav:hover { background: var(--primary); transform: translateY(-1px); }

        /* NEW: Sign Up Button Style */
        .btn-signup {
            background: linear-gradient(135deg, var(--primary) 0%, #7c3aed 100%);
            color: white !important;
            padding: 0.6rem 1.5rem;
            border-radius: 50px;
            font-size: 0.9rem;
            transition: 0.2s;
            box-shadow: 0 4px 6px -1px rgba(79, 4, 112, 0.3);
        }
        .btn-signup:hover { 
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); 
            transform: translateY(-1px); 
        }

        /* --- HERO SECTION --- */
        .hero {
            padding: 4rem 5%;
            max-width: 1300px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 80vh;
        }

        .hero-content {
            flex: 1;
            max-width: 750px;
            text-align: center;
        }

        .hero p {
            max-width: 100%;
        }

        .tag {
            display: inline-block;
            /* GRADIENT ADDED: Tag background */
            background: linear-gradient(135deg, #e0f2fe 0%, #f3e8ff 100%);
            color: var(--primary);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .hero h1 {
            font-size: 3.5rem;
            line-height: 1.1;
            font-weight: 800;
            color: var(--secondary);
            margin-bottom: 1.5rem;
            letter-spacing: -1px;
        }

        .hero p {
            font-size: 1.125rem;
            color: var(--text-muted);
            margin-bottom: 2.5rem;
            max-width: 90%;
        }
        .hero-buttons { display: flex; gap: 4rem; justify-content: center; margin-top: 1rem; }
        

        .btn-primary {
            /* GRADIENT ADDED: Primary button with gradient */
            background: linear-gradient(135deg, var(--primary) 0%, #7c3aed 100%);
            color: white;
            padding: 1rem 2rem;
            border-radius: var(--radius);
            text-decoration: none;
            font-weight: 600;
            box-shadow: 0 4px 6px -1px rgba(79, 4, 112, 0.3);
            transition: 0.3s;
        }
        .btn-primary:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); transform: translateY(-2px); }

        .btn-secondary {
            /* GRADIENT ADDED: Secondary button */
            background: linear-gradient(135deg, white 0%, #f8fafc 100%);
            color: var(--secondary);
            border: 1px solid #e2e8f0;
            padding: 1rem 2rem;
            border-radius: var(--radius);
            text-decoration: none;
            font-weight: 600;
            transition: 0.3s;
        }
        .btn-secondary:hover { border-color: var(--primary); background: linear-gradient(135deg, #f8fafc 0%, #f3e8ff 100%); }

        /* --- HERO GRAPHIC (CSS MOCKUP) --- */
        .hero-graphic {
            flex: 1;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .glass-card {
            width: 100%;
            max-width: 500px;
            aspect-ratio: 4/3;
            /* GRADIENT ADDED: Glass card background */
            background: linear-gradient(135deg, rgba(193, 176, 224, 0.8) 0%, rgba(168, 139, 209, 0.7) 100%);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
            transform: perspective(1000px) rotateY(-10deg) rotateX(5deg);
            transition: transform 0.5s ease;
        }

        .hero-graphic:hover .glass-card {
            transform: perspective(1000px) rotateY(0deg) rotateX(0deg);
        }

        .map-bg {
            position: absolute;
            inset: 0;
            /* GRADIENT ADDED: Map background */
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(79, 4, 112, 0.1) 0%, transparent 20%),
                radial-gradient(circle at 80% 70%, rgba(79, 4, 112, 0.1) 0%, transparent 25%),
                linear-gradient(45deg, #e2e8f0 25%, transparent 25%, transparent 75%, #e2e8f0 75%, #e2e8f0),
                linear-gradient(45deg, #e2e8f0 25%, transparent 25%, transparent 75%, #e2e8f0 75%, #e2e8f0);
            background-size: 100% 100%, 100% 100%, 40px 40px, 40px 40px;
            background-position: 0 0, 0 0, 0 0, 20px 20px;
            opacity: 0.8;
        }

        .ui-sidebar {
            position: absolute;
            left: 20px;
            top: 20px;
            bottom: 20px;
            width: 60px;
            /* GRADIENT ADDED: Sidebar */
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(248, 250, 252, 0.9) 100%);
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            gap: 15px;
            align-items: center;
            padding-top: 20px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .ui-dot { width: 12px; height: 12px; background: #cbd5e1; border-radius: 50%; }
        .ui-dot.active { background: var(--primary); }

        .ui-panel {
            position: absolute;
            right: 20px;
            top: 20px;
            width: 140px;
            /* GRADIENT ADDED: Panel */
            background: linear-gradient(135deg, white 0%, #f8fafc 100%);
            padding: 15px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .ui-line { height: 8px; background: linear-gradient(90deg, #f1f5f9 0%, #e2e8f0 100%); border-radius: 4px; margin-bottom: 8px; }
        .ui-line.short { width: 60%; }
        .ui-stat-box {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        .ui-stat-circle { 
            width: 30px; height: 30px; 
            /* GRADIENT ADDED: Stat circle */
            background: linear-gradient(135deg, #ecfeff 0%, #f3e8ff 100%); 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: var(--primary); 
            font-size: 12px; 
        }

        .route-svg {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            pointer-events: none;
        }
        .route-path {
            stroke: var(--primary);
            stroke-width: 3;
            fill: none;
            stroke-dasharray: 10;
            stroke-linecap: round;
            filter: drop-shadow(0 4px 6px rgba(79, 4, 112, 0.4));
            animation: dash 30s linear infinite;
        }

        @keyframes dash {
            to { stroke-dashoffset: 1000; }
        }


        .right-column {
            flex: 1.2;
        }

        .right-column img {
            width: 100%;
            max-width: 620px;
            height: auto;
            min-height: 380px;
            border-radius: 20px;
            object-fit: cover;
            box-shadow: var(--shadow-lg);
        }

        /* --- PRODUCT TYPES SECTION --- */
        .products-section {
            /* GRADIENT ADDED: Products section background */
            background: linear-gradient(135deg, #ffffff 0%, #faf5ff 100%);
            padding: 5rem 5%;
            margin-top: 4rem;
            border-top: 1px solid #f1f5f9;
        }
        .section-head { text-align: center; margin-bottom: 3rem; }
        .section-head h2 { font-size: 2.25rem; color: var(--secondary); margin-bottom: 0.5rem; }
        .section-head p { color: var(--text-muted); }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        .product-card {
            /* GRADIENT ADDED: Product cards */
            background: linear-gradient(135deg, #f8fafc 0%, #f3e8ff 100%);
            padding: 2rem;
            border-radius: var(--radius);
            text-align: center;
            transition: 0.3s;
            border: 1px solid transparent;
            cursor: pointer;
        }
        .product-card:hover { 
            background: linear-gradient(135deg, #ffffff 0%, #faf5ff 100%); 
            border-color: var(--primary); 
            box-shadow: var(--shadow-lg); 
            transform: translateY(-5px); 
        }
        .product-icon { font-size: 2.5rem; margin-bottom: 1rem; display: inline-block; }
        .product-card h3 { font-size: 1.1rem; color: var(--secondary); margin-bottom: 0.5rem; }
        .product-card p { font-size: 0.9rem; color: var(--text-muted); }

        /* --- MODULES GRID --- */
        .modules-section { padding: 5rem 5%; max-width: 1300px; margin: 0 auto; }
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }
        .module-card {
            /* GRADIENT ADDED: Module cards */
            background: linear-gradient(135deg, #ffffff 0%, #fef9ff 100%);
            padding: 2rem;
            border-radius: var(--radius);
            border: 1px solid #e2e8f0;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            transition: 0.2s;
        }
        .module-card:hover { 
            border-color: var(--primary); 
            box-shadow: var(--shadow-lg); 
            transform: translateY(-4px);
            background: linear-gradient(135deg, #ffffff 0%, #faf3ff 100%);
        }
        
        .module-card h3 { font-size: 1.1rem; color: var(--secondary); font-weight: 700; }
        .module-card p { font-size: 0.9rem; color: var(--text-muted); line-height: 1.5; flex-grow: 1; }
        .lock-overlay { font-size: 0.75rem; color: #94a3b8; font-weight: 600; margin-top: auto; display: flex; align-items: center; gap: 5px; }

        /* --- FOOTER --- */
        footer { 
            /* GRADIENT ADDED: Footer gradient */
            background: linear-gradient(135deg, var(--secondary) 0%, #1e1b4b 100%); 
            color: #94a3b8; 
            text-align: center; 
            padding: 3rem 1rem; 
            margin-top: 4rem; 
        }

        /* --- RESPONSIVE --- */
        @media (max-width: 900px) {
            .hero { flex-direction: column; text-align: center; gap: 3rem; padding-top: 2rem; }
            .hero-content { max-width: 100%; }
            .hero h1 { font-size: 2.5rem; }
            .hero-buttons { justify-content: center; }
            .hero-graphic { width: 100%; }
            .glass-card { transform: none; }
            .hero-graphic:hover .glass-card { transform: none; }
        }
        @media (max-width: 768px) { .nav-links { display: none; } }
    </style>
</head>
<body>

    <nav>
        <a href="index.php" class="nav-logo">
            <span>🛢️</span> PetroTrack
        </a>
        <div class="nav-links">
            <a href="index.php">Home</a>
            <a href="#products">Products</a>
            <a href="#modules">Modules</a>
            <!-- RESTORED: Original Login Button Style -->
            <a href="login.php" class="btn-nav">Login</a>
            <!-- NEW: Sign Up Button -->
            <a href="register.php" class="btn-signup">Sign Up</a>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <section class="hero">
        <div class="hero-content">
            <span class="tag">Logistics Redefined</span>
            <h1>Smart Fuel Delivery<br>For Modern Industry</h1>
            <p>Streamline your petroleum supply chain with real-time monitoring, automated dispatch and secure fleet management.</p>
            
            <div class="hero-buttons">
                <!-- UPDATED: Get Started now goes to register.php -->
                <a href="register.php" class="btn-primary">Get Started</a>
                <a href="#products" class="btn-secondary">Our Fuels</a>
            </div>
        </div>
        
        
    </section>

    <!-- PRODUCT TYPES SECTION -->
    <section id="products" class="products-section">
        <div class="section-head">
            <h2>Fuel Products We Deliver</h2>
            <p>High-grade petroleum products for every industry need</p>
        </div>
        <div class="products-grid">
            <div class="product-card"><div class="product-icon"></div><h3>Premium Petrol</h3><p>High-octane fuel designed for efficiency.</p></div>
            <div class="product-card"><div class="product-icon"></div><h3>Diesel</h3><p>Standard and premium diesel for heavy transport.</p></div>
           
            <div class="product-card"><div class="product-icon"></div><h3>Kerosene</h3><p>Pure kerosene for heating and cleaning.</p></div>
        </div>
    </section>

    <!-- ALL MODULES SECTION -->
    <section id="modules" class="modules-section">
        <div class="section-head">
            <h2>Platform Capabilities</h2>
            <p>Manage your entire operation from one dashboard</p>
        </div>
        <div class="modules-grid">
            <a href="login.php" class="module-card"><div class="module-icon"></div><h3>Admin Dashboard</h3><p>Centralized control for managers.</p><span class="lock-overlay">🔒 Admin Access Only</span></a>
            <a href="login.php" class="module-card"><div class="module-icon"></div><h3>Driver Dashboard</h3><p>Interface for drivers to update status.</p><span class="lock-overlay">🔒 Driver Access Only</span></a>
            <a href="login.php" class="module-card"><div class="module-icon"></div><h3>Supervisor Dashboard</h3><p>Station supervisors monitoring tool.</p><span class="lock-overlay">🔒 Supervisor Access Only</span></a>
            <a href="login.php" class="module-card"><div class="module-icon"></div><h3>Vehicle Management</h3><p>Database module for tracking truck assets.</p></a>
            <a href="login.php" class="module-card"><div class="module-icon"></div><h3>Delivery Management</h3><p>Tools for dispatch orders and schedules.</p></a>
            <a href="login.php" class="module-card"><div class="module-icon"></div><h3>Simulated Tracking</h3><p>Managing and displaying tanker movement data.</p></a>
            <a href="login.php" class="module-card"><div class="module-icon"></div><h3>System Reports</h3><p>Generate and export detailed logs.</p></a>
        </div>
    </section>

    <footer>
        <p>&copy; 2026 PetroTrack Logistics System. All rights reserved.</p>
    </footer>

</body>
</html>