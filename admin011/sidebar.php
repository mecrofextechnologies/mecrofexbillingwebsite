<?php 
// Check if the user is logged in
// 🚨 Check before ANY HTML or includes
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    echo '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Login Required</title>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>
            body {
                margin: 0;
                background-color: navy;
            }
        </style>
    </head>
    <body>
        <script>
            Swal.fire({
                icon: "warning",
                title: "Login Required",
                text: "You must log in first to access this page.",
                confirmButtonText: "Go to Login",
                background: "#fff"
            }).then(() => {
                window.location.href = "login.php";
            });
        </script>
    </body>
    </html>';
    exit(); // ✅ Prevents sidebar/topbar from loading
}

// Assuming the logged-in admin's ID is stored in the session
$admin_id = $_SESSION['admin_id']; // Replace with actual session variable if different
?>

<style>
  .main-sidebar {
    background-color: #3083B5;
  }
</style>

<aside class="main-sidebar sidebar-dark-secondary elevation-4 ">
   <div class="dropdown">
  <a href="javascript:void(0)" 
     class="brand-link dropdown-toggle d-flex align-items-center px-3 py-2" 
     data-toggle="dropdown" aria-expanded="false"
     style="background: #4CAF6D; border-radius: 8px; text-decoration: none;">

    <!-- Avatar Circle -->
    <span class="brand-image img-circle d-flex justify-content-center align-items-center text-white font-weight-bold shadow-sm" 
          style="width: 42px; height: 42px; background: rgba(255,255,255,0.25); font-size: 18px;">
        <?php echo strtoupper(substr($user['firstname'], 0, 1)); ?><?php echo strtoupper(substr($user['lastname'], 0, 1)); ?>
    </span>

    <!-- User Name -->
    <span class="brand-text font-weight-semibold text-white ml-2">
        <?php echo htmlspecialchars($user['firstname']); ?> <?php echo htmlspecialchars($user['lastname']); ?>
    </span>
  </a>

  <!-- Dropdown Menu -->
  <div class="dropdown-menu dropdown-menu-right shadow-sm" style="border-radius: 8px; min-width: 200px;">
    <a class="dropdown-item manage_account d-flex align-items-center" href="javascript:void(0)" data-id="">
      <i class="fas fa-user-cog mr-2 text-success"></i> Manage Account
    </a>
    <div class="dropdown-divider"></div>
    <a class="dropdown-item d-flex align-items-center text-danger" href="ajax.php?action=logout">
      <i class="fas fa-sign-out-alt mr-2"></i> Logout
    </a>
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
               
   
        
<li class="nav-item ">
    <a href="#" class="nav-link nav-link1 nav-edit_staff">
        <i class="nav-icon fas fa-globe"></i>
        <p>User Homepage <i class="right fas fa-angle-left"></i></p>
    </a>
    <ul class="nav nav-treeview">
        <li class="nav-item">
            <a href="./index.php?page=slider" class="nav-link nav-new_staff tree-item">
                <i class="fas fa-images nav-icon"></i>
                <p>Slider</p>
            </a>
        </li>
        <li class="nav-item">
            <a href="./index.php?page=about_us" class="nav-link nav-about_us tree-item">
                <i class="fas fa-circle-info nav-icon"></i>
                <p>About Us</p>
            </a>
        </li>

    </ul>
</li>

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

 <li class="nav-item">
                    <a href="#" class="nav-link nav-link4">
                        <i class="nav-icon fas fa-blog"></i>
                        <p>Media <i class="right fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="./index.php?page=blogs" class="nav-link nav-sub_link3_1 tree-item">
                                <i class="fas fa-plus-circle nav-icon"></i>
                                <p>Add blog</p>
                            </a>
                        </li>
                       

                        <li class="nav-item">
                            <a href="./index.php?page=youtube_videos" class="nav-link nav-sub_link3_2 tree-item">
                                <i class="fas fa-list-ul nav-icon"></i>
                                <p>All blogs</p>
                            </a>
                        </li>

                    </ul>
                </li>

                <li class="nav-item">
                    <a href="#" class="nav-link nav-link2">
                        <i class="nav-icon fas fa-users"></i>
                        <p>Creators <i class="right fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="./index.php?page=all_creators" class="nav-link nav-sub_link1_1 tree-item">
                                <i class="fas fa-list-alt nav-icon"></i>
                                <p>All creators</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./index.php?page=all_creators&tab=inactivity" class="nav-link nav-sub_link1_2 tree-item">
                                <i class="fas fa-user-clock nav-icon"></i>
                                <p>Inactivity management</p>
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a href="#" class="nav-link nav-link2">
                        <i class="nav-icon fas fa-user-group"></i>
                        <p>Audience <i class="right fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="./index.php?page=ticket_buyers" class="nav-link tree-item">
                                <i class="fas fa-ticket nav-icon"></i>
                                <p>Ticket buyers</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./index.php?page=donors" class="nav-link tree-item">
                                <i class="fas fa-hand-holding-heart nav-icon"></i>
                                <p>Donors</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./index.php?page=visitor_accounts" class="nav-link tree-item">
                                <i class="fas fa-address-book nav-icon"></i>
                                <p>Visitor accounts</p>
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a href="#" class="nav-link nav-link3">
                        <i class="nav-icon fas fa-exchange-alt"></i>
                        <p>Transactions <i class="right fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="./index.php?page=transactions" class="nav-link nav-sub_link2_1 tree-item">
                                <i class="fas fa-list-ul nav-icon"></i>
                                <p>All transactions</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="./index.php?page=withdraw" class="nav-link nav-mission tree-item">
                                <i class="fas fa-money-bill-wave nav-icon"></i>
                                <p>Withdraw funds</p>
                            </a>
                        </li>
                    </ul>
                </li>
                
                
                
                
                
<li class="nav-item">
    <a href="#" class="nav-link nav-link3">
        <i class="nav-icon fas fa-hand-holding-usd"></i>
        <p>
            Requests
            <i class="right fas fa-angle-left"></i>
        </p>
    </a>
    <ul class="nav nav-treeview">
        <li class="nav-item">
            <a href="./index.php?page=approve_withdrawal" class="nav-link nav-sub_link2_1 tree-item">
                <i class="fas fa-clipboard-list nav-icon"></i>
                <p>All Requests</p>
            </a>
        </li>
        <li class="nav-item">
            <a href="./index.php?page=approved_requests" class="nav-link nav-mission tree-item">
                <i class="fas fa-check-circle nav-icon"></i>
                <p>Approved Requests</p>
            </a>
        </li>
    </ul>
</li>

                
                
                
                
               
           <li class="nav-item">
                  <a href="#" class="nav-link nav-link5">
                    <i class="nav-icon fas fa-ticket-alt"></i>
                    <p>Tickets <i class="right fas fa-angle-left"></i></p>
                  </a>
                  <ul class="nav nav-treeview">
                    <li class="nav-item">
                          <a href="index.php?page=creator_tickets" class="nav-link nav-sub_link5_1 tree-item">
                              <i class="fas fa-list-ul nav-icon"></i>
                              <p>All Tickets</p>
                           </a>
                      </li>
                      <li class="nav-item">
                          <a href="index.php?page=all_tickets" class="nav-link nav-sub_link5_1 tree-item">
                              <i class="fas fa-list-ul nav-icon"></i>
                              <p>All Tickets Purchases</p>
                           </a>
                      </li>
                      
                      <li class="nav-item">
                          <a href="index.php?page=all_events_analytics" class="nav-link nav-sub_link5_1 tree-item">
                              <i class="fas fa-list-ul nav-icon"></i>
                              <p>All Events Analytics</p>
                           </a>
                      </li>
                      
                      <li class="nav-item">
                          <a href="index.php?page=surveys_analytics" class="nav-link nav-sub_link5_1 tree-item">
                              <i class="fas fa-list-ul nav-icon"></i>
                              <p>Surveys Analytics</p>
                           </a>
                      </li>

                      <li class="nav-item">
                          <a href="index.php?page=automations" class="nav-link nav-automations tree-item">
                              <i class="fas fa-robot nav-icon"></i>
                              <p>Automations</p>
                           </a>
                      </li>

                  </ul>
               </li>

                <li class="nav-item">
                          <a href="./index.php?page=whatsapp_bot_status" class="nav-link nav-link5 nav-whatsapp_bot_status">
                          <i class="nav-icon fa-brands fa-whatsapp"></i>
                          <p>WhatsApp Bot Status</p>
                          </a>
                 </li>

                <li class="nav-item">
                          <a href="#" class="nav-link nav-link5 nav-settings">
                          <i class="nav-icon fas fa-cogs"></i>
                          <p>Settings <i class="right fas fa-angle-left"></i></p>
                          </a>
                          <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="./index.php?page=settings" class="nav-link tree-item">
                                    <i class="fas fa-sliders-h nav-icon"></i>
                                    <p>General</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="./index.php?page=daraja_settings" class="nav-link tree-item">
                                    <i class="fas fa-key nav-icon"></i>
                                    <p>Daraja API Credentials</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="./index.php?page=sms_settings" class="nav-link tree-item">
                                    <i class="fas fa-sms nav-icon"></i>
                                    <p>SMS Settings</p>
                                </a>
                            </li>
                          </ul>
                 </li>
                 
                     <li class="nav-item">
                           <a href="./index.php?page=create_terms_of_service" class="nav-link nav-link5 nav-terms">
                               <i class="nav-icon fas fa-file-contract"></i>
                              <p>Terms of Service</p>
                             </a>
                      </li>

                
             <li class="nav-item">
                <a href="./index.php?page=support_center" class="nav-link nav-support_center">
             <i class="nav-icon fas fa-headset"></i>
                  <p>Support Center</p>
                    </a>
                 </li>
           
            </ul>
        </nav>
    </div>
</aside>

<style>
    .main-sidebar {
        min-height: 100vh;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        color: #fff;
    }

    .main-sidebar .nav-link {
        border-radius: 6px;
        margin: 4px 10px;
        font-size: 15px;
        font-weight: 500;
        transition: all 0.3s ease;
        color: #fff;
    }

    .main-sidebar .nav-link i {
        width: 22px;
        text-align: center;
    }

    .main-sidebar .nav-link:hover,
    .main-sidebar .nav-link.active {
        background: rgba(255, 255, 255, 0.2);
        color: #fff !important;
        transform: translateX(4px);
    }

    .main-sidebar .nav-treeview .nav-link {
        font-size: 14px;
        padding-left: 35px;
        color: #f1f1f1;
    }

    .main-sidebar .nav-treeview .nav-link:hover {
        background: rgba(255, 255, 255, 0.15);
    }

    .dropdown-menu {
        font-size: 14px;
    }

    .dropdown-menu a {
        transition: background 0.3s ease;
    }

    .dropdown-menu a:hover {
        background: #f1f1f1;
        border-radius: 5px;
    }
</style>

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