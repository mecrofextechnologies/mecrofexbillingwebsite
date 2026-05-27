<?php 
include '../includes/connect.php'; // Include the PDO connection

?>
<div class="container-fluid">
	<div id="msg"></div>
	
	<form action="" id="manage-user">	
		<input type="hidden" name="id">
		<input type="hidden" name="table" value="<?php echo $utype[$_SESSION['login_type']] ?>">
		<div class="form-group">
			<label for="name">First Name</label>
			<input type="text" name="firstname" id="firstname" class="form-control" value="PAUL ODINYA" required>
		</div>
		<div class="form-group">
			<label for="name">Middle Name</label>
			<input type="text" name="middlename" id="middlename" class="form-control" value="ODINYA">
		</div>
		<div class="form-group">
			<label for="name">Last Name</label>
			<input type="text" name="lastname" id="lastname" class="form-control" value="OTIENO" required>
		</div>
		<div class="form-group">
			<label for="username">Username/Email</label>
			<input type="text" name="username" id="username" class="form-control" value="MEDOLIST" required  autocomplete="off">
		</div>
		<div class="form-group">
			<label for="password">Password</label>
			<input type="password" name="password" id="password" class="form-control" value="" autocomplete="off">
			<small><i>Leave this blank if you dont want to change the password.</i></small>
		</div>
		
		

	</form>
</div>
