<?php
include 'msanii/includes/connect.php';


// Handle form submission
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (empty($name) || empty($email) || empty($message)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Add real email sending here later (mail() or PHPMailer)
        $success = true;
    }
}
?>

<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Contact Keychele - Get in touch for support, inquiries, or donations">
        <meta name="author" content="Keychele">
        <title>Contact Us | Keychele</title>

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
            }

            .hero.section-hero {
                background: linear-gradient(rgba(3,45,93,0.8), rgba(3,45,93,0.8)), url('images/ui/contact-hero.jpg') center/cover no-repeat;
            }

            .events-title {
                font-size: 2.8rem;
                color: var(--dark);
                font-weight: 700;
            }

            .search-wrap {
                position: relative;
                display: inline-flex;
                align-items: center;
                justify-content: flex-end;
                width: 100%;
                max-width: 400px;
            }
            .search-wrap input[type="search"] {
                width: 100%;
                padding: 14px 50px 14px 20px;
                border-radius: 50px;
                border: 1px solid rgba(3,45,93,0.1);
                box-shadow: 0 4px 15px rgba(0,0,0,0.08);
                background: #fff;
                font-size: 1rem;
            }
            .search-wrap .search-btn {
                position: absolute;
                right: 10px;
                background: var(--primary);
                color: white;
                border: none;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
            }

            /* Contact Form Card - same as event cards */
            .contact-card {
                background: #fff;
                border-radius: 16px;
                overflow: hidden;
                box-shadow: 0 8px 25px rgba(0,0,0,0.1);
                transition: all 0.3s ease;
                padding: 40px;
            }
            .contact-card:hover {
                transform: translateY(-10px);
                box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            }

            .contact-card h2 {
                color: var(--primary);
                font-weight: 700;
                margin-bottom: 20px;
            }

            .contact-card .form-control {
                border-radius: 12px;
                padding: 14px 18px;
                margin-bottom: 20px;
                border: 1px solid #e0e0e0;
                font-size: 1rem;
            }
            .contact-card .form-control:focus {
                border-color: var(--primary);
                box-shadow: 0 0 0 0.2rem rgba(184,18,54,0.15);
            }
            .contact-card textarea {
                min-height: 160px;
                resize: vertical;
            }

            .contact-card .btn-submit {
                background: var(--primary);
                color: white;
                font-weight: 700;
                padding: 14px 32px;
                border-radius: 50px;
                border: none;
                transition: all 0.3s;
            }
            .contact-card .btn-submit:hover {
                background: #97112f;
                transform: translateY(-3px);
            }

            .contact-info-card {
                background: #fff;
                border-radius: 16px;
                box-shadow: 0 8px 25px rgba(0,0,0,0.1);
                padding: 40px;
                height: 100%;
            }
            .contact-info-card h3 {
                color: var(--dark);
                font-weight: 700;
                margin-bottom: 30px;
            }
            .contact-info-card ul {
                list-style: none;
                padding: 0;
            }
            .contact-info-card li {
                margin-bottom: 25px;
                font-size: 1.1rem;
                display: flex;
                align-items: flex-start;
            }
            .contact-info-card i {
                color: var(--primary);
                font-size: 1.6rem;
                margin-right: 18px;
                min-width: 35px;
            }

            .alert {
                border-radius: 12px;
                margin-bottom: 25px;
            }

            @media (max-width: 768px) {
                .search-wrap { max-width: 100%; }
                .contact-card, .contact-info-card {
                    padding: 30px;
                    margin-bottom: 30px;
                }
            }
        </style>
    </head>

    <body class="projects-detail-page" id="section_1">
        <?php include_once('includes/header.php'); ?>
        <?php include_once('includes/nav.php'); ?>

        <main>
            <!-- Hero Section - same as events/creators -->
            <section class="hero section-hero section-padding">
                <div class="container">
                    <div class="row">
                        <div class="col-lg-8 col-12 text-center mx-auto">
                            <div class="section-hero-text">
                                <small class="small-title text-warning">Get in Touch</small>
                                <h1 class="text-white">Contact Us</h1>
                                <p class="text-white mt-3 opacity-90">We’re here to help — questions, support, feedback, or donations</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Contact Section - same structure as events -->
            <section class="projects section-padding pb-5">
                <div class="container">
                    <div class="row align-items-center mb-5">
                        <div class="col-lg-8 col-12">
                            <h2 class="events-title">Send us a Message</h2>
                        </div>
                        <div class="col-lg-4 col-12 text-lg-end">
                            <div class="search-wrap">
                                <input type="search" placeholder="Quick search... (not active)" disabled>
                                <button class="search-btn"><i class="bi bi-search"></i></button>
                            </div>
                        </div>
                    </div>

                    <div class="row g-5">
                        <!-- Contact Form -->
                        <div class="col-lg-8 col-12">
                            <div class="contact-card">
                                <?php if ($success): ?>
                                    <div class="alert alert-success">
                                        <strong>Thank you!</strong> Your message has been sent. We'll reply soon.
                                    </div>
                                <?php endif; ?>

                                <?php if ($error): ?>
                                    <div class="alert alert-danger">
                                        <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                                    </div>
                                <?php endif; ?>

                                <form method="POST" action="/contact">
                                    <input type="text" name="name" class="form-control" placeholder="Your Name" required 
                                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">

                                    <input type="email" name="email" class="form-control" placeholder="Your Email" required 
                                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

                                    <textarea name="message" class="form-control" placeholder="Your Message" required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>

                                    <button type="submit" class="btn btn-submit">Send Message</button>
                                </form>
                            </div>
                        </div>

                        <!-- Contact Info -->
                        <div class="col-lg-4 col-12">
                            <div class="contact-info-card">
                                <h3>Contact Information</h3>
                                <ul>
                                    <li><i class="bi bi-envelope"></i> <div><strong>Email:</strong><br>support@keychele.co.ke</div></li>
                                   
                                    <li><i class="bi bi-gift"></i> <div><strong>Support Us:</strong><br>Use this form for donations or inquiries</div></li>
                                </ul>

                                <div class="mt-4 p-4 bg-light rounded-3">
                                    <p class="mb-0"><strong>Thank you</strong> for being part of our journey to empower creators in Kenya!</p>
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