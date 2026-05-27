<?php
include '../includes/connect.php'; 


ob_start();
$action = $_GET['action'];
include 'admin_class.php';
$crud = new Action();
if($action == 'login'){
	$login = $crud->login();
	if($login)
		echo $login;
}
if($action == 'logout'){
	$logout = $crud->logout();
	if($logout)
		echo $logout;
}
if($action == 'save_user'){
	$save = $crud->save_user();
	if($save)
		echo $save;
}
if($action == 'save_page_img'){
	$save = $crud->save_page_img();
	if($save)
		echo $save;
}
if($action == 'delete_user'){
	$save = $crud->delete_user();
	if($save)
		echo $save;
}
if($action == "save_customer"){
	$save = $crud->save_customer();
	if($save)
		echo $save;
}
if($action == "delete_customer"){
	$delete = $crud->delete_customer();
	if($delete)
		echo $delete;
}
if($action == "save_staff"){
	$save = $crud->save_staff();
	if($save)
		echo $save;
}
if($action == "delete_staff"){
	$delete = $crud->delete_staff();
	if($delete)
		echo $delete;
}
if($action == "save_department"){
	$save = $crud->save_department();
	if($save)
		echo $save;
}
if($action == "delete_department"){
	$delete = $crud->delete_department();
	if($delete)
		echo $delete;
}
if($action == "delete_moderator"){
	$delete = $crud->delete_moderator();
	if($delete)
		echo $delete;
}
if($action == "save_ticket"){
	$save = $crud->save_ticket();
	if($save)
		echo $save;
}
if($action == "delete_ticket"){
	$delsete = $crud->delete_ticket();
	if($delsete)
		echo $delsete;
}

if($action == "update_ticket"){
	$save = $crud->update_ticket();
	if($save)
		echo $save;
}
if($action == "save_comment"){
	$save = $crud->save_comment();
	if($save)
		echo $save;
}
if($action == "delete_comment"){
	$delsete = $crud->delete_comment();
	if($delsete)
		echo $delsete;
}

ob_end_flush();






if (isset($_POST['action']) && $_POST['action'] == 'save_staff') {
    $firstname = $_POST['firstname'];
    $middlename = $_POST['middlename'];
    $lastname = $_POST['lastname'];
    $contact = $_POST['contact'];
    $email = $_POST['email'];
    $password = $_POST['password'];

    // Check if email already exists
    $query = $conn->prepare("SELECT * FROM staff WHERE email = ?");
    $query->bind_param("s", $email);
    $query->execute();
    $result = $query->get_result();

    if ($result->num_rows > 0) {
        echo 2; // Email already exists
        exit;
    }

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert the new staff member
    $query = $conn->prepare("INSERT INTO staff (firstname, middlename, lastname, contact, email, password) VALUES (?, ?, ?, ?, ?, ?)");
    $query->bind_param("ssssss", $firstname, $middlename, $lastname, $contact, $email, $hashed_password);

    if ($query->execute()) {
        echo 1; // Success
    } else {
        echo 0; // Failure
    }
}
?>

