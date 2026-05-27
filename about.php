<?php
include 'msanii/includes/connect.php';

?>

<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="About Keychele - Empowering passion, rewarding creativity">
        <meta name="author" content="Keychele">
        <title>About Us | Keychele</title>

        <!-- CSS FILES -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
        <link href="css/bootstrap.min.css" rel="stylesheet">
        <link href="css/bootstrap-icons.css" rel="stylesheet">
        <link href="css/magnific-popup.css" rel="stylesheet">
        <link href="css/tooplate-waso-strategy.css" rel="stylesheet">

        <style>
            :root {
                --primary: #b81236;
                --dark: #032d5d;
                --accent: #fdbd10;
            }

            .hero.section-hero {
                background: linear-gradient(rgba(3,45,93,0.85), rgba(3,45,93,0.85)), url('images/ui/about-hero.jpg') center/cover no-repeat;
            }

            .about-image-wrap { 
                overflow: hidden; 
                border-radius: 20px; 
            }
            .about-image { 
                transition: transform 0.5s ease; 
                width: 100%;
                height: auto;
                object-fit: cover;
            }
            .about-image-wrap:hover .about-image { 
                transform: scale(1.05) rotate(1deg); 
            }

            .about-image-info {
                background: linear-gradient(135deg, rgba(184,18,54,0.9), rgba(253,189,16,0.9));
                transition: transform 0.3s ease, box-shadow 0.3s ease;
                max-width: 280px;
            }
            .about-image-info:hover { 
                transform: translateY(-5px); 
                box-shadow: 0 10px 30px rgba(0,0,0,0.35); 
            }

            .about-content { 
                background: #fff;
                border-radius: 20px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                padding: 40px;
                height: 100%;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
            }
            .about-content h2 { 
                color: var(--primary); 
                font-size: 2.5rem;
                font-weight: 700;
            }
            .about-content h5 { 
                color: #444; 
                font-weight: 500;
            }
            .about-content ul li {
                margin-bottom: 12px;
                font-size: 1.05rem;
            }
            .about-content a { 
                color: var(--accent); 
                text-decoration: underline; 
            }

            /* Action Cards */
            .action-card { 
                transition: all 0.3s ease; 
                cursor: pointer; 
                border-radius: 16px;
                padding: 30px;
                height: 100%;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                text-align: center;
            }
            .action-card:hover { 
                transform: translateY(-8px) scale(1.03); 
                box-shadow: 0 20px 40px rgba(0,0,0,0.25); 
            }
            .action-card.bg-danger { background: var(--primary) !important; }
            .action-card.bg-warning { background: var(--accent) !important; color: #000 !important; }

            /* Video Panel */
            .video-panel {
                border-radius: 20px;
                overflow: hidden;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                height: 100%;
                min-height: 400px;
            }
            .video-panel iframe {
                width: 100%;
                height: 100%;
                border: none;
            }

            /* Responsive */
            @media (max-width: 992px) {
                .about-image-wrap { margin-top: 0; }
                .about-image-info { position: static !important; margin-top: 20px; max-width: 100%; }
                .video-panel { margin-top: 30px; min-height: 300px; }
                .about-content { padding: 30px; }
            }
            @media (max-width: 768px) {
                .about-content h2 { font-size: 2rem; }
                .video-panel { min-height: 250px; }
            }
        </style>
    </head>

    <body class="projects-detail-page" id="section_1">
        <?php include_once('includes/header.php'); ?>
        <?php include_once('includes/nav.php'); ?>

        <main>
            <!-- Hero Section -->
            <section class="hero section-hero section-padding">
                <div class="container">
                    <div class="row">
                        <div class="col-lg-8 col-12 text-center mx-auto">
                            <div class="section-hero-text">
                                <small class="small-title text-warning">Our Mission</small>
                                <h1 class="text-white">About Keychele</h1>
                                <p class="text-white mt-3 opacity-90">Empowering creators and connecting passionate communities across Kenya</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- About Content Section -->
            <section class="about section-padding">
                <div class="container">
                    <div class="row align-items-start">
                        <!-- IMAGE / CEO + VIDEO -->
                        <div class="col-lg-6 col-12 mb-5 mb-lg-0 d-flex flex-column justify-content-start position-relative">
                            <div class="about-image-wrap position-relative mb-4">
                                <img src="imges/peter-jones-WZROBIlY8Rg-unsplash.jpg" 
                                     class="img-fluid rounded-4 about-image shadow-lg" 
                                     alt="">

                                <!-- Floating CEO Info Card -->
                                <div class="about-image-info p-4 rounded-4 shadow-lg position-absolute text-white">
                                    <h5 class="mb-2 fw-bold">Wih</h5>
                                    <small>CEO & Founder</small>
                                    <p class="mb-0 mt-3" style="font-size: 0.9rem;">
                                        Passion, creativity, and support drive our mission. Join us to empower your community.
                                    </p>
                                </div>
                            </div>

                            
                        </div>

                        <!-- ABOUT CONTENT -->
                        <div class="col-lg-6 col-12">
                            <div class="about-content">
                                <div>
                                    <h2 class="mb-3 fw-bold">Empowering Passion. Rewarding Creativity.</h2>
                                    <h5 class="mb-4 text-muted">Support, monetize, and engage your community — from events and competitions to quizzes and direct fan support.</h5>
                                    
                                    <p>At the heart of every great idea, performance, or community initiative is someone who gives their all. But passion alone isn’t always enough — it needs support. That’s where we come in.</p>
                                    
                                    <p>Our platform empowers creators to:</p>
                                    
                                    <ul class="list-unstyled mb-4">
                                        <li class="mb-3">✅ <strong>Receive direct support</strong> — fans support you when they can. No pressure.</li>
                                        <li class="mb-3">✅ <strong>Sell event tickets</strong> — from small gatherings to large shows.</li>
                                        <li class="mb-3">✅ <strong>Host competitions & online voting</strong> — engage your audience and reward excellence.</li>
                                        <li class="mb-3">✅ <strong>Create quizzes & trivia</strong> — interactive fun for digital or live events.</li>
                                    </ul>
                                    
                                    <p>We believe great work deserves support. Fans want to show up. We build the bridge.</p>
                                    
                                    <p class="mb-0">Support us with a donation via our <a href="contact">contact page</a>. Thank you.</p>
                                </div>

                                <!-- ACTION CARDS -->
                                <div class="row mt-5 g-4">
                                    <div class="col-md-6">
                                        <div class="action-card bg-danger">
                                            <h5 class="mb-3">Don't have an account?</h5>
                                            <a href="/register" class="btn btn-light text-danger fw-bold rounded-pill px-5 py-2">Sign Up</a>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="action-card bg-warning text-dark">
                                            <h5 class="mb-3">Already have an account?</h5>
                                            <a href="/login" class="btn btn-dark text-warning fw-bold rounded-pill px-5 py-2">Login</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <?php include_once('includes/footer.php'); ?>

        <!-- JAVASCRIPT FILES -->
        <script src="js/jquery.min.js"></script>
        <script src="js/bootstrap.min.js"></script>
        <script src="js/jquery.sticky.js"></script>
        <script src="js/jquery.magnific-popup.min.js"></script>
        <script src="js/magnific-popup-options.js"></script>
        <script src="js/click-scroll.js"></script>
        <script src="js/custom.js"></script>
    </body>
</html>