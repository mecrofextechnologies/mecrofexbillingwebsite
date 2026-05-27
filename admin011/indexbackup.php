<?php
include 'includes/connect.php';

// Fetch latest 4 news ordered by date
try {
  $sql = "SELECT id, title, thumbnail, description, date_created FROM blogs ORDER BY date_created DESC LIMIT 4";
  $stmt = $pdo->prepare($sql);
  $stmt->execute();

  // Fetch the results
  $news_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
} catch (PDOException $e) {
  echo "Error: " . $e->getMessage();
}


// Fetch latest 7 news ordered by date
try {
  $sql = "SELECT id, title, thumbnail, description, date_created FROM blogs ORDER BY date_created DESC LIMIT 7";
  $stmt = $pdo->prepare($sql);
  $stmt->execute();

  // Fetch the results
  $newss_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
} catch (PDOException $e) {
  echo "Error: " . $e->getMessage();
}



// Fetch latest 3 slides ordered by date
try {
  $sql = "SELECT id, image, description, date FROM slides ORDER BY date DESC LIMIT 3";
  $stmt = $pdo->prepare($sql);
  $stmt->execute();

  // Fetch the results
  $slide_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
} catch (PDOException $e) {
  echo "Error: " . $e->getMessage();
}


// Fetch the latest 7 adverts ordered by date
try {
    $sql = "SELECT id, image, description, date FROM adverts ORDER BY date DESC LIMIT 7";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    // Fetch the results
    $slides = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}


// Fetch the latest 4 governance ordered by date
try {
  $sql = "SELECT id, image, description, date FROM governance_home ORDER BY date DESC LIMIT 4";
  $stmt = $pdo->prepare($sql);
  $stmt->execute();

  // Fetch the results
  $governance = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
  echo "Error: " . $e->getMessage();
}







//fetch youtube videos
try {
  $sql = "SELECT * FROM yvideos ORDER BY date DESC LIMIT 3";
  $stmt = $pdo->prepare($sql);
  $stmt->execute();
  $uploadedVideos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $error_message = "Failed to fetch videos: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">

  <title>home</title>
  <meta content="" name="description">
  <meta content="" name="keywords">

  <!-- Favicons -->
  <link href="assets/img/IUK-logo.png" rel="icon">
  <link href="assets/img/apple-touch-icon.png" rel="apple-touch-icon">

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Roboto:300,300i,400,400i,500,500i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="assets/vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
  <link href="assets/vendor/animate.css/animate.min.css" rel="stylesheet">
  <link href="assets/vendor/aos/aos.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="assets/vendor/glightbox/css/glightbox.min.css" rel="stylesheet">
  <link href="assets/vendor/swiper/swiper-bundle.min.css" rel="stylesheet">
  <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="assets/css/style.css" rel="stylesheet">

</head>

<body>

 <!-- header -->
<?php include_once('includes/header.php');?>
<!-- ./  header -->

  <!-- ======= Hero Section ======= -->
  <section>
    <div class="carousel-container">
        <div class="carousel">
            <div class="carousel-images">
                <?php foreach ($slide_items as $slide): ?>
                    <div class="carousel-slide">
                        <img src="admin/uploads/<?php echo htmlspecialchars($slide['image']); ?>" alt="Slide Image">
                        <div class="carousel-overlay"><?php echo htmlspecialchars($slide['description']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="prev" onclick="moveSlide(-1)">&#10094;</button>
            <button class="next" onclick="moveSlide(1)">&#10095;</button>
        </div>
    </div>
    
    <style>
    .carousel-container {
        position: relative;
        width: 100%; 
        height: 600px; 
        overflow: hidden;
      
    }

    .carousel {
        position: relative;
        width: 100%;
        height: 100%;
    }

    .carousel-images {
        display: flex;
        width: 100%;
        height: 100%;
        transition: transform 0.5s ease;
    }

    .carousel-slide {
        position: relative;
        flex-shrink: 0;
        width: 100%;
        height: 100%;
    }

    .carousel-images img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .carousel-overlay {
        position: absolute;
        bottom: 20px;
        left: 20px;
        width: 60%; /* Adjust width as needed */
        background-color: rgba(205, 158, 18, 0.7)        ; /* Yellow with 80% opacity */
        color: black;
        text-align: left;
        padding: 10px;
        font-size: 18px;
        z-index: 100;
        border-radius: 5px;
        opacity: 0;
        transform: rotate(0deg);
        color: white;
    }

    .animate-overlay {
        animation: uTurn 1s forwards;
    }

    @keyframes uTurn {
        0% {
            opacity: 0;
            transform: rotate(0deg);
        }
        50% {
            opacity: 1;
            transform: rotate(180deg);
        }
        100% {
            opacity: 1;
            transform: rotate(0deg);
        }
    }

    button {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        background-color: rgba(0, 0, 0, 0.5);
        color: white;
        border: none;
        padding: 10px;
        cursor: pointer;
        z-index: 1000;
    }

    .prev {
        left: 10px;
    }

    .next {
        right: 10px;
    }

   /* Responsive adjustments */
@media (max-width: 768px) {
    .carousel-container {
        margin-bottom: -2px;
    }
}

@media (max-width: 480px) {
    .carousel-container {
        padding-bottom: 60%; /* Further reduce padding for smaller screens */
        margin-bottom: -5px; /* Increase negative margin to move it up more */
    }

    button {
        padding: 5px;
    }

    .carousel-overlay {
        font-size: 16px;
        padding: 8px;
    }
}


    </style>
    
    <script>
    let index = 0;
    let intervalId;
    const transitionStyles = [
        'ease',
        'ease-in',
        'ease-out',
        'ease-in-out',
        'linear'
    ];
    let transitionIndex = 0;
    
    function moveSlide(step) {
        const slides = document.querySelectorAll('.carousel-images .carousel-slide');
        const totalSlides = slides.length;
    
        index = (index + step + totalSlides) % totalSlides;
        const offset = -index * 100;
    
        const transitionStyle = transitionStyles[transitionIndex];
        document.querySelector('.carousel-images').style.transition = `transform 0.5s ${transitionStyle}`;
    
        document.querySelector('.carousel-images').style.transform = `translateX(${offset}%)`;
    
        // Remove animation class from all overlays
        document.querySelectorAll('.carousel-overlay').forEach((overlay) => {
            overlay.classList.remove('animate-overlay');
        });

        // Add animation class to the current overlay
        const currentOverlay = document.querySelectorAll('.carousel-images .carousel-slide')[index].querySelector('.carousel-overlay');
        currentOverlay.classList.add('animate-overlay');

        transitionIndex = (transitionIndex + 1) % transitionStyles.length;
    }
    
    function autoSlide() {
        intervalId = setInterval(() => moveSlide(1), 3000);
    }
    
    autoSlide();
    
    window.addEventListener('blur', () => clearInterval(intervalId));
    window.addEventListener('focus', () => autoSlide());
    </script>
</section>






<!-- End Hero -->

<div class="container mt-5">
  <div class="row">
      <div class="col-md-8">
      <?php
          $i = 1;
          $qry = $pdo->query("SELECT * FROM settings ORDER BY date DESC LIMIT 1");
          while ($row = $qry->fetch(PDO::FETCH_ASSOC)):
       ?>
        <h1>Welcome to</h1><h1 style="color: #CD9E12;"><?php echo ucwords($row['institution_name']) ?></h1>
          <p>
          <?php echo $row['description'] ?>
          </p>
      </div>
      <div class="col-md-4">
          <ul class="list-group">
              <li class="list-group-item"><p><Strong>Faculty of Sharia & Islamic Studies</Strong></p> <a href="">View</a></a></li>
              <li class="list-group-item"><p><Strong>Faculty of Education Arts</Strong></p> <a href="">View</a></a></li>
              <li class="list-group-item"><p><Strong>Faculty of Business Management</Strong></p> <a href="">View</a></a></li>
              <li class="list-group-item"><p><Strong>Faculty of Information Technology</Strong></p> <a href="">View</a></a></li>
          </ul>
      </div>
      <?php endwhile; ?>
  </div>
</div>
   <!-- ======= Departments Section ======= -->

   <style>
    /* Adjust margin-top for small devices (mobile screens) */
@media (max-width: 576px) { /* Adjust this breakpoint as needed */
    .container {
        margin-top: 1rem; /* Adjust this value as needed */
    }
}


    .profile-card {
        text-align: center;
    }

    .profile-img {
        max-width: 100%;
        height: auto;
        max-height: 150px; /* Adjust height as needed */
    }

    .video-wrapper {
        text-align: left;
    }

    video {
        width: 100%; /* Make video responsive */
        max-width: 900px; /* Adjust max-width as needed */
        height: auto;
        margin-right: 45px;
        margin-bottom: 80px;
    }

    @media (max-width: 767.98px) {
        .profile-img {
            max-height: 100px; /* Smaller image height for mobile */
        }

        video {
            max-width: 100%; /* Ensure video fits within screen width on mobile */
        }
    }
</style>
</head>
<body>
<div class="container mt-5">
    <div class="row">
      <div class="section-title">
        <?php
        // Fetch videos to display
            $stmt = $pdo->query("SELECT * FROM videos");
            $videos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <?php foreach ($videos as $video): ?>
        <h2>Extra Ordinary Experience</h2>
        <p>Magnam dolores commodi suscipit. Necessitatibus eius consequatur ex aliquid fuga eum quidem. Sit sint consectetur velit. Quisquam quos quisquam cupiditate. Et nemo qui impedit suscipit alias ea. Quia fugiat sit in iste officiis commodi quidem hic quas.</p>
      </div>
    </div>
    <div class="row">
        <div class="col-md-6 profile-card">
            <img src="admin/uploads/<?= htmlspecialchars($video['thumbnail_path']) ?>" alt="<?= htmlspecialchars($video['president_name']) ?>" class="profile-img mb-3">
            <h3><?= htmlspecialchars($video['course']) ?></h3>
            <p><strong><?= htmlspecialchars($video['president_name']) ?></strong></p>
            <p>"<?= htmlspecialchars($video['president_quote']) ?>"</p>
            <a href="#" class="btn btn-warning">View Student Leaders</a>
        </div>
        <div class="col-md-6 video-wrapper">
            <video controls>
                <source src="admin/videos/<?= htmlspecialchars($video['video_path']) ?>" type="video/mp4">
                Your browser does not support the video tag.
            </video>
            <p class="mt-3">Shaikh Ahmad Hassan School of Law</p>
        </div>
        <?php endforeach; ?>
    </div>
</div>
   <section id="departments" class="departments">
    <div class="container" data-aos="fade-up">

      <div class="section-title">
        <h2>Programs We offer</h2>
        <p>Magnam dolores commodi suscipit. Necessitatibus eius consequatur ex aliquid fuga eum quidem. Sit sint consectetur velit. Quisquam quos quisquam cupiditate. Et nemo qui impedit suscipit alias ea. Quia fugiat sit in iste officiis commodi quidem hic quas.</p>
      </div>

      <div class="row" data-aos="fade-up" data-aos-delay="100">
        <div class="col-lg-4 mb-5 mb-lg-0">
          <ul class="nav nav-tabs flex-column">
            <li class="nav-item">
              <a class="nav-link active show" data-bs-toggle="tab" data-bs-target="#tab-1">
                <h4>Undergraduate Programs</h4>
                <p>Quis excepturi porro totam sint earum quo nulla perspiciatis eius.</p>
              </a>
            </li>
            <li class="nav-item mt-2">
              <a class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-2">
                <h4>Diploma Programs</h4>
                <p>Voluptas vel esse repudiandae quo excepturi.</p>
              </a>
            </li>
            <li class="nav-item mt-2">
              <a class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-3">
                <h4>Certificate Program</h4>
                <p>Velit veniam ipsa sit nihil blanditiis mollitia natus.</p>
              </a>
            </li>
          </ul>
        </div>
        <div class="col-lg-8">
          <div class="tab-content">
            <div class="tab-pane active show" id="tab-1">
              <h3>Undergraduate Programs</h3>
              <p class="fst-italic">Qui laudantium consequatur laborum sit qui ad sapiente dila parde sonata raqer a videna mareta paulona marka</p>
              <img src="assets/img/program.jpg" alt="" class="img-fluid">
              <p>Et nobis maiores eius. Voluptatibus ut enim blanditiis atque harum sint. Laborum eos ipsum ipsa odit magni. Incidunt hic ut molestiae aut qui. Est repellat minima eveniet eius et quis magni nihil. Consequatur dolorem quaerat quos qui similique accusamus nostrum rem vero</p>
            </div>
            <div class="tab-pane" id="tab-2">
              <h3>Diploma Programs</h3>
              <p class="fst-italic">Qui laudantium consequatur laborum sit qui ad sapiente dila parde sonata raqer a videna mareta paulona marka</p>
              <img src="assets/img/diploma.jpg" alt="" class="img-fluid">
              <p>Et nobis maiores eius. Voluptatibus ut enim blanditiis atque harum sint. Laborum eos ipsum ipsa odit magni. Incidunt hic ut molestiae aut qui. Est repellat minima eveniet eius et quis magni nihil. Consequatur dolorem quaerat quos qui similique accusamus nostrum rem vero
                <br>Et nobis maiores eius. Voluptatibus ut enim blanditiis atque harum sint. Laborum eos ipsum ipsa odit magni. Incidunt hic ut molestiae aut qui. Est repellat minima eveniet eius et quis magni nihil. Consequatur dolorem quaerat quos qui similique accusamus nostrum rem vero
                <br>Et nobis maiores eius. Voluptatibus ut enim blanditiis atque harum sint. Laborum eos ipsum ipsa odit magni. Incidunt hic ut molestiae aut qui. Est repellat minima eveniet eius et quis magni nihil. Consequatur dolorem quaerat quos qui similique accusamus nostrum rem vero
              </p>
            </div>
            <div class="tab-pane" id="tab-3">
              <h3>Certificate Program</h3>
              <p class="fst-italic">Qui laudantium consequatur laborum sit qui ad sapiente dila parde sonata raqer a videna mareta paulona marka</p>
              <img src="assets/img/l2.jpeg" alt="" class="img-fluid">
              <p>Et nobis maiores eius. Voluptatibus ut enim blanditiis atque harum sint. Laborum eos ipsum ipsa odit magni. Incidunt hic ut molestiae aut qui. Est repellat minima eveniet eius et quis magni nihil. Consequatur dolorem quaerat quos qui similique accusamus nostrum rem vero
                <br> Et nobis maiores eius. Voluptatibus ut enim blanditiis atque harum sint. Laborum eos ipsum ipsa odit magni. Incidunt hic ut molestiae aut qui. Est repellat minima eveniet eius et quis magni nihil. Consequatur dolorem quaerat quos qui similique accusamus nostrum rem vero
                <br>Et nobis maiores eius. Voluptatibus ut enim blanditiis atque harum sint. Laborum eos ipsum ipsa odit magni. Incidunt hic ut molestiae aut qui. Est repellat minima eveniet eius et quis magni nihil. Consequatur dolorem quaerat quos qui similique accusamus nostrum rem vero
              </p>
            </div>
          </div>
        </div>
      </div>
      <a href="" class="read-more-button">View More Programs</a>
    </div>
    <style>
  .section-title {
    padding: 15px; 
    color: black; 
  }

  .section-title h2 {
    border-radius: 5px; 
    font-size: 24px; 
    padding: 10px; 
    margin-bottom: 10px; 
    color: rgb(156, 29, 29); 
  }
    </style>
 <style>
  .content-wrapper {
    display: flex;
    flex-wrap: wrap;
    margin-bottom: 50px;
  }
  .unique-carousel-container {
    max-width: 600px; 
    flex: 1;
  }
  .unique-carousel-item img {
    width: 100%;
    height: auto;
  }
  .sidebar {
    margin-left: 20px;
    flex: 1;
    min-width: 250px; 
  }
  @media (max-width: 768px) {
    .sidebar {
      margin-left: 0;
      margin-top: 20px;
    }
  }
</style>
</head>
<body>
<div class="container mt-5">
  <div class="section-title">
    <h2>Whats Happening At Iuk</h2>
  </div>
  <div class="content-wrapper">
    <!-- Carousel -->
    <div id="Carousel" class="carousel slide unique-carousel-container" data-ride="carousel">
      <div class="carousel-inner">
        <div class="carousel-item active unique-carousel-item">
          <img src="assets/img/comp lab.jpg" class="d-block w-100" alt="Computer Lab">
          <div class="carousel-caption d-none d-md-block">
            <h5>Computer Lab</h5>
          </div>
        </div>
       
       
      </div>
    </div>
    <!-- Sidebar -->
    <div class="sidebar2">
      <ul class="list-group">
      <?php if (!empty($newss_items)): ?>
        <?php foreach ($newss_items as $news): ?>
        <li class="list-group-item">->><a href="news-detail?title=<?php echo urlencode($news['title']); ?>"><?php $title = substr($news['title'], 0, 70); echo ($title); ?>
         </a></li>
        <?php endforeach; ?>
           <?php else: ?>
                <p>No news items found.</p>
            <?php endif; ?>
      </ul>
    </div>
  </div>
</div>


<!--=========================NOTICE==============-->

<style>
  @keyframes zoomInOut {
    0%, 100% {
      transform: scale(1);
    }
    50% {
      transform: scale(1.1);
    }
  }
  .notice-container {
    background-color:#F5F5F5;
    padding: 10px;
    min-height: 50vh;
  }
  .notice-header {
    background-color: #275D7A;
    color: white;
    padding: 5px;  /* Reduced padding */
    margin-bottom: 10px;  /* Reduced margin */
    width: 550px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    animation: zoomInOut 2s infinite;
  }
  .notice-header h4 {
    margin: 0;
  }
  .notice-icon {
    font-size: 24px;
  }
  .professor-card {
    background-color: #CD9E12;
    border: none;
  }
  .professor-image {
    width: 100%;
    height: auto;
  }
  .btn-primary:hover {
    background-color: #1E4A60; /* Darker shade for hover */
    border-color: #1E4A60; /* Match the darker shade for border */
    text-decoration: underline; /* Add underline on hover */
  }
</style>
</head>
<body>
<div class="container-fluid notice-container">
  <div class="row">
    <div class="col-12">
      <div class="notice-header text-left">
        <h4>NOTICE BOARD</h4>
        <span class="notice-icon">&#128276;</span> <!-- Notification icon -->
      </div>
    </div>
    <div class="col-md-8">
    <div class="text-left my-4">
                   <?php
                    $i = 1;
                    $qry = $pdo->query("SELECT * FROM notice_board ORDER BY id DESC");
                    while ($row = $qry->fetch(PDO::FETCH_ASSOC)):
                    ?>
         <a href="#" class="btn btn-primary" style="background-color: #725c06; border-color: #275D7A; text-decoration: none; color: white;">
         <?php echo strtoupper($row['title']); ?>
          </a>
        </div> 
      <div class="mt-3">
        <p><?php echo $row['description'] ?></p>
        
      </div>
      <?php endwhile; ?>
    </div>
   <div class="col-md-4 d-flex justify-content-end">
    <div id="professorCarousel" class="carousel slide professor-card" data-ride="carousel">
        <div class="carousel-inner">
            <?php
            // Check if there are slides fetched
            if (!empty($slides)) {
                // Loop through each slide and generate the carousel items
                foreach ($slides as $index => $slide) {
                    // Determine if the first item should be active
                    $activeClass = $index === 0 ? 'active' : '';
                    echo '
                    <div class="carousel-item ' . $activeClass . '">
                        <img src="admin/uploads/' . htmlspecialchars($slide['image']) . '" class="d-block professor-image" alt="Advert ' . $index . '">
                        <div class="carousel-caption d-none d-md-block">
                            <h5>' . htmlspecialchars($slide['description']) . '</h5>
                        </div>
                    </div>';
                }
            } else {
                echo '<div class="carousel-item active">
                        <p>No adverts available</p>
                      </div>';
            }
            ?>
        </div>
        <a class="carousel-control-prev" href="#professorCarousel" role="button" data-slide="prev">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
            <span class="sr-only">Previous</span>
        </a>
        <a class="carousel-control-next" href="#professorCarousel" role="button" data-slide="next">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
            <span class="sr-only">Next</span>
        </a>
    </div>
</div>

  </div>
</div>
    <!---=================Accordion=========================-->

    <!-- ======= Testimonials Section ======= -->
    <section id="testimonials" class="testimonials">
      <div class="container" data-aos="fade-up">

        <div class="section-title">
          <h2>Testimonials</h2>
          <p>Magnam dolores commodi suscipit. Necessitatibus eius consequatur ex aliquid fuga eum quidem. Sit sint consectetur velit. Quisquam quos quisquam cupiditate. Et nemo qui impedit suscipit alias ea. Quia fugiat sit in iste officiis commodi quidem hic quas.</p>
        </div>

        <div class="testimonials-slider swiper" data-aos="fade-up" data-aos-delay="100">
          <div class="swiper-wrapper">

            <div class="swiper-slide">
              <div class="testimonial-item">
                <p>
                  <i class="bx bxs-quote-alt-left quote-icon-left"></i>
                  Proin iaculis purus consequat sem cure digni ssim donec porttitora entum suscipit rhoncus. Accusantium quam, ultricies eget id, aliquam eget nibh et. Maecen aliquam, risus at semper.
                  <i class="bx bxs-quote-alt-right quote-icon-right"></i>
                </p>
                <img src="assets/img/student 1.jpg" class="testimonial-img" alt="">
                <h3>Saul Goodman</h3>
                <h4>Business Management student</h4>
              </div>
            </div><!-- End testimonial item -->

            <div class="swiper-slide">
              <div class="testimonial-item">
                <p>
                  <i class="bx bxs-quote-alt-left quote-icon-left"></i>
                  Export tempor illum tamen malis malis eram quae irure esse labore quem cillum quid cillum eram malis quorum velit fore eram velit sunt aliqua noster fugiat irure amet legam anim culpa.
                  <i class="bx bxs-quote-alt-right quote-icon-right"></i>
                </p>
                <img src="assets/img/student 2.jpg" class="testimonial-img" alt="">
                <h3>Sara Wilsson</h3>
                <h4>IT Student</h4>
              </div>
            </div><!-- End testimonial item -->

            <div class="swiper-slide">
              <div class="testimonial-item">
                <p>
                  <i class="bx bxs-quote-alt-left quote-icon-left"></i>
                  Enim nisi quem export duis labore cillum quae magna enim sint quorum nulla quem veniam duis minim tempor labore quem eram duis noster aute amet eram fore quis sint minim.
                  <i class="bx bxs-quote-alt-right quote-icon-right"></i>
                </p>
                <img src="assets/img/student 2.jpg" class="testimonial-img" alt="">
                <h3>Jena Karlis</h3>
                <h4>Sharia Student</h4>
              </div>
            </div><!-- End testimonial item -->

            <div class="swiper-slide">
              <div class="testimonial-item">
                <p>
                  <i class="bx bxs-quote-alt-left quote-icon-left"></i>
                  Fugiat enim eram quae cillum dolore dolor amet nulla culpa multos export minim fugiat minim velit minim dolor enim duis veniam ipsum anim magna sunt elit fore quem dolore labore illum veniam.
                  <i class="bx bxs-quote-alt-right quote-icon-right"></i>
                </p>
                <img src="assets/img/student 1.jpg" class="testimonial-img" alt="">
                <h3>Matt Brandon</h3>
                <h4>Sharia Student</h4>
              </div>
            </div><!-- End testimonial item -->

            <div class="swiper-slide">
              <div class="testimonial-item">
                <p>
                  <i class="bx bxs-quote-alt-left quote-icon-left"></i>
                  Quis quorum aliqua sint quem legam fore sunt eram irure aliqua veniam tempor noster veniam enim culpa labore duis sunt culpa nulla illum cillum fugiat legam esse veniam culpa fore nisi cillum quid.
                  <i class="bx bxs-quote-alt-right quote-icon-right"></i>
                </p>
                <img src="assets/img/student 2.jpg" class="testimonial-img" alt="">
                <h3>John Larson</h3>
                <h4>Entrepreneur</h4>
              </div>
            </div><!-- End testimonial item -->

          </div>
          <div class="swiper-pagination"></div>
        </div>

      </div>
    </section><!-- End Testimonials Section -->

    <!--------------blogs-->
    <div class="container">
      <div class="section-title" data-aos="zoom-out">
        <h2>News And Events</h2>
        <p>Magnam dolores commodi suscipit. Necessitatibus eius consequatur ex aliquid fuga eum quidem. Sit sint consectetur velit. Quisquam quos quisquam cupiditate. Et nemo qui impedit suscipit alias ea. Quia fugiat sit in iste officiis commodi quidem hic quas.</p>
      </div>
    
      <div class="row">
    <div class="col-md-8">
        <h3 class="mb-4">News</h3>
        <a href="news-updates">All News →</a>
        <div class="row">
            <?php if (!empty($news_items)): ?>
                <?php foreach ($news_items as $news): ?>
                <div class="col-md-6 mb-4">
                    <div class="news-item card shadow-sm">
                        <img src="uploads_folder/<?php echo htmlspecialchars($news['thumbnail']); ?>" class="card-img-top" alt="<?php echo htmlspecialchars($news['title']); ?>">
                        <div class="card-body">
                        <a href="news-detail?title=<?php echo urlencode($news['title']); ?>" class="card-link"><?php echo htmlspecialchars($news['title']); ?></a>
                            <p><?php echo date("F j, Y, g:i a", strtotime($news['date_created'])); ?></p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No news items found.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-md-4">
        <div class="header1 d-flex justify-content-between align-items-center mb-4">
            <h3>Events</h3>
            <a href="#">All Events →</a>
        </div>
        <div class="event-list">
            <div class="event-item mb-4">
                <img src="assets/img/academic.jpg" class="event-img" alt="Event 1">
                <p><a href="#">Jun 29<br>Convocation 2024: Celebrating This Year's Graduating Class</a></p>
            </div>
            <div class="event-item mb-4">
                <img src="assets/img/event2.jpg" class="event-img" alt="Event 2">
                <p><a href="#">Jun 11<br>Public Defence of PhD Management Dissertation - Shoaib Amjad Hussain</a></p>
            </div>
            <div class="event-item mb-4">
                <img src="assets/img/event3.jpg" class="event-img" alt="Event 3">
                <p><a href="#">May 31<br>Workshop on Water and Climate Justice in South Asia</a></p>
            </div>
            <div class="event-item mb-4">
                <img src="assets/img/event4.jpg" class="event-img" alt="Event 4">
                <p><a href="#">May 17<br>Public Defence of PhD Management Dissertation - Afaf Khalid</a></p>
            </div>
        </div>
    </div>
</div>

      <!-- ======= Governance Section ======= -->
      <section id="doctors" class="doctors section-bg">
        <div class="container" data-aos="fade-up">
  
          <div class="section-title">
            <h2>Governance</h2>
            <p>Magnam dolores commodi suscipit. Necessitatibus eius consequatur ex aliquid fuga eum quidem. Sit sint consectetur velit. Quisquam quos quisquam cupiditate. Et nemo qui impedit suscipit alias ea. Quia fugiat sit in iste officiis commodi quidem hic quas.</p>
          </div>
  
          <div class="row">
  
  <?php foreach ($governance as $item): ?>
  <div class="col-lg-3 col-md-6 d-flex align-items-stretch">
    <div class="member" data-aos="fade-up" data-aos-delay="100">
      <div class="member-img">
        <img src="admin/uploads/<?php echo htmlspecialchars($item['image']); ?>" class="img-fluid" alt="">
        <div class="social">
          <a href=""><i class="bi bi-twitter"></i></a>
          <a href=""><i class="bi bi-facebook"></i></a>
          <a href=""><i class="bi bi-instagram"></i></a>
          <a href=""><i class="bi bi-linkedin"></i></a>
        </div>
      </div>
      <div class="member-info">
        <h4><?php echo htmlspecialchars($item['description']); ?></h4>
        <span><?php echo htmlspecialchars(date('F j, Y', strtotime($item['date']))); ?></span>
      </div>
    </div>
  </div>
<?php endforeach; ?>

           
            
  
            
  
          </div>
  
        </div>
      </section><!-- End Doctors Section -->
      <!-- ======= Counts Section ======= -->
      <div class="social-icons-wrapper">
        <ul class="social-icons">
          <li><i class="fab fa-facebook-f"></i></li>
          <li><i class="fab fa-instagram"></i></li>
          <li><i class="fab fa-linkedin-in"></i></li>
          <li><i class="fab fa-youtube"></i></li>
        </ul>
      </div>
    
      <section id="counts" class="counts">
        <div class="section-title">
          <h2>Statistics</h2>
          <p>Magnam dolores commodi suscipit. Necessitatibus eius consequatur ex aliquid fuga eum quidem. Sit sint consectetur velit. Quisquam quos quisquam cupiditate. Et nemo qui impedit suscipit alias ea. Quia fugiat sit in iste officiis commodi quidem hic quas.</p>
        </div>
        <div class="container" data-aos="fade-up">
          <div class="row no-gutters">
            <div class="col-lg-3 col-md-6 d-md-flex align-items-md-stretch">
              <div class="count-box">
                <i class="fas fa-graduation-cap"></i>
                <span data-purecounter-start="0" data-purecounter-end="85" data-purecounter-duration="1" class="purecounter"></span>
  
                <p><strong>Graduants</strong> consequuntur quae qui deca rode</p>
                <a href="#">Find out more &raquo;</a>
              </div>
            </div>
  
            <div class="col-lg-3 col-md-6 d-md-flex align-items-md-stretch">
              <div class="count-box">
                <i class="far fa-person"></i>
                <span data-purecounter-start="0" data-purecounter-end="26" data-purecounter-duration="1" class="purecounter"></span>
                <p><strong>Enrolled</strong> adipisci atque cum quia aut numquam delectus</p>
                <a href="#">Apply Now &raquo;</a>
              </div>
            </div>
  
            <div class="col-lg-3 col-md-6 d-md-flex align-items-md-stretch">
              <div class="count-box">
                <i class="fas fa-flask"></i>
                <span data-purecounter-start="0" data-purecounter-end="14" data-purecounter-duration="1" class="purecounter"></span>
                <p><strong>Courses</strong> aut commodi quaerat. Aliquam ratione</p>
                <a href="#">Apply now &raquo;</a>
              </div>
            </div>
  
            <div class="col-lg-3 col-md-6 d-md-flex align-items-md-stretch">
              <div class="count-box">
                <i class="fas fa-award"></i>
                <span data-purecounter-start="0" data-purecounter-end="150" data-purecounter-duration="1" class="purecounter"></span>
                <p><strong>Awards</strong> rerum asperiores dolor molestiae doloribu</p>
                <a href="#">Find out more &raquo;</a>
              </div>
            </div>
  
          </div>
  
        </div>
      </section><!-- End Counts Section -->


  </style>
  </section>
  

    <!-- ======= Cta Section ======= -->
    <section id="cta" class="cta">
      <div class="container" data-aos="zoom-in">

        <div class="text-center">
          <h3>Help us to Educate Needy Students in Africa</h3>
          <p> The Campaign for #Accesstoeducation is the largest fundraising campaign in IUK's history. With a historic $1 million goal, the campaign endeavors to give access to education to needy students from marginalized counties.</p>
          <a class="cta-btn scrollto" href="https://www.paypal.com/donate?hosted_button_id=LG5ELMA894KQ6">Donate Now</a>
        </div>

      </div>
    </section><!-- End Cta Section -->

<!-- YouTube Embedding Section Start -->
<div class="youtube-embed-area pt-50">
  <div class="container">
      <div class="youtube-wrapper">
          <!-- Section Title -->
          <div class="row" data-aos="zoom-in">
              <div class="col-lg-12">
                  <div class="section-title mb-30" data-aos="zoom-in">
                      <h3>Latest Videos</h3>
                  </div>
              </div>
          </div>
          <div class="row">
              <!-- Big Video -->
              <div class="col-md-8">
                  <div class="youtube-video">
                      <div class="video-container">
                          <iframe width="100%" height="450" src="https://www.youtube.com/embed/Ksf3JfNyMhA?rel=0" frameborder="0" allowfullscreen></iframe>
                          <div class="play-button">
                              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 68 48"><path d="M66.52 7.85a4.8 4.8 0 00-6.74-1.8L22.1 25.8a4.8 4.8 0 000 7.7L59.78 53.9a4.8 4.8 0 006.74-1.8 4.8 4.8 0 00-1.8-6.74L32.05 28l32.68-19.6a4.8 4.8 0 002.84-6.55z"/></svg>
                          </div>
                      </div>
                      <div class="video-description">
                          <p>Daima mimi mkenya Lyrics Eric Wainaina</p>
                      </div>
                  </div>
                 
                 
                  <div class="banner-container">
                     <div class="banner-image">
                     <img src="assets/img/newbanner.jpg" alt="Banner Image" width="100%" height="255">
                     </div>
                     <div class="banner-description">
                       <p>Iuk Building</p>
                   </div>
              </div>




              </div>
              <!-- Small Videos -->
              <div class="col-md-4">
                  <!-- Video 2 -->
                  <?php foreach ($uploadedVideos as $video): ?>
                  <div class="youtube-video mb-3">
                      <div class="video-container">
                      <iframe width="150" height="84" src="https://www.youtube.com/embed/<?php echo htmlspecialchars($video['id']); ?>" frameborder="0" allowfullscreen></iframe>
                      </div>
                      <div class="video-description">
                          <p><?php 
                            $description = htmlspecialchars($video['description']);
                            echo strlen($description) > 150 ? substr($description, 0, 150) . '...' : $description;
                        ?></p>
                      </div>
                  </div>
                  <?php endforeach; ?>  
              </div>
          </div>
      </div>
  </div>
</div>
<!-- End YouTube Embedding Section -->

<!-- Add the following CSS -->
<style>
    .youtube-video .video-container {
        position: relative;
        padding-bottom: 56.25%; /* 16:9 aspect ratio */
        height: 0;
        overflow: hidden;
        max-width: 100%;
        background: #000;
        margin: 0 auto;
    }
    .youtube-video2 .video-container {
        position: relative;
        padding-bottom: 35.25%; /* 16:9 aspect ratio */
        height: 0;
        overflow: hidden;
        max-width: 100%;
        background: #000;
        margin: 0 auto;
    }

    .youtube-video .video-container iframe {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
    }

    .youtube-video .play-button {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 68px;
        height: 48px;
        background: rgba(0, 0, 0, 0.5);
        border-radius: 50%;
        cursor: pointer;
    }

    .youtube-video .video-description {
        text-align: center;
        padding: 10px 0;
    }

    .youtube-embed-area .col-md-4 .youtube-video {
        margin-bottom: 20px;
    }
</style>

    <!-- ======= About Us Section ======= -->
    <section id="about" class="about">
      <div class="container" data-aos="fade-up">

        <div class="section-title">
          <h2>About Us</h2>
          <?php
          $i = 1;
          $qry = $pdo->query("SELECT * FROM about ORDER BY date DESC LIMIT 1");
          while ($row = $qry->fetch(PDO::FETCH_ASSOC)):
          ?>
          <p><?php echo ucwords($row['title']) ?></p>
        </div>

        <div class="row">
          <div class="col-lg-6" data-aos="fade-right">
            <img src="assets/img/about us.jpg" class="img-fluid curved-corners" alt="">

          </div>
          <div class="col-lg-6 pt-4 pt-lg-0 content" data-aos="fade-left">
            <h3><?php echo ucwords($row['title']) ?></h3>
            <p class="fst-italic">
            <?php echo ucwords($row['title']) ?>
            </p>
            <p>
            <?php echo $row['description'] ?>
            </p>
          </div>
        </div>
        
      </div>
    </section><!-- End About Us Section -->



    <!-- ======= Mission Vision Section ======= -->
      <div class="container" data-aos="fade-up">

        <div class="row">
          <div class="col-lg-6 order-2 order-lg-1" data-aos="fade-right">
            <div class="icon-box mt-5 mt-lg-0">
              <h4>Our Mission</h4>
              <p><?php echo $row['mission'] ?></p>
            </div>
            <div class="icon-box mt-5">
              <h4>Our Vision</h4>
              <p><?php echo $row['vision'] ?></p>
            </div>
            <div class="icon-box mt-5">
              <h4>Core Values</h4>
              <p><?php echo $row['core_values'] ?></p>
            </div>
            <?php endwhile; ?>
          </div>
          <div class="image col-lg-6 order-1 order-lg-2 curved-background" style='background-image: url("assets/img/about us 2.jpg");' data-aos="zoom-in"></div>

        </div>

      </div>
    
   
      




    <!-- ======= Gallery Section ======= -->
    <section id="gallery" class="gallery">
      <div class="container" data-aos="fade-up">

        <div class="section-title">
          <h2>Gallery</h2>
          <p>Magnam dolores commodi suscipit. Necessitatibus eius consequatur ex aliquid fuga eum quidem. Sit sint consectetur velit. Quisquam quos quisquam cupiditate. Et nemo qui impedit suscipit alias ea. Quia fugiat sit in iste officiis commodi quidem hic quas.</p>
        </div>

        <div class="gallery-slider swiper">
          <div class="swiper-wrapper align-items-center">
            <div class="swiper-slide"><a class="gallery-lightbox" href="assets/img/about us 2.jpg"><img src="assets/img/about us 2.jpg" class="img-fluid" alt=""></a></div>
            <div class="swiper-slide"><a class="gallery-lightbox" href="assets/img/blog 1.jpg"><img src="assets/img/blog 1.jpg" class="img-fluid" alt=""></a></div>
            <div class="swiper-slide"><a class="gallery-lightbox" href="assets/img/about us.jpg"><img src="assets/img/about us.jpg" class="img-fluid" alt=""></a></div>
            <div class="swiper-slide"><a class="gallery-lightbox" href="assets/img/diploma.jpg"><img src="assets/img/diploma.jpg" class="img-fluid" alt=""></a></div>
            <div class="swiper-slide"><a class="gallery-lightbox" href="assets/img/gallery/gallery-5.jpg"><img src="assets/img/gallery/gallery-5.jpg" class="img-fluid" alt=""></a></div>
            <div class="swiper-slide"><a class="gallery-lightbox" href="assets/img/gallery/gallery-6.jpg"><img src="assets/img/gallery/gallery-6.jpg" class="img-fluid" alt=""></a></div>
            <div class="swiper-slide"><a class="gallery-lightbox" href="assets/img/gallery/gallery-7.jpg"><img src="assets/img/gallery/gallery-7.jpg" class="img-fluid" alt=""></a></div>
            <div class="swiper-slide"><a class="gallery-lightbox" href="assets/img/gallery/gallery-8.jpg"><img src="assets/img/gallery/gallery-8.jpg" class="img-fluid" alt=""></a></div>
          </div>
          <div class="swiper-pagination"></div>
        </div>

      </div>
    </section><!-- End Gallery Section -->


  </main><!-- End #main -->
<!-- footer -->
  <!-- jQuery and Bootstrap JS -->
  <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.2/dist/umd/popper.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<?php include_once('includes/footer.php');?>
<!-- ./  footer -->
 
</body>

</html>


