<header class="header rs-nav header-transparent">
		<div class="top-bar">
			<div class="container">
				<div class="row d-flex justify-content-between">
					<div class="topbar-left">
						<ul>
							<li><a href="javascript:;"><i class="fa fa-question-circle"></i>Ask a Question</a></li>
							<li><a href="javascript:;"><i class="fa fa-envelope-o"></i>info@pioneercenter.co.ke</a></li>
						</ul>
					</div>
					<div class="topbar-right">
						<ul>
							<li>
								<select class="header-lang-bx">
									<option data-icon="flag flag-uk">English UK</option>
									<option data-icon="flag flag-us">English US</option>
								</select>
							</li>
							<li><a href="#">Login</a></li>
							<li><a href="#">Register</a></li>
						</ul>
					</div>
				</div>
			</div>
		</div>
		<div class="sticky-header navbar-expand-lg">
            <div class="menu-bar clearfix">
                <div class="container clearfix">
					<!-- Header Logo ==== -->
					<div class="menu-logo">
						<a href="#"><img src="school photos/newlogo.jpg" alt=""></a>
					</div>
					<!-- Mobile Nav Button ==== -->
                    <button class="navbar-toggler collapsed menuicon justify-content-end" type="button" data-toggle="collapse" data-target="#menuDropdown" aria-controls="menuDropdown" aria-expanded="false" aria-label="Toggle navigation">
						<span></span>
						<span></span>
						<span></span>
					</button>
					<!-- Author Nav ==== -->
                    <div class="secondary-menu">
                        <div class="secondary-inner">
                            <ul>
								<li><a href="javascript:;" class="btn-link"><i class="fa fa-facebook"></i></a></li>
								<li><a href="javascript:;" class="btn-link"><i class="fa fa-google-plus"></i></a></li>
								<li><a href="javascript:;" class="btn-link"><i class="fa fa-linkedin"></i></a></li>
								<!-- Search Button ==== -->
								<li class="search-btn"><button id="quik-search-btn" type="button" class="btn-link"><i class="fa fa-search"></i></button></li>
							</ul>
						</div>
                    </div>
					<!-- Search Box ==== -->
                    <div class="nav-search-bar">
                        <form action="#">
                            <input name="search" value="" type="text" class="form-control" placeholder="Type to search">
                            <span><i class="ti-search"></i></span>
                        </form>
						<span id="search-remove"><i class="ti-close"></i></span>
                    </div>
					<!-- Navigation Menu ==== -->
                    <div class="menu-links navbar-collapse collapse justify-content-start" id="menuDropdown">
						<div class="menu-logo">
							<a href="javascript:;"><img src="school photos/newlogo.jpg" alt=""></a>
						</div>
                        <ul class="nav navbar-nav">	
							<li class="active"><a href="javascript:;">Home <i class="fa fa-chevron-down"></i></a>
								<ul class="sub-menu">
									<li><a href="index">home</a></li>
								</ul>
							</li>
							<li><a href="javascript:;">About us <i class="fa fa-chevron-down"></i></a>
								<ul class="sub-menu">
									<li><a href="javascript:;">Vision<i class="fa fa-angle-right"></i></a>
										<ul class="sub-menu">
											<li><a href="vision">Vision</a></li>
											<li><a href="mission">Mission</a></li>
										</ul>
									</li>
									<li><a href="coming-soon.html">Values<i class="fa fa-angle-right"></i></a>
										<ul class="sub-menu">
											<li><a href="coming-soon.html">Values</a></li>
											<li><a href="coming-soon.html">Goals</a></li>
										</ul>
									</li>
									<li><a href="coming-soon.html">FAQ's<i class="fa fa-angle-right"></i></a>
										<ul class="sub-menu">
											<li><a href="coming-soon.html">FAQ's</a></li>
											
										</ul>
									</li>
									
									<li><a href="coming-soon.html">Our beliefs</a></li>
									<li><a href="coming-soon.html">College History</a></li>
									
								</ul>
							</li>
                            <li><a href="coming-soon.html">Media <i class="fa fa-chevron-down"></i></a>
								<ul class="sub-menu">
									<li><a href="video-gallery.php">Video Gallery</a></li>
									<li><a href="photo-gallery.php">Photo Gallery</a></li>
									<li><a href="downloads.php">Downloads</a></li>
                                    <li><a href="testimonials.php">Testimonials</a></li>
                                    <li><a href="events.php">Events</a></li>
                                    <li><a href="faqs.php">FAQs</a></li>
								</ul>
							</li>
							<li class="add-mega-menu"><a href="javascript:;">Schools <i class="fa fa-chevron-down"></i></a>
								<ul class="sub-menu add-menu">
									<li class="add-menu-left">
    <h5 class="menu-adv-title">Schools</h5>
    <ul>
        <?php
        include 'includes/connect.php'; // adjust path if needed

        try {
            $stmt = $pdo->query("SELECT id, school_name FROM schools ORDER BY school_name ASC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $id = $row['id'];
                $name = htmlspecialchars($row['school_name']);
                echo "<li><a href=\"read-more-school?id=$id\">$name</a></li>";
            }
        } catch (PDOException $e) {
            echo "<li>Error loading schools.</li>";
        }
        ?>
    </ul>
</li>

									<li class="add-menu-right">
										<img src="assets/images/adv/adv.jpg" alt=""/>
									</li>
								</ul>
							</li>
							<li><a href="javascript:;">Blog <i class="fa fa-chevron-down"></i></a>
								<ul class="sub-menu">
								
									<li><a href="coming-soon.html">Blogs</a></li>
								</ul>
							</li>
                            <!------
							<li class="nav-dashboard"><a href="javascript:;">Dashboard <i class="fa fa-chevron-down"></i></a>
								<ul class="sub-menu">
									<li><a href="admin/index.html">Dashboard</a></li>
									<li><a href="admin/add-listing.html">Add Listing</a></li>
									<li><a href="admin/bookmark.html">Bookmark</a></li>
									<li><a href="admin/courses.html">Courses</a></li>
									<li><a href="admin/review.html">Review</a></li>
									<li><a href="admin/teacher-profile.html">Teacher Profile</a></li>
									<li><a href="admin/user-profile.html">User Profile</a></li>
									<li><a href="javascript:;">Calendar<i class="fa fa-angle-right"></i></a>
										<ul class="sub-menu">
											<li><a href="admin/basic-calendar.html">Basic Calendar</a></li>
											<li><a href="admin/list-view-calendar.html">List View Calendar</a></li>
										</ul>
									</li>
									<li><a href="javascript:;">Mailbox<i class="fa fa-angle-right"></i></a>
										<ul class="sub-menu">
											<li><a href="admin/mailbox.html">Mailbox</a></li>
											<li><a href="admin/mailbox-compose.html">Compose</a></li>
											<li><a href="admin/mailbox-read.html">Mail Read</a></li>
										</ul>
									</li>
								</ul>
							</li>
                            <------>
                            <li class="nav-dashboard"><a href="javascript:;">Admission <i class="fa fa-chevron-down"></i></a>
								<ul class="sub-menu">
									<li><a href="application-procedure">Application Procedure</a></li>
									<li><a href="requirements">Requirements</a></li>
								</ul>
							</li>
                            <li class="nav-dashboard"><a href="coming-soon.html">Contact Us</a>
								<ul class="sub-menu">
									<li><a href="coming-soon.html">Contact Pioneer</a></li>
								</ul>
							</li>
						</ul>
						<div class="nav-social-link">
							<a href="coming-soon.html"><i class="fa fa-facebook"></i></a>
							<a href="coming-soon.html"><i class="fa fa-google-plus"></i></a>
							<a href="coming-soon.html"><i class="fa fa-linkedin"></i></a>
						</div>
                    </div>
					<!-- Navigation Menu END ==== -->
                </div>
            </div>
        </div>
    </header>