<?php
include 'includes/connect.php';

?>

<div class="container-fluid">
        <div class="row border-top px-xl-5">
            <div class="col-lg-3 d-none d-lg-block">
                <a class="d-flex align-items-center justify-content-between bg-secondary w-100 text-decoration-none" data-toggle="collapse" href="#navbar-vertical" style="height: 67px; padding: 0 30px;">
                    <h5 class="text-primary m-0"><i class="fa fa-bars mr-2"></i>Menu</h5>  <!-- Changed the icon to "fa-bars" and label to "Menu" -->
                    <i class="fa fa-angle-down text-primary"></i>
                </a>                
                <nav class="collapse position-absolute navbar navbar-vertical navbar-light align-items-start p-0 border border-top-0 border-bottom-0 bg-light" id="navbar-vertical" style="width: calc(100% - 30px); z-index: 9;">
                    <div class="navbar-nav w-100">
                        <div class="nav-item dropdown">
                            <a href="#" class="nav-link" data-toggle="dropdown">Web Design <i class="fa fa-angle-down float-right mt-1"></i></a>
                            <div class="dropdown-menu position-absolute bg-secondary border-0 rounded-0 w-100 m-0">
                                <a href="" class="dropdown-item">HTML</a>
                                <a href="" class="dropdown-item">CSS</a>
                                <a href="" class="dropdown-item">jQuery</a>
                            </div>
                        </div>
                        <a href="" class="nav-item nav-link">Apps Design</a>
                        <a href="" class="nav-item nav-link">Marketing</a>
                        <a href="" class="nav-item nav-link">Research</a>
                        <a href="" class="nav-item nav-link">SEO</a>
                    </div>
                </nav>
            </div>
            <div class="col-lg-9">
                <nav class="navbar navbar-expand-lg bg-light navbar-light py-3 py-lg-0 px-0">
                    <a href="" class="text-decoration-none d-block d-lg-none">
                        <h1 class="m-0"><span class="text-primary">P</span>ioneer Collage</h1>
                    </a>
                    <button type="button" class="navbar-toggler" data-toggle="collapse" data-target="#navbarCollapse">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse justify-content-between" id="navbarCollapse">
                        <div class="navbar-nav py-0">
                            <a href="index" class="nav-item nav-link active">Home</a>
                            <a href="about.html" class="nav-item nav-link">About</a>
                            <div class="nav-item dropdown">
                                <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">Courses</a>
                                <div class="dropdown-menu rounded-0 m-0">
                                    <a href="blog.html" class="dropdown-item">Blog List</a>
                                    <a href="single.html" class="dropdown-item">Blog Detail</a>
                                </div>
                            </div>
                            <a href="teacher.html" class="nav-item nav-link">Trainers</a>
                            <?php
                     

                           try {
                                   // Fetch schools from the database
                                   $stmt = $pdo->query("SELECT id, school_name FROM schools ORDER BY school_name ASC");
                                   $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                   } catch (PDOException $e) {
                                   $schools = [];
                                }
                             ?>
                           <div class="nav-item dropdown">
    <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">Schools</a>
    <div class="dropdown-menu rounded-0 m-0">
        <?php if (!empty($schools)): ?>
            <?php foreach ($schools as $school): ?>
                <a href="view.php?id=<?= $school['id'] ?>" class="dropdown-item">
                    <?= htmlspecialchars($school['school_name']) ?>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <span class="dropdown-item text-muted">No Schools Available</span>
        <?php endif; ?>
    </div>
</div>
                            <div class="nav-item dropdown">
                                <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">Blog</a>
                                <div class="dropdown-menu rounded-0 m-0">
                                    <a href="blog.html" class="dropdown-item">Latest Blog</a>
                                    <a href="single.html" class="dropdown-item">All Blogs</a>
                                </div>
                            </div>
                            <a href="contact.html" class="nav-item nav-link">Contact</a>
                        </div>
                        <a class="btn btn-primary py-2 px-4 ml-auto d-none d-lg-block" href="register">Join Now</a>
                    </div>
                </nav>
            </div>
        </div>
    </div>


    