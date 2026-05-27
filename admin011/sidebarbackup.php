<?php 
// Check if the user is logged in
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    // Redirect to login page if not logged in
    header("Location: login.php");
    exit();
}

// Assuming the logged-in admin's ID is stored in the session
$admin_id = $_SESSION['admin_id']; // Replace with actual session variable if different

// Query to check the OTP status
$query = "SELECT otp_status FROM admin WHERE admin_id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$admin_id]);

// Fetch the OTP status
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row && $row['otp_status'] == 0) {
    // Output JavaScript to redirect to otp.php
    echo "<script>window.location.href = 'otp.php';</script>";
    exit();
}
?>




<style>
  .main-sidebar {
    background-color: #1e5e88;
  }
 
</style>


<aside class="main-sidebar sidebar-dark-secondary elevation-4">
    <div class="dropdown">
        <a href="javascript:void(0)" class="brand-link dropdown-toggle" data-toggle="dropdown" aria-expanded="true">
            <span class="brand-image img-circle elevation-3 d-flex justify-content-center align-items-center bg-primary text-white font-weight-500" style="width: 38px; height: 50px;"></span>
            <span class="brand-text font-weight-light">
                <?php echo htmlspecialchars($user['firstname']); ?> <?php echo htmlspecialchars($user['lastname']); ?>
            </span>
        </a>
        <div class="dropdown-menu">
            <a class="dropdown-item manage_account" href="javascript:void(0)" data-id="">Manage Account</a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item" href="ajax.php?action=logout">Logout</a>

        </div>
    </div>
    <div class="sidebar">
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column nav-flat" data-widget="treeview" role="menu" data-accordion="false">
                <li class="nav-item dropdown">
                    <a href="./" class="nav-link nav-link0 nav-home">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>Dashboard</p>
                    </a>
                </li>
               
   
                



<?php
$user_role = $_SESSION['user_role']; 
if ($user_role == 4):
?>

<li class="nav-item">
    <a href="#" class="nav-link nav-link7">
        <i class="nav-icon fas fa-hand-holding-usd"></i>
        <p>Admissions <i class="right fas fa-angle-left"></i></p>
    </a>
    <ul class="nav nav-treeview">
        <li class="nav-item">
            <a href="./index.php?page=coming_soon" class="nav-link nav-coming_soon tree-item">
                <i class="fas fa-file-alt nav-icon"></i>
                <p>Admission Procedure</p>
            </a>
        </li>
        <li class="nav-item">
            <a href="./index.php?page=sub_link7_2" class="nav-link nav-sub_link7_2 tree-item">
                <i class="fas fa-dollar-sign nav-icon"></i>
                <p>Fee Structure</p>
            </a>
        </li>
        <li class="nav-item">
            <a href="./index.php?page=sub_link7_3" class="nav-link nav-sub_link7_3 tree-item">
                <i class="fas fa-edit nav-icon"></i>
                <p>Online Form</p>
            </a>
        </li>
        <li class="nav-item">
            <a href="./index.php?page=sub_link7_4" class="nav-link nav-sub_link7_4 tree-item">
                <i class="fas fa-gift nav-icon"></i>
                <p>Scholarship</p>
            </a>
        </li>
        <li class="nav-item">
            <a href="./index.php?page=sub_link7_5" class="nav-link nav-sub_link7_5 tree-item">
                <i class="fas fa-credit-card nav-icon"></i>
                <p>Payment Information</p>
            </a>
        </li>
        <li class="nav-item">
            <a href="./index.php?page=sub_link7_6" class="nav-link nav-sub_link7_6 tree-item">
                <i class="fas fa-map-marker-alt nav-icon"></i>
                <p>Campus Tour</p>
            </a>
        </li>
    </ul>
</li>


<li class="nav-item">
    <a href="./index.php?page=support_center" class="nav-link nav-support_center">
     <i class="nav-icon fas fa-headset"></i>
     <p>Support Center</p>
     </a>
 </li>
<?php else: ?>


<?php
 $user_role = $_SESSION['user_role']; 
// Check if user_role is 1 or 2
if ($user_role == 1 || $user_role == 2):
?>
<li class="nav-item ">
    <a href="#" class="nav-link nav-link1 nav-edit_staff">
        <i class="nav-icon fas fa-user-shield"></i>
        <p>Moderator(s) <i class="right fas fa-angle-left"></i></p>
    </a>
    <ul class="nav nav-treeview">
        <li class="nav-item">
            <a href="./index.php?page=new_moderator" class="nav-link nav-new_staff tree-item">
                <i class="fas fa-user-plus nav-icon"></i>
                <p>Add New Moderator</p>
            </a>
        </li>
        <li class="nav-item">
            <a href="./index.php?page=moderators_list" class="nav-link nav-staff_list tree-item">
                <i class="fas fa-users nav-icon"></i>
                <p>All Moderators</p>
            </a>
        </li>
    </ul>
</li>
<?php endif; ?>

                <li class="nav-item">
                    <a href="#" class="nav-link nav-link2">
                        <i class="nav-icon fas fa-camera"></i>
                        <p>Media <i class="right fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="./index.php?page=new_media" class="nav-link nav-sub_link1_1 tree-item">
                                <i class="fas fa-plus-circle nav-icon"></i>
                                <p>Add New Blog</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./index.php?page=new_photo_gallery" class="nav-link nav-sub_link1_1 tree-item">
                                <i class="fas fa-plus-circle nav-icon"></i>
                                <p>Add New Photo gallery</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./index.php?page=media_list" class="nav-link nav-sub_link1_2 tree-item">
                                <i class="fas fa-list-alt nav-icon"></i>
                                <p>Blog List</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./index.php?page=downloads" class="nav-link nav-sub_link1_2 tree-item">
                                <i class="fas fa-download nav-icon"></i>
                                <p>Downloads</p>
                            </a>
                        </li>
                       
                       
                    </ul>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link nav-link3">
                        <i class="nav-icon fas fa-info-circle"></i>
                        <p>About <i class="right fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="./index.php?page=vision" class="nav-link nav-sub_link2_1 tree-item">
                                <i class="fas fa-info nav-icon"></i>
                                <p>Vision</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./index.php?page=mission" class="nav-link nav-mission tree-item">
                                <i class="fas fa-certificate nav-icon"></i>
                                <p>Mission</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./index.php?page=sub_link2_3" class="nav-link nav-sub_link2_2 tree-item">
                                <i class="fas fa-eye nav-icon"></i>
                                <p>Vision & Mission</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./index.php?page=sub_link2_4" class="nav-link nav-sub_link2_2 tree-item">
                                <i class="fas fa-star nav-icon"></i>
                                <p>Core Values</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link nav-link4">
                        <i class="nav-icon fas fa-home"></i>
                        <p>User Homepage <i class="right fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="./index.php?page=slides" class="nav-link nav-sub_link3_1 tree-item">
                                <i class="fas fa-sliders-h nav-icon"></i>
                                <p>Slides</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./index.php?page=description" class="nav-link nav-sub_link3_2 tree-item">
                                <i class="fas fa-file-alt nav-icon"></i>
                                <p>Pioneer Description</p>
                            </a>
                        </li>
                       
                       
                        <li class="nav-item">
                            <a href="./index.php?page=testimonials" class="nav-link nav-sub_link3_2 tree-item">
                                <i class="fas fa-quote-right nav-icon"></i>
                                <p>Testimonials</p>
                            </a>
                        </li>
                       
                        <li class="nav-item">
                            <a href="./index.php?page=sub_link3_8" class="nav-link nav-sub_link3_2 tree-item">
                                <i class="fas fa-chart-bar nav-icon"></i>
                                <p>Statistics</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./index.php?page=youtube_videos" class="nav-link nav-sub_link3_2 tree-item">
                                <i class="fab fa-youtube nav-icon"></i>
                                <p>Youtube Videos</p>
                            </a>
                        </li>
                        <li class="nav-item">
                                 <a href="./index.php?page=adverts" class="nav-link nav-sub_link3_2 tree-item">
                                <i class="fas fa-bullhorn nav-icon"></i>
                          <p>Adverts</p>
                          </a>
                        </li>
                         <li class="nav-item">
                              <a href="./index.php?page=notice_board" class="nav-link nav-sub_link3_2 tree-item">
                               <i class="fas fa-bullhorn nav-icon"></i>
                          <p>Notice Board</p>
                       </a>
                       </li>

                    </ul>
                </li>
             
                <li class="nav-item">
                    <a href="#" class="nav-link nav-link5">
                        <i class="nav-icon fas fa-building"></i>
                        <p>Courses <i class="right fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="./index.php?page=courses" class="nav-link nav-sub_link5_1 tree-item">
                                <i class="fas fa-book nav-icon"></i>
                                <p>Add a Course</p>
                            </a>
                        </li>
                        
                    </ul>
                </li>

                 <li class="nav-item">
                    <a href="#" class="nav-link nav-link5">
                        <i class="nav-icon fas fa-building"></i>
                        <p>Schools <i class="right fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="./index.php?page=schools" class="nav-link nav-sub_link5_1 tree-item">
                                <i class="fas fa-book nav-icon"></i>
                                <p>Add a School</p>
                            </a>
                        </li>
                        
                    </ul>
                </li>

                <?php
                  $user_role = $_SESSION['user_role']; 
                 // Check if user_role is 1 or 2
                  if ($user_role == 1 || $user_role == 2):
                ?>
              
                <li class="nav-item">
                    <a href="#" class="nav-link nav-link7">
                        <i class="nav-icon fas fa-hand-holding-usd"></i>
                        <p>Admissions <i class="right fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="./index.php?page=sub_link7_1" class="nav-link nav-sub_link7_1 tree-item">
                                <i class="fas fa-file-alt nav-icon"></i>
                                <p>Admission Procedure</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./index.php?page=sub_link7_2" class="nav-link nav-sub_link7_2 tree-item">
                                <i class="fas fa-dollar-sign nav-icon"></i>
                                <p>Fee Structure</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./index.php?page=sub_link7_3" class="nav-link nav-sub_link7_3 tree-item">
                                <i class="fas fa-edit nav-icon"></i>
                                <p>Online Form</p>
                            </a>
                        </li>
                       
                        <li class="nav-item">
                            <a href="./index.php?page=sub_link7_5" class="nav-link nav-sub_link7_5 tree-item">
                                <i class="fas fa-credit-card nav-icon"></i>
                                <p>Payment Information</p>
                            </a>
                        </li>
                        
                    </ul>
                </li>
           
        
                <li class="nav-item">
                          <a href="./index.php?page=settings" class="nav-link nav-link5 nav-settings">
                          <i class="nav-icon fas fa-columns"></i>
                          <p>Settings</p>
                          </a>
                 </li>
                
                 <?php endif; ?>
                  <!-- Start Tour Button as a Link -->
            <li class="nav-item">
                <a href="javascript:void(0);" onclick="startTour();" class="nav-link nav-tour">
                    <i class="nav-icon fas fa-play-circle"></i>
                    <p>Start Tour</p>
                </a>
            </li>
             <li class="nav-item">
                <a href="./index.php?page=support_center" class="nav-link nav-support_center">
             <i class="nav-icon fas fa-headset"></i>
                  <p>Support Center</p>
                    </a>
                 </li>
           
            <?php endif; ?>
            
            </ul>
        </nav>
    </div>
</aside>
<!-----------style for tour--------> 
<script>
    function startTour() {
        var userRole = <?php echo json_encode($_SESSION['user_role']); ?>; // Assuming user_role is stored in session

        var steps = [];

        // Conditionally add nav-link1 step
        if (userRole !== 3 && userRole !== 4) {
            steps.push({
                element: document.querySelector('.nav-link1'),
                intro: "This is where you will add moderators who will be managing the system.",
                position: 'right'
            });
        }

        // Conditionally add nav-settings step
        if (userRole !== 3 && userRole !== 4) {
            steps.push({
                element: document.querySelector('.nav-settings'),
                intro: "This is where you will conduct system settings such as institution name, address, phone, email, and more.",
                position: 'bottom'
            });
        }

       
        // Add the remaining steps
        steps.push(
            {
                element: document.querySelector('.nav-link2'),
                intro: "Here is where you will be updating contents such as blogs, news, downloads, events and more.",
                position: 'right'
            },
            {
                element: document.querySelector('.nav-link3'),
                intro: "This is the about section where you will be updating information about PIONEER, core values, vision, mission, and more.",
                position: 'right'
            },
            {
                element: document.querySelector('.nav-link4'),
                intro: "This is where you will be updating the user homepage, which includes slides, YouTube videos, and more, especially what is seen on the user homepage (index page).",
                position: 'top'
            },
            {
                element: document.querySelector('.nav-link7'),
                intro: "All admissions and applicants-related information is available here.",
                position: 'right'
            },
            {
                element: document.querySelector('.nav-link0'),
                intro: "All set! If you experience any technical issues, contact our support team via 0711888037. Enjoy the system!",
                position: 'right'
            }
        );

        // Initialize the tour with the constructed steps
        introJs().setOptions({
            steps: steps,
            showBullets: false,
            showProgress: true,
            showStepNumbers: false,
            disableInteraction: true,
            scrollToElement: true,
            exitOnEsc: true,
            exitOnOverlayClick: false,
            nextLabel: 'Next',
            prevLabel: 'Back',
            doneLabel: 'Done',
            tooltipClass: 'customTooltip'
        }).onbeforechange(function(targetElement) {
            // Add smooth transition effect
            targetElement.style.transition = 'all 0.3s ease';
        }).start();
    }
</script>







<script>
    $(document).ready(function(){
        var page = '<?php echo isset($_GET['page']) ? $_GET['page'] : 'home' ?>';
        if($('.nav-link.nav-'+page).length > 0){
            $('.nav-link.nav-'+page).addClass('active')
            console.log($('.nav-link.nav-'+page).hasClass('tree-item'))
            if($('.nav-link.nav-'+page).hasClass('tree-item') == true){
                $('.nav-link.nav-'+page).closest('.nav-treeview').siblings('a').addClass('active')
                $('.nav-link.nav-'+page).closest('.nav-treeview').parent().addClass('menu-open')
            }
        }
        $('.manage_account').click(function(){
            uni_modal('Manage Account','manage_user.php?id='+$(this).attr('data-id'))
        })
    })
</script>
